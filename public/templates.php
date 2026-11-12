<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

/** Email designs library: every email the workspace has, as visual cards. */

// A made-up contact for thumbnails and previews; nothing is written against a real lead.
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

/** A name not already taken, e.g. "Invitation (copy 2)". */
$uniqueName = static function (string $name): string {
    $candidate = $name;
    for ($n = 1; Db::value('SELECT 1 FROM templates WHERE name = :n', ['n' => $candidate]); $n++) {
        $candidate = $name . ($n === 1 ? ' (copy)' : " (copy $n)");
    }
    return $candidate;
};

if ($pid = (int) ($_GET['preview'] ?? 0)) {
    $tpl = Db::one('SELECT * FROM templates WHERE id = :id', ['id' => $pid]);
    if (!$tpl) {
        http_response_code(404);
        exit('Template not found.');
    }
    echo view('template_preview', [
        'tpl'  => $tpl,
        'lead' => $previewLead,
        'mail' => Mailer::preview((string) $tpl['subject'], Templates::bodyFor($tpl), $previewLead, $currentUser),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $from = (string) ($_POST['from'] ?? 'blank');
        $samples = EmailDesign::samples();
        if (str_starts_with($from, 'sample:') && isset($samples[substr($from, 7)])) {
            $s = $samples[substr($from, 7)];
            [$name, $subject, $preheader, $design] = [$s['name'], $s['subject'], $s['preheader'], $s['design']];
        } else {
            [$name, $subject, $preheader, $design] = ['Untitled email', 'Your subject line', '', EmailDesign::blank()];
        }
        $newId = Db::insert('templates', [
            'name'        => $uniqueName($name),
            'subject'     => $subject,
            'preheader'   => $preheader,
            'design_json' => json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'body_html'   => EmailDesign::render($design, $preheader),
            'created_by'  => $currentUser['id'],
        ]);
        redirect('composer.php?id=' . $newId);
    }

    $existing = $id ? Db::one('SELECT * FROM templates WHERE id = :id', ['id' => $id]) : null;
    if (!$existing) {
        flash('That email design no longer exists.', 'err');
        redirect('templates.php');
    }

    if ($action === 'duplicate') {
        $newId = Db::insert('templates', [
            'name'            => $uniqueName((string) $existing['name']),
            'subject'         => $existing['subject'],
            'preheader'       => $existing['preheader'],
            'design_json'     => $existing['design_json'],
            'body_html'       => $existing['body_html'],
            'attachment_path' => $existing['attachment_path'],
            'attachment_name' => $existing['attachment_name'],
            'created_by'      => $currentUser['id'],
        ]);
        flash('Copy created. You are editing the copy.');
        redirect('composer.php?id=' . $newId);
    }

    if ($action === 'delete') {
        $inUse = (int) Db::value('SELECT COUNT(*) FROM campaign_steps WHERE template_id = :id', ['id' => $id]);
        if ($inUse) {
            flash('That email is a step in a campaign. Remove it from the campaign first.', 'err');
        } else {
            Db::run('DELETE FROM templates WHERE id = :id', ['id' => $id]);
            $dropFile($existing['attachment_path']);
            flash('Email design deleted.');
        }
        redirect('templates.php');
    }
}

$templates = Db::all(
    "SELECT t.*, (SELECT COUNT(*) FROM campaign_steps s WHERE s.template_id = t.id) AS used_in
     FROM templates t ORDER BY t.updated_at DESC"
);
foreach ($templates as &$t) {
    $t['thumb'] = Mailer::preview((string) $t['subject'], Templates::bodyFor($t), $previewLead, $currentUser)['html'];
}
unset($t);

render('templates', [
    'title'     => 'Email designs',
    'templates' => $templates,
    'samples'   => EmailDesign::samples(),
]);
