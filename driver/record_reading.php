<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/record_reading.php  -  the driver records the fuel level and the odometer of the vehicle after a task.
// POST only, from "My Tasks". One reading per task (it can be corrected); the vehicle keeps the latest one:
// vehicles.fuel_level (0 almost empty .. 4 full), fuel_updated_at, fuel_updated_by and mileage (the odometer).
// ============================================================
require_once '../config/database.php';
requireDriver();

$back = 'schedules.php?tab=' . (($_POST['tab'] ?? '') === 'past' ? 'past' : 'upcoming');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit(); }

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$kind  = ($_POST['kind'] ?? '') === 'internal' ? 'internal' : 'trip';
$id    = (int)($_POST['id'] ?? 0);
$fuel  = (string)($_POST['fuel'] ?? '');
$odoIn = trim(str_replace([',', ' '], '', (string)($_POST['odometer'] ?? '')));

$fail = function (string $msg) use ($back) { setFlash('danger', $msg); header('Location: ' . $back); exit(); };

// The task must be this driver's, have a vehicle, and have started
if ($kind === 'trip') {
    $stmt = $conn->prepare("SELECT s.vehicle_id, s.start_dt FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id
                             WHERE s.schedule_id = ? AND s.driver_id = ? AND s.status <> 'cancelled' AND vr.status IN ('processed','completed')");
} else {
    $stmt = $conn->prepare("SELECT vehicle_id, start_dt FROM internal_tasks WHERE task_id = ? AND driver_id = ? AND status = 'active'");
}
$stmt->bind_param('ii', $id, $driver_id);
$stmt->execute();
$task = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$task || !$task['vehicle_id'])            { $fail('This task cannot be found.'); }
if (strtotime($task['start_dt']) > time())      { $fail('Record the fuel and odometer after the task has started.'); }
if (!preg_match('/^[0-4]$/', $fuel))            { $fail('Choose the fuel level.'); }
$fuel = (int)$fuel;
$vehicle_id = (int)$task['vehicle_id'];

$odometer = null;
if ($odoIn !== '') {
    if (!preg_match('/^\d{1,7}$/', $odoIn)) { $fail('Enter the odometer in km, digits only.'); }
    $odometer = (int)$odoIn;
    // Not below the highest reading of this vehicle from another task
    $col = $kind === 'trip' ? 'schedule_id' : 'task_id';
    $stmt = $conn->prepare("SELECT MAX(odometer) FROM vehicle_readings WHERE vehicle_id = ? AND ($col IS NULL OR $col <> ?)");
    $stmt->bind_param('ii', $vehicle_id, $id);
    $stmt->execute();
    $max = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    if ($odometer < $max) { $fail('The odometer cannot be lower than the last reading of this vehicle (' . number_format($max) . ' km).'); }
}

// Save (one reading per task; a second save corrects it)
$sid = $kind === 'trip' ? $id : null;
$tid = $kind === 'internal' ? $id : null;
$stmt = $conn->prepare('INSERT INTO vehicle_readings (vehicle_id, driver_id, schedule_id, task_id, fuel_level, odometer) VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE fuel_level = VALUES(fuel_level), odometer = VALUES(odometer), recorded_at = NOW()');
$stmt->bind_param('iiiiii', $vehicle_id, $driver_id, $sid, $tid, $fuel, $odometer);
$stmt->execute();
$stmt->close();

// The vehicle keeps the latest fuel level and the highest odometer
$stmt = $conn->prepare('UPDATE vehicles SET fuel_level = ?, fuel_updated_at = NOW(), fuel_updated_by = ?, mileage = GREATEST(mileage, COALESCE(?, 0)) WHERE vehicle_id = ?');
$stmt->bind_param('iiii', $fuel, $driver_id, $odometer, $vehicle_id);
$stmt->execute();
$stmt->close();

auditLog($conn, 'vehicle_reading', null, 'Vehicle ' . $vehicle_id . ': fuel ' . $fuel . '/4' . ($odometer !== null ? ', odometer ' . $odometer . ' km' : ''));
setFlash('success', 'Fuel and odometer saved. Thank you.');
header('Location: ' . $back);
exit();
