<?php
require_once '../config/database.php';
requireLogin();
header('Content-Type: application/json');

$vehicle_id  = isset($_POST['vehicle_id'])  ? (int)$_POST['vehicle_id']  : 0;
$driver_id   = isset($_POST['driver_id'])   ? (int)$_POST['driver_id']   : 0;
$trip_date   = $_POST['trip_date']   ?? '';
$start_time  = $_POST['start_time']  ?? '';
$end_time    = $_POST['end_time']    ?? '';
$exclude_id  = isset($_POST['exclude_id']) ? (int)$_POST['exclude_id'] : 0;

$response = [
    'vehicle_conflict' => false,
    'driver_conflict'  => false,
    'message'          => '',
];

if (!$trip_date || !$start_time || !$end_time) {
    echo json_encode($response);
    exit();
}

$overlap_cond = "trip_date = ? AND status NOT IN ('cancelled')
    AND ((start_time < ? AND end_time > ?)
      OR (start_time < ? AND end_time > ?)
      OR (start_time >= ? AND end_time <= ?))";
$id_exclude = "AND schedule_id != ?";

// Vehicle conflict
if ($vehicle_id > 0) {
    $sql = "SELECT schedule_id FROM schedules WHERE vehicle_id = ? AND $overlap_cond $id_exclude LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssssi",
        $vehicle_id,
        $trip_date, $end_time, $start_time,
        $end_time, $start_time,
        $start_time, $end_time,
        $exclude_id
    );
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $response['vehicle_conflict'] = true;
        $response['message'] .= 'Vehicle is already scheduled for this time slot. ';
    }
}

// Driver conflict
if ($driver_id > 0) {
    $sql = "SELECT schedule_id FROM schedules WHERE driver_id = ? AND $overlap_cond $id_exclude LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssssi",
        $driver_id,
        $trip_date, $end_time, $start_time,
        $end_time, $start_time,
        $start_time, $end_time,
        $exclude_id
    );
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $response['driver_conflict'] = true;
        $response['message'] .= 'Driver is already assigned to another schedule at this time. ';
    }
}

if (!$response['vehicle_conflict'] && !$response['driver_conflict']) {
    $response['message'] = 'No conflicts detected.';
}

echo json_encode($response);
