<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/process_request.php  –  AJAX endpoint: process a
//                              supervisor-approved e-Kenderaan
//                              vehicle request into a schedule
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/mailer.php';

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
$chosen_vehicle = isset($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : -1;   // -1 = not sent: keep the requested vehicle
$chosen_driver  = isset($_POST['driver_id'])  ? (int)$_POST['driver_id']  : 0;
$chosen_type    = isset($_POST['trip_type'])  ? trim((string)$_POST['trip_type']) : 'regular';
if (!in_array($chosen_type, ['regular', 'top_management'], true)) {
    $chosen_type = 'regular';
}

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
$trip_date       = $request['trip_date'];
$start_time      = $request['start_time'];
$end_time        = $request['end_time'];
$destination     = $request['destination'];
$purpose         = $request['purpose'];
$passenger_count = (int)$request['passenger_count'];
$created_by      = (int)$_SESSION['user_id'];
$trip_type       = $chosen_type;
$officer_name    = $request['officer_name']  ?? null;
$officer_phone   = $request['officer_phone'] ?? null;
$waiting_place   = $request['waiting_place'] ?? null;
$notes          = 'e-Kenderaan request #' . (int)$request['request_id']
                 . ' by ' . $request['staff_name']
                 . ' (' . ($request['department'] ?? '') . '). Supervisor-approved.';

function failJson(string $message): void
{
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

// The same job (day, start time and destination) must not be entered twice
$same_job = findDuplicateJob($conn, $trip_date, $start_time, $destination);
if ($same_job) {
    failJson('A job to this destination on the same date and start time already exists (Schedule #'
        . str_pad($same_job['schedule_id'], 4, '0', STR_PAD_LEFT)
        . '). The same job cannot be entered twice. If it needs another driver, open that schedule and use Add driver.');
}

// Vehicle: the one the admin picked, else (field not sent) the one that was requested; 0 = decide later
$vehicle_id = $chosen_vehicle >= 0
    ? ($chosen_vehicle > 0 ? $chosen_vehicle : null)
    : ($request['vehicle_id'] !== null ? (int)$request['vehicle_id'] : null);

$vehicle_type = null;
if ($vehicle_id !== null) {
    $vs = $conn->prepare(
        "SELECT vehicle_type FROM vehicles
         WHERE vehicle_id = ? AND status = 'available' AND capacity >= ?
           AND vehicle_id NOT IN (
                 SELECT vehicle_id FROM schedules
                 WHERE trip_date = ? AND vehicle_id IS NOT NULL
                   AND status NOT IN ('cancelled','completed')
                   AND start_time < ? AND end_time > ?
           )
         LIMIT 1"
    );
    $vs->bind_param('iisss', $vehicle_id, $passenger_count, $trip_date, $end_time, $start_time);
    $vs->execute();
    $vrow = $vs->get_result()->fetch_assoc();
    $vs->close();
    if (!$vrow) {
        failJson('That vehicle is not free for this date and time, is not available, or has too few seats. Please choose another vehicle.');
    }
    $vehicle_type = $vrow['vehicle_type'];
}

// Driver: optional. Must be active, free, hold the right licence and match the trip type
$driver_id      = null;
$driver_name    = null;
$priority_score = 0.00;
if ($chosen_driver > 0) {
    $ds = $conn->prepare(
        "SELECT driver_id, name, experience_years, license_class, driver_type
         FROM drivers
         WHERE driver_id = ? AND status = 'active'
           AND driver_id NOT IN (
                 SELECT driver_id FROM schedules
                 WHERE trip_date = ? AND driver_id IS NOT NULL AND status NOT IN ('cancelled')
                   AND start_time < ? AND end_time > ?
           )
         LIMIT 1"
    );
    $ds->bind_param('isss', $chosen_driver, $trip_date, $end_time, $start_time);
    $ds->execute();
    $drow = $ds->get_result()->fetch_assoc();
    $ds->close();

    if (!$drow) {
        failJson('That driver is not active or already has a task at this time. Please choose another driver.');
    }
    if ($vehicle_type !== null && !driverHasLicense($drow['license_class'] ?? null, requiredLicenseClasses($vehicle_type))) {
        failJson($drow['name'] . ' does not hold the licence class needed for this vehicle. Please choose another driver.');
    }
    if (($drow['driver_type'] ?? 'regular') !== $trip_type) {
        failJson($drow['name'] . ' is a ' . (($drow['driver_type'] ?? 'regular') === 'top_management' ? 'Top Management' : 'Regular')
            . ' driver, which does not match the trip type. Please choose another driver or change the trip type.');
    }

    $month_counts   = getMonthlyTaskCounts($conn, substr($trip_date, 0, 7));
    $mc             = $month_counts[$chosen_driver] ?? ['tasks' => 0, 'weekend' => 0];
    $priority_score = calculateAllocationScore($mc['tasks'], $mc['weekend'], (float)$drow['experience_years']);
    $driver_id      = (int)$drow['driver_id'];
    $driver_name    = $drow['name'];
}

$status = $driver_id !== null ? 'approved' : 'pending';

// ── INSERT the schedule ───────────────────────────────────────
$ins = $conn->prepare(
    "INSERT INTO schedules
         (driver_id, vehicle_id, trip_date, start_time, end_time,
          destination, purpose, passenger_count, status, priority_score, created_by, notes, trip_type,
          officer_name, officer_phone, waiting_place)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$ins) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit();
}

$ins->bind_param(
    'iisssssisdisssss',
    $driver_id, $vehicle_id, $trip_date, $start_time, $end_time,
    $destination, $purpose, $passenger_count,
    $status, $priority_score, $created_by, $notes, $trip_type,
    $officer_name, $officer_phone, $waiting_place
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
// ── Tell the driver (never lets an e-mail problem undo the assignment) ──
$email_note = '';
if ($driver_id !== null) {
    try {
        $email_note = emailSummary(notifyDriversAssigned($conn, [$schedule_id]));
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

echo json_encode([
    'success'         => true,
    'message'         => 'Schedule created.',
    'schedule_id'     => $schedule_id,
    'driver_assigned' => $driver_id !== null,
    'driver_name'     => $driver_name,
    'email'           => $email_note,
]);
?>
