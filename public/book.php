<?php
declare(strict_types=1);
define('PUBLIC_PAGE', true);
require_once __DIR__ . '/_init.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$record = Tokens::resolve($token, 'book');

if (!$record) {
    http_response_code(404);
    echo view('public_message', [
        'heading' => 'This link is no longer valid',
        'body' => 'The booking link has expired or was already replaced. Reply to the email and we will send a fresh one.',
    ]);
    exit;
}

$repId = (int) ($record['user_id'] ?: Db::value('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1'));
$rep = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $repId]);
$booked = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rep) {
    $result = Scheduler::book(
        (int) $record['lead_id'],
        $repId,
        (string) ($_POST['slot'] ?? ''),
        'self',
        trim((string) ($_POST['notes'] ?? '')) ?: null
    );

    if ($result['ok']) {
        Tokens::markUsed($token);
        $booked = Db::one('SELECT * FROM appointments WHERE id = :id', ['id' => $result['appointment_id']]);
    } else {
        $error = $result['error'];
    }
}

echo view('book', [
    'record' => $record,
    'rep' => $rep,
    'slots' => $rep && !$booked ? Scheduler::freeSlots($repId, 14) : [],
    'token' => $token,
    'booked' => $booked,
    'error' => $error ?? null,
]);
