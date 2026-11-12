<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$campaign = Db::one('SELECT * FROM campaigns WHERE id = :id', ['id' => $id]);
if (!$campaign) {
    http_response_code(404);
    exit('Campaign not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ((string) ($_POST['action'] ?? '')) {
        case 'settings':
            Db::update('campaigns', [
                'name'              => trim((string) $_POST['name']),
                'from_name'         => trim((string) $_POST['from_name']) ?: null,
                'from_email'        => trim((string) $_POST['from_email']) ?: null,
                'reply_to'          => trim((string) $_POST['reply_to']) ?: null,
                'throttle_per_hour' => max(1, (int) $_POST['throttle_per_hour']),
                'send_window_start' => substr((string) $_POST['send_window_start'] . ':00', 0, 8),
                'send_window_end'   => substr((string) $_POST['send_window_end'] . ':00', 0, 8),
                'skip_weekends'     => isset($_POST['skip_weekends']) ? 1 : 0,
            ], 'id = :wid', ['wid' => $id]);
            flash('Campaign settings saved.');
            break;

        case 'add_step':
            $next = (int) Db::value('SELECT COALESCE(MAX(step_no),0)+1 FROM campaign_steps WHERE campaign_id = :c', ['c' => $id]);
            Db::insert('campaign_steps', [
                'campaign_id' => $id,
                'step_no'     => $next,
                'template_id' => (int) $_POST['template_id'],
                'delay_hours' => max(0, (int) $_POST['delay_hours']),
            ]);
            flash("Step $next added.");
            break;

        case 'delete_step':
            Db::run('DELETE FROM campaign_steps WHERE id = :s AND campaign_id = :c',
                ['s' => (int) $_POST['step_id'], 'c' => $id]);
            // Renumber so the runner never hits a gap and stalls.
            $steps = Db::all('SELECT id FROM campaign_steps WHERE campaign_id = :c ORDER BY step_no', ['c' => $id]);
            foreach ($steps as $i => $s) {
                Db::run('UPDATE campaign_steps SET step_no = :n WHERE id = :id', ['n' => $i + 1, 'id' => $s['id']]);
            }
            flash('Step removed.');
            break;

        case 'status':
            $new = (string) $_POST['status'];
            if ($new === 'running') {
                if ((int) Db::value('SELECT COUNT(*) FROM campaign_steps WHERE campaign_id = :c', ['c' => $id]) === 0) {
                    flash('Add at least one step before starting.', 'err');
                    redirect('campaign.php?id=' . $id);
                }
                if (!Settings::smtpReady()) {
                    flash('SMTP is not configured. Fill in Settings first.', 'err');
                    redirect('campaign.php?id=' . $id);
                }
            }
            Db::update('campaigns', ['status' => $new], 'id = :wid', ['wid' => $id]);
            flash('Campaign is now ' . $new . '.');
            break;

        case 'unenroll':
            Db::run('DELETE FROM enrollments WHERE id = :e AND campaign_id = :c',
                ['e' => (int) $_POST['enrollment_id'], 'c' => $id]);
            flash('Lead removed from this campaign.');
            break;
    }
    redirect('campaign.php?id=' . $id);
}

$steps = Db::all(
    'SELECT s.*, t.name AS template_name, t.subject FROM campaign_steps s
     JOIN templates t ON t.id = s.template_id
     WHERE s.campaign_id = :c ORDER BY s.step_no',
    ['c' => $id]
);

$enrolled = Db::all(
    'SELECT en.*, l.name, l.email, l.company, l.status AS lead_status
     FROM enrollments en JOIN leads l ON l.id = en.lead_id
     WHERE en.campaign_id = :c ORDER BY en.created_at DESC LIMIT 200',
    ['c' => $id]
);

render('campaign', [
    'title' => $campaign['name'],
    'campaign' => $campaign,
    'steps' => $steps,
    'enrolled' => $enrolled,
    'templates' => Db::all('SELECT id, name FROM templates ORDER BY name'),
]);
