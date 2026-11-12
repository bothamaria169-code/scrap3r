<?php
declare(strict_types=1);

/**
 * Gets leads into the system from the two sources that actually have data:
 * the Python scraper's JSON output (targeting info, no contact details) and
 * a CSV upload (where the email addresses come from).
 */
final class LeadImporter
{
    /**
     * Header names accepted for each CSV field, lowercase, first match wins.
     * Covers our own format plus the exports of Apollo, Hunter, Lusha and
     * LinkedIn-style tools, so their CSVs import without hand-editing.
     */
    private const CSV_ALIASES = [
        'email'       => ['email', 'email address', 'e-mail', 'work email', 'business email', 'primary email'],
        'name'        => ['name', 'full name', 'contact name', 'person name'],
        'first_name'  => ['first name', 'first_name', 'firstname', 'given name'],
        'last_name'   => ['last name', 'last_name', 'lastname', 'surname', 'family name'],
        'title'       => ['title', 'job title', 'position', 'role', 'job_title'],
        'company'     => ['company', 'company name', 'organization', 'organisation', 'account name', 'company_name'],
        'phone'       => ['phone', 'phone number', 'mobile phone', 'work direct phone', 'direct phone',
                          'corporate phone', 'mobile', 'telephone'],
        'source_url'  => ['source_url', 'zoominfo url', 'profile url'],
        'description' => ['description', 'notes'],
    ];

    /** First non-empty value among a field's accepted header names. */
    private static function pick(array $rec, string $field): ?string
    {
        foreach (self::CSV_ALIASES[$field] as $header) {
            $value = trim((string) ($rec[$header] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }
    /**
     * Import the scraper's output file.
     *
     * The live feed has 41 fields, but contact details (email_formats,
     * phone_number, top_contacts) come back null in practice, so records
     * land unmailable until someone supplies an address. The whole record
     * is kept in `raw_json` regardless.
     *
     * @return array{import_id:int, seen:int, created:int, updated:int, skipped:int, errors:array<int,string>}
     */
    public static function importScraperJson(string $path, ?int $userId = null): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0, 'errors' => []];

        if (!is_file($path)) {
            $stats['errors'][] = "File not found: $path";
            return $stats + ['import_id' => 0];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $stats['errors'][] = 'File is not valid JSON.';
            return $stats + ['import_id' => 0];
        }
        if (isset($data['url']) || isset($data['id'])) {
            $data = [$data]; // single record
        }

        $importId = Db::insert('lead_imports', [
            'source'     => 'scraper',
            'filename'   => basename($path),
            'created_by' => $userId,
        ]);

        foreach ($data as $row) {
            if (!is_array($row)) {
                $stats['skipped']++;
                continue;
            }
            $stats['seen']++;

            // Bright Data returns a per-URL row carrying an `error` instead of
            // data when the crawl was blocked. Record why rather than dropping it.
            if (!empty($row['error'])) {
                $stats['errored']++;
                $failed = $row['input']['url'] ?? '(unknown url)';
                $stats['errors'][] = sprintf(
                    '%s — %s: %s',
                    $failed,
                    $row['error_code'] ?? 'error',
                    $row['error']
                );
                continue;
            }

            // Identity comes from the top-level fields only. `input.url` is
            // echoed back even on rows carrying no data, so trusting it here
            // would turn every empty placeholder into a junk lead.
            $externalId = isset($row['id']) ? (string) $row['id'] : null;
            $url = isset($row['url']) ? (string) $row['url'] : null;

            if ($externalId === null && $url === null) {
                $stats['skipped']++;
                continue;
            }

            $parsed = parse_description($row['description'] ?? null);

            $fields = [
                'external_id'      => $externalId,
                'source_url'       => $url,
                'record_type'      => self::recordType($url),
                'name'             => ($row['name'] ?? null) ?: $parsed['name'],
                'title'            => $parsed['title'],
                'company'          => $parsed['company'],
                'website'          => self::scalar($row['website'] ?? null),
                'industry'         => self::scalar($row['industry'] ?? null),
                'headquarters'     => self::scalar($row['headquarters'] ?? null) ?: $parsed['headquarters'],
                'employees'        => self::scalar($row['employees'] ?? $row['employees_text'] ?? null) ?: $parsed['employees'],
                'description'      => $row['description'] ?? null,
                'raw_json'         => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'revenue'          => self::scalar($row['revenue'] ?? $row['revenue_text'] ?? null),
                'revenue_currency' => self::scalar($row['revenue_currency'] ?? null),
                'email_formats'    => self::listToText($row['email_formats'] ?? null),
                'import_id'        => $importId,
            ];

            // Never overwrite a hand-entered phone number with a scraper NULL.
            if (!empty($row['phone_number'])) {
                $fields['phone'] = self::scalar($row['phone_number']);
            }

            $existingId = $externalId !== null
                ? Db::value('SELECT id FROM leads WHERE external_id = :x', ['x' => $externalId])
                : null;

            if ($existingId) {
                // Never overwrite a hand-entered email/phone with a scraper NULL.
                Db::update('leads', $fields, 'id = :wid', ['wid' => $existingId]);
                $stats['updated']++;
            } else {
                Db::insert('leads', $fields);
                $stats['created']++;
            }
        }

        Db::update('lead_imports', [
            'rows_seen'    => $stats['seen'],
            'rows_created' => $stats['created'],
            'rows_updated' => $stats['updated'],
            'rows_skipped' => $stats['skipped'],
            'rows_errored' => $stats['errored'],
        ], 'id = :wid', ['wid' => $importId]);

        return $stats + ['import_id' => $importId];
    }

    /**
     * Save contacts picked from a Hunter domain search. Matched on email, so
     * importing the same person twice updates them rather than duplicating.
     *
     * @param array<int, array<string,mixed>> $contacts normalised by Hunter::domainSearch()
     */
    public static function importHunterContacts(array $contacts, ?string $domain, ?int $userId = null): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0, 'errors' => []];

        $importId = Db::insert('lead_imports', [
            'source'     => 'hunter',
            'filename'   => $domain ? 'hunter:' . $domain : 'hunter',
            'created_by' => $userId,
        ]);

        foreach ($contacts as $c) {
            $stats['seen']++;
            $email = strtolower(trim((string) ($c['email'] ?? '')));
            if (!is_valid_email($email)) {
                $stats['skipped']++;
                continue;
            }

            $fields = array_filter([
                'email'            => $email,
                'name'             => $c['name'] ?? null,
                'title'            => $c['position'] ?? null,
                'company'          => $c['company'] ?? null,
                'phone'            => $c['phone'] ?? null,
                'linkedin_url'     => $c['linkedin'] ?? null,
                'website'          => $domain,
                'email_confidence' => isset($c['confidence']) ? (int) $c['confidence'] : null,
                'email_status'     => $c['status'] ?? null,
                'email_source'     => 'hunter',
            ], static fn($v) => $v !== null && $v !== '');

            $existingId = Db::value('SELECT id FROM leads WHERE email = :e', ['e' => $email]);
            if ($existingId) {
                Db::update('leads', $fields, 'id = :wid', ['wid' => $existingId]);
                $stats['updated']++;
            } else {
                Db::insert('leads', $fields + ['import_id' => $importId, 'record_type' => 'person']);
                $stats['created']++;
            }
        }

        Db::update('lead_imports', [
            'rows_seen'    => $stats['seen'],
            'rows_created' => $stats['created'],
            'rows_updated' => $stats['updated'],
            'rows_skipped' => $stats['skipped'],
        ], 'id = :wid', ['wid' => $importId]);

        return $stats + ['import_id' => $importId];
    }

    /** Flatten whatever Bright Data put in a field into a short string. */
    private static function scalar(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value) || is_object($value)) {
            return is_array($value) && $value !== [] ? mb_substr(implode(', ', array_filter($value, 'is_scalar')), 0, 250) ?: null : null;
        }
        return mb_substr((string) $value, 0, 250);
    }

    private static function listToText(mixed $value): ?string
    {
        if (is_array($value) && $value !== []) {
            return mb_substr(implode(', ', array_map(
                static fn($v) => is_scalar($v) ? (string) $v : json_encode($v),
                $value
            )), 0, 1000);
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Import a CSV. Expected headers (case-insensitive, extras ignored):
     * email, name, title, company, phone, source_url, description
     */
    public static function importCsv(string $path, ?int $userId = null, ?string $originalName = null): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0, 'errors' => []];

        $handle = @fopen($path, 'r');
        if (!$handle) {
            $stats['errors'][] = 'Could not open the uploaded file.';
            return $stats + ['import_id' => 0];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $stats['errors'][] = 'CSV appears to be empty.';
            return $stats + ['import_id' => 0];
        }

        // Strip a UTF-8 BOM off the first header cell if Excel added one.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $cols = array_map(static fn($h) => strtolower(trim((string) $h)), $header);

        if (!array_intersect(self::CSV_ALIASES['email'], $cols)) {
            fclose($handle);
            $stats['errors'][] = 'CSV must contain an email column (e.g. "email" or "Email address"). Found: '
                . implode(', ', $cols);
            return $stats + ['import_id' => 0];
        }

        $importId = Db::insert('lead_imports', [
            'source'     => 'csv',
            'filename'   => $originalName ?: basename($path),
            'created_by' => $userId,
        ]);

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $stats['seen']++;
            $rec = array_combine(
                array_slice($cols, 0, count($row)),
                array_slice($row, 0, count($cols))
            ) ?: [];

            $email = strtolower((string) self::pick($rec, 'email'));
            if (!is_valid_email($email)) {
                $stats['skipped']++;
                continue;
            }

            // Tools that split names (Apollo, Hunter) get joined back together.
            $name = self::pick($rec, 'name')
                ?? (trim(self::pick($rec, 'first_name') . ' ' . self::pick($rec, 'last_name')) ?: null);

            $fields = array_filter([
                'email'       => $email,
                'name'        => $name,
                'title'       => self::pick($rec, 'title'),
                'company'     => self::pick($rec, 'company'),
                'phone'       => self::pick($rec, 'phone'),
                'source_url'  => self::pick($rec, 'source_url'),
                'description' => self::pick($rec, 'description'),
            ], static fn($v) => $v !== null);

            // Match an existing lead by profile URL first, then by email.
            $existingId = null;
            if (!empty($fields['source_url'])) {
                $existingId = Db::value('SELECT id FROM leads WHERE source_url = :u', ['u' => $fields['source_url']]);
            }
            if (!$existingId) {
                $existingId = Db::value('SELECT id FROM leads WHERE email = :e', ['e' => $email]);
            }

            if ($existingId) {
                Db::update('leads', $fields, 'id = :wid', ['wid' => $existingId]);
                $stats['updated']++;
            } else {
                Db::insert('leads', $fields + ['import_id' => $importId, 'record_type' => 'person']);
                $stats['created']++;
            }
        }

        fclose($handle);

        Db::update('lead_imports', [
            'rows_seen'    => $stats['seen'],
            'rows_created' => $stats['created'],
            'rows_updated' => $stats['updated'],
            'rows_skipped' => $stats['skipped'],
        ], 'id = :wid', ['wid' => $importId]);

        return $stats + ['import_id' => $importId];
    }

    private static function recordType(?string $url): string
    {
        if (!$url) {
            return 'unknown';
        }
        if (str_contains($url, '/p/')) {
            return 'person';
        }
        // /c/ and /pic/ are both company profile paths.
        if (str_contains($url, '/c/') || str_contains($url, '/pic/')) {
            return 'company';
        }
        return 'unknown';
    }

    public const SCRAPER_UNAVAILABLE = 'The ZoomInfo scraper needs Python with the "requests" and "tenacity" '
        . 'packages, which this server does not provide. Run it on a computer that has them and import the '
        . 'JSON file here, or use Find contacts / CSV upload instead.';

    public static function scraperAvailable(): bool
    {
        return self::pythonBinary() !== null;
    }

    /** A Python that can run the collector: the project venv first, then python3/python. */
    private static function pythonBinary(): ?string
    {
        static $found = false;
        if ($found !== false) {
            return $found;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            return $found = null;
        }

        foreach ([BASE_PATH . '/.venv/Scripts/python.exe', BASE_PATH . '/.venv/bin/python'] as $venv) {
            if (is_file($venv)) {
                return $found = $venv;
            }
        }
        foreach (['python3', 'python'] as $cmd) {
            $output = [];
            $code = 1;
            @exec($cmd . ' -c "import requests, tenacity" 2>&1', $output, $code);
            if ($code === 0) {
                return $found = $cmd;
            }
        }
        return $found = null;
    }

    /** Run the Python scraper, then import whatever it wrote. */
    public static function runScraper(string $urlsFile, string $outputFile, ?int $userId = null): array
    {
        $python = self::pythonBinary();
        if ($python === null) {
            return [
                'seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0,
                'import_id' => 0,
                'errors' => [self::SCRAPER_UNAVAILABLE],
            ];
        }

        $cmd = sprintf(
            '%s %s -f %s -o %s 2>&1',
            escapeshellarg($python),
            escapeshellarg(BASE_PATH . '/zoominfo_scraper_api_codes/zoominfo_scraper.py'),
            escapeshellarg($urlsFile),
            escapeshellarg($outputFile)
        );

        exec($cmd, $output, $exitCode);
        $log = implode("\n", $output);

        if ($exitCode !== 0) {
            return [
                'seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'import_id' => 0,
                'errors' => ['Scraper exited with code ' . $exitCode . ":\n" . $log],
            ];
        }

        $result = self::importScraperJson($outputFile, $userId);
        Db::update('lead_imports', ['notes' => $log], 'id = :wid', ['wid' => $result['import_id']]);
        return $result;
    }
}
