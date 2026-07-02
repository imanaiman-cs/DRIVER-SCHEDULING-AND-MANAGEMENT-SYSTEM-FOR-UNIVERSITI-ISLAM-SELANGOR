<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/add_schedule.php  –  Create New Schedule
// Universiti Islam Selangor (UIS)
// ============================================================

require_once '../config/database.php';
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

// ── Fetch active drivers with priority scores ────────────────
$drivers_result = $conn->query(
    "SELECT driver_id, name, employee_id, experience_years, attendance_rate, performance_score, certification_score
     FROM drivers WHERE status = 'active' ORDER BY name ASC"
);
$drivers = $drivers_result ? $drivers_result->fetch_all(MYSQLI_ASSOC) : [];
foreach ($drivers as &$d) {
    $d['priority_score'] = calculatePriorityScore(
        (float)$d['experience_years'],
        (float)$d['attendance_rate'],
        (float)$d['performance_score'],
        (float)$d['certification_score']
    );
}
unset($d);

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

    if (empty($trip_date))   $errors[] = 'Trip date is required.';
    if (empty($start_time))  $errors[] = 'Start time is required.';
    if (empty($end_time))    $errors[] = 'End time is required.';
    if (empty($destination)) $errors[] = 'Destination is required.';
    if ($passenger_count < 1) $passenger_count = 1;

    if (!empty($start_time) && !empty($end_time) && $end_time <= $start_time) {
        $errors[] = 'End time must be after start time.';
    }
    if (!empty($trip_date) && $trip_date < date('Y-m-d')) {
        $errors[] = 'Trip date cannot be in the past.';
    }
    if (!in_array($status, ['pending','approved','in_progress','completed','cancelled'])) {
        $status = 'pending';
    }
    if (!in_array($trip_type, ['regular','top_management'])) {
        $trip_type = 'regular';
    }

    // ── Conflict check – vehicle ─────────────────────────────
    if (empty($errors) && $vehicle_id > 0) {
        $conflict_sql = "SELECT schedule_id FROM schedules
                         WHERE vehicle_id = ? AND trip_date = ?
                           AND status NOT IN ('cancelled')
                           AND (
                               (start_time < ? AND end_time > ?) OR
                               (start_time < ? AND end_time > ?) OR
                               (start_time >= ? AND end_time <= ?)
                           )
                         LIMIT 1";
        $cs = $conn->prepare($conflict_sql);
        $cs->bind_param('isssssss', $vehicle_id, $trip_date,
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time
        );
        $cs->execute();
        $cs->store_result();
        if ($cs->num_rows > 0) {
            $errors[] = 'The selected vehicle already has a schedule that overlaps with this time slot.';
        }
        $cs->close();
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
            $errors[] = 'The selected driver already has a schedule that overlaps with this time slot.';
        }
        $cs->close();
    }

    // ── Calculate priority score ─────────────────────────────
    $priority_score = 0.00;
    if ($driver_id > 0) {
        foreach ($drivers as $d) {
            if ((int)$d['driver_id'] === $driver_id) {
                $priority_score = $d['priority_score'];
                break;
            }
        }
    }

    // ── INSERT ───────────────────────────────────────────────
    if (empty($errors)) {
        $vid = $vehicle_id > 0 ? $vehicle_id : null;
        $did = $driver_id  > 0 ? $driver_id  : null;
        $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;

        $ins = $conn->prepare(
            "INSERT INTO schedules
                 (driver_id, vehicle_id, trip_date, start_time, end_time,
                  destination, purpose, passenger_count, status, priority_score, created_by, notes, trip_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param(
            'iisssssidssss',
            $did, $vid, $trip_date, $start_time, $end_time,
            $destination, $purpose, $passenger_count,
            $status, $priority_score, $uid, $notes, $trip_type
        );

        if ($ins->execute()) {
            $new_id = $ins->insert_id;
            $ins->close();
            setFlash('success', "Schedule #" . str_pad($new_id, 4, '0', STR_PAD_LEFT) . " to <strong>" . htmlspecialchars($destination) . "</strong> created successfully." . ($did === null ? " It will be auto-assigned." : ""));
            header('Location: ' . SITE_URL . '/admin/schedules.php');
            exit();
        } else {
            $errors[] = 'Database error: ' . $conn->error;
        }
        $ins->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> | UIS Driver Management</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo SITE_URL; ?>/assets/css/style.css" rel="stylesheet">

    <style>
        .page-header {
            background: linear-gradient(135deg, #003580 0%, #0056b3 100%);
            border-radius: 14px;
            color: #fff;
            padding: 1.6rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 16px rgba(0,53,128,.20);
        }
        .page-header h1 { font-size: 1.55rem; font-weight: 700; margin: 0; }
        .page-header p  { margin: .3rem 0 0; opacity: .8; font-size: .88rem; }
        .form-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(0,53,128,.10);
        }
        .section-title {
            font-size: .82rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #003580;
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
            <h1><i class="fas fa-calendar-plus me-2" aria-hidden="true"></i>Create Schedule</h1>
            <p>Add a new trip schedule to the system.</p>
        </div>
        <a href="<?php echo SITE_URL; ?>/admin/schedules.php" class="btn btn-light fw-semibold text-primary">
            <i class="fas fa-arrow-left me-1" aria-hidden="true"></i> Back to Schedules
        </a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-circle-exclamation me-2" aria-hidden="true"></i>
        <strong>Please fix the following errors:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $e): ?>
            <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="scheduleForm" novalidate>

        <div class="row g-4">

            <!-- Left column: trip details + vehicle -->
            <div class="col-lg-8">

                <!-- Trip Details -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title"><i class="fas fa-map-marker-alt me-2" aria-hidden="true"></i>Trip Details</div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="trip_date">Trip Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="trip_date" name="trip_date"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       value="<?php echo htmlspecialchars($old['trip_date'] ?? ''); ?>"
                                       required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="start_time">Start Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="start_time" name="start_time"
                                       value="<?php echo htmlspecialchars($old['start_time'] ?? ''); ?>"
                                       required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="end_time">End Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="end_time" name="end_time"
                                       value="<?php echo htmlspecialchars($old['end_time'] ?? ''); ?>"
                                       required>
                                <div id="timeError" class="text-danger small mt-1" style="display:none;">
                                    End time must be after start time.
                                </div>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label fw-semibold" for="destination">Destination <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="destination" name="destination"
                                       placeholder="e.g., Kuala Lumpur International Airport"
                                       value="<?php echo htmlspecialchars($old['destination'] ?? ''); ?>"
                                       required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" for="passenger_count">Passengers</label>
                                <input type="number" class="form-control" id="passenger_count" name="passenger_count"
                                       min="1" max="100"
                                       value="<?php echo (int)($old['passenger_count'] ?? 1); ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold" for="purpose">Purpose</label>
                                <input type="text" class="form-control" id="purpose" name="purpose"
                                       placeholder="e.g., Faculty field trip, Airport transfer"
                                       value="<?php echo htmlspecialchars($old['purpose'] ?? ''); ?>">
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
                                <select class="form-select" id="trip_type" name="trip_type">
                                    <option value="regular"        <?php echo (($old['trip_type'] ?? 'regular') === 'regular')        ? 'selected' : ''; ?>>Regular</option>
                                    <option value="top_management" <?php echo (($old['trip_type'] ?? 'regular') === 'top_management') ? 'selected' : ''; ?>>Top Management</option>
                                </select>
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

                        <select class="form-select" id="vehicle_id" name="vehicle_id">
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
                        <div class="form-text">Only available vehicles are shown.</div>
                    </div>
                </div>

            </div><!-- /col-lg-8 -->

            <!-- Right column: driver + submit -->
            <div class="col-lg-4">

                <!-- Driver Selection -->
                <div class="card form-card mb-4">
                    <div class="card-body p-4">
                        <div class="section-title"><i class="fas fa-user me-2" aria-hidden="true"></i>Driver (optional)</div>

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

                        <select class="form-select" id="driver_id" name="driver_id">
                            <option value="">-- Auto-assign (leave blank) --</option>
                            <?php foreach ($drivers as $d):
                                $sc = $d['priority_score'] >= 7 ? 'priority-high' : ($d['priority_score'] >= 4 ? 'priority-medium' : 'priority-low');
                            ?>
                            <option value="<?php echo (int)$d['driver_id']; ?>"
                                data-score="<?php echo $d['priority_score']; ?>"
                                <?php echo ((int)($old['driver_id'] ?? 0) === (int)$d['driver_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['name']); ?> — Score: <?php echo number_format($d['priority_score'], 2); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                        <div id="autoAssignNotice" class="alert alert-info py-2 mt-2 small">
                            <i class="fas fa-wand-magic-sparkles me-1" aria-hidden="true"></i>
                            No driver selected. The system will auto-assign the best available driver.
                        </div>
                        <div id="driverScorePanel" class="mt-2 small" style="display:none;"></div>
                    </div>
                </div>

                <!-- Priority formula info -->
                <div class="card form-card mb-4" style="border-left: 4px solid #003580 !important;">
                    <div class="card-body p-3">
                        <div class="fw-semibold text-primary mb-2 small">
                            <i class="fas fa-star me-1" aria-hidden="true"></i> Priority Score Formula
                        </div>
                        <p class="small text-muted mb-2">
                            Drivers are ranked by priority score when auto-assigning:
                        </p>
                        <code class="small d-block bg-light rounded p-2">
                            Score = (Exp×0.30) + (Att×0.20)<br>
                            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;+ (Perf×0.30) + (Cert×0.20)
                        </code>
                        <div class="small text-muted mt-2">All components normalised to 0–10 scale. Max score = 10.</div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="card form-card">
                    <div class="card-body p-4">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                            <i class="fas fa-calendar-plus me-2" aria-hidden="true"></i> Create Schedule
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            const score = parseFloat($('#driver_id option:selected').data('score') || 0);
            const cls   = score >= 7 ? 'priority-high' : (score >= 4 ? 'priority-medium' : 'priority-low');
            $('#driverScorePanel').html(
                'Priority Score: <span class="priority-chip ' + cls + '">' + score.toFixed(2) + ' / 10</span>'
            ).show();
        } else {
            $('#autoAssignNotice').show();
            $('#driverScorePanel').hide();
        }
    }

    $('#driver_id').on('change', updateDriverUI);
    updateDriverUI();

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
                data:     { vehicle_id: vehicleId, driver_id: driverId, trip_date: date, start_time: startTime, end_time: endTime },
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
                    data:     { trip_date: date, start_time: startTime, end_time: endTime },
                    dataType: 'json'
                }).done(function (res) {
                    if (res.success && res.drivers && res.drivers.length > 0) {
                        let html = '<ul class="list-unstyled mb-0">';
                        res.drivers.slice(0, 5).forEach(function (d) {
                            const cls = d.priority_score >= 7 ? 'priority-high' : (d.priority_score >= 4 ? 'priority-medium' : 'priority-low');
                            html += '<li class="mb-1">' + escHtml(d.name)
                                + ' <span class="priority-chip ' + cls + '">' + parseFloat(d.priority_score).toFixed(2) + '</span>'
                                + ' <span class="text-muted">(' + d.daily_hours + 'h today)</span>'
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

    $('#trip_date, #start_time, #end_time, #vehicle_id, #driver_id').on('change', checkConflict);

    // ── Client-side form validation ──────────────────────────
    $('#scheduleForm').on('submit', function (e) {
        validateTimes();
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        this.classList.add('was-validated');
    });

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

})();
</script>

</body>
</html>
