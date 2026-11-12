<?php
declare(strict_types=1);
/**
 * Add a sales rep:
 *   php bin/add_user.php "Jane Doe" jane@example.com --role=rep --tz="Africa/Johannesburg"
 * The password is prompted for, so it never lands in shell history.
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => !str_starts_with($a, '--')));
$options = getopt('', ['role::', 'tz::', 'slot::']);

if (count($args) < 2) {
    exit("Usage: php bin/add_user.php \"Name\" email@example.com [--role=rep|admin] [--tz=Area/City] [--slot=30]\n");
}

[$name, $email] = $args;

if (!is_valid_email($email)) {
    exit("That is not a valid email address.\n");
}
if (Db::value('SELECT id FROM users WHERE email = :e', ['e' => strtolower($email)])) {
    exit("A user with that address already exists.\n");
}

echo 'Password (min 10 chars): ';
$password = trim((string) fgets(STDIN));
if (strlen($password) < 10) {
    exit("Password too short. Nothing created.\n");
}

$id = Auth::createUser($name, $email, $password, ($options['role'] ?? 'rep') === 'admin' ? 'admin' : 'rep');

Db::update('users', [
    'timezone'     => $options['tz'] ?? date_default_timezone_get(),
    'slot_minutes' => max(5, (int) ($options['slot'] ?? 30)),
], 'id = :wid', ['wid' => $id]);

Scheduler::seedDefaultAvailability($id);

echo "Created user #$id ($email). Default availability: Mon-Fri 09:00-17:00.\n";
