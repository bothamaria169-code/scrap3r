<?php
declare(strict_types=1);

final class Templates
{
    /** File types allowed as email attachments, with the MIME type they are sent as. */
    public const ALLOWED_ATTACHMENTS = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    /** Big attachments get bounced or spam-foldered; keep them modest. */
    public const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;

    /**
     * A brand-new workspace starts with the sample campaign: the four workshop
     * emails as editable designs, and a draft three-step campaign using them,
     * so there is something real to open, edit and learn from.
     */
    public static function seedSamples(int $userId): void
    {
        if ((int) Db::value('SELECT COUNT(*) FROM templates') > 0) {
            return;
        }

        $ids = [];
        foreach (EmailDesign::samples() as $key => $sample) {
            $ids[$key] = Db::insert('templates', [
                'name'        => $sample['name'],
                'subject'     => $sample['subject'],
                'preheader'   => $sample['preheader'],
                'design_json' => json_encode($sample['design'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'body_html'   => EmailDesign::render($sample['design'], $sample['preheader']),
                'is_sample'   => 1,
                'created_by'  => $userId,
            ]);
        }

        $campaignId = Db::insert('campaigns', [
            'name'        => 'Sample: workshop invitation sequence',
            'status'      => 'draft',
            'owner_id'    => $userId,
            'description' => 'An example of a short, high-reply sequence. Edit the emails, or delete this campaign.',
        ]);
        foreach ([[1, 'invite', 0], [2, 'followup', 72], [3, 'lastcall', 120]] as [$step, $key, $delay]) {
            Db::insert('campaign_steps', [
                'campaign_id' => $campaignId, 'step_no' => $step, 'template_id' => $ids[$key], 'delay_hours' => $delay,
            ]);
        }
    }

    /**
     * The HTML to send for a template. Designs are rendered fresh every time,
     * so a change to the workspace brand (logo, colour, header) reaches every
     * email at once. body_html is only a cache for designs, and the source for
     * old hand-written HTML emails.
     */
    public static function bodyFor(array $template): string
    {
        if (!empty($template['design_json'])) {
            return EmailDesign::render(
                EmailDesign::decode((string) $template['design_json']),
                (string) ($template['preheader'] ?? '')
            );
        }
        return (string) ($template['body_html'] ?? '');
    }

    /**
     * Attachment spec for Mailer from a template row, or null if it has none.
     * Paths are stored relative to the project root.
     */
    public static function attachmentFor(array $template): ?array
    {
        $path = (string) ($template['attachment_path'] ?? '');
        if ($path === '') {
            return null;
        }
        return [
            'path' => BASE_PATH . '/' . ltrim($path, '/'),
            'name' => (string) (($template['attachment_name'] ?? '') ?: basename($path)),
        ];
    }

    /**
     * Save an uploaded attachment under storage/attachments (not web-reachable).
     *
     * @return array{path:string,name:string}|string stored path + display name, or an error message
     */
    public static function storeUpload(array $file): array|string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is larger than the server allows.'
                : 'The upload failed. Please try again.';
        }

        $name = basename((string) $file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_ATTACHMENTS[$ext])) {
            return 'Only PDF, Word, Excel or image files can be attached.';
        }
        if ((int) $file['size'] > self::MAX_ATTACHMENT_BYTES) {
            return 'Attachments must be under 8 MB. Larger files often bounce or land in spam.';
        }

        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($name, PATHINFO_FILENAME)), '-') ?: 'attachment';
        $relative = 'storage/attachments/' . bin2hex(random_bytes(4)) . '-' . $safe . '.' . $ext;
        @mkdir(BASE_PATH . '/storage/attachments', 0775, true);

        if (!move_uploaded_file((string) $file['tmp_name'], BASE_PATH . '/' . $relative)) {
            return 'Could not save the file.';
        }
        return ['path' => $relative, 'name' => $name];
    }
    /**
     * Starter three-step sequence. Written to be short and specific, because
     * generic blasts are what get sending domains blocked. Edit before use —
     * these are a skeleton, not a finished pitch.
     */
    public static function seedDefaults(int $userId): void
    {
        if ((int) Db::value('SELECT COUNT(*) FROM templates') > 0) {
            return;
        }

        $seed = [
            [
                'name'    => 'Step 1 — Opening',
                'subject' => 'Quick question about {{company}}',
                'body'    => "<p>Hi {{first_name}},</p>
<p>I saw you're {{title}} at {{company}}. [One specific sentence about why you're reaching out to <em>them</em> — replace this; it is the only line that matters.]</p>
<p>Worth a short conversation? You can grab a time that suits you here:</p>
<p><a href=\"{{booking_link}}\">Book a 30-minute call</a></p>
<p>— {{sender_name}}<br>{{company_name}}</p>",
            ],
            [
                'name'    => 'Step 2 — Follow-up',
                'subject' => 'Re: Quick question about {{company}}',
                'body'    => "<p>Hi {{first_name}},</p>
<p>Following up on my note last week in case it got buried.</p>
<p>If it's useful, here's my calendar: <a href=\"{{booking_link}}\">book a time</a>. If not, just say so and I'll close the loop.</p>
<p>— {{sender_name}}</p>",
            ],
            [
                'name'    => 'Step 3 — Close the loop',
                'subject' => 'Closing the loop, {{first_name}}',
                'body'    => "<p>Hi {{first_name}},</p>
<p>I haven't heard back, so I'll assume the timing isn't right and stop here. No reply needed.</p>
<p>If things change, my calendar stays open: <a href=\"{{booking_link}}\">book a time</a>.</p>
<p>— {{sender_name}}<br>{{company_name}}</p>",
            ],
        ];

        foreach ($seed as $tpl) {
            Db::insert('templates', [
                'name'       => $tpl['name'],
                'subject'    => $tpl['subject'],
                'body_html'  => $tpl['body'],
                'created_by' => $userId,
            ]);
        }
    }
}
