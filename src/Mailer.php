<?php
declare(strict_types=1);

/**
 * Builds and sends outreach mail.
 *
 * Every send passes through sendToLead(), which enforces the rules that keep
 * this from behaving like a spam cannon: suppression list, valid address,
 * unsubscribe link, and a physical address in the footer.
 *
 * Each message gets its own Message-ID, stored with the message, so that a
 * bounce or a reply arriving later can be traced to the exact email.
 */
final class Mailer
{
    /** Merge fields available in templates. */
    public const VARIABLES = [
        '{{first_name}}'       => 'Lead first name (falls back to "there")',
        '{{name}}'             => 'Lead full name',
        '{{title}}'            => 'Job title, parsed from the ZoomInfo description',
        '{{company}}'          => 'Company name',
        '{{sender_name}}'      => 'Your name',
        '{{sender_email}}'     => 'Your reply address, for "reply" buttons (mailto:{{sender_email}})',
        '{{signature}}'        => 'Your email signature from Settings',
        '{{company_name}}'     => 'Your company',
        '{{booking_link}}'     => 'Unique self-booking URL for this lead',
        '{{unsubscribe_link}}' => 'Unique opt-out URL (added automatically if omitted)',
    ];

    /**
     * Fill merge fields. In HTML ($html = true) every value is escaped, so a
     * lead called "O'Neil & Sons" cannot break the markup; the signature is
     * trusted HTML from Settings and goes in as-is. Subjects are plain text.
     *
     * Booking and unsubscribe tokens are only created when the text uses them.
     */
    public static function renderTemplate(string $text, array $lead, array $sender, bool $html = true): string
    {
        $parsed = parse_description($lead['description'] ?? null);
        $name = (string) (($lead['name'] ?? '') ?: ($parsed['name'] ?? ''));
        $first = $name !== '' ? explode(' ', trim($name))[0] : 'there';

        $values = [
            '{{first_name}}'   => $first,
            '{{name}}'         => $name !== '' ? $name : 'there',
            '{{title}}'        => (string) (($lead['title'] ?? '') ?: ($parsed['title'] ?? '')),
            '{{company}}'      => (string) (($lead['company'] ?? '') ?: ($parsed['company'] ?? 'your company')),
            '{{sender_name}}'  => (string) ($sender['name'] ?? ''),
            '{{sender_email}}' => Settings::get('reply_to') ?: (Settings::get('from_email') ?: (string) ($sender['email'] ?? '')),
            '{{company_name}}' => Settings::get('company_name'),
        ];
        if (str_contains($text, '{{booking_link}}')) {
            $values['{{booking_link}}'] = Tokens::bookingUrl((int) $lead['id'], (int) ($sender['id'] ?? 0));
        }
        if (str_contains($text, '{{unsubscribe_link}}')) {
            $values['{{unsubscribe_link}}'] = Tokens::unsubscribeUrl((int) $lead['id']);
        }

        if ($html) {
            $values = array_map(static fn($v) => e((string) $v), $values);
        }
        $signature = Settings::get('signature_html');
        $values['{{signature}}'] = $html ? $signature : self::htmlToText($signature);

        return strtr($text, $values);
    }

    /** Subject and body exactly as sendToLead() would send them, without sending. */
    public static function preview(string $subject, string $bodyHtml, array $lead, array $sender): array
    {
        $html = self::renderTemplate($bodyHtml, $lead, $sender);

        // Opt-out is mandatory. Look for the real opt-out URL rather than the
        // word "unsubscribe", so a template that merely mentions it in prose
        // does not end up shipping without a working link.
        if (!str_contains($html, 'unsubscribe.php?t=')) {
            $html .= self::footer($lead);
        }

        return ['subject' => self::renderTemplate($subject, $lead, $sender, false), 'html' => $html];
    }

    /**
     * Queue + send one email to one lead.
     *
     * @param array{path:string,name:string}|null $attachment
     * @return array{ok:bool, status:string, error:?string, message_id:?int}
     */
    public static function sendToLead(
        array $lead,
        string $subject,
        string $bodyHtml,
        array $sender,
        ?int $campaignId = null,
        ?int $stepNo = null,
        ?array $attachment = null,
    ): array {
        $email = trim((string) ($lead['email'] ?? ''));

        if (!is_valid_email($email)) {
            return self::record($lead, $email, $subject, $bodyHtml, $campaignId, $stepNo, 'skipped', 'No valid email address on this lead.');
        }

        if (Suppression::has($email)) {
            return self::record($lead, $email, $subject, $bodyHtml, $campaignId, $stepNo, 'skipped', 'Address is on the suppression list.');
        }

        // Sending to an address known to bounce damages the sending domain's
        // reputation for every future email, so never attempt it.
        if (($lead['email_status'] ?? null) === 'invalid') {
            return self::record($lead, $email, $subject, $bodyHtml, $campaignId, $stepNo, 'skipped', 'Address failed verification and would bounce.');
        }

        $mail = self::preview($subject, $bodyHtml, $lead, $sender);
        $subject = $mail['subject'];
        $bodyHtml = $mail['html'];

        if ($attachment !== null && ($problem = self::attachmentProblem($attachment)) !== null) {
            return self::record($lead, $email, $subject, $bodyHtml, $campaignId, $stepNo, 'failed', $problem);
        }

        if (!Settings::smtpReady()) {
            return self::record($lead, $email, $subject, $bodyHtml, $campaignId, $stepNo, 'failed', 'SMTP is not configured. Fill in Settings first.');
        }

        $messageId = self::newMessageId();
        $error = self::deliver($email, $subject, $bodyHtml, $lead, $sender, $attachment, $messageId);
        if ($error !== null) {
            return self::record($lead, $email, $subject, $bodyHtml, $campaignId, $stepNo, 'failed', $error);
        }

        Db::run(
            'UPDATE leads SET last_emailed_at = NOW(),
                    status = CASE WHEN status = :new THEN :contacted ELSE status END
             WHERE id = :id',
            ['new' => 'new', 'contacted' => 'contacted', 'id' => $lead['id']]
        );

        return self::record($lead, $email, $subject, $bodyHtml, $campaignId, $stepNo, 'sent', null, $messageId);
    }

    /**
     * Send a template to any address to check it in a real inbox. Rendered with
     * a sample contact, subject prefixed [TEST], and never logged against a
     * lead or counted as outreach.
     *
     * @return string|null error message, or null on success
     */
    public static function sendTest(string $to, string $subject, string $bodyHtml, array $lead, array $sender, ?array $attachment = null): ?string
    {
        if (!is_valid_email($to)) {
            return 'Enter a valid address to send the test to.';
        }
        if ($attachment !== null && ($problem = self::attachmentProblem($attachment)) !== null) {
            return $problem;
        }
        if (!Settings::smtpReady()) {
            return 'SMTP is not configured. Fill in Settings first.';
        }

        $mail = self::preview($subject, $bodyHtml, $lead, $sender);
        return self::deliver($to, '[TEST] ' . $mail['subject'], $mail['html'], $lead, $sender, $attachment, self::newMessageId());
    }

    /** A globally unique Message-ID on the sending domain. */
    public static function newMessageId(): string
    {
        $domain = substr((string) strrchr(Settings::get('from_email'), '@'), 1) ?: 'localhost';
        return '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
    }

    /** @return string|null error message, or null when the server accepted the message */
    private static function deliver(string $to, string $subject, string $html, array $lead, array $sender, ?array $attachment, string $messageId): ?string
    {
        $fromEmail = Settings::get('from_email');
        $fromName  = Settings::get('from_name') ?: (string) ($sender['name'] ?? '');
        $raw = self::buildMime($fromName, $fromEmail, $to, $subject, $html, $lead, $attachment, $messageId);

        $smtp = Smtp::fromSettings();
        try {
            $smtp->connect();
            $smtp->send($fromEmail, [$to], $raw);
            $smtp->quit();
            return null;
        } catch (SmtpException $e) {
            $smtp->quit();
            return $e->getMessage();
        }
    }

    private static function attachmentProblem(array $attachment): ?string
    {
        $path = (string) ($attachment['path'] ?? '');
        $name = (string) (($attachment['name'] ?? '') ?: basename($path));

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return "Attachment \"$name\" is missing on disk. Re-upload it on the Templates page.";
        }
        if (filesize($path) > Templates::MAX_ATTACHMENT_BYTES) {
            return "Attachment \"$name\" is over 8 MB. Large files often bounce; use a smaller file.";
        }
        return null;
    }

    private static function footer(array $lead): string
    {
        $company = e(Settings::get('company_name'));
        $address = e(Settings::get('company_address'));
        $link = e(Tokens::unsubscribeUrl((int) $lead['id']));

        return '<div style="max-width:600px;margin:0 auto;padding:14px 12px 24px;font-family:Arial,Helvetica,sans-serif;'
            . 'font-size:12px;color:#7b8794;line-height:1.5;text-align:center">'
            . ($company !== '' ? $company . '<br>' : '')
            . ($address !== '' ? nl2br($address) . '<br>' : '')
            . 'Not relevant? <a href="' . $link . '" style="color:#7b8794;">Unsubscribe</a> and we won&rsquo;t email you again.'
            . '</div>';
    }

    private static function buildMime(
        string $fromName,
        string $fromEmail,
        string $to,
        string $subject,
        string $html,
        array $lead,
        ?array $attachment,
        string $messageId,
    ): string {
        $replyTo = Settings::get('reply_to') ?: $fromEmail;
        $unsubUrl = Tokens::unsubscribeUrl((int) $lead['id']);
        $text = self::htmlToText($html);

        $encodedName = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= '
            : '';

        $alt = 'a' . bin2hex(random_bytes(12));
        $alternative = "--$alt\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--$alt\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--$alt--\r\n";

        if ($attachment === null) {
            $contentType = 'multipart/alternative; boundary="' . $alt . '"';
            $body = $alternative;
        } else {
            // multipart/mixed wraps the text/html alternative plus the file.
            $mixed = 'm' . bin2hex(random_bytes(12));
            $name = (string) (($attachment['name'] ?? '') ?: basename((string) $attachment['path']));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = Templates::ALLOWED_ATTACHMENTS[$ext] ?? 'application/octet-stream';
            $quoted = self::quoteFilename($name);

            $contentType = 'multipart/mixed; boundary="' . $mixed . '"';
            $body = "--$mixed\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $alt . "\"\r\n\r\n"
                . $alternative . "\r\n"
                . "--$mixed\r\n"
                . "Content-Type: $mime; name=$quoted\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=$quoted\r\n\r\n"
                . chunk_split(base64_encode((string) file_get_contents((string) $attachment['path']))) . "\r\n"
                . "--$mixed--\r\n";
        }

        $headers = [
            'Date: ' . date('r'),
            'Message-ID: ' . $messageId,
            'From: ' . $encodedName . '<' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Reply-To: <' . $replyTo . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            // Lets Gmail/Outlook show a native unsubscribe control, which
            // measurably reduces spam complaints.
            'List-Unsubscribe: <' . $unsubUrl . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType,
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** Quoted filename for MIME headers; non-ASCII names use RFC 2047 encoding. */
    private static function quoteFilename(string $name): string
    {
        if (preg_match('/^[\x20-\x7E]+$/', $name)) {
            return '"' . addcslashes($name, "\"\\") . '"';
        }
        return '"=?UTF-8?B?' . base64_encode($name) . '?="';
    }

    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<(style|head)[^>]*>.*?</\1>#is', '', $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
        $text = preg_replace('#<li[^>]*>#i', "\n- ", (string) $text);
        $text = preg_replace('#</(p|div|h[1-6]|tr|ul|ol|table)>#i', "\n\n", (string) $text);
        $text = preg_replace_callback(
            '#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                $label = trim(strip_tags($m[2]));
                // A mailto button reads better in plain text as just the address.
                if (str_starts_with($href, 'mailto:')) {
                    $address = strtok(substr($href, 7), '?');
                    return $label !== '' && $label !== $address ? "$label: $address" : (string) $address;
                }
                return $label !== '' && $label !== $href ? "$label ($href)" : $href;
            },
            (string) $text
        );
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');
    }

    private static function record(
        array $lead,
        string $email,
        string $subject,
        string $body,
        ?int $campaignId,
        ?int $stepNo,
        string $status,
        ?string $error,
        ?string $messageIdHeader = null,
    ): array {
        $id = Db::insert('messages', [
            'lead_id'           => $lead['id'],
            'campaign_id'       => $campaignId,
            'step_no'           => $stepNo,
            'to_email'          => $email !== '' ? $email : '(none)',
            'subject'           => mb_substr($subject, 0, 255),
            'body_html'         => $body,
            'status'            => $status,
            'error'             => $error,
            'sent_at'           => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            'message_id_header' => $messageIdHeader,
        ]);

        if ($status === 'sent' || $status === 'failed') {
            Events::log($status, $id, (int) $lead['id'], $campaignId, $status === 'failed' ? $error : $subject);
        }

        return [
            'ok'         => $status === 'sent',
            'status'     => $status,
            'error'      => $error,
            'message_id' => $id,
        ];
    }
}
