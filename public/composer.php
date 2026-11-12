<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

/**
 * The visual email composer. The page loads one design; the browser edits it
 * and talks back here with small POST actions (save, render a preview, upload
 * an image or attachment, send a test). Every action re-validates what it gets.
 */

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$tpl = $id ? Db::one('SELECT * FROM templates WHERE id = :id', ['id' => $id]) : null;

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!$tpl) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $json(['ok' => false, 'error' => 'That email design no longer exists.'], 404);
    }
    flash('That email design no longer exists.', 'err');
    redirect('templates.php');
}

// A made-up contact for previews and tests, so nothing is recorded against a real lead.
$previewLead = [
    'id' => 0, 'name' => 'Thandi Mokoena', 'title' => 'Head of Communications',
    'company' => 'Standard Bank', 'description' => null, 'email' => 'thandi@example.com',
];

/** Delete an attachment file once no template points at it any more. */
$dropFile = static function (?string $path): void {
    if ($path && !Db::value('SELECT COUNT(*) FROM templates WHERE attachment_path = :p', ['p' => $path])) {
        @unlink(BASE_PATH . '/' . $path);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $posted = static fn(): array => EmailDesign::decode((string) ($_POST['design'] ?? ''));

    if ($action === 'save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $preheader = mb_substr(trim((string) ($_POST['preheader'] ?? '')), 0, 200);
        if ($name === '' || $subject === '') {
            $json(['ok' => false, 'error' => 'Give the email a name and a subject line before saving.'], 422);
        }
        $design = $posted();
        Db::update('templates', [
            'name'        => mb_substr($name, 0, 160),
            'subject'     => mb_substr($subject, 0, 255),
            'preheader'   => $preheader,
            'design_json' => json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'body_html'   => EmailDesign::render($design, $preheader),
        ], 'id = :wid', ['wid' => $id]);
        $json(['ok' => true, 'saved_at' => date('H:i')]);
    }

    if ($action === 'render') {
        $design = $posted();
        $mail = Mailer::preview(
            (string) ($_POST['subject'] ?? ''),
            EmailDesign::render($design, (string) ($_POST['preheader'] ?? '')),
            $previewLead,
            $currentUser
        );
        $json(['ok' => true, 'html' => $mail['html'], 'subject' => $mail['subject']]);
    }

    if ($action === 'upload_image') {
        $result = EmailDesign::storeImage($_FILES['image'] ?? []);
        is_string($result) ? $json(['ok' => false, 'error' => $result], 422) : $json(['ok' => true] + $result);
    }

    if ($action === 'upload_attachment') {
        $stored = Templates::storeUpload($_FILES['attachment'] ?? []);
        if (is_string($stored)) {
            $json(['ok' => false, 'error' => $stored], 422);
        }
        Db::update('templates', ['attachment_path' => $stored['path'], 'attachment_name' => $stored['name']], 'id = :wid', ['wid' => $id]);
        $dropFile($tpl['attachment_path']);
        $json(['ok' => true, 'name' => $stored['name'], 'size' => (int) filesize(BASE_PATH . '/' . $stored['path'])]);
    }

    if ($action === 'remove_attachment') {
        Db::update('templates', ['attachment_path' => null, 'attachment_name' => null], 'id = :wid', ['wid' => $id]);
        $dropFile($tpl['attachment_path']);
        $json(['ok' => true]);
    }

    if ($action === 'test_send') {
        $to = trim((string) ($_POST['to'] ?? ''));
        $fresh = Db::one('SELECT * FROM templates WHERE id = :id', ['id' => $id]);
        $error = Mailer::sendTest($to, (string) $fresh['subject'], Templates::bodyFor($fresh), $previewLead, $currentUser, Templates::attachmentFor($fresh));
        $error === null
            ? $json(['ok' => true, 'message' => "Test sent to $to. Check the inbox, and the spam folder."])
            : $json(['ok' => false, 'error' => $error], 422);
    }

    if ($action === 'audience' || $action === 'broadcast') {
        // Who can receive it: a valid address, not opted out, not known to bounce.
        $status = (string) ($_POST['status'] ?? '');
        $statuses = ['new', 'contacted', 'replied', 'booked', 'won', 'lost', 'unqualified'];
        $where = "email IS NOT NULL AND email <> '' AND unsubscribed_at IS NULL
                  AND (email_status IS NULL OR email_status <> 'invalid')
                  AND NOT EXISTS (SELECT 1 FROM suppressions s WHERE s.email = leads.email)";
        $params = [];
        if (in_array($status, $statuses, true)) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        $ids = array_map('intval', array_column(Db::all("SELECT id FROM leads WHERE $where ORDER BY id", $params), 'id'));

        if ($action === 'audience') {
            $json([
                'ok'           => true,
                'count'        => count($ids),
                'no_email'     => (int) Db::value("SELECT COUNT(*) FROM leads WHERE email IS NULL OR email = ''"),
                'unsubscribed' => (int) Db::value('SELECT COUNT(*) FROM suppressions'),
            ]);
        }

        if (!$ids) {
            $json(['ok' => false, 'error' => 'Nobody matches: no contacts with a usable email address in that group.'], 422);
        }
        if (!Settings::smtpReady()) {
            $json(['ok' => false, 'error' => 'Connect your mailbox in Settings before sending.'], 422);
        }

        // A one-step campaign: the scheduled sender delivers it with the usual
        // throttle and business-hours window, and it shows up in the logs.
        $campaignId = Db::insert('campaigns', [
            'name'        => mb_substr('Send: ' . $tpl['name'] . ' · ' . date('j M H:i'), 0, 160),
            'status'      => 'running',
            'owner_id'    => $currentUser['id'],
            'from_name'   => Settings::get('from_name') ?: $currentUser['name'],
            'from_email'  => Settings::get('from_email') ?: null,
            'reply_to'    => Settings::get('reply_to') ?: null,
            'description' => 'Sent from the composer to ' . ($status !== '' ? "contacts with status \"$status\"" : 'all contacts with an email address'),
        ]);
        Db::insert('campaign_steps', ['campaign_id' => $campaignId, 'step_no' => 1, 'template_id' => $id, 'delay_hours' => 0]);
        $result = SequenceRunner::enroll($campaignId, $ids);

        $json([
            'ok'      => true,
            'added'   => $result['added'],
            'url'     => app_url('campaign.php?id=' . $campaignId),
            'message' => $result['added'] . ' contact(s) queued. They go out through the scheduled sender, '
                       . 'up to 30 an hour on weekdays 08:00-18:00.',
        ]);
    }

    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$design = $tpl['design_json'] ? EmailDesign::decode($tpl['design_json']) : EmailDesign::fromLegacyHtml((string) $tpl['body_html']);
$attachmentFile = $tpl['attachment_path'] ? BASE_PATH . '/' . $tpl['attachment_path'] : null;

echo view('composer', [
    'tpl'  => $tpl,
    'data' => [
        'id'         => (int) $tpl['id'],
        'name'       => (string) $tpl['name'],
        'subject'    => (string) $tpl['subject'],
        'preheader'  => (string) ($tpl['preheader'] ?? ''),
        'design'     => $design,
        'csrf'       => csrf_token(),
        'endpoint'   => app_url('composer.php'),
        'me'         => (string) ($currentUser['email'] ?? ''),
        'signature'  => Settings::get('signature_html'),
        'mergeFields' => array_keys(Mailer::VARIABLES),
        'workspace'  => [
            'brand'  => EmailDesign::isHex(Settings::get('brand_color')) ? Settings::get('brand_color') : '#4f46e5',
            'logo'   => Settings::get('brand_logo'),
            'header' => Settings::get('brand_header') ?: Settings::get('company_name'),
        ],
        'attachment' => $tpl['attachment_name'] ? [
            'name'    => (string) $tpl['attachment_name'],
            'size'    => $attachmentFile && is_file($attachmentFile) ? (int) filesize($attachmentFile) : null,
        ] : null,
    ],
]);
