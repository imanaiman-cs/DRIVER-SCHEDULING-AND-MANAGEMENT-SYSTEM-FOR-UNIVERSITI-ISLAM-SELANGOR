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
if (!isLoggedIn() || !isAdmin()) {
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

// ── Common values ─────────────────────────────────────────────
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

// Mark as Self-Drive: the vehicle is reserved but the requester drives it, so no driver is assigned.
$self_drive = !empty($_POST['self_drive']);
// Override: a driver who already has a clashing task may be chosen on purpose, with a reason (audited).
$override_reason = mb_substr(trim((string)($_POST['override_reason'] ?? '')), 0, 255);

function failJson(string $message, array $extra = []): void
{
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
    exit();
}

// ── The legs of the trip (leg 1 = the request itself) ─────────
$legs = requestLegs($conn, $request_id);
if (!$legs) {
    failJson('Request not found or not ready for processing.');
}

// ── Who drives what, for leg 1 ─────────────────────────────────
// A trip that needs several vehicles is processed as one job with one schedule per vehicle
// (each with its own driver): vehicle_ids[] and driver_ids[] are parallel lists.
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

if ($self_drive) {
    foreach ($assign as &$a) {
        if ($a['vehicle'] === null) { failJson('Self-drive needs a vehicle: please choose the vehicle the requester will drive.'); }
        $a['driver'] = null;
    }
    unset($a);
}

// The same vehicle may appear only once in a job
$seen_v = [];
foreach ($assign as $a) {
    if ($a['vehicle'] !== null) {
        if (isset($seen_v[$a['vehicle']])) { failJson('The same vehicle is chosen more than once. Each vehicle can be used once in a job.'); }
        $seen_v[$a['vehicle']] = true;
    }
}

/**
 * Validate one leg and return its rows (vehicle, driver, ...). Stops with a JSON error when something is wrong.
 * $assignLeg: [['vehicle' => id|null, 'driver' => id|null], ...]
 */
$overrides = [];   // [driver name => reason] for the audit log
$validateLeg = function (array $leg, array $assignLeg, int $leg_no)
    use ($conn, $request_id, $passenger_count, $trip_type, $multi, $self_drive, $override_reason, &$overrides) {
    $trip_date = $leg['date'];
    $start_time = $leg['start'] . ':00';
    $end_time   = $leg['end'] . ':00';
    $where = count($GLOBALS['legs']) > 1 ? ' (leg ' . $leg_no . ', ' . date('d M', strtotime($trip_date)) . ')' : '';

    $same_job = findDuplicateJob($conn, $trip_date, $start_time, $leg['destination']);
    if ($same_job) {
        failJson('A job to this destination on the same date and start time already exists (Schedule #'
            . str_pad($same_job['schedule_id'], 4, '0', STR_PAD_LEFT)
            . ')' . $where . '. The same job cannot be entered twice. If it needs another driver, open that schedule and use Add driver.');
    }

    $seen_d = [];
    $month_counts = getMonthlyTaskCounts($conn, substr($trip_date, 0, 7));
    // R3 / R4: maintenance periods and days held by ANOTHER request (this request's own hold is fine)
    $blocked_vehicles = blockedVehicleIds($conn, $trip_date, $request_id);
    $plan = []; $seat_total = 0; $all_vehicles_chosen = true;

    foreach ($assignLeg as $a) {
        $vehicle_id = $a['vehicle'];
        $vehicle_type = null;
        if ($vehicle_id !== null) {
            if (isset($blocked_vehicles[$vehicle_id])) {
                failJson('A chosen vehicle is ' . vehicleBlockText($blocked_vehicles[$vehicle_id]) . $where . '. Please choose another vehicle.');
            }
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
                failJson('A chosen vehicle is not free for this date and time, is not available, or has too few seats' . $where . '. Please choose another vehicle.');
            }
            $vehicle_type = $vrow['vehicle_type'];
            $seat_total  += (int)$vrow['capacity'];
        } else {
            $all_vehicles_chosen = false;
        }

        // Driver: optional. Must be active, hold the right licence, match the trip type and (unless overridden) be free
        $driver_id = null; $driver_name = null; $priority_score = 0.00; $overridden = null;
        if ($a['driver'] !== null) {
            if (isset($seen_d[$a['driver']])) { failJson('The same driver is chosen more than once' . $where . '. A driver can only be on a job once.'); }
            $seen_d[$a['driver']] = true;

            $ds = $conn->prepare("SELECT driver_id, name, experience_years, license_class, driver_type FROM drivers WHERE driver_id = ? AND status = 'active' LIMIT 1");
            $ds->bind_param('i', $a['driver']);
            $ds->execute();
            $drow = $ds->get_result()->fetch_assoc();
            $ds->close();
            if (!$drow) { failJson('A chosen driver is not active' . $where . '. Please choose another driver.'); }

            $cl = $conn->prepare("SELECT 1 FROM schedules WHERE driver_id = ? AND trip_date = ? AND status NOT IN ('cancelled')
                                    AND start_time < ? AND end_time > ? LIMIT 1");
            $cl->bind_param('isss', $a['driver'], $trip_date, $end_time, $start_time);
            $cl->execute();
            $clash = (bool)$cl->get_result()->fetch_row();
            $cl->close();
            if ($clash) {
                if (mb_strlen($override_reason) < 5) {
                    failJson($drow['name'] . ' already has a task at this time' . $where . '. Choose another driver, or assign anyway with a reason (at least 5 characters).', ['needs_reason' => true]);
                }
                $overridden = $override_reason;
                $overrides[$drow['name']] = $override_reason;
            }
            if ($vehicle_type !== null && !driverHasLicense($drow['license_class'] ?? null, requiredLicenseClasses($vehicle_type))) {
                failJson($drow['name'] . ' does not hold the licence class needed for this vehicle' . $where . '. Please choose another driver.');
            }
            if (($drow['driver_type'] ?? 'regular') !== $trip_type) {
                failJson($drow['name'] . ' is a ' . (($drow['driver_type'] ?? 'regular') === 'top_management' ? 'Top Management' : 'Regular')
                    . ' driver, which does not match the trip type. Please choose another driver or change the trip type.');
            }
            if (driverLeaveOn($conn, (int)$drow['driver_id'], $trip_date)) {
                failJson($drow['name'] . ' is on leave / MC on ' . date('d M Y', strtotime($trip_date)) . '. Please choose another driver.');
            }
            $mc             = $month_counts[(int)$drow['driver_id']] ?? ['tasks' => 0, 'weekend' => 0];
            $priority_score = calculateAllocationScore($mc['tasks'], $mc['weekend'], (float)$drow['experience_years']);
            $driver_id      = (int)$drow['driver_id'];
            $driver_name    = $drow['name'];
        }
        $plan[] = ['vehicle' => $vehicle_id, 'driver' => $driver_id, 'driver_name' => $driver_name, 'score' => $priority_score,
                   'leg_no' => $leg_no, 'date' => $trip_date, 'start' => $start_time, 'end' => $end_time,
                   'destination' => $leg['destination'], 'override' => $overridden];
    }
    if ($multi && $all_vehicles_chosen && $seat_total < $passenger_count) {
        failJson('The chosen vehicles seat ' . $seat_total . ' in total, but the request has ' . $passenger_count
            . ' passengers' . $where . '. Add a bigger vehicle or another vehicle.');
    }
    return $plan;
};

$plan = [];
foreach ($legs as $li => $leg) {
    if ($li === 0) {
        $assignLeg = $assign;
    } else {
        // Legs 2..: the same vehicles; the driver per vehicle is "same as leg 1" unless the admin picked another
        $assignLeg = [];
        $picked = $_POST['leg_driver_ids'][$leg['leg_no']] ?? [];
        foreach ($assign as $j => $a) {
            $v = $picked[$j] ?? 'same';
            if ($self_drive)             { $d = null; }
            elseif ($v === 'same' || $v === '') { $d = $a['driver']; }
            else                         { $d = (int)$v > 0 ? (int)$v : null; }
            $assignLeg[] = ['vehicle' => $a['vehicle'], 'driver' => $d];
        }
    }
    foreach ($validateLeg($leg, $assignLeg, (int)$leg['leg_no']) as $row) { $plan[] = $row; }
}

// ── INSERT the schedule rows and mark the request, all or nothing ──
$schedule_ids = [];
$new_status   = isHeadAdmin() ? 'processed' : 'assigned';
try {
    $conn->begin_transaction();

    $ins = $conn->prepare(
        "INSERT INTO schedules
             (driver_id, vehicle_id, trip_date, start_time, end_time,
              destination, purpose, passenger_count, status, priority_score, created_by, notes, trip_type,
              officer_name, officer_phone, waiting_place, request_id, leg_no, self_drive, override_reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$ins) {
        throw new RuntimeException('prepare failed');
    }
    $sd = $self_drive ? 1 : 0;
    foreach ($plan as $row) {
        $driver_id      = $row['driver'];
        $vehicle_id     = $row['vehicle'];
        $priority_score = $row['score'];
        $status         = ($driver_id !== null || $self_drive) ? 'approved' : 'pending';
        $leg_dest       = $row['destination'];
        $leg_date       = $row['date'];
        $leg_start      = $row['start'];
        $leg_end        = $row['end'];
        $leg_no         = $row['leg_no'];
        $ovr            = $row['override'];
        $ins->bind_param(
            'iisssssisdisssssiiis',
            $driver_id, $vehicle_id, $leg_date, $leg_start, $leg_end,
            $leg_dest, $purpose, $passenger_count,
            $status, $priority_score, $created_by, $notes, $trip_type,
            $officer_name, $officer_phone, $waiting_place, $request_id, $leg_no, $sd, $ovr
        );
        if (!$ins->execute()) {
            throw new RuntimeException('insert failed');
        }
        $schedule_ids[] = (int)$ins->insert_id;
    }
    $ins->close();

    // The rows of a multi-vehicle leg share one job_group
    if ($multi) {
        $byLeg = [];
        foreach ($plan as $k => $row) { $byLeg[$row['leg_no']][] = $schedule_ids[$k]; }
        foreach ($byLeg as $ids) {
            if (count($ids) > 1) { $conn->query("UPDATE schedules SET job_group = {$ids[0]} WHERE schedule_id IN (" . implode(',', $ids) . ")"); }
        }
    }

    // Admin Assistant -> "Driver Assigned" (waits for the Head of Admin);
    // Head of Admin assigning it himself -> "Confirmed" (stored as 'processed') at once.
    $first_id = $schedule_ids[0];
    $upd = $conn->prepare(
        "UPDATE vehicle_requests SET status = ?, schedule_id = ?, self_drive = ?
         WHERE request_id = ? AND status = 'approved'"
    );
    $upd->bind_param('siii', $new_status, $first_id, $sd, $request_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected === 0) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'This request has already been processed by another admin.']);
        exit();
    }
    // R3: the day holds now follow the vehicles that were really assigned, on every day of the trip
    $pairs = [];
    foreach ($plan as $row) { if ($row['vehicle']) { $pairs[] = [$row['vehicle'], $row['date']]; } }
    holdVehicleDatePairs($conn, $request_id, $pairs);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'Sorry,')) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
    error_log('process_request: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to create the schedule. Please try again.']);
    exit();
}

// ── Audit log + tell the people involved (never lets a problem undo the assignment) ──
$who = [];
foreach ($plan as $row) {
    if ($row['driver_name'] && !in_array($row['driver_name'], $who, true)) { $who[] = $row['driver_name']; }
}
$detail = $self_drive ? 'Self-drive (no driver)' : ($who ? 'Drivers: ' . implode(', ', $who) : 'Vehicle set, driver to be assigned');
if (count($legs) > 1) { $detail .= '; ' . count($legs) . ' legs'; }
auditLog($conn, $new_status === 'processed' ? 'confirm' : 'assign', $request_id, $detail);
foreach ($overrides as $dn => $why) {
    auditLog($conn, 'override_conflict', $request_id, "$dn assigned although they already have a task at that time. Reason: $why");
}
if ($overrides) {
    notifyUsers($conn, headAdminUserIds($conn), "Request #$request_id: a driver with a clashing task was assigned on purpose (" . implode('; ', array_keys($overrides)) . '). See the audit log for the reason.', 'admin/audit_log.php');
}
$trip_text = '#' . $request_id . ' (' . $request['destination'] . ', ' . date('d M Y', strtotime($request['trip_date'])) . ')';
$driverText = $self_drive ? ' You will drive the vehicle yourself (self-drive).' : ($who ? ' Driver: ' . implode(', ', $who) . '.' : ' A driver will be assigned soon.');
if ($new_status === 'processed') {
    notifyUsers($conn, [(int)$request['staff_id']], "Your request $trip_text is confirmed by the Head of Admin." . $driverText, 'staff/my_requests.php');
} else {
    notifyUsers($conn, [(int)$request['staff_id']], "A vehicle was assigned to your request $trip_text." . $driverText . ' It is waiting for the Head of Admin to confirm.', 'staff/my_requests.php');
    notifyUsers($conn, headAdminUserIds($conn), "Request $trip_text has a driver assigned and needs your approval.", 'admin/approve_assignments.php');
}

// ── Tell the drivers once the trip is Confirmed (never lets an e-mail problem undo the assignment) ──
$email_note = '';
$mail_ids = [];
if ($new_status === 'processed') {
    foreach ($plan as $k => $row) {
        if ($row['driver'] !== null) { $mail_ids[] = $schedule_ids[$k]; }
    }
}
if ($mail_ids) {
    try {
        $email_note = emailSummary(notifyDriversAssigned($conn, $mail_ids));
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

$assigned_names = array_values(array_unique(array_filter(array_column($plan, 'driver_name'))));
echo json_encode([
    'success'         => true,
    'message'         => $new_status === 'processed' ? 'Schedule created and confirmed.' : 'Schedule created. It now waits for the Head of Admin to confirm.',
    'request_status'  => $new_status,
    'schedule_id'     => $schedule_ids[0],
    'schedule_ids'    => $schedule_ids,
    'self_drive'      => $self_drive,
    'driver_assigned' => count($assigned_names) > 0,
    'driver_name'     => $assigned_names ? implode(', ', $assigned_names) : null,
    'drivers_missing' => $self_drive ? 0 : count(array_filter($plan, fn($r) => $r['driver'] === null)),
    'email'           => $email_note,
]);
