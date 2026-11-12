<?php
declare(strict_types=1);
define('PUBLIC_PAGE', true);
require_once __DIR__ . '/_init.php';

if (Auth::user()) {
    redirect('index.php');
}

// No users yet -> send the operator to the installer instead of a dead login box.
if ((int) Db::value('SELECT COUNT(*) FROM users') === 0) {
    redirect('install.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        redirect('index.php');
    }
    $error = 'Those credentials did not match an active account.';
}

render('login', ['title' => 'Sign in', 'error' => $error]);
