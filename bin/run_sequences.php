<?php
declare(strict_types=1);
/**
 * Sequence worker. Run every 10-15 minutes from Task Scheduler:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\scrap3r\bin\run_sequences.php
 *
 * Options: --dry-run   show what would be sent, send nothing
 *          --campaign=N  restrict to one campaign
 */
require_once dirname(__DIR__) . '/config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$options = getopt('', ['dry-run', 'campaign::']);
$dryRun = array_key_exists('dry-run', $options);
$campaign = isset($options['campaign']) ? (int) $options['campaign'] : null;

$runner = new SequenceRunner($dryRun);
$totals = $runner->run($campaign);

foreach ($runner->log() as $line) {
    echo $line, PHP_EOL;
}

printf(
    "%s  processed=%d sent=%d skipped=%d failed=%d%s\n",
    date('H:i:s'),
    $totals['processed'],
    $totals['sent'],
    $totals['skipped'],
    $totals['failed'],
    $dryRun ? '  (dry run)' : ''
);

exit($totals['failed'] > 0 ? 1 : 0);
