<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$result = null;
$scraperLog = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'json') {
        $path = trim((string) ($_POST['json_path'] ?? ''));
        if ($path === '') {
            $path = BASE_PATH . '/output/zoominfo_data.json';
        }
        $result = LeadImporter::importScraperJson($path, (int) $currentUser['id']);
    }

    if ($action === 'csv') {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            flash('Choose a CSV file to upload.', 'err');
        } else {
            $result = LeadImporter::importCsv(
                $_FILES['csv']['tmp_name'],
                (int) $currentUser['id'],
                (string) $_FILES['csv']['name']
            );
        }
    }

    if ($action === 'scrape') {
        $urls = trim((string) ($_POST['urls'] ?? ''));
        if ($urls === '') {
            flash('Paste at least one ZoomInfo URL.', 'err');
        } else {
            $tmp = BASE_PATH . '/output/urls_' . date('Ymd_His') . '.txt';
            @mkdir(dirname($tmp), 0775, true);
            file_put_contents($tmp, $urls);
            $out = BASE_PATH . '/output/zoominfo_data.json';
            $result = LeadImporter::runScraper($tmp, $out, (int) $currentUser['id']);
            $scraperLog = Db::value('SELECT notes FROM lead_imports WHERE id = :id', ['id' => $result['import_id'] ?: 0]);
        }
    }

    if ($result) {
        $summary = sprintf(
            '%d rows read — %d new, %d updated, %d skipped, %d blocked by the source.',
            $result['seen'], $result['created'], $result['updated'],
            $result['skipped'], $result['errored'] ?? 0
        );
        if ($result['errors']) {
            // Show why rows were blocked (rate limits, crawler errors) rather
            // than reporting a bare count the operator cannot act on.
            flash($summary . "\n\n" . implode("\n", array_slice($result['errors'], 0, 10)), 'warn');
        } else {
            flash($summary);
        }
    }
}

$imports = Db::all(
    'SELECT i.*, u.name AS who FROM lead_imports i LEFT JOIN users u ON u.id = i.created_by
     ORDER BY i.created_at DESC LIMIT 15'
);

render('import', ['title' => 'Import leads', 'imports' => $imports, 'scraperLog' => $scraperLog]);
