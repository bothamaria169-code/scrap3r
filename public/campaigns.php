<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash('Give the campaign a name.', 'err');
        redirect('campaigns.php');
    }
    $id = Db::insert('campaigns', [
        'name'       => $name,
        'owner_id'   => $currentUser['id'],
        'from_name'  => Settings::get('from_name') ?: $currentUser['name'],
        'from_email' => Settings::get('from_email') ?: null,
    ]);
    flash('Campaign created. Add steps, then enrol leads.');
    redirect('campaign.php?id=' . $id);
}

$campaigns = Db::all(
    "SELECT c.*, u.name AS owner,
       (SELECT COUNT(*) FROM enrollments e WHERE e.campaign_id = c.id) AS enrolled,
       (SELECT COUNT(*) FROM enrollments e WHERE e.campaign_id = c.id AND e.status='active') AS active,
       (SELECT COUNT(*) FROM messages m WHERE m.campaign_id = c.id AND m.status='sent') AS sent,
       (SELECT COUNT(*) FROM campaign_steps s WHERE s.campaign_id = c.id) AS steps
     FROM campaigns c LEFT JOIN users u ON u.id = c.owner_id
     ORDER BY c.created_at DESC"
);

render('campaigns', ['title' => 'Campaigns', 'campaigns' => $campaigns]);
