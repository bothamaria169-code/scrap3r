<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$lead = Db::one('SELECT * FROM leads WHERE id = :id', ['id' => $id]);
if (!$lead) {
    http_response_code(404);
    exit('Lead not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email !== '' && !is_valid_email($email)) {
            flash('That email address is not valid.', 'err');
            redirect('lead.php?id=' . $id);
        }
        Db::update('leads', [
            'name'    => trim((string) $_POST['name']) ?: null,
            'title'   => trim((string) $_POST['title']) ?: null,
            'company' => trim((string) $_POST['company']) ?: null,
            'email'   => $email ?: null,
            'phone'   => trim((string) $_POST['phone']) ?: null,
            'status'  => (string) $_POST['status'],
            'owner_id'=> ((int) $_POST['owner_id']) ?: null,
        ], 'id = :wid', ['wid' => $id]);
        if ($email !== strtolower((string) $lead['email'])) {
            // A hand-edited address has not been checked by anyone.
            Db::update('leads', [
                'email_status'     => null,
                'email_confidence' => null,
                'email_source'     => $email !== '' ? 'manual' : null,
            ], 'id = :wid', ['wid' => $id]);
        }
        flash('Lead saved.');
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'book') {
        $result = Scheduler::book(
            $id,
            (int) $_POST['user_id'],
            (string) $_POST['starts_at'],
            'manual',
            trim((string) ($_POST['notes'] ?? '')) ?: null
        );
        flash($result['ok'] ? 'Appointment booked.' : $result['error'], $result['ok'] ? 'ok' : 'err');
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'send_template') {
        $tpl = Db::one('SELECT * FROM templates WHERE id = :t', ['t' => (int) ($_POST['template_id'] ?? 0)]);
        if (!$tpl) {
            flash('Pick a template to send.', 'err');
        } else {
            $attachment = Templates::attachmentFor($tpl);
            $r = Mailer::sendToLead($lead, (string) $tpl['subject'], Templates::bodyFor($tpl), $currentUser, null, null, $attachment);
            [$message, $type] = match ($r['status']) {
                'sent'    => ["Sent \"{$tpl['name']}\" to {$lead['email']}" . ($attachment ? ' with the attachment.' : '.'), 'ok'],
                'skipped' => ['Not sent: ' . $r['error'], 'warn'],
                default   => ['Sending failed: ' . $r['error'], 'err'],
            };
            flash($message, $type);
        }
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'hunter_find') {
        $p = parse_description($lead['description']);
        $name = (string) ($lead['name'] ?: $p['name']);
        try {
            $found = Hunter::fromSettings()->findEmail($name, (string) ($_POST['target'] ?? ''));
            if (!$found) {
                flash('Hunter has no address for this person. No credit was used.', 'warn');
            } elseif ($dupe = Db::value('SELECT id FROM leads WHERE email = :e AND id <> :id', ['e' => $found['email'], 'id' => $id])) {
                flash("Hunter found {$found['email']}, but lead #$dupe already has that address. Not saved, to avoid two records for one person.", 'warn');
            } elseif (Suppression::has($found['email'])) {
                flash("Hunter found {$found['email']}, but that address has unsubscribed. Not saved.", 'warn');
            } else {
                Db::update('leads', array_filter([
                    'email'            => $found['email'],
                    'email_confidence' => $found['confidence'],
                    'email_status'     => $found['status'],
                    'email_source'     => 'hunter',
                    'title'            => $lead['title'] ?: $found['position'],
                    'phone'            => $lead['phone'] ?: $found['phone'],
                    'linkedin_url'     => $found['linkedin'],
                    'website'          => $lead['website'] ?: $found['domain'],
                ], static fn($v) => $v !== null && $v !== ''), 'id = :wid', ['wid' => $id]);
                flash("Found {$found['email']} ({$found['confidence']}% confidence).");
            }
        } catch (HunterException $e) {
            flash($e->getMessage(), 'err');
        }
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'hunter_verify' && $lead['email']) {
        try {
            $v = Hunter::fromSettings()->verify((string) $lead['email']);
            Db::update('leads', [
                'email_status'     => $v['status'],
                'email_confidence' => $v['score'] ?? $lead['email_confidence'],
            ], 'id = :wid', ['wid' => $id]);
            [$message, $type] = match ($v['status']) {
                'valid'      => ['Verified: this address receives mail.', 'ok'],
                'invalid'    => ['This address will bounce. It is now blocked from campaigns - find a replacement.', 'err'],
                'accept_all' => ["This company's server accepts any address, so Hunter can't confirm this exact one. Usually fine to send.", 'warn'],
                default      => ['Hunter could not confirm this address. ' . ($v['note'] ?? ''), 'warn'],
            };
            flash($message, $type);
        } catch (HunterException $e) {
            if ($e->status === 451) {
                // The person asked Hunter to erase their data: honour it here too.
                Suppression::add((string) $lead['email'], 'manual');
            }
            flash($e->getMessage(), 'err');
        }
        redirect('lead.php?id=' . $id);
    }

    if ($action === 'suppress') {
        if ($lead['email']) {
            Suppression::add($lead['email'], 'manual');
            flash('Added to the suppression list and removed from any active sequence.');
        }
        redirect('lead.php?id=' . $id);
    }
}

$messages = Db::all('SELECT * FROM messages WHERE lead_id = :id ORDER BY created_at DESC', ['id' => $id]);
$appointments = Db::all(
    'SELECT a.*, u.name AS rep FROM appointments a JOIN users u ON u.id = a.user_id
     WHERE a.lead_id = :id ORDER BY a.starts_at DESC',
    ['id' => $id]
);
$enrollments = Db::all(
    'SELECT en.*, c.name AS campaign FROM enrollments en JOIN campaigns c ON c.id = en.campaign_id
     WHERE en.lead_id = :id',
    ['id' => $id]
);
$users = Db::all('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name');

render('lead', [
    'title' => $lead['name'] ?: 'Lead',
    'lead' => $lead,
    'messages' => $messages,
    'appointments' => $appointments,
    'enrollments' => $enrollments,
    'users' => $users,
    'templates' => Db::all('SELECT id, name, attachment_name FROM templates ORDER BY name'),
]);
