<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apptId = (int) ($_POST['appointment_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if ($apptId && in_array($status, ['scheduled', 'completed', 'cancelled', 'no_show'], true)) {
        Db::update('appointments', ['status' => $status], 'id = :wid', ['wid' => $apptId]);
        flash('Appointment updated.');
    }
    redirect('appointments.php');
}

$scope = (string) ($_GET['scope'] ?? 'upcoming');
$condition = match ($scope) {
    'past' => 'a.starts_at < NOW()',
    'all'  => '1=1',
    default => 'a.starts_at >= NOW()',
};

$appointments = Db::all(
    "SELECT a.*, l.name AS lead_name, l.email, l.company, u.name AS rep, u.timezone
     FROM appointments a
     JOIN leads l ON l.id = a.lead_id
     JOIN users u ON u.id = a.user_id
     WHERE $condition
     ORDER BY a.starts_at " . ($scope === 'past' ? 'DESC' : 'ASC') . ' LIMIT 200'
);

render('appointments', ['title' => 'Appointments', 'appointments' => $appointments, 'scope' => $scope]);
