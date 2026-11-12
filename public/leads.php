<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$where = [];
$params = [];

if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
    $where[] = '(l.name LIKE :q OR l.company LIKE :q OR l.email LIKE :q OR l.description LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if (($status = (string) ($_GET['status'] ?? '')) !== '') {
    $where[] = 'l.status = :status';
    $params['status'] = $status;
}
if (($has = (string) ($_GET['has_email'] ?? '')) === 'yes') {
    $where[] = "l.email IS NOT NULL AND l.email <> ''";
} elseif ($has === 'no') {
    $where[] = "(l.email IS NULL OR l.email = '')";
}

$sql = 'FROM leads l ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '');
$total = (int) Db::value("SELECT COUNT(*) $sql", $params);

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$leads = Db::all(
    "SELECT l.* $sql ORDER BY l.created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);

$campaigns = Db::all("SELECT id, name FROM campaigns WHERE status IN ('draft','running','paused') ORDER BY name");

render('leads', [
    'title' => 'Leads',
    'leads' => $leads,
    'total' => $total,
    'page' => $page,
    'pages' => max(1, (int) ceil($total / $perPage)),
    'campaigns' => $campaigns,
]);
