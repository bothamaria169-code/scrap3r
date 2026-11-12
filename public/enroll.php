<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$campaignId = (int) ($_POST['campaign_id'] ?? 0);
$leadIds = array_map('intval', (array) ($_POST['lead_ids'] ?? []));

if (!$campaignId || !$leadIds) {
    flash('Pick a campaign and at least one lead.', 'err');
    redirect('leads.php');
}

$result = SequenceRunner::enroll($campaignId, $leadIds);

$message = $result['added'] . ' lead(s) enrolled.';
if ($result['rejected']) {
    $message .= "\nSkipped:\n- " . implode("\n- ", array_slice($result['rejected'], 0, 15));
}
flash($message, $result['rejected'] ? 'warn' : 'ok');
redirect('campaign.php?id=' . $campaignId);
