<?php
declare(strict_types=1);

final class HunterException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorId = '',
    ) {
        parent::__construct($message);
    }
}

/**
 * Hunter.io API v2 client: domain search, email finder, verifier, account.
 *
 * The key travels in the X-API-KEY header rather than the query string, so it
 * never shows up in URLs, logs or error messages.
 */
final class Hunter
{
    private const BASE = 'https://api.hunter.io/v2/';

    public const DEPARTMENTS = [
        'executive', 'it', 'finance', 'management', 'sales', 'legal', 'support', 'hr',
        'marketing', 'communication', 'education', 'design', 'health', 'operations',
        'product', 'research', 'consulting', 'administrative', 'procurement',
    ];

    public const SENIORITIES = ['junior', 'senior', 'executive'];

    private Closure $transport;

    /** @param callable(string, array<int,string>): array{0:int,1:string}|null $transport */
    public function __construct(private string $apiKey, ?callable $transport = null)
    {
        if (trim($apiKey) === '') {
            throw new HunterException('No Hunter API key. Add one under Settings.');
        }
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : $this->curl(...);
    }

    public static function configured(): bool
    {
        return Settings::get('hunter_api_key') !== '';
    }

    public static function fromSettings(): self
    {
        return new self(Settings::get('hunter_api_key'));
    }

    /** Plan and remaining credits. Free: costs no credits. */
    public function account(): array
    {
        $d = $this->get('account', [])['data'] ?? [];
        $searches = self::quota($d, 'searches');
        $verifications = self::quota($d, 'verifications');

        return [
            'email'              => $d['email'] ?? null,
            'plan'               => $d['plan_name'] ?? ($d['plan'] ?? 'unknown'),
            'searches_used'      => $searches['used'],
            'searches_left'      => $searches['left'],
            'verifications_used' => $verifications['used'],
            'verifications_left' => $verifications['left'],
        ];
    }

    /**
     * People with addresses at a company. Costs 1 credit per 10 addresses
     * returned; empty results are free.
     */
    public function domainSearch(string $target, array $filters = []): array
    {
        $department = (string) ($filters['department'] ?? '');
        $seniority  = (string) ($filters['seniority'] ?? '');

        $params = self::target($target) + array_filter([
            'limit'      => max(1, min(100, (int) ($filters['limit'] ?? 10))),
            'offset'     => max(0, (int) ($filters['offset'] ?? 0)) ?: null,
            'type'       => in_array($filters['type'] ?? '', ['personal', 'generic'], true) ? $filters['type'] : null,
            'department' => in_array($department, self::DEPARTMENTS, true) ? $department : null,
            'seniority'  => in_array($seniority, self::SENIORITIES, true) ? $seniority : null,
            'job_titles' => trim((string) ($filters['job_titles'] ?? '')) ?: null,
        ], static fn($v) => $v !== null && $v !== '');

        $body = $this->get('domain-search', $params);
        $d = $body['data'] ?? [];
        $org = $d['organization'] ?? null;

        return [
            'domain'       => $d['domain'] ?? null,
            'organization' => $org,
            'pattern'      => $d['pattern'] ?? null,
            'accept_all'   => (bool) ($d['accept_all'] ?? false),
            'total'        => (int) ($body['meta']['results'] ?? count($d['emails'] ?? [])),
            'emails'       => array_map(static fn(array $e) => self::normalise($e, $org), $d['emails'] ?? []),
        ];
    }

    /**
     * One person's address. Costs 1 credit if found, nothing if not.
     * Returns null when Hunter has no address for them.
     */
    public function findEmail(string $fullName, string $companyOrDomain): ?array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            throw new HunterException('A person\'s name is needed to look up their email.');
        }

        $params = self::target($companyOrDomain);
        $parts = preg_split('/\s+/', $fullName) ?: [];
        if (count($parts) >= 2) {
            $params['first_name'] = $parts[0];
            $params['last_name'] = (string) end($parts);
        } else {
            $params['full_name'] = $fullName;
        }

        try {
            $body = $this->get('email-finder', $params);
        } catch (HunterException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }

        $d = $body['data'] ?? [];
        if (empty($d['email'])) {
            return null;
        }

        return [
            'email'      => strtolower((string) $d['email']),
            'confidence' => (int) ($d['score'] ?? 0),
            'status'     => $d['verification']['status'] ?? null,
            'position'   => $d['position'] ?? null,
            'phone'      => $d['phone_number'] ?? null,
            'linkedin'   => $d['linkedin_url'] ?? null,
            'company'    => $d['company'] ?? null,
            'domain'     => $d['domain'] ?? null,
        ];
    }

    /**
     * Check an address will actually receive mail. Costs 1 verification credit.
     * Hunter answers 202 while it is still checking; we poll a few times.
     */
    public function verify(string $email): array
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            [$status, $raw] = $this->request('email-verifier', ['email' => $email]);

            if ($status === 202) {
                sleep(2);
                continue;
            }
            if ($status === 222) {
                return ['status' => 'unknown', 'result' => 'risky', 'score' => null,
                        'note' => 'The recipient\'s mail server did not answer. Try again later.'];
            }

            $d = $this->decode($status, $raw)['data'] ?? [];
            return [
                'status' => $d['status'] ?? 'unknown',
                'result' => $d['result'] ?? null,
                'score'  => isset($d['score']) ? (int) $d['score'] : null,
                'note'   => null,
            ];
        }

        return ['status' => 'unknown', 'result' => null, 'score' => null,
                'note' => 'Hunter is still checking. Try again in a minute.'];
    }

    /** Decide whether the user typed a domain or a company name. */
    public static function target(string $input): array
    {
        $t = trim($input);
        if ($t === '') {
            throw new HunterException('Enter a company domain or name.');
        }

        if (!str_contains($t, ' ')) {
            $host = parse_url(str_contains($t, '://') ? $t : 'http://' . $t, PHP_URL_HOST) ?: '';
            $host = preg_replace('/^www\./i', '', strtolower($host)) ?? '';
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $host)) {
                return ['domain' => $host];
            }
        }

        return ['company' => $t];
    }

    private static function normalise(array $e, ?string $org): array
    {
        $first = trim((string) ($e['first_name'] ?? ''));
        $last  = trim((string) ($e['last_name'] ?? ''));

        return [
            'email'      => strtolower((string) ($e['value'] ?? '')),
            'type'       => $e['type'] ?? null,
            'confidence' => (int) ($e['confidence'] ?? 0),
            'first_name' => $first !== '' ? $first : null,
            'last_name'  => $last !== '' ? $last : null,
            'name'       => trim("$first $last") ?: null,
            'position'   => $e['position'] ?? null,
            'department' => $e['department'] ?? null,
            'seniority'  => $e['seniority'] ?? null,
            'phone'      => $e['phone_number'] ?? null,
            'linkedin'   => $e['linkedin'] ?? null,
            'status'     => $e['verification']['status'] ?? null,
            'company'    => $org,
        ];
    }

    /** Hunter has returned two shapes for credit counts over time; accept both. */
    private static function quota(array $d, string $kind): array
    {
        if (isset($d['requests'][$kind])) {
            $used = (int) ($d['requests'][$kind]['used'] ?? 0);
            $available = (int) ($d['requests'][$kind]['available'] ?? 0);
            return ['used' => $used, 'left' => max(0, $available - $used)];
        }
        return [
            'used' => (int) ($d['calls'][$kind] ?? 0),
            'left' => isset($d['calls_left'][$kind]) ? (int) $d['calls_left'][$kind] : null,
        ];
    }

    private function get(string $endpoint, array $params): array
    {
        [$status, $raw] = $this->request($endpoint, $params);
        return $this->decode($status, $raw);
    }

    /** @return array{0:int,1:string} */
    private function request(string $endpoint, array $params): array
    {
        $url = self::BASE . $endpoint . ($params ? '?' . http_build_query($params) : '');
        return ($this->transport)($url, ['X-API-KEY: ' . $this->apiKey, 'Accept: application/json']);
    }

    private function decode(int $status, string $raw): array
    {
        $body = json_decode($raw, true);
        if ($status >= 200 && $status < 300 && is_array($body) && empty($body['errors'])) {
            return $body;
        }

        $error = is_array($body) ? ($body['errors'][0] ?? []) : [];
        $details = (string) ($error['details'] ?? '');

        $message = match (true) {
            $status === 0   => 'Could not reach Hunter: ' . $raw,
            $status === 401 => 'Hunter rejected the API key. Check it under Settings.',
            $status === 403 => 'Hunter rate limit reached. Wait a few seconds and try again.',
            $status === 429 => 'Your Hunter credits for this billing period are used up.',
            $status === 451 => 'This person asked Hunter to delete their data. Do not contact them.',
            default         => 'Hunter error ' . $status . ($details !== '' ? ': ' . $details : ''),
        };

        throw new HunterException($message, $status, (string) ($error['id'] ?? ''));
    }

    /** @return array{0:int,1:string} */
    private function curl(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'scrap3r/1.0',
        ]);

        // XAMPP's PHP often ships without a CA bundle configured, which makes
        // every HTTPS call fail certificate checks. Use one we know exists.
        if (!ini_get('curl.cainfo')) {
            foreach ([
                'C:/xampp/apache/bin/curl-ca-bundle.crt',
                BASE_PATH . '/.venv/Lib/site-packages/certifi/cacert.pem',
            ] as $bundle) {
                if (is_file($bundle)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $bundle);
                    break;
                }
            }
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [0, $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $body];
    }
}
