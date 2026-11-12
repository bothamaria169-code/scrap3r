<?php
declare(strict_types=1);
/**
 * Replace a rep's weekly availability:
 *   php bin/set_availability.php 1 "1-5:09:00-17:00" "6:10:00-13:00"
 * Weekday numbers are 0=Sunday .. 6=Saturday. Ranges like 1-5 are expanded.
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$argvRest = array_slice($argv, 1);
if (count($argvRest) < 2) {
    exit("Usage: php bin/set_availability.php <user_id> \"1-5:09:00-17:00\" [more...]\n");
}

$userId = (int) array_shift($argvRest);
if (!Db::one('SELECT id FROM users WHERE id = :id', ['id' => $userId])) {
    exit("No such user.\n");
}

Db::run('DELETE FROM availability WHERE user_id = :u', ['u' => $userId]);
$added = 0;

foreach ($argvRest as $spec) {
    if (!preg_match('/^(\d)(?:-(\d))?:(\d{2}:\d{2})-(\d{2}:\d{2})$/', $spec, $m)) {
        echo "Skipping unparseable spec: $spec\n";
        continue;
    }
    $from = (int) $m[1];
    $to = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $from;
    for ($d = $from; $d <= $to; $d++) {
        Db::run(
            Db::insertIgnore() . ' INTO availability (user_id, weekday, start_time, end_time) VALUES (:u,:w,:s,:e)',
            ['u' => $userId, 'w' => $d, 's' => $m[3] . ':00', 'e' => $m[4] . ':00']
        );
        $added++;
    }
}

echo "Set $added availability window(s) for user #$userId.\n";
