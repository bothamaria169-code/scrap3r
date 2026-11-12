<?php
declare(strict_types=1);
/**
 * Reset a dashboard password:
 *   php bin/reset_password.php someone@example.com
 * The new password is prompted for, so it never lands in shell history.
 * Also re-activates the account if it had been disabled.
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$email = strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '') {
    // No email given (e.g. double-clicked reset_password.bat): pick from a list.
    $users = Db::all('SELECT email, name, role FROM users ORDER BY id');
    if (!$users) {
        exit("No accounts exist yet. Open /scrap3r/public/install.php in a browser to create one.\n");
    }
    echo "Accounts:\n";
    foreach ($users as $i => $u) {
        echo '  ' . ($i + 1) . ") {$u['email']}  ({$u['name']}, {$u['role']})\n";
    }
    echo 'Which account? [1]: ';
    $choice = trim((string) fgets(STDIN));
    $index = $choice === '' ? 0 : (int) $choice - 1;
    if (!isset($users[$index])) {
        exit("No such account. Nothing changed.\n");
    }
    $email = (string) $users[$index]['email'];
}

$user = Db::one('SELECT id, name FROM users WHERE email = :e', ['e' => $email]);
if (!$user) {
    exit("No account with that email. Run without arguments to list accounts.\n");
}

echo "New password for {$user['name']} (min 10 chars): ";
$password = trim((string) fgets(STDIN));
if (strlen($password) < 10) {
    exit("Too short. Password unchanged.\n");
}

echo 'Type it again: ';
if (trim((string) fgets(STDIN)) !== $password) {
    exit("Passwords did not match. Password unchanged.\n");
}

Db::update('users', [
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'is_active'     => 1,
], 'id = :wid', ['wid' => $user['id']]);

echo "Password updated. Sign in at " . public_url('login.php') . "\n";
