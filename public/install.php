<?php
declare(strict_types=1);
define('PUBLIC_PAGE', true);
require_once __DIR__ . '/_init.php';

// One-time bootstrap: only reachable while there are no users at all.
if ((int) Db::value('SELECT COUNT(*) FROM users') > 0) {
    flash('The system is already installed. Sign in instead.', 'warn');
    redirect('login.php');
}

/*
 * On a public server, whoever opens this page first becomes the admin. So
 * outside local development it only works with the INSTALL_KEY from .env,
 * which only someone with access to the server's files can read.
 */
$installKey = (string) env('INSTALL_KEY', '');
$isLocal = env('APP_ENV', 'production') === 'local';
$givenKey = (string) ($_POST['key'] ?? $_GET['key'] ?? '');

if (!$isLocal && $installKey === '') {
    http_response_code(403);
    echo view('public_message', [
        'heading' => 'Setup is locked',
        'body'    => 'Add a line INSTALL_KEY=<a long random value> to the .env file on the server, '
                   . 'then open install.php?key=<that value>.',
    ]);
    exit;
}
if ($installKey !== '' && !hash_equals($installKey, $givenKey)) {
    http_response_code(403);
    echo view('public_message', [
        'heading' => 'Install key required',
        'body'    => 'Open this page with the link that includes your key: install.php?key= followed by the '
                   . 'INSTALL_KEY value from the .env file in the site folder.',
    ]);
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $company = trim((string) ($_POST['company'] ?? ''));
    $name    = trim((string) ($_POST['name'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ''));
    $pass    = (string) ($_POST['password'] ?? '');

    if ($company === '' || $name === '' || !is_valid_email($email)) {
        $error = 'Enter your company name, your name and a valid email address.';
    } elseif (strlen($pass) < 10) {
        $error = 'Use a password of at least 10 characters.';
    } else {
        $id = Auth::createUser($name, $email, $pass, 'admin');
        Settings::setMany(['company_name' => mb_substr($company, 0, 120), 'brand_header' => mb_substr($company, 0, 80)]);
        Scheduler::seedDefaultAvailability($id);
        Templates::seedSamples($id);
        flash('Your workspace is ready. Sign in, then connect your mailbox under Settings.');
        redirect('login.php');
    }
}

render('install', ['title' => 'Set up', 'error' => $error, 'key' => $givenKey]);
