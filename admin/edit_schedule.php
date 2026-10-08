<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/edit_schedule.php  –  Edit Existing Schedule
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
require_once '../includes/mailer.php';
requireAdmin();

$page_title   = 'Edit Schedule';
$current_page = 'edit_schedule.php';

// ── Load existing record ─────────────────────────────────────
$schedule_id = (int)($_GET['id'] ?? 0);
if ($schedule_id <= 0) {
    setFlash('danger', 'Invalid schedule ID.');
    header('Location: ' . SITE_URL . '/admin/schedules.php');
    exit();
}

$stmt = $conn->prepare(
    "SELECT s.*, d.name AS driver_name, v.plate_number AS vehicle_plate
     FROM schedules s
     LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
     LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
     WHERE s.schedule_id = ?"
);
$stmt->bind_param('i', $schedule_id);
$stmt->execute();
$schedule = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$schedule) {
    setFlash('danger', 'Schedule not found.');
    header('Location: ' . SITE_URL . '/admin/schedules.php');
    exit();
}

$errors = [];
$old    = $schedule; // pre-fill with existing data

// ── Other drivers on the same multi-driver job (if any) ──────
$job_group   = (int)($schedule['job_group'] ?? 0);
$team_rows   = [];     // every other row of this job, any status but cancelled
if ($job_group > 0) {
    $tm = $conn->prepare(
        "SELECT m.*, d.name AS driver_name, v.plate_number
         FROM schedules m
         LEFT JOIN drivers  d ON d.driver_id  = m.driver_id
         LEFT JOIN vehicles v ON v.vehicle_id = m.vehicle_id
         WHERE m.job_group = ? AND m.schedule_id <> ? AND m.status <> 'cancelled'
         ORDER BY d.name"
    );
    $tm->bind_param('ii', $job_group, $schedule_id);
    $tm->execute();
    $team_rows = $tm->get_result()->fetch_all(MYSQLI_ASSOC);
    $tm->close();
}
$team_ids = array_map(static function ($r) { return (int)$r['schedule_id']; }, $team_rows);

// ── Fetch available vehicles ─────────────────────────────────
// Include the currently assigned vehicle even if in_use
$vehicles_result = $conn->query(
    "SELECT vehicle_id, plate_number, vehicle_type, capacity, brand, model, status
     FROM vehicles
     WHERE status IN ('available', 'in_use')
     ORDER BY vehicle_type, plate_number"
);
$vehicles = $vehicles_result ? $vehicles_result->fetch_all(MYSQLI_ASSOC) : [];

// ── Fetch active drivers with allocation scores ──────────────
// Scores are based on the trip month. This schedule's own task is excluded
// from the counts so the score reflects the driver as if it were unassigned.
function loadScoredDrivers(mysqli $conn, string $month, array $schedule): array
{
    $result  = $conn->query(
        "SELECT driver_id, name, employee_id, experience_years, license_class, driver_type, status
         FROM drivers WHERE status IN ('active', 'on_leave') ORDER BY name ASC"
    );
    $drivers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $counts  = getMonthlyTaskCounts($conn, $month);

    $own_driver = (int)($schedule['driver_id'] ?? 0);
    if ($own_driver > 0
        && ($schedule['status'] ?? '') !== 'cancelled'
        && substr((string)($schedule['trip_date'] ?? ''), 0, 7) === $month
        && isset($counts[$own_driver])) {
        $counts[$own_driver]['tasks'] = max(0, $counts[$own_driver]['tasks'] - 1);
        if (in_array((int)date('w', strtotime($schedule['trip_date'])), [0, 6], true)) {
            $counts[$own_driver]['weekend'] = max(0, $counts[$own_driver]['weekend'] - 1);
        }
    }

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

$trip_month = substr((string)($schedule['trip_date'] ?? ''), 0, 7) ?: date('Y-m');
$drivers    = loadScoredDrivers($conn, $trip_month, $schedule);

// ── POST handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old = $_POST;

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

    // These fields are locked (disabled in the form) while the trip is in progress,
    // so the browser does not send them. Keep the stored values instead.
    if ($schedule['status'] === 'in_progress') {
        $trip_date  = $schedule['trip_date'];
        $start_time = $schedule['start_time'];
        $end_time   = $schedule['end_time'];
        $vehicle_id = (int)($schedule['vehicle_id'] ?? 0);
        $driver_id  = (int)($schedule['driver_id']  ?? 0);
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
    if (!in_array($status, ['pending','approved','in_progress','completed','cancelled'])) {
        $status = 'pending';
    }
    if (!in_array($trip_type, ['regular','top_management'])) {
        $trip_type = 'regular';
    }

    // ── Same job: a driver / vehicle cannot appear twice ──────
    if (empty($errors) && $team_rows) {
        foreach ($team_rows as $m) {
            if ($driver_id > 0 && (int)$m['driver_id'] === $driver_id) {
                $errors['driver_id'] = ($m['driver_name'] ?? 'This driver') . ' is already on this job. The same driver cannot be assigned twice to the same job; choose a different driver.';
                break;
            }
        }
    }

    // ── Conflict check – vehicle (exclude this job: its drivers may share a vehicle) ──
    if (empty($errors) && $vehicle_id > 0) {
        if (findResourceConflict($conn, 'vehicle_id', $vehicle_id, $trip_date, $start_time, $end_time, array_merge([$schedule_id], $team_ids))) {
            $errors['vehicle_id'] = 'The selected vehicle already has a schedule that overlaps this time slot. Choose another vehicle, or change the date or times.';
        }
    }

    // ── Conflict check – driver (exclude this schedule) ─────
    if (empty($errors) && $driver_id > 0) {
        $conflict_sql = "SELECT schedule_id FROM schedules
                         WHERE driver_id = ? AND trip_date = ?
                           AND schedule_id != ?
                           AND status NOT IN ('cancelled')
                           AND (
                               (start_time < ? AND end_time > ?) OR
                               (start_time < ? AND end_time > ?) OR
                               (start_time >= ? AND end_time <= ?)
                           )
                         LIMIT 1";
        $cs = $conn->prepare($conflict_sql);
        $cs->bind_param('isississs', $driver_id, $trip_date, $schedule_id,
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time
        );
        $cs->execute();
        $cs->store_result();
        if ($cs->num_rows > 0) {
            $errors['driver_id'] = 'The selected driver already has a schedule that overlaps this time slot. Choose another driver, change the date or times, or set the driver to unassigned (auto-assign).';
        }
        $cs->close();
    }

    // ── Duplicate job: same day, start time and destination ──
    if (empty($errors) && $status !== 'cancelled' && $trip_date !== '' && $start_time !== '' && $destination !== '') {
        $same = findDuplicateJob($conn, $trip_date, $start_time, $destination, [$schedule_id], $job_group);
        if ($same) {
            $errors['destination'] = 'This job is already scheduled (Schedule #' . str_pad($same['schedule_id'], 4, '0', STR_PAD_LEFT)
                . ': same destination, date and start time). The same job cannot be entered twice.';
        }
    }

    // ── Shared details moved: the other drivers must still be free ──
    $shared_changed = $trip_date !== $schedule['trip_date']
        || substr($start_time, 0, 5) !== substr($schedule['start_time'], 0, 5)
        || substr($end_time, 0, 5)   !== substr($schedule['end_time'], 0, 5);
    if (empty($errors) && $team_rows && $shared_changed) {
        $group_ids = array_merge([$schedule_id], $team_ids);
        foreach ($team_rows as $m) {
            if (!in_array($m['status'], ['pending', 'approved'], true)) { continue; }
            $hit_d = findResourceConflict($conn, 'driver_id',  (int)$m['driver_id'],  $trip_date, $start_time, $end_time, $group_ids);
            $hit_v = findResourceConflict($conn, 'vehicle_id', (int)$m['vehicle_id'], $trip_date, $start_time, $end_time, $group_ids);
            if ($hit_d || $hit_v) {
                $errors['trip_date'] = 'This job has other drivers, and the new date or time clashes with another schedule of '
                    . ($m['driver_name'] ?? 'a teammate') . ($hit_v && !$hit_d ? '\'s vehicle' : '')
                    . '. Choose another date or time, or remove that driver from the job first.';
                break;
            }
        }
    }

    // ── Allocation score (for the trip month) ────────────────
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trip_date) && substr($trip_date, 0, 7) !== $trip_month) {
        $drivers = loadScoredDrivers($conn, substr($trip_date, 0, 7), $schedule);
    }
    $priority_score = (float)($schedule['priority_score'] ?? 0);
    if ($driver_id > 0) {
        foreach ($drivers as $d) {
            if ((int)$d['driver_id'] === $driver_id) {
                $priority_score = $d['priority_score'];
                break;
            }
        }
    } elseif ($driver_id === 0) {
        $priority_score = 0.00;
    }

    // ── UPDATE ───────────────────────────────────────────────
    if (empty($errors)) {
        $vid = $vehicle_id > 0 ? $vehicle_id : null;
        $did = $driver_id  > 0 ? $driver_id  : null;
        $officer_name_db  = $officer_name  === '' ? null : $officer_name;
        $officer_phone_db = $officer_phone === '' ? null : $officer_phone;
        $waiting_place_db = $waiting_place === '' ? null : $waiting_place;

        // Snapshot before the update so the e-mail layer can tell what changed
        $before = null;
        try {
            $before = snapshotSchedule($conn, $schedule_id);
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }

        $upd = $conn->prepare(
            "UPDATE schedules SET
                 driver_id       = ?,
                 vehicle_id      = ?,
                 trip_date       = ?,
                 start_time      = ?,
                 end_time        = ?,
                 destination     = ?,
                 purpose         = ?,
                 passenger_count = ?,
                 status          = ?,
                 priority_score  = ?,
                 notes           = ?,
                 trip_type       = ?,
                 officer_name    = ?,
                 officer_phone   = ?,
                 waiting_place   = ?
             WHERE schedule_id = ?"
        );
        $upd->bind_param(
            'iisssssisdsssssi',
            $did, $vid, $trip_date, $start_time, $end_time,
            $destination, $purpose, $passenger_count,
            $status, $priority_score, $notes, $trip_type,
            $officer_name_db, $officer_phone_db, $waiting_place_db, $schedule_id
        );

        if ($upd->execute()) {
            $upd->close();

            // Keep the other drivers of the same job in step with the shared details
            $cascade_before = [];
            foreach ($team_rows as $m) {
                if (!in_array($m['status'], ['pending', 'approved'], true)) { continue; }
                try { $cascade_before[(int)$m['schedule_id']] = snapshotSchedule($conn, (int)$m['schedule_id']); }
                catch (Throwable $e) { error_log($e->getMessage()); }
                $mid = (int)$m['schedule_id'];
                $cu = $conn->prepare(
                    "UPDATE schedules SET trip_date = ?, start_time = ?, end_time = ?, destination = ?, purpose = ?,
                            passenger_count = ?, trip_type = ?, officer_name = ?, officer_phone = ?, waiting_place = ?
                     WHERE schedule_id = ?"
                );
                $cu->bind_param('sssssissssi', $trip_date, $start_time, $end_time, $destination, $purpose,
                    $passenger_count, $trip_type, $officer_name_db, $officer_phone_db, $waiting_place_db, $mid);
                $cu->execute();
                $cu->close();
            }
            if ($cascade_before && $shared_changed) {
                foreach ($cascade_before as $mid => $snap) {
                    if ($snap === null) { continue; }
                    try { notifyScheduleChanged($conn, $snap, $mid); } catch (Throwable $e) { error_log($e->getMessage()); }
                }
            }

            $email_note = '';
            if ($before !== null) {
                try {
                    $email_results = notifyScheduleChanged($conn, $before, $schedule_id);
                    if (!empty($email_results)) {
                        $email_note = emailSummary($email_results);
                    }
                } catch (Throwable $e) {
                    error_log($e->getMessage());
                }
            }

            setFlash('success', "Schedule #" . str_pad($schedule_id, 4, '0', STR_PAD_LEFT) . " updated successfully." . ($email_note !== '' ? ' ' . htmlspecialchars($email_note) : ''));
            header('Location: ' . SITE_URL . '/admin/schedules.php');
            exit();
        } else {
            error_log('edit_schedule: ' . $conn->error);
            $errors['db'] = 'The schedule could not be saved because of a system error. Your entries are still on this page, so please try again. If it keeps happening, contact the system administrator.';
        }
        $upd->close();
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
        .status-locked-notice { display: none; }
    </style>
</head>
<body>

<?php include '../includes/sidebar.php'; ?>

<main class="main-content p-4">

    <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1>
                <i class="fas fa-pen-to-square me-2" aria-hidden="true"></i>Edit Schedule
                <span class="ms-2 opacity-75 fs-5">#<?php echo str_pad($schedule_id, 4, '0', STR_PAD_LEFT); ?></span>
            </h1>
            <p>Update trip schedule details and assignment.</p>
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

    <?php if ($team_rows): ?>
    <div class="alert alert-info mb-4" role="note">
        <i class="fas fa-users me-2" aria-hidden="true"></i>
        <strong>Team job:</strong> this job has <?php echo count($team_rows) + 1; ?> drivers. You are editing
        <strong><?php echo htmlspecialchars($schedule['driver_name'] ?? 'this driver'); ?></strong>'s part.
        Date, time, destination and officer details are shared and change for every driver on the job
        (drivers who already started or finished keep their own record).
        <div class="small mt-1">With:
            <?php echo implode(', ', array_map(static function ($m) {
                return '<a href="edit_schedule.php?id=' . (int)$m['schedule_id'] . '">' . htmlspecialchars($m['driver_name'] ?? 'Unassigned') . '</a>'
                     . ($m['plate_number'] ? ' (' . htmlspecialchars($m['plate_number']) . ')' : '');
            }, $team_rows)); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Status banner for in_progress -->
    <?php if ($schedule['status'] === 'in_progress'): ?>
    <div class="alert alert-info mb-4">
        <i class="fas fa-truck-moving me-2" aria-hidden="true"></i>
        <strong>Trip In Progress:</strong> This schedule is currently active. You can update notes and status only.
    </div>
    <?php endif; ?>

    <form method="POST" id="scheduleForm" novalidate>

        <div class="row g-4">

            <!-- Left column -->
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
                                       value="<?php echo htmlspecialchars($old['trip_date'] ?? $schedule['trip_date']); ?>"
                                       <?php echo $schedule['status'] === 'in_progress' ? 'disabled' : ''; ?>
                                       required
                                       aria-required="true"
                                       <?php echo isset($errors['trip_date']) ? 'aria-invalid="true" aria-describedby="trip_date_error"' : ''; ?>>
                                <div class="invalid-feedback" id="trip_date_error"><?php echo htmlspecialchars($errors['trip_date'] ?? 'Choose a trip date (today or later).'); ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="start_time">Start Time <span class="req" aria-hidden="true">*</span></label>
                                <input type="time" class="form-control <?php echo isset($errors['start_time']) ? 'is-invalid' : ''; ?>" id="start_time" name="start_time"
                                       value="<?php echo htmlspecialchars($old['start_time'] ?? $schedule['start_time']); ?>"
                                       <?php echo $schedule['status'] === 'in_progress' ? 'disabled' : ''; ?>
                                       required
                                       aria-required="true"
                                       <?php echo isset($errors['start_time']) ? 'aria-invalid="true" aria-describedby="start_time_error"' : ''; ?>>
                                <div class="invalid-feedback" id="start_time_error"><?php echo htmlspecialchars($errors['start_time'] ?? 'Enter the start time, for example 08:30.'); ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="end_time">End Time <span class="req" aria-hidden="true">*</span></label>
                                <input type="time" class="form-control <?php echo isset($errors['end_time']) ? 'is-invalid' : ''; ?>" id="end_time" name="end_time"
                                       value="<?php echo htmlspecialchars($old['end_time'] ?? $schedule['end_time']); ?>"
                                       <?php echo $schedule['status'] === 'in_progress' ? 'disabled' : ''; ?>
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
                                       value="<?php echo htmlspecialchars($old['destination'] ?? $schedule['destination']); ?>"
                                       required
                                       aria-required="true"
                                       <?php echo isset($errors['destination']) ? 'aria-invalid="true" aria-describedby="destination_error"' : ''; ?>>
                                <div class="invalid-feedback" id="destination_error"><?php echo htmlspecialchars($errors['destination'] ?? 'Enter the destination, for example Kuala Lumpur International Airport.'); ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="passenger_count">Passengers</label>
                                <input type="number" class="form-control" id="passenger_count" name="passenger_count"
                                       min="1" max="100"
                                       value="<?php echo (int)($old['passenger_count'] ?? $schedule['passenger_count']); ?>"
                                       inputmode="numeric"
                                       step="1"
                                       aria-describedby="passenger_count_help">
                                <div class="invalid-feedback" id="passenger_count_error">Enter a whole number of passengers from 1 to 100.</div>
                                <div class="form-text" id="passenger_count_help">Whole number, 1&ndash;100.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold" for="purpose">Purpose</label>
                                <input type="text" class="form-control" id="purpose" name="purpose"
                                       value="<?php echo htmlspecialchars($old['purpose'] ?? $schedule['purpose'] ?? ''); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="officer_name">Officer Name(s)</label>
                                <input type="text" class="form-control" id="officer_name" name="officer_name"
                                       maxlength="255" placeholder="e.g. En. Faruq, En. Amir"
                                       value="<?php echo htmlspecialchars($old['officer_name'] ?? $schedule['officer_name'] ?? ''); ?>"
                                       autocomplete="off"
                                       aria-describedby="officer_name_help">
                                <div class="form-text" id="officer_name_help">Person(s) the driver will serve</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="officer_phone">Officer Phone No.</label>
                                <input type="tel" class="form-control <?php echo isset($errors['officer_phone']) ? 'is-invalid' : ''; ?>" id="officer_phone" name="officer_phone"
                                       maxlength="50" placeholder="e.g. 011-2835 4792"
                                       pattern="[0-9+\-\s\(\)]{7,20}"
                                       value="<?php echo htmlspecialchars($old['officer_phone'] ?? $schedule['officer_phone'] ?? ''); ?>"
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
                                       value="<?php echo htmlspecialchars($old['waiting_place'] ?? $schedule['waiting_place'] ?? ''); ?>"
                                       autocomplete="off">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="status">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <?php
                                    $currentStatus = $old['status'] ?? $schedule['status'];
                                    foreach (['pending','approved','in_progress','completed','cancelled'] as $st):
                                    ?>
                                    <option value="<?php echo $st; ?>" <?php echo $currentStatus === $st ? 'selected' : ''; ?>>
                                        <?php echo ucwords(str_replace('_',' ', $st)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="trip_type">Trip Type</label>
                                <?php $currentTripType = $old['trip_type'] ?? $schedule['trip_type'] ?? 'regular'; ?>
                                <select class="form-select" id="trip_type" name="trip_type"
                                        aria-describedby="trip_type_help">
                                    <option value="regular"        <?php echo $currentTripType === 'regular'        ? 'selected' : ''; ?>>Regular</option>
                                    <option value="top_management" <?php echo $currentTripType === 'top_management' ? 'selected' : ''; ?>>Top Management</option>
                                </select>
                                <div class="form-text" id="trip_type_help">Top Management is for VIP and executive trips.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold" for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($old['notes'] ?? $schedule['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vehicle Selection -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title"><i class="fas fa-car me-2" aria-hidden="true"></i>Vehicle</div>

                        <div id="vehicleConflictAlert" class="alert alert-danger py-2 mb-3" style="display:none;">
                            <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            <strong>Vehicle Conflict:</strong> <span id="vehicleConflictMsg"></span>
                        </div>

                        <label for="vehicle_id" class="visually-hidden">Vehicle</label>
                        <select class="form-select <?php echo isset($errors['vehicle_id']) ? 'is-invalid' : ''; ?>" id="vehicle_id" name="vehicle_id"
                                <?php echo $schedule['status'] === 'in_progress' ? 'disabled' : ''; ?>
                                <?php echo isset($errors['vehicle_id']) ? 'aria-invalid="true" aria-describedby="vehicle_id_error"' : ''; ?>>
                            <option value="">-- No vehicle --</option>
                            <?php
                            $currentVehicleId = (int)($old['vehicle_id'] ?? $schedule['vehicle_id'] ?? 0);
                            foreach ($vehicles as $v):
                                $selected = ($currentVehicleId === (int)$v['vehicle_id']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo (int)$v['vehicle_id']; ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($v['plate_number'] . ' — ' . $v['vehicle_type']
                                    . ($v['brand'] ? ' (' . $v['brand'] . ')' : '')
                                    . ' — ' . $v['capacity'] . ' pax'
                                    . ($v['status'] !== 'available' ? ' [' . $v['status'] . ']' : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['vehicle_id'])): ?><div class="invalid-feedback" id="vehicle_id_error"><?php echo htmlspecialchars($errors['vehicle_id']); ?></div><?php endif; ?>
                    </div>
                </div>

            </div><!-- /col-lg-8 -->

            <!-- Right column -->
            <div class="col-lg-4">

                <!-- Driver Selection -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title"><i class="fas fa-user me-2" aria-hidden="true"></i>Driver</div>

                        <div id="driverConflictAlert" class="alert alert-danger py-2 mb-3" style="display:none;">
                            <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            <strong>Driver Conflict:</strong> <span id="driverConflictMsg"></span>
                        </div>

                        <label for="driver_id" class="visually-hidden">Driver</label>
                        <select class="form-select <?php echo isset($errors['driver_id']) ? 'is-invalid' : ''; ?>" id="driver_id" name="driver_id"
                                <?php echo $schedule['status'] === 'in_progress' ? 'disabled' : ''; ?>
                                <?php echo isset($errors['driver_id']) ? 'aria-invalid="true" aria-describedby="driver_id_error driver_help"' : 'aria-describedby="driver_help"'; ?>>
                            <option value="">-- Unassigned (auto-assign) --</option>
                            <?php
                            $currentDriverId = (int)($old['driver_id'] ?? $schedule['driver_id'] ?? 0);
                            foreach ($drivers as $i => $d):
                                $selected = ($currentDriverId === (int)$d['driver_id']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo (int)$d['driver_id']; ?>"
                                data-score="<?php echo $d['priority_score']; ?>"
                                data-tasks="<?php echo (int)$d['month_tasks']; ?>"
                                data-weekend="<?php echo (int)$d['month_weekend']; ?>"
                                <?php echo $selected; ?>>
                                <?php echo $i === 0 ? '★ Recommended: ' : ''; ?><?php echo htmlspecialchars($d['name']); ?> — Score: <?php echo number_format($d['priority_score'], 2); ?> (<?php echo (int)$d['month_tasks']; ?> tasks this month)
                                <?php echo $d['status'] !== 'active' ? ' [' . $d['status'] . ']' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['driver_id'])): ?><div class="invalid-feedback" id="driver_id_error"><?php echo htmlspecialchars($errors['driver_id']); ?></div><?php endif; ?>

                        <div id="autoAssignNotice" class="alert alert-info py-2 mt-2 small" style="<?php echo $currentDriverId ? 'display:none;' : ''; ?>">
                            <i class="fas fa-wand-magic-sparkles me-1" aria-hidden="true"></i>
                            No driver selected. Will be auto-assigned.
                        </div>
                        <div id="driverScorePanel" class="mt-2 small" style="<?php echo $currentDriverId ? '' : 'display:none;'; ?>"></div>
                        <div class="form-text" id="driver_help">Allocation score (0–10): fewer tasks this month 50%, fewer weekend tasks 30%, experience 20%.</div>
                    </div>
                </div>

                <!-- Record info -->
                <div class="card form-card mb-4">
                    <div class="card-body p-3">
                        <div class="fw-semibold text-primary mb-2 small">
                            <i class="fas fa-info-circle me-1" aria-hidden="true"></i> Record Info
                        </div>
                        <div class="small text-muted">
                            <div class="mb-1"><strong>ID:</strong> #<?php echo str_pad($schedule_id, 4, '0', STR_PAD_LEFT); ?></div>
                            <div class="mb-1"><strong>Created:</strong> <?php echo formatDate($schedule['created_at']); ?></div>
                            <div class="mb-1"><strong>Allocation Score:</strong> <?php echo number_format((float)$schedule['priority_score'], 2); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="card form-card">
                    <div class="card-body p-4">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                            <i class="fas fa-floppy-disk me-2" aria-hidden="true"></i> Save Changes
                        </button>
                        <a href="<?php echo SITE_URL; ?>/admin/schedules.php" class="btn btn-outline-secondary w-100 mt-2">
                            <i class="fas fa-times me-1" aria-hidden="true"></i> Cancel
                        </a>
                    </div>
                </div>

            </div><!-- /col-lg-4 -->

        </div><!-- /row -->
    </form>

</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/main.js"></script>

<script>
(function () {
    'use strict';

    const SITE_URL   = '<?php echo SITE_URL; ?>';
    const SCHEDULE_ID = <?php echo $schedule_id; ?>;
    let conflictTimer = null;

    // ── Driver UI ────────────────────────────────────────────
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

    // ── Time validation ──────────────────────────────────────
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

    // ── Live conflict check ──────────────────────────────────
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
                data:     {
                    vehicle_id: vehicleId, driver_id: driverId,
                    trip_date: date, start_time: startTime, end_time: endTime,
                    exclude_id: SCHEDULE_ID, share_with: SCHEDULE_ID
                },
                dataType: 'json'
            }).done(function (res) {
                if (res.vehicle_conflict) {
                    $('#vehicleConflictMsg').text(res.vehicle_message || 'Vehicle conflict detected.');
                    $('#vehicleConflictAlert').show();
                } else {
                    $('#vehicleConflictAlert').hide();
                }
                if (res.driver_conflict) {
                    $('#driverConflictMsg').text(res.driver_message || 'Driver conflict detected.');
                    $('#driverConflictAlert').show();
                } else {
                    $('#driverConflictAlert').hide();
                }
            });
        }, 400);
    }

    $('#trip_date, #start_time, #end_time, #vehicle_id, #driver_id').on('change', checkConflict);

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

    // ── Form validation ──────────────────────────────────────
    $('#scheduleForm').on('submit', function (e) {
        validateTimes();
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        this.classList.add('was-validated');
        syncFieldAria(this);
    });

})();
</script>

</body>
</html>
