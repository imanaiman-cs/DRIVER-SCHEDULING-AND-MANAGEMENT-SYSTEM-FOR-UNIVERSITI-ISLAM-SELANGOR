<?php
require_once '../config/database.php';
header('Content-Type: application/json');

// Allow staff OR admin users
if (!isStaff() && !isAdmin()) {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'Unauthorized.']);
    exit();
}

$trip_date  = trim($_GET['trip_date']  ?? '');
$start_time = trim($_GET['start_time'] ?? '');
$end_time   = trim($_GET['end_time']   ?? '');

// Validate presence and format
if ($trip_date === '' || $start_time === '' || $end_time === '') {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'Missing parameters.']);
    exit();
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trip_date) || !strtotime($trip_date)) {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'Invalid trip date.']);
    exit();
}

if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'Invalid time format.']);
    exit();
}

if ($end_time <= $start_time) {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'End time must be after start time.']);
    exit();
}

// Vehicles that are available and NOT booked on an overlapping schedule.
// Overlap test: existing.start_time < new_end AND existing.end_time > new_start
$sql = "SELECT vehicle_id, plate_number, vehicle_type, brand, model, capacity
        FROM vehicles
        WHERE status = 'available'
          AND vehicle_id NOT IN (
                SELECT vehicle_id FROM schedules
                WHERE trip_date = ?
                  AND status NOT IN ('cancelled','completed')
                  AND vehicle_id IS NOT NULL
                  AND (start_time < ? AND end_time > ?)
          )
        ORDER BY vehicle_type ASC, plate_number ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('sss', $trip_date, $end_time, $start_time);
$stmt->execute();
$result = $stmt->get_result();

$vehicles = [];
while ($row = $result->fetch_assoc()) {
    $vehicles[] = [
        'vehicle_id'   => (int)$row['vehicle_id'],
        'plate_number' => $row['plate_number'],
        'vehicle_type' => $row['vehicle_type'],
        'brand'        => $row['brand'],
        'model'        => $row['model'],
        'capacity'     => (int)$row['capacity'],
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'vehicles' => $vehicles, 'count' => count($vehicles)]);
