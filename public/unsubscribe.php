<?php
declare(strict_types=1);
define('PUBLIC_PAGE', true);
require_once __DIR__ . '/_init.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$record = Tokens::resolve($token, 'unsubscribe');

if (!$record) {
    http_response_code(404);
    echo view('public_message', [
        'heading' => 'Link not recognised',
        'body' => 'This opt-out link is not valid. Reply to any of our emails with "unsubscribe" and we will remove you by hand.',
    ]);
    exit;
}

// One-click unsubscribe (RFC 8058) posts here directly; honour it without a confirm step.
$oneClick = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($oneClick || isset($_GET['confirm'])) {
    if ($record['lead_email']) {
        Suppression::add((string) $record['lead_email'], 'unsubscribed');
    }
    Tokens::markUsed($token);

    if ($oneClick && isset($_POST['List-Unsubscribe'])) {
        http_response_code(200);
        exit;
    }

    echo view('public_message', [
        'heading' => 'You have been unsubscribed',
        'body' => 'We have removed ' . ($record['lead_email'] ?: 'your address')
                . ' from our list. You will not receive further emails from us.',
    ]);
    exit;
}

echo view('unsubscribe', ['record' => $record, 'token' => $token]);
