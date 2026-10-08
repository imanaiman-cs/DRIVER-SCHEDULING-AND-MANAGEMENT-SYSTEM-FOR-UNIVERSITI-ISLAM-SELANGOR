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

// ── Who drives what ────────────────────────────────────────────
// A trip that needs several vehicles is processed as one job with one
// schedule per vehicle (each with its own driver): vehicle_ids[] and
// driver_ids[] are parallel lists. A normal trip sends vehicle_id/driver_id.
$assign = [];
if (isset($_POST['vehicle_ids']) && is_array($_POST['vehicle_ids'])) {
    $vlist = array_slice(array_values(array_map('intval', $_POST['vehicle_ids'])), 0, 5);
    $dlist = (isset($_POST['driver_ids']) && is_array($_POST['driver_ids'])) ? array_values(array_map('intval', $_POST['driver_ids'])) : [];
    foreach ($vlist as $i => $vv) {
        $dd = $dlist[$i] ?? 0;
        $assign[] = ['vehicle' => $vv > 0 ? $vv : null, 'driver' => $dd > 0 ? $dd : null];
    }
}
if (!$assign) {
    // Vehicle: the one the admin picked, else (field not sent) the one that was requested; 0 = decide later
    $one_vehicle = $chosen_vehicle >= 0
        ? ($chosen_vehicle > 0 ? $chosen_vehicle : null)
        : ($request['vehicle_id'] !== null ? (int)$request['vehicle_id'] : null);
    $assign[] = ['vehicle' => $one_vehicle, 'driver' => $chosen_driver > 0 ? $chosen_driver : null];
}
$multi = count($assign) > 1;

// A vehicle or a driver may appear only once in a job
$seen_v = [];
$seen_d = [];
foreach ($assign as $a) {
    if ($a['vehicle'] !== null) {
        if (isset($seen_v[$a['vehicle']])) { failJson('The same vehicle is chosen more than once. Each vehicle can be used once in a job.'); }
        $seen_v[$a['vehicle']] = true;
    }
    if ($a['driver'] !== null) {
        if (isset($seen_d[$a['driver']])) { failJson('The same driver is chosen more than once. A driver can only be on a job once.'); }
        $seen_d[$a['driver']] = true;
    }
}

$month_counts = getMonthlyTaskCounts($conn, substr($trip_date, 0, 7));
$plan = [];          // validated rows: vehicle, driver, driver_name, score
$seat_total = 0;
$all_vehicles_chosen = true;

foreach ($assign as $a) {
    $vehicle_id   = $a['vehicle'];
    $vehicle_type = null;
    if ($vehicle_id !== null) {
        // One vehicle must seat everybody; with several vehicles the seats are added up below
        $need_seats = $multi ? 0 : $passenger_count;
        $vs = $conn->prepare(
            "SELECT vehicle_type, capacity FROM vehicles
             WHERE vehicle_id = ? AND status = 'available' AND capacity >= ?
               AND vehicle_id NOT IN (
                     SELECT vehicle_id FROM schedules
                     WHERE trip_date = ? AND vehicle_id IS NOT NULL
                       AND status NOT IN ('cancelled','completed')
                       AND start_time < ? AND end_time > ?
               )
             LIMIT 1"
        );
        $vs->bind_param('iisss', $vehicle_id, $need_seats, $trip_date, $end_time, $start_time);
        $vs->execute();
        $vrow = $vs->get_result()->fetch_assoc();
        $vs->close();
        if (!$vrow) {
            failJson('A chosen vehicle is not free for this date and time, is not available, or has too few seats. Please choose another vehicle.');
        }
        $vehicle_type = $vrow['vehicle_type'];
        $seat_total  += (int)$vrow['capacity'];
    } else {
        $all_vehicles_chosen = false;
    }

    // Driver: optional. Must be active, free, hold the right licence and match the trip type
    $driver_id = null; $driver_name = null; $priority_score = 0.00;
    if ($a['driver'] !== null) {
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
        $ds->bind_param('isss', $a['driver'], $trip_date, $end_time, $start_time);
        $ds->execute();
        $drow = $ds->get_result()->fetch_assoc();
        $ds->close();

        if (!$drow) {
            failJson('A chosen driver is not active or already has a task at this time. Please choose another driver.');
        }
        if ($vehicle_type !== null && !driverHasLicense($drow['license_class'] ?? null, requiredLicenseClasses($vehicle_type))) {
            failJson($drow['name'] . ' does not hold the licence class needed for this vehicle. Please choose another driver.');
        }
        if (($drow['driver_type'] ?? 'regular') !== $trip_type) {
            failJson($drow['name'] . ' is a ' . (($drow['driver_type'] ?? 'regular') === 'top_management' ? 'Top Management' : 'Regular')
                . ' driver, which does not match the trip type. Please choose another driver or change the trip type.');
        }
        $mc             = $month_counts[(int)$drow['driver_id']] ?? ['tasks' => 0, 'weekend' => 0];
        $priority_score = calculateAllocationScore($mc['tasks'], $mc['weekend'], (float)$drow['experience_years']);
        $driver_id      = (int)$drow['driver_id'];
        $driver_name    = $drow['name'];
    }
    $plan[] = ['vehicle' => $vehicle_id, 'driver' => $driver_id, 'driver_name' => $driver_name, 'score' => $priority_score];
}

if ($multi && $all_vehicles_chosen && $seat_total < $passenger_count) {
    failJson('The chosen vehicles seat ' . $seat_total . ' in total, but the request has ' . $passenger_count
        . ' passengers. Add a bigger vehicle or another vehicle.');
}

// ── INSERT the schedule(s) and mark the request processed, all or nothing ──
$schedule_ids = [];
try {
    $conn->begin_transaction();

    $ins = $conn->prepare(
        "INSERT INTO schedules
             (driver_id, vehicle_id, trip_date, start_time, end_time,
              destination, purpose, passenger_count, status, priority_score, created_by, notes, trip_type,
              officer_name, officer_phone, waiting_place)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$ins) {
        throw new RuntimeException('prepare failed');
    }
    foreach ($plan as $row) {
        $driver_id      = $row['driver'];
        $vehicle_id     = $row['vehicle'];
        $priority_score = $row['score'];
        $status         = $driver_id !== null ? 'approved' : 'pending';
        $ins->bind_param(
            'iisssssisdisssss',
            $driver_id, $vehicle_id, $trip_date, $start_time, $end_time,
            $destination, $purpose, $passenger_count,
            $status, $priority_score, $created_by, $notes, $trip_type,
            $officer_name, $officer_phone, $waiting_place
        );
        if (!$ins->execute()) {
            throw new RuntimeException('insert failed');
        }
        $schedule_ids[] = (int)$ins->insert_id;
    }
    $ins->close();

    // The rows of a multi-vehicle job share one job_group
    if ($multi) {
        $grp = $schedule_ids[0];
        $conn->query("UPDATE schedules SET job_group = {$grp} WHERE schedule_id IN (" . implode(',', $schedule_ids) . ")");
    }

    $first_id = $schedule_ids[0];
    $upd = $conn->prepare(
        "UPDATE vehicle_requests SET status = 'processed', schedule_id = ?
         WHERE request_id = ? AND status = 'approved'"
    );
    $upd->bind_param('ii', $first_id, $request_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected === 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'This request has already been processed by another admin.']);
        exit();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('process_request: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to create the schedule. Please try again.']);
    exit();
}

// ── Tell the drivers (never lets an e-mail problem undo the assignment) ──
$email_note = '';
$mail_ids = [];
foreach ($plan as $k => $row) {
    if ($row['driver'] !== null) { $mail_ids[] = $schedule_ids[$k]; }
}
if ($mail_ids) {
    try {
        $email_note = emailSummary(notifyDriversAssigned($conn, $mail_ids));
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

$assigned_names = array_values(array_filter(array_column($plan, 'driver_name')));
echo json_encode([
    'success'         => true,
    'message'         => 'Schedule created.',
    'schedule_id'     => $schedule_ids[0],
    'schedule_ids'    => $schedule_ids,
    'driver_assigned' => count($assigned_names) > 0,
    'driver_name'     => $assigned_names ? implode(', ', $assigned_names) : null,
    'drivers_missing' => count($plan) - count($assigned_names),
    'email'           => $email_note,
]);
