<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$stats = [
    'leads'      => (int) Db::value('SELECT COUNT(*) FROM leads'),
    'mailable'   => (int) Db::value("SELECT COUNT(*) FROM leads WHERE email IS NOT NULL AND email <> '' AND unsubscribed_at IS NULL"),
    'sent_7d'    => (int) Db::value("SELECT COUNT(*) FROM messages WHERE status='sent' AND sent_at >= :since", ['since' => date('Y-m-d H:i:s', strtotime('-7 days'))]),
    'booked'     => (int) Db::value("SELECT COUNT(*) FROM appointments WHERE status='scheduled'"),
    'suppressed' => Suppression::count(),
    'failed_24h' => (int) Db::value("SELECT COUNT(*) FROM messages WHERE status='failed' AND created_at >= :since", ['since' => date('Y-m-d H:i:s', strtotime('-1 day'))]),
];

$upcoming = Db::all(
    "SELECT a.*, l.name AS lead_name, l.company, l.email, u.name AS rep
     FROM appointments a
     JOIN leads l ON l.id = a.lead_id
     JOIN users u ON u.id = a.user_id
     WHERE a.status='scheduled' AND a.starts_at >= NOW()
     ORDER BY a.starts_at LIMIT 8"
);

$recent = Db::all(
    "SELECT m.*, l.name AS lead_name FROM messages m
     JOIN leads l ON l.id = m.lead_id
     ORDER BY m.created_at DESC LIMIT 10"
);

render('dashboard', [
    'title' => 'Dashboard',
    'stats' => $stats,
    'upcoming' => $upcoming,
    'recent' => $recent,
]);
