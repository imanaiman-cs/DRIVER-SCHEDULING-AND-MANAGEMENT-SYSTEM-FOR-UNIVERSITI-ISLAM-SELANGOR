<?php
// ============================================================
// ajax/get_busy_resources.php
// Which drivers and vehicles are already booked in a time slot.
// Used by the schedule forms to disable them in the pickers.
//
// POST: trip_date, start_time, end_time
//       exclude_id  schedule being edited (its own booking does not count)
//       share_with  schedule whose job may share a vehicle: that job's
//                   schedules do not make a vehicle "busy"
// ============================================================
require_once '../config/database.php';
requireAdmin();
header('Content-Type: application/json');

$trip_date  = trim($_POST['trip_date']  ?? '');
$start_time = trim($_POST['start_time'] ?? '');
$end_time   = trim($_POST['end_time']   ?? '');
$exclude_id = (int)($_POST['exclude_id'] ?? 0);
$share_with = (int)($_POST['share_with'] ?? 0);

$empty = ['success' => true, 'driver_ids' => [], 'vehicle_ids' => []];

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trip_date)
    || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)
    || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)
    || $end_time <= $start_time) {
    echo json_encode($empty);
    exit();
}

// Schedules of the job that may share a vehicle
$share_ids = [];
if ($share_with > 0) {
    $gs = $conn->prepare(
        "SELECT schedule_id FROM schedules
         WHERE schedule_id = ?
            OR (job_group IS NOT NULL AND job_group = (SELECT job_group FROM schedules WHERE schedule_id = ?))"
    );
    $gs->bind_param('ii', $share_with, $share_with);
    $gs->execute();
    foreach ($gs->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $share_ids[] = (int)$r['schedule_id'];
    }
    $gs->close();
}

$stmt = $conn->prepare(
    "SELECT schedule_id, driver_id, vehicle_id
     FROM schedules
     WHERE trip_date = ? AND status <> 'cancelled'
       AND start_time < ? AND end_time > ?"
);
$stmt->bind_param('sss', $trip_date, $end_time, $start_time);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$drivers  = [];
$vehicles = [];
foreach ($rows as $r) {
    $sid = (int)$r['schedule_id'];
    if ($sid === $exclude_id) {
        continue;                                   // the schedule being edited
    }
    if ($r['driver_id'] !== null) {
        $drivers[(int)$r['driver_id']] = true;
    }
    if ($r['vehicle_id'] !== null && !in_array($sid, $share_ids, true)) {
        $vehicles[(int)$r['vehicle_id']] = true;
    }
}

echo json_encode([
    'success'     => true,
    'driver_ids'  => array_keys($drivers),
    'vehicle_ids' => array_keys($vehicles),
]);
