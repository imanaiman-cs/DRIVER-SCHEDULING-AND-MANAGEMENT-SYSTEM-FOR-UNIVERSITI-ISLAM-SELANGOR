<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/add_schedule.php  –  Create New Schedule
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/mailer.php';
requireAdmin();

$page_title   = 'Create Schedule';
$current_page = 'create_schedule.php';

$errors  = [];
$success = false;
$old     = [];          // repopulate form on error

// ── Fetch available vehicles ─────────────────────────────────
$vehicles_result = $conn->query(
    "SELECT vehicle_id, plate_number, vehicle_type, capacity, brand, model
     FROM vehicles WHERE status = 'available' ORDER BY vehicle_type, plate_number"
);
$vehicles = $vehicles_result ? $vehicles_result->fetch_all(MYSQLI_ASSOC) : [];

// ── Fetch active drivers with allocation scores ──────────────
// Scores are based on the trip month (current month until a trip date is known).
function loadScoredDrivers(mysqli $conn, string $month): array
{
    $result  = $conn->query(
        "SELECT driver_id, name, employee_id, experience_years, license_class, driver_type
         FROM drivers WHERE status = 'active' ORDER BY name ASC"
    );
    $drivers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $counts  = getMonthlyTaskCounts($conn, $month);
    foreach ($drivers as &$d) {
        $c = $counts[(int)$d['driver_id']] ?? ['tasks' => 0, 'weekend' => 0];
        $d['month_tasks']   = $c['tasks'];
        $d['month_weekend'] = $c['weekend'];
        $d['priority_score'] = calculateAllocationScore(
            $c['tasks'],
            $c['weekend'],
            (float)$d['experience_years']
        );
    }
    unset($d);
    // Highest allocation score first (recommended driver on top)
    usort($drivers, function ($a, $b) {
        return ($b['priority_score'] <=> $a['priority_score']) ?: strcmp($a['name'], $b['name']);
    });
    return $drivers;
}

$drivers = loadScoredDrivers($conn, date('Y-m'));

// ── "Add a driver to an existing job" mode (?add_to=<schedule id>) ──
// The job details are copied from the existing job and cannot be changed
// here; the new driver becomes part of the same multi-driver job.
$add_to = (int)($_GET['add_to'] ?? $_POST['add_to'] ?? 0);
$lead   = null;
if ($add_to > 0) {
    $ls = $conn->prepare("SELECT * FROM schedules WHERE schedule_id = ? AND status IN ('pending','approved')");
    $ls->bind_param('i', $add_to);
    $ls->execute();
    $lead = $ls->get_result()->fetch_assoc();
    $ls->close();
    if (!$lead) {
        setFlash('danger', 'Another driver can only be added to a job that is still pending or approved.');
        header('Location: ' . SITE_URL . '/admin/schedules.php');
        exit();
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $old = [
            'trip_date' => $lead['trip_date'], 'start_time' => substr($lead['start_time'], 0, 5),
            'end_time' => substr($lead['end_time'], 0, 5), 'destination' => $lead['destination'],
            'purpose' => $lead['purpose'], 'passenger_count' => $lead['passenger_count'],
            'officer_name' => $lead['officer_name'], 'officer_phone' => $lead['officer_phone'],
            'waiting_place' => $lead['waiting_place'], 'trip_type' => $lead['trip_type'],
            'status' => $lead['status'],
        ];
    }
}
$driver_names = [];
foreach ($drivers as $d) { $driver_names[(int)$d['driver_id']] = $d['name']; }

// Top Management officers and the one driver dedicated to each
$top_officers = [];
$to = $conn->query("SELECT driver_id, name, assigned_to FROM drivers
                    WHERE status = 'active' AND driver_type = 'top_management' AND assigned_to IS NOT NULL AND assigned_to <> ''
                    ORDER BY assigned_to");
if ($to) { $top_officers = $to->fetch_all(MYSQLI_ASSOC); }

// ── POST handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old = $_POST;

    // Sanitise & validate
    $trip_date       = trim($_POST['trip_date']       ?? '');
    $start_time      = trim($_POST['start_time']      ?? '');
    $end_time        = trim($_POST['end_time']         ?? '');
    $destination     = trim($_POST['destination']      ?? '');
    $purpose         = trim($_POST['purpose']          ?? '');
    $passenger_count = (int)($_POST['passenger_count'] ?? 1);
    $vehicle_id      = (int)($_POST['vehicle_id']      ?? 0);
    $driver_id       = (int)($_POST['driver_id']       ?? 0);
    $status          = trim($_POST['status']           ?? 'pending');
    $notes           = trim($_POST['notes']            ?? '');
    $trip_type       = trim($_POST['trip_type']        ?? 'regular');
    $officer_name    = trim($_POST['officer_name']     ?? '');
    $officer_phone   = trim($_POST['officer_phone']    ?? '');
    $waiting_place   = trim($_POST['waiting_place']    ?? '');

    // Adding a driver to an existing job: the job details come from that job
    if ($lead) {
        $trip_date       = $lead['trip_date'];
        $start_time      = substr($lead['start_time'], 0, 5);
        $end_time        = substr($lead['end_time'], 0, 5);
        $destination     = $lead['destination'];
        $purpose         = (string)$lead['purpose'];
        $passenger_count = (int)$lead['passenger_count'];
        $officer_name    = (string)$lead['officer_name'];
        $officer_phone   = (string)$lead['officer_phone'];
        $waiting_place   = (string)$lead['waiting_place'];
        $trip_type       = $lead['trip_type'];
    }

    // Team of drivers for this job: the first pair plus up to 4 extra pairs
    $team_n = $lead ? 1 : max(1, min(5, (int)($_POST['drivers_needed'] ?? 1)));
    $assign = [['driver' => $driver_id, 'vehicle' => $vehicle_id]];
    $extra_d = (array)($_POST['extra_driver_id']  ?? []);
    $extra_v = (array)($_POST['extra_vehicle_id'] ?? []);
    for ($i = 0; $i < $team_n - 1; $i++) {
        $assign[] = ['driver' => (int)($extra_d[$i] ?? 0), 'vehicle' => (int)($extra_v[$i] ?? 0)];
    }

    if (empty($trip_date))   $errors['trip_date']   = 'Choose the trip date.';
    if (empty($start_time))  $errors['start_time']  = 'Enter the start time, for example 08:30.';
    if (empty($end_time))    $errors['end_time']    = 'Enter the end time, for example 17:00.';
    if (empty($destination)) $errors['destination'] = 'Enter the destination, for example Kuala Lumpur International Airport.';
    if ($passenger_count < 1) $passenger_count = 1;
    if ($officer_phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $officer_phone)) {
        $errors['officer_phone'] = 'Officer phone number can only contain digits, spaces, + - and ( ), and must be 7 to 20 characters long, for example 011-2835 4792.';
    }

    if (!empty($start_time) && !empty($end_time) && $end_time <= $start_time) {
        $errors['end_time'] = 'End time must be later than the start time. Choose a later end time, or move the start time earlier.';
    }
    if (!empty($trip_date) && $trip_date < date('Y-m-d')) {
        $errors['trip_date'] = 'Trip date cannot be in the past. Choose today or a later date.';
    }
    if (!in_array($status, ['pending','approved','in_progress','completed','cancelled'])) {
        $status = 'pending';
    }
    if (!in_array($trip_type, ['regular','top_management'])) {
        $trip_type = 'regular';
    }

    // ── Team rules: no driver or vehicle twice in the same job ──
    $valid_vehicle_ids = array_map('intval', array_column($vehicles, 'vehicle_id'));
    if ($team_n > 1 || $lead) {
        $label = function (int $i) use ($team_n) { return $team_n > 1 ? 'Driver ' . ($i + 1) : 'The new driver'; };
        $seen_d = [];
        foreach ($assign as $i => $a) {
            if ($a['driver'] <= 0) {
                $errors['team'] = $lead
                    ? 'Choose the driver to add to this job.'
                    : 'This job needs ' . $team_n . ' drivers. Choose a driver for every row, or lower "Drivers needed".';
                break;
            }
            if (!isset($driver_names[$a['driver']])) {
                $errors['team'] = $label($i) . ' is not an active driver. Choose a driver from the list.';
                break;
            }
            if (isset($seen_d[$a['driver']])) {
                $errors['team'] = $driver_names[$a['driver']] . ' is selected more than once. The same driver cannot be assigned twice to the same job; choose a different driver.';
                break;
            }
            $seen_d[$a['driver']] = true;
            if ($a['vehicle'] > 0) {
                if (!in_array($a['vehicle'], $valid_vehicle_ids, true)) {
                    $errors['team'] = $label($i) . ' has a vehicle that is not available. Choose a vehicle from the list.';
                    break;
                }
            }
        }
        // Adding to an existing job: the driver must not already be on it
        if ($lead && empty($errors['team']) && $driver_id > 0) {
            $gid = (int)($lead['job_group'] ?: $lead['schedule_id']);
            $dup = $conn->prepare(
                "SELECT 1 FROM schedules
                 WHERE (schedule_id = ? OR job_group = ?) AND driver_id = ? AND status <> 'cancelled' LIMIT 1"
            );
            $dup->bind_param('iii', $lead['schedule_id'], $gid, $driver_id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $errors['team'] = ($driver_names[$driver_id] ?? 'This driver') . ' is already on this job. Choose a different driver.';
            }
            $dup->close();
        }
    }

    // ── Duplicate job: same day, start time and destination ──
    if (empty($errors) && !$lead && $trip_date !== '' && $start_time !== '' && $destination !== '') {
        $same = findDuplicateJob($conn, $trip_date, $start_time, $destination);
        if ($same) {
            $errors['destination'] = 'This job is already scheduled (Schedule #' . str_pad($same['schedule_id'], 4, '0', STR_PAD_LEFT)
                . ': same destination, date and start time). The same job cannot be entered twice. If it needs another driver, use "Add driver" on that schedule instead.';
        }
    }

    // ── Conflict check – vehicle ─────────────────────────────
    // (drivers of the same job may share a vehicle, so the job being added to is ignored)
    if (empty($errors) && $vehicle_id > 0) {
        $share_ids = [];
        if ($lead) {
            $gid = (int)($lead['job_group'] ?: $lead['schedule_id']);
            $gq  = $conn->query("SELECT schedule_id FROM schedules WHERE schedule_id = " . (int)$lead['schedule_id'] . " OR job_group = {$gid}");
            while ($gq && ($gr = $gq->fetch_assoc())) { $share_ids[] = (int)$gr['schedule_id']; }
        }
        if (findResourceConflict($conn, 'vehicle_id', $vehicle_id, $trip_date, $start_time, $end_time, $share_ids)) {
            $errors['vehicle_id'] = 'The selected vehicle already has a schedule that overlaps this time slot. Choose another vehicle, or change the date or times.';
        }
    }

    // ── Conflict check – driver ──────────────────────────────
    if (empty($errors) && $driver_id > 0) {
        $conflict_sql = "SELECT schedule_id FROM schedules
                         WHERE driver_id = ? AND trip_date = ?
                           AND status NOT IN ('cancelled')
                           AND (
                               (start_time < ? AND end_time > ?) OR
                               (start_time < ? AND end_time > ?) OR
                               (start_time >= ? AND end_time <= ?)
                           )
                         LIMIT 1";
        $cs = $conn->prepare($conflict_sql);
        $cs->bind_param('isssssss', $driver_id, $trip_date,
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time
        );
        $cs->execute();
        $cs->store_result();
        if ($cs->num_rows > 0) {
            $errors['driver_id'] = 'The selected driver already has a schedule that overlaps this time slot. Choose another driver, change the date or times, or leave the driver blank to auto-assign.';
        }
        $cs->close();
    }

    // ── Conflict check – the other drivers/vehicles of a team job ──
    if (empty($errors)) {
        foreach ($assign as $i => $a) {
            if ($i === 0) { continue; }          // first pair is checked above
            $who = 'Driver ' . ($i + 1);
            if ($a['driver'] > 0 && findResourceConflict($conn, 'driver_id', $a['driver'], $trip_date, $start_time, $end_time)) {
                $errors['team'] = $who . ' (' . ($driver_names[$a['driver']] ?? '') . ') already has a schedule that overlaps this time slot. Choose another driver.';
                break;
            }
            if ($a['vehicle'] > 0 && findResourceConflict($conn, 'vehicle_id', $a['vehicle'], $trip_date, $start_time, $end_time)) {
                $errors['team'] = $who . '\'s vehicle already has a schedule that overlaps this time slot. Choose another vehicle.';
                break;
            }
        }
    }

    // ── Allocation score (for the trip month) ────────────────
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trip_date) && substr($trip_date, 0, 7) !== date('Y-m')) {
        $drivers = loadScoredDrivers($conn, substr($trip_date, 0, 7));
    }
    $score_of = function (int $driver) use ($drivers): float {
        foreach ($drivers as $d) {
            if ((int)$d['driver_id'] === $driver) { return (float)$d['priority_score']; }
        }
        return 0.00;
    };

    // ── INSERT (one row per driver; rows of a team job share job_group) ──
    if (empty($errors)) {
        $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $officer_name_db  = $officer_name  === '' ? null : $officer_name;
        $officer_phone_db = $officer_phone === '' ? null : $officer_phone;
        $waiting_place_db = $waiting_place === '' ? null : $waiting_place;
        $new_ids = [];

        try {
            $conn->begin_transaction();
            $ins = $conn->prepare(
                "INSERT INTO schedules
                     (driver_id, vehicle_id, trip_date, start_time, end_time,
                      destination, purpose, passenger_count, status, priority_score, created_by, notes, trip_type,
                      officer_name, officer_phone, waiting_place)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($assign as $a) {
                $vid = $a['vehicle'] > 0 ? $a['vehicle'] : null;
                $did = $a['driver']  > 0 ? $a['driver']  : null;
                $priority_score = $did !== null ? $score_of($did) : 0.00;
                $ins->bind_param(
                    'iisssssisdisssss',
                    $did, $vid, $trip_date, $start_time, $end_time,
                    $destination, $purpose, $passenger_count,
                    $status, $priority_score, $uid, $notes, $trip_type,
                    $officer_name_db, $officer_phone_db, $waiting_place_db
                );
                if (!$ins->execute()) {
                    throw new RuntimeException($conn->error);
                }
                $new_ids[] = (int)$ins->insert_id;
            }
            $ins->close();

            // Link the rows of a team job together
            $group = null;
            if ($lead) {
                $group = (int)($lead['job_group'] ?: $lead['schedule_id']);
                if (!$lead['job_group']) {
                    $conn->query("UPDATE schedules SET job_group = {$group} WHERE schedule_id = " . (int)$lead['schedule_id']);
                }
            } elseif (count($new_ids) > 1) {
                $group = $new_ids[0];
            }
            if ($group !== null) {
                $conn->query("UPDATE schedules SET job_group = {$group} WHERE schedule_id IN (" . implode(',', $new_ids) . ")");
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('add_schedule: ' . $e->getMessage());
            $new_ids = [];
            $errors['db'] = 'The schedule could not be saved because of a system error. Your entries are still on this page, so please try again. If it keeps happening, contact the system administrator.';
        }

        if ($new_ids) {
            // E-mail the drivers that were chosen (never breaks the save)
            $email_note = '';
            $mail_ids = [];
            foreach ($assign as $k => $a) {
                if ($a['driver'] > 0 && $status !== 'cancelled') { $mail_ids[] = $new_ids[$k]; }
            }
            if ($mail_ids) {
                try {
                    $email_results = notifyDriversAssigned($conn, $mail_ids);
                    $email_note    = emailSummary($email_results);
                } catch (Throwable $e) {
                    error_log($e->getMessage());
                }
            }

            $first = $new_ids[0];
            $auto  = (!$lead && $assign[0]['driver'] <= 0);
            if ($lead) {
                $msg = "Driver added to the job to <strong>" . htmlspecialchars($destination) . "</strong> (Schedule #" . str_pad($first, 4, '0', STR_PAD_LEFT) . ").";
            } elseif (count($new_ids) > 1) {
                $msg = "Job to <strong>" . htmlspecialchars($destination) . "</strong> created with " . count($new_ids) . " drivers.";
            } else {
                $msg = "Schedule #" . str_pad($first, 4, '0', STR_PAD_LEFT) . " to <strong>" . htmlspecialchars($destination) . "</strong> created successfully." . ($auto ? " It will be auto-assigned." : "");
            }
            setFlash('success', $msg . ($email_note !== '' ? ' ' . htmlspecialchars($email_note) : ''));
            header('Location: ' . SITE_URL . '/admin/schedules.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        .page-header {
            background: linear-gradient(135deg, #0b5d3b 0%, #15804f 100%);
            border-radius: 14px;
            color: #fff;
            padding: 1.6rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(11,93,59,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .3rem 0 0; opacity: .8; font-size: .88rem; }
        .form-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(11,93,59,.10);
        }
        .section-title {
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #0b5d3b;
            font-weight: 700;
            border-bottom: 2px solid #e8f0fe;
            padding-bottom: .5rem;
            margin-bottom: 1.25rem;
        }
        .conflict-badge {
            display: none;
            font-size: .75rem;
        }
        .priority-chip {
            display: inline-block;
            padding: .15em .55em;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 600;
        }
        .priority-high   { background: #d1fae5; color: #065f46; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-low    { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1><i class="fas fa-calendar-plus me-2" aria-hidden="true"></i><?php echo $lead ? 'Add Driver to Job' : 'Create Schedule'; ?></h1>
            <p><?php echo $lead
                ? 'Another driver for the job to ' . htmlspecialchars($lead['destination']) . ' (Schedule #' . str_pad($lead['schedule_id'], 4, '0', STR_PAD_LEFT) . ').'
                : 'Add a new trip schedule to the system.'; ?></p>
        </div>
        <a href="<?php echo SITE_URL; ?>/admin/schedules.php" class="btn btn-light fw-semibold text-primary">
            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to Schedules
        </a>
    </div>

    <?php if (isset($errors['db'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-circle-exclamation me-2" aria-hidden="true"></i>
        <?php echo htmlspecialchars($errors['db']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    <?php
    // Field => [plain-language name, id of the control the summary link jumps to]
    $error_fields = [
        'trip_date'      => ['Trip date',            'trip_date'],
        'start_time'     => ['Start time',           'start_time'],
        'end_time'       => ['End time',             'end_time'],
        'destination'    => ['Destination',          'destination'],
        'officer_phone'  => ['Officer phone number', 'officer_phone'],
        'vehicle_id'     => ['Vehicle',              'vehicle_id'],
        'driver_id'      => ['Driver',               'driver_id'],
        'team'           => ['Drivers',              'teamCard'],
    ];
    $summary_errors = array_intersect_key($error_fields, $errors);
    ?>
    <?php if (!empty($summary_errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" id="errorSummary">
        <i class="fas fa-circle-exclamation me-2" aria-hidden="true"></i>
        <strong>Nothing was saved. Please fix <?php echo count($summary_errors) === 1 ? 'this problem' : 'these ' . count($summary_errors) . ' problems'; ?> and submit again:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($summary_errors as $key => [$field_name, $field_id]): ?>
            <li><a href="#<?php echo $field_id; ?>" class="alert-link"><?php echo htmlspecialchars($field_name); ?></a>: <?php echo htmlspecialchars($errors[$key]); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if ($lead): ?>
    <div class="alert alert-info" role="note">
        <i class="fas fa-circle-info me-2" aria-hidden="true"></i>
        The job details are taken from the existing job and cannot be changed here. Choose the extra driver
        (and vehicle) below. To change the date, time or destination, edit the original schedule.
    </div>
    <?php endif; ?>

    <form method="POST" id="scheduleForm" novalidate>
        <?php if ($lead): ?><input type="hidden" name="add_to" value="<?php echo (int)$lead['schedule_id']; ?>"><?php endif; ?>

        <div class="row g-4">

            <!-- Left column: trip details + vehicle -->
            <div class="col-lg-8">

                <!-- Trip Details -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title"><i class="fas fa-map-marker-alt me-2" aria-hidden="true"></i>Trip Details</div>
                        <p class="required-legend"><span class="req">*</span> Required field</p>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="trip_date">Trip Date <span class="req" aria-hidden="true">*</span></label>
                                <input type="date" class="form-control <?php echo isset($errors['trip_date']) ? 'is-invalid' : ''; ?>" id="trip_date" name="trip_date"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       value="<?php echo htmlspecialchars($old['trip_date'] ?? ''); ?>"
                                       required
                                       aria-required="true"
                                       <?php echo isset($errors['trip_date']) ? 'aria-invalid="true" aria-describedby="trip_date_error"' : ''; ?>>
                                <div class="invalid-feedback" id="trip_date_error"><?php echo htmlspecialchars($errors['trip_date'] ?? 'Choose a trip date (today or later).'); ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="start_time">Start Time <span class="req" aria-hidden="true">*</span></label>
                                <input type="time" class="form-control <?php echo isset($errors['start_time']) ? 'is-invalid' : ''; ?>" id="start_time" name="start_time"
                                       value="<?php echo htmlspecialchars($old['start_time'] ?? ''); ?>"
                                       required
                                       aria-required="true"
                                       <?php echo isset($errors['start_time']) ? 'aria-invalid="true" aria-describedby="start_time_error"' : ''; ?>>
                                <div class="invalid-feedback" id="start_time_error"><?php echo htmlspecialchars($errors['start_time'] ?? 'Enter the start time, for example 08:30.'); ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="end_time">End Time <span class="req" aria-hidden="true">*</span></label>
                                <input type="time" class="form-control <?php echo isset($errors['end_time']) ? 'is-invalid' : ''; ?>" id="end_time" name="end_time"
                                       value="<?php echo htmlspecialchars($old['end_time'] ?? ''); ?>"
                                       required
                                       aria-required="true"
                                       <?php echo isset($errors['end_time']) ? 'aria-invalid="true" aria-describedby="end_time_error timeError"' : 'aria-describedby="timeError"'; ?>>
                                <?php if (isset($errors['end_time'])): ?><div class="invalid-feedback" id="end_time_error"><?php echo htmlspecialchars($errors['end_time']); ?></div><?php endif; ?>
                                <div id="timeError" class="text-danger small mt-1" style="display:none;" role="alert">
                                    End time must be after start time.
                                </div>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label fw-semibold" for="destination">Destination <span class="req" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control <?php echo isset($errors['destination']) ? 'is-invalid' : ''; ?>" id="destination" name="destination"
                                       placeholder="e.g., Kuala Lumpur International Airport"
                                       value="<?php echo htmlspecialchars($old['destination'] ?? ''); ?>"
                                       required
                                       aria-required="true"
                                       <?php echo isset($errors['destination']) ? 'aria-invalid="true" aria-describedby="destination_error"' : ''; ?>>
                                <div class="invalid-feedback" id="destination_error"><?php echo htmlspecialchars($errors['destination'] ?? 'Enter the destination, for example Kuala Lumpur International Airport.'); ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="passenger_count">Passengers</label>
                                <input type="number" class="form-control" id="passenger_count" name="passenger_count"
                                       min="1" max="100"
                                       value="<?php echo (int)($old['passenger_count'] ?? 1); ?>"
                                       inputmode="numeric"
                                       step="1"
                                       aria-describedby="passenger_count_help">
                                <div class="invalid-feedback" id="passenger_count_error">Enter a whole number of passengers from 1 to 100.</div>
                                <div class="form-text" id="passenger_count_help">Whole number, 1&ndash;100.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold" for="purpose">Purpose</label>
                                <input type="text" class="form-control" id="purpose" name="purpose"
                                       placeholder="e.g., Faculty field trip, Airport transfer"
                                       value="<?php echo htmlspecialchars($old['purpose'] ?? ''); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="officer_name">Officer Name(s)</label>
                                <input type="text" class="form-control" id="officer_name" name="officer_name"
                                       maxlength="255" placeholder="e.g. En. Faruq, En. Amir"
                                       value="<?php echo htmlspecialchars($old['officer_name'] ?? ''); ?>"
                                       autocomplete="off"
                                       aria-describedby="officer_name_help">
                                <div class="form-text" id="officer_name_help">Person(s) the driver will serve</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="officer_phone">Officer Phone No.</label>
                                <input type="tel" class="form-control <?php echo isset($errors['officer_phone']) ? 'is-invalid' : ''; ?>" id="officer_phone" name="officer_phone"
                                       maxlength="50" placeholder="e.g. 011-2835 4792"
                                       pattern="[0-9+\-\s\(\)]{7,20}"
                                       value="<?php echo htmlspecialchars($old['officer_phone'] ?? ''); ?>"
                                       inputmode="tel"
                                       autocomplete="off"
                                       <?php echo isset($errors['officer_phone']) ? 'aria-invalid="true" aria-describedby="officer_phone_error officer_phone_help"' : 'aria-describedby="officer_phone_help"'; ?>>
                                <div class="invalid-feedback" id="officer_phone_error"><?php echo htmlspecialchars($errors['officer_phone'] ?? 'Use digits, spaces, + - and ( ) only, 7 to 20 characters, for example 011-2835 4792.'); ?></div>
                                <div class="form-text" id="officer_phone_help">Digits, spaces, + - and ( ) only; 7&ndash;20 characters.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="waiting_place">Waiting Place</label>
                                <input type="text" class="form-control" id="waiting_place" name="waiting_place"
                                       maxlength="150" placeholder="e.g. Stor UIS"
                                       value="<?php echo htmlspecialchars($old['waiting_place'] ?? ''); ?>"
                                       autocomplete="off">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach (['pending','approved','in_progress','completed','cancelled'] as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo (($old['status'] ?? 'pending') === $st) ? 'selected' : ''; ?>>
                                        <?php echo ucwords(str_replace('_',' ', $st)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="trip_type">Trip Type</label>
                                <select class="form-select" id="trip_type" name="trip_type"
                                        aria-describedby="trip_type_help">
                                    <option value="regular"        <?php echo (($old['trip_type'] ?? 'regular') === 'regular')        ? 'selected' : ''; ?>>Regular</option>
                                    <option value="top_management" <?php echo (($old['trip_type'] ?? 'regular') === 'top_management') ? 'selected' : ''; ?>>Top Management</option>
                                </select>
                                <div class="form-text" id="trip_type_help">Top Management is for trips that carry a Top Management officer.</div>
                            </div>

                            <div class="col-12" id="topOfficerGroup" style="display:none;">
                                <label class="form-label fw-semibold" for="top_officer">Top Management officer travelling</label>
                                <select class="form-select" id="top_officer" aria-describedby="top_officer_help">
                                    <option value="">-- Choose officer (selects their driver) --</option>
                                    <?php foreach ($top_officers as $o): ?>
                                    <option value="<?php echo (int)$o['driver_id']; ?>" data-officer="<?php echo htmlspecialchars($o['assigned_to']); ?>">
                                        <?php echo htmlspecialchars($o['assigned_to'] . ' — driver: ' . $o['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" id="top_officer_help">Each Top Management officer has one dedicated driver. Choosing the officer selects that driver and fills in the officer name.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold" for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"
                                          placeholder="Any additional information..."><?php echo htmlspecialchars($old['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vehicle Selection -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title"><i class="fas fa-car me-2" aria-hidden="true"></i>Vehicle</div>

                        <!-- Conflict warning -->
                        <div id="vehicleConflictAlert" class="alert alert-danger py-2 mb-3" style="display:none;">
                            <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            <strong>Vehicle Conflict:</strong> <span id="vehicleConflictMsg"></span>
                        </div>

                        <label for="vehicle_id" class="visually-hidden">Vehicle (optional)</label>
                        <select class="form-select <?php echo isset($errors['vehicle_id']) ? 'is-invalid' : ''; ?>" id="vehicle_id" name="vehicle_id"
                                <?php echo isset($errors['vehicle_id']) ? 'aria-invalid="true" aria-describedby="vehicle_id_error vehicle_help"' : 'aria-describedby="vehicle_help"'; ?>>
                            <option value="">-- No vehicle selected --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?php echo (int)$v['vehicle_id']; ?>"
                                data-capacity="<?php echo (int)$v['capacity']; ?>"
                                <?php echo ((int)($old['vehicle_id'] ?? 0) === (int)$v['vehicle_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($v['plate_number'] . ' — ' . $v['vehicle_type']
                                    . ($v['brand'] ? ' (' . $v['brand'] . ($v['model'] ? ' ' . $v['model'] : '') . ')' : '')
                                    . ' — ' . $v['capacity'] . ' pax'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['vehicle_id'])): ?><div class="invalid-feedback" id="vehicle_id_error"><?php echo htmlspecialchars($errors['vehicle_id']); ?></div><?php endif; ?>
                        <div class="form-text" id="vehicle_help">Only available vehicles are shown.</div>
                    </div>
                </div>

            </div><!-- /col-lg-8 -->

            <!-- Right column: driver + submit -->
            <div class="col-lg-4">

                <!-- Driver Selection -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title" id="driverCardTitle"><i class="fas fa-user me-2" aria-hidden="true"></i><span><?php echo $lead ? 'Driver to add' : 'Driver (optional)'; ?></span></div>

                        <!-- Conflict warning -->
                        <div id="driverConflictAlert" class="alert alert-danger py-2 mb-3" style="display:none;">
                            <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            <strong>Driver Conflict:</strong> <span id="driverConflictMsg"></span>
                        </div>

                        <!-- Available drivers live list -->
                        <div id="availableDriversPanel" class="mb-3" style="display:none;">
                            <div class="small fw-semibold text-success mb-1">
                                <i class="fas fa-circle-check me-1" aria-hidden="true"></i>
                                Available drivers for this slot:
                            </div>
                            <div id="availableDriversList" class="small text-muted"></div>
                        </div>

                        <label for="driver_id" class="visually-hidden">Driver (optional)</label>
                        <select class="form-select <?php echo isset($errors['driver_id']) ? 'is-invalid' : ''; ?>" id="driver_id" name="driver_id"
                                <?php echo isset($errors['driver_id']) ? 'aria-invalid="true" aria-describedby="driver_id_error"' : ''; ?>>
                            <option value=""><?php echo $lead ? '-- Choose driver --' : '-- Auto-assign (leave blank) --'; ?></option>
                            <?php foreach ($drivers as $i => $d): ?>
                            <option value="<?php echo (int)$d['driver_id']; ?>"
                                data-score="<?php echo $d['priority_score']; ?>"
                                data-tasks="<?php echo (int)$d['month_tasks']; ?>"
                                data-weekend="<?php echo (int)$d['month_weekend']; ?>"
                                <?php echo ((int)($old['driver_id'] ?? 0) === (int)$d['driver_id']) ? 'selected' : ''; ?>>
                                <?php echo $i === 0 ? '★ Recommended: ' : ''; ?><?php echo htmlspecialchars($d['name']); ?> — Score: <?php echo number_format($d['priority_score'], 2); ?> (<?php echo (int)$d['month_tasks']; ?> tasks this month)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['driver_id'])): ?><div class="invalid-feedback" id="driver_id_error"><?php echo htmlspecialchars($errors['driver_id']); ?></div><?php endif; ?>

                        <div id="autoAssignNotice" class="alert alert-info py-2 mt-2 small">
                            <i class="fas fa-wand-magic-sparkles me-1" aria-hidden="true"></i>
                            No driver selected. The system will auto-assign the best available driver.
                        </div>
                        <div id="driverScorePanel" class="mt-2 small" style="display:none;"></div>
                    </div>
                </div>

<?php
    // Driver / vehicle <option> lists reused by the team rows
    $driver_option_html = function (int $selected) use ($drivers): string {
        $h = '<option value="">-- Choose driver --</option>';
        foreach ($drivers as $i => $d) {
            $h .= '<option value="' . (int)$d['driver_id'] . '"' . ((int)$d['driver_id'] === $selected ? ' selected' : '') . '>'
                . ($i === 0 ? '★ ' : '') . htmlspecialchars($d['name']) . ' — Score ' . number_format($d['priority_score'], 2)
                . ' (' . (int)$d['month_tasks'] . ' tasks)</option>';
        }
        return $h;
    };
    $vehicle_option_html = function (int $selected) use ($vehicles): string {
        $h = '<option value="">-- No vehicle selected --</option>';
        foreach ($vehicles as $v) {
            $h .= '<option value="' . (int)$v['vehicle_id'] . '"' . ((int)$v['vehicle_id'] === $selected ? ' selected' : '') . '>'
                . htmlspecialchars($v['plate_number'] . ' — ' . $v['vehicle_type'] . ' — ' . $v['capacity'] . ' pax') . '</option>';
        }
        return $h;
    };
    $team_n_old = $lead ? 1 : max(1, min(5, (int)($old['drivers_needed'] ?? 1)));
?>
                <?php if (!$lead): ?>
                <!-- Team job: only for jobs that need more than one driver -->
                <div class="card form-card mb-4" id="teamCard" tabindex="-1"
                     style="<?php echo isset($errors['team']) ? 'border:2px solid #dc3545;' : ''; ?>">
                    <div class="card-body p-4">
                        <div class="section-title"><i class="fas fa-users me-2" aria-hidden="true"></i>Drivers needed</div>
                        <label for="drivers_needed" class="form-label small text-muted mb-1">
                            Most jobs need one driver. Choose more only for jobs such as seminars or events with several vehicles.
                        </label>
                        <select class="form-select" id="drivers_needed" name="drivers_needed" aria-describedby="teamHelp">
                            <?php for ($n = 1; $n <= 5; $n++): ?>
                            <option value="<?php echo $n; ?>" <?php echo $team_n_old === $n ? 'selected' : ''; ?>>
                                <?php echo $n === 1 ? '1 driver (normal job)' : $n . ' drivers'; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                        <div class="form-text" id="teamHelp">
                            For 2 or more drivers, the same driver cannot be chosen twice. Drivers may use different vehicles or share one
                            (for example a relief driver on a long trip). The first driver and vehicle are the ones chosen above.
                        </div>
                        <?php if (isset($errors['team'])): ?>
                        <div class="alert alert-danger py-2 mt-3 mb-0 small" role="alert">
                            <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo htmlspecialchars($errors['team']); ?>
                        </div>
                        <?php endif; ?>
                        <div id="teamClientWarn" class="alert alert-warning py-2 mt-3 mb-0 small" style="display:none;" role="alert"></div>

                        <div id="teamRows" class="mt-3">
                            <?php for ($i = 0; $i < 4; $i++): ?>
                            <div class="team-row border rounded-3 p-3 mb-3 bg-light" data-row="<?php echo $i; ?>" style="display:none;">
                                <div class="small fw-semibold text-primary mb-2">Driver <?php echo $i + 2; ?></div>
                                <label class="form-label small mb-1" for="extra_driver_<?php echo $i; ?>">Driver</label>
                                <select class="form-select form-select-sm mb-2 team-driver" id="extra_driver_<?php echo $i; ?>" name="extra_driver_id[]" disabled>
                                    <?php echo $driver_option_html((int)($old['extra_driver_id'][$i] ?? 0)); ?>
                                </select>
                                <label class="form-label small mb-1" for="extra_vehicle_<?php echo $i; ?>">Vehicle</label>
                                <select class="form-select form-select-sm team-vehicle" id="extra_vehicle_<?php echo $i; ?>" name="extra_vehicle_id[]" disabled>
                                    <?php echo $vehicle_option_html((int)($old['extra_vehicle_id'][$i] ?? 0)); ?>
                                </select>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <?php elseif (isset($errors['team'])): ?>
                <div class="alert alert-danger small" role="alert" id="teamCard">
                    <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i><?php echo htmlspecialchars($errors['team']); ?>
                </div>
                <?php endif; ?>

                <!-- Allocation score info -->
                <div class="card form-card mb-4" style="border-left: 4px solid #0b5d3b !important;">
                    <div class="card-body p-3">
                        <div class="fw-semibold text-primary mb-2 small">
                            <i class="fas fa-star me-1" aria-hidden="true"></i> Allocation Score
                        </div>
                        <p class="small text-muted mb-2">
                            Drivers are ranked by allocation score when auto-assigning, based on the trip month:
                        </p>
                        <code class="small d-block bg-light rounded p-2">
                            Score = (Fewer tasks×0.50)<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;+ (Fewer weekend tasks×0.30)<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;+ (Experience×0.20)
                        </code>
                        <div class="small text-muted mt-2">Fewer tasks this month 50%, fewer weekend tasks 30%, experience 20%. Scaled 0–10. Max score = 10.</div>
                    </div>
                </div>

            </div><!-- /col-lg-4 -->

        </div><!-- /row -->

        <!-- Actions: always at the bottom of the form -->
        <div class="d-flex justify-content-end align-items-center gap-2 mt-2 mb-4">
            <a href="<?php echo SITE_URL; ?>/admin/schedules.php" class="btn btn-outline-secondary px-4">
                <i class="fas fa-times me-1" aria-hidden="true"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary fw-semibold px-5">
                <i class="fas fa-floppy-disk me-2" aria-hidden="true"></i> Save Schedule
            </button>
        </div>
    </form>

</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    const SITE_URL = '<?php echo SITE_URL; ?>';
    let conflictTimer = null;

    // ── Toggle auto-assign notice ────────────────────────────
    function updateDriverUI() {
        const driverId = $('#driver_id').val();
        if (driverId) {
            $('#autoAssignNotice').hide();
            const opt     = $('#driver_id option:selected');
            const score   = parseFloat(opt.data('score') || 0);
            const tasks   = parseInt(opt.data('tasks') || 0, 10);
            const weekend = parseInt(opt.data('weekend') || 0, 10);
            const cls     = score >= 7 ? 'priority-high' : (score >= 4 ? 'priority-medium' : 'priority-low');
            $('#driverScorePanel').html(
                'Allocation Score: <span class="priority-chip ' + cls + '">' + score.toFixed(2) + ' / 10</span>'
                + '<div class="text-muted mt-1">' + tasks + ' tasks this month (' + weekend + ' weekend)</div>'
            ).show();
        } else {
            $('#autoAssignNotice').show();
            $('#driverScorePanel').hide();
        }
    }

    $('#driver_id').on('change', updateDriverUI);
    updateDriverUI();

    // ── Team job: show one row per extra driver ──────────────
    const teamSelect = document.getElementById('drivers_needed');
    function syncTeamRows() {
        const n = teamSelect ? parseInt(teamSelect.value, 10) : 1;
        document.querySelectorAll('#teamRows .team-row').forEach(function (row) {
            const show = parseInt(row.getAttribute('data-row'), 10) < n - 1;
            row.style.display = show ? '' : 'none';
            row.querySelectorAll('select').forEach(function (sel) { sel.disabled = !show; });
        });
        const first = document.getElementById('driver_id');
        if (first) {
            first.required = n > 1 || <?php echo $lead ? 'true' : 'false'; ?>;
            first.options[0].textContent = n > 1 ? '-- Choose driver --' : '<?php echo $lead ? '-- Choose driver --' : '-- Auto-assign (leave blank) --'; ?>';
        }
        const title = document.querySelector('#driverCardTitle span');
        if (title && !<?php echo $lead ? 'true' : 'false'; ?>) { title.textContent = n > 1 ? 'Driver 1' : 'Driver (optional)'; }
        if (n > 1) { $('#autoAssignNotice').hide(); }
        else if (!$('#driver_id').val()) { $('#autoAssignNotice').show(); }
        syncTeamChoices();
    }

    // The same driver cannot be picked twice in one job:
    // grey out what another row already chose, and warn if it still happens.
    function syncTeamChoices() {
        ['team-driver'].forEach(function (cls) {
            const primary = document.getElementById('driver_id');
            const selects = [primary].concat(Array.from(document.querySelectorAll('.' + cls))).filter(function (el) {
                return el && !el.disabled;
            });
            const chosen = selects.map(function (el) { return el.value; });
            selects.forEach(function (el, idx) {
                Array.from(el.options).forEach(function (opt) {
                    opt.disabled = opt.value !== '' && chosen.some(function (v, j) { return j !== idx && v === opt.value; });
                });
            });
        });
        const warn = document.getElementById('teamClientWarn');
        if (warn) {
            const ds = [$('#driver_id').val()].concat($('.team-driver:enabled').map(function () { return $(this).val(); }).get()).filter(Boolean);
            const dup = ds.some(function (v, i) { return ds.indexOf(v) !== i; });
            warn.style.display = dup ? '' : 'none';
            warn.textContent = dup ? 'The same driver cannot be assigned twice to the same job.' : '';
        }
    }
    if (teamSelect) { teamSelect.addEventListener('change', syncTeamRows); }
    $(document).on('change', '#driver_id, #vehicle_id, .team-driver, .team-vehicle', syncTeamChoices);
    syncTeamRows();

    <?php if ($lead): ?>
    // Adding a driver to an existing job: the job details are read-only
    ['trip_date','start_time','end_time','destination','passenger_count','purpose','officer_name','officer_phone','waiting_place']
        .forEach(function (id) { const el = document.getElementById(id); if (el) { el.readOnly = true; } });
    const tt = document.getElementById('trip_type'); if (tt) { tt.style.pointerEvents = 'none'; tt.setAttribute('tabindex', '-1'); }
    <?php endif; ?>

    // ── Top Management trip: pick the officer, get their dedicated driver ──
    function syncTopOfficer() {
        const isTop = $('#trip_type').val() === 'top_management';
        $('#topOfficerGroup').toggle(isTop);
    }
    $('#trip_type').on('change', syncTopOfficer);
    $('#top_officer').on('change', function () {
        const driverId = $(this).val();
        if (!driverId) { return; }
        $('#driver_id').val(driverId).trigger('change');
        const officer = $(this).find('option:selected').data('officer');
        const nameBox = $('#officer_name');
        if (officer && (nameBox.val().trim() === '' || nameBox.data('auto'))) {
            nameBox.val(officer).data('auto', true);
        }
    });
    $('#officer_name').on('input', function () { $(this).data('auto', false); });
    syncTopOfficer();

    // ── End time validation ──────────────────────────────────
    function validateTimes() {
        const s = $('#start_time').val();
        const e = $('#end_time').val();
        if (s && e && e <= s) {
            $('#timeError').show();
            $('#end_time')[0].setCustomValidity('End time must be after start time.');
        } else {
            $('#timeError').hide();
            $('#end_time')[0].setCustomValidity('');
        }
    }
    $('#start_time, #end_time').on('change', validateTimes);

    // ── Live conflict check via AJAX ─────────────────────────
    function checkConflict() {
        clearTimeout(conflictTimer);
        conflictTimer = setTimeout(function () {
            const date      = $('#trip_date').val();
            const startTime = $('#start_time').val();
            const endTime   = $('#end_time').val();
            const vehicleId = $('#vehicle_id').val();
            const driverId  = $('#driver_id').val();

            if (!date || !startTime || !endTime || endTime <= startTime) return;

            $.ajax({
                url:      SITE_URL + '/ajax/check_conflict.php',
                method:   'POST',
                data:     { vehicle_id: vehicleId, driver_id: driverId, trip_date: date, start_time: startTime, end_time: endTime, share_with: <?php echo $lead ? (int)$lead['schedule_id'] : 0; ?> },
                dataType: 'json'
            }).done(function (res) {
                if (res.vehicle_conflict) {
                    $('#vehicleConflictMsg').text(res.vehicle_message || 'This vehicle is already booked for this time slot.');
                    $('#vehicleConflictAlert').show();
                } else {
                    $('#vehicleConflictAlert').hide();
                }
                if (res.driver_conflict) {
                    $('#driverConflictMsg').text(res.driver_message || 'This driver is already scheduled for this time slot.');
                    $('#driverConflictAlert').show();
                } else {
                    $('#driverConflictAlert').hide();
                }
            });

            // Also fetch available drivers
            if (driverId === '') {
                $.ajax({
                    url:      SITE_URL + '/ajax/get_available_drivers.php',
                    method:   'POST',
                    data:     {
                        trip_date: date, start_time: startTime, end_time: endTime,
                        vehicle_id: vehicleId, trip_type: $('#trip_type').val()
                    },
                    dataType: 'json'
                }).done(function (res) {
                    if (res.success && res.drivers && res.drivers.length > 0) {
                        let html = '<ul class="list-unstyled mb-0">';
                        res.drivers.slice(0, 5).forEach(function (d, idx) {
                            const score = parseFloat(d.priority !== undefined ? d.priority : d.priority_score);
                            const cls = score >= 7 ? 'priority-high' : (score >= 4 ? 'priority-medium' : 'priority-low');
                            html += '<li class="mb-1">' + (idx === 0 ? '<span class="fw-semibold">&#9733; Recommended:</span> ' : '') + escHtml(d.name)
                                + ' <span class="priority-chip ' + cls + '">' + score.toFixed(2) + '</span>'
                                + ' <span class="text-muted">(' + d.month_tasks + ' tasks this month, ' + d.month_weekend + ' weekend; ' + d.daily_hours + 'h today)</span>'
                                + '</li>';
                        });
                        html += '</ul>';
                        if (res.drivers.length > 5) html += '<div class="text-muted">...and ' + (res.drivers.length - 5) + ' more</div>';
                        $('#availableDriversList').html(html);
                        $('#availableDriversPanel').show();
                    } else {
                        $('#availableDriversPanel').hide();
                    }
                });
            } else {
                $('#availableDriversPanel').hide();
            }

        }, 400);
    }

    $('#trip_date, #start_time, #end_time, #vehicle_id, #driver_id, #trip_type').on('change', checkConflict);

    // ── Link each invalid field to its error message (screen readers) ──
    function syncFieldAria(form) {
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (!el.id || !document.getElementById(el.id + '_error')) { return; }
            if (!el.checkValidity()) {
                var ids = [el.id + '_error'];
                if (document.getElementById(el.id + '_help')) { ids.push(el.id + '_help'); }
                if (el.id === 'end_time') { ids.push('timeError'); }
                el.setAttribute('aria-invalid', 'true');
                el.setAttribute('aria-describedby', ids.join(' '));
            } else if (!el.classList.contains('is-invalid')) {
                el.removeAttribute('aria-invalid');
            }
        });
    }

    // ── Client-side form validation ──────────────────────────
    $('#scheduleForm').on('submit', function (e) {
        validateTimes();
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        this.classList.add('was-validated');
        syncFieldAria(this);
    });

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

})();
</script>

</body>
</html>
