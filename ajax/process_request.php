<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/process_request.php  –  AJAX endpoint: process a
//                              supervisor-approved e-Kenderaan
//                              vehicle request into a schedule
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';

header('Content-Type: application/json');

// ── Admin only (JSON response, no redirect) ───────────────────
if (
    !isLoggedIn()
    || !isset($_SESSION['role'])
    || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ── Only accept POST requests ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// ── Read and validate inputs ──────────────────────────────────
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

if ($request_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit();
}

// ── Load the approved request with its staff details ──────────
$stmt = $conn->prepare(
    "SELECT vr.*, s.full_name AS staff_name, s.department
     FROM vehicle_requests vr
     JOIN users s ON vr.staff_id = s.user_id
     WHERE vr.request_id = ? AND vr.status = 'approved'
     LIMIT 1"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

$stmt->bind_param('i', $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'Request not found or not ready for processing.']);
    exit();
}

// ── Build schedule values from the request ────────────────────
$driver_id       = null;                                              // assigned later via Auto Assign
$vehicle_id      = $request['vehicle_id'] !== null ? (int)$request['vehicle_id'] : null;
$trip_date       = $request['trip_date'];
$start_time      = $request['start_time'];
$end_time        = $request['end_time'];
$destination     = $request['destination'];
$purpose         = $request['purpose'];
$passenger_count = (int)$request['passenger_count'];
$status          = 'pending';
$priority_score  = 0.00;
$created_by      = (int)$_SESSION['user_id'];
$trip_type       = 'regular';
$notes           = 'e-Kenderaan request #' . (int)$request['request_id']
                 . ' by ' . $request['staff_name']
                 . ' (' . ($request['department'] ?? '') . '). Supervisor-approved.';

// ── INSERT the schedule ───────────────────────────────────────
$ins = $conn->prepare(
    "INSERT INTO schedules
         (driver_id, vehicle_id, trip_date, start_time, end_time,
          destination, purpose, passenger_count, status, priority_score, created_by, notes, trip_type)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$ins) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

$ins->bind_param(
    'iisssssisdiss',
    $driver_id, $vehicle_id, $trip_date, $start_time, $end_time,
    $destination, $purpose, $passenger_count,
    $status, $priority_score, $created_by, $notes, $trip_type
);

if (!$ins->execute()) {
    $ins->close();
    echo json_encode(['success' => false, 'message' => 'Failed to create the schedule. Please try again.']);
    exit();
}

$schedule_id = (int)$ins->insert_id;
$ins->close();

// ── Mark the request as processed (optimistic lock) ───────────
$upd = $conn->prepare(
    "UPDATE vehicle_requests
     SET status = 'processed', schedule_id = ?
     WHERE request_id = ? AND status = 'approved'"
);

if (!$upd) {
    // Roll back the schedule we just created
    $del = $conn->prepare("DELETE FROM schedules WHERE schedule_id = ?");
    if ($del) {
        $del->bind_param('i', $schedule_id);
        $del->execute();
        $del->close();
    }
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

$upd->bind_param('ii', $schedule_id, $request_id);
$upd->execute();
$affected = $upd->affected_rows;
$upd->close();

if ($affected === 0) {
    // Someone processed this request concurrently — undo our schedule
    $del = $conn->prepare("DELETE FROM schedules WHERE schedule_id = ?");
    if ($del) {
        $del->bind_param('i', $schedule_id);
        $del->execute();
        $del->close();
    }
    echo json_encode([
        'success' => false,
        'message' => 'This request has already been processed by another admin.'
    ]);
    exit();
}

// ── Respond ───────────────────────────────────────────────────
echo json_encode([
    'success'     => true,
    'message'     => 'Schedule created.',
    'schedule_id' => $schedule_id,
]);
?>
