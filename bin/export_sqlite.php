<?php
declare(strict_types=1);
/**
 * Copy the local MySQL database into a SQLite file for the hosted copy:
 *   php bin/export_sqlite.php                     -> storage/database.sqlite
 *   php bin/export_sqlite.php path/to/out.sqlite  [--force]
 *
 * Reads MySQL using the DB_HOST/DB_NAME/DB_USER/DB_PASS values in .env,
 * keeps every id, and verifies row counts and foreign keys afterwards.
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => !str_starts_with($a, '--')));
$force = in_array('--force', $argv, true);
$out = $args[0] ?? BASE_PATH . '/storage/database.sqlite';
if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $out)) {
    $out = getcwd() . '/' . $out;
}

if (is_file($out) && !$force) {
    exit("$out already exists. Re-run with --force to overwrite it.\n");
}

// Parent-first, so foreign keys always point at rows already copied.
$tables = [
    'users', 'settings', 'lead_imports', 'leads', 'templates', 'campaigns',
    'campaign_steps', 'enrollments', 'messages', 'suppressions',
    'availability', 'appointments', 'tokens',
];

$mysql = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'scrap3r')),
    env('DB_USER', 'root'),
    env('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

foreach (glob($out . '*') ?: [] as $stale) {       // database + any -wal / -shm siblings
    if (in_array($stale, [$out, "$out-wal", "$out-shm", "$out-journal"], true)) {
        unlink($stale);
    }
}
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}

$sqlite = Db::openSqlite($out);
$sqlite->exec((string) file_get_contents(BASE_PATH . '/db/schema.sqlite.sql'));
$sqlite->exec('PRAGMA foreign_keys = OFF');
$sqlite->exec('BEGIN');

$report = [];
foreach ($tables as $table) {
    $targetCols = array_column($sqlite->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    $rows = $mysql->query("SELECT * FROM $table")->fetchAll();
    $copied = 0;
    $insert = null;
    foreach ($rows as $row) {
        $row = array_intersect_key($row, array_flip($targetCols));
        if ($insert === null) {
            $cols = array_keys($row);
            $insert = $sqlite->prepare(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(',', $cols),
                implode(',', array_fill(0, count($cols), '?'))
            ));
        }
        $insert->execute(array_values($row));
        $copied++;
    }
    $report[$table] = [count($rows), $copied];
}

$sqlite->exec('COMMIT');
$sqlite->exec('PRAGMA foreign_keys = ON');

$problems = $sqlite->query('PRAGMA foreign_key_check')->fetchAll();
$integrity = (string) $sqlite->query('PRAGMA integrity_check')->fetchColumn();
// Fold the WAL back into the main file so a single file can be uploaded.
$sqlite->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$sqlite->exec('PRAGMA journal_mode = DELETE');
$sqlite = null;

$ok = true;
printf("%-16s %8s %8s\n", 'table', 'mysql', 'sqlite');
foreach ($report as $table => [$src, $dst]) {
    printf("%-16s %8d %8d%s\n", $table, $src, $dst, $src === $dst ? '' : '  MISMATCH');
    $ok = $ok && $src === $dst;
}
echo "foreign keys:  " . ($problems ? count($problems) . ' broken reference(s)' : 'all valid') . "\n";
echo "integrity:     $integrity\n";
echo "written:       $out (" . number_format((int) filesize($out) / 1024) . " KB)\n";

exit($ok && !$problems && $integrity === 'ok' ? 0 : 1);
