<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/auto_assign.php  –  Auto Assign Drivers
// Universiti Islam Selangor (UIS)
// ============================================================

$page_title   = 'Auto Assign Drivers';
$current_page = 'auto_assign.php';

require_once '../config/database.php';
requireAdmin();

$assignments   = [];
$errors        = [];
$success_count = 0;
$preview_mode  = true;

// ── Run Assignment Algorithm ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'preview' || $_POST['action'] === 'confirm') {
        $confirm = ($_POST['action'] === 'confirm');

        $unassigned_sql = "SELECT s.*, v.plate_number, v.vehicle_type, v.capacity
                           FROM schedules s
                           LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
                           WHERE s.driver_id IS NULL AND s.status = 'pending'
                           ORDER BY s.trip_type = 'top_management' DESC, s.trip_date ASC, s.start_time ASC";
        $unassigned_result = $conn->query($unassigned_sql);

        if (!$unassigned_result) {
            $errors[] = 'Database query failed: ' . $conn->error;
        } else {
            $schedules_to_assign  = [];
            while ($row = $unassigned_result->fetch_assoc()) {
                $schedules_to_assign[] = $row;
            }

            $daily_hours         = [];
            $daily_trips         = [];
            $daily_assigned_slots = [];

            foreach ($schedules_to_assign as $schedule) {
                $trip_date  = $schedule['trip_date'];
                $start_time = $schedule['start_time'];
                $end_time   = $schedule['end_time'];
                $trip_hours = (strtotime($end_time) - strtotime($start_time)) / 3600;

                $required_driver_type = ($schedule['trip_type'] ?? 'regular') === 'top_management'
                    ? 'top_management' : 'regular';

                $avail_sql = "
                    SELECT d.*
                    FROM drivers d
                    WHERE d.status = 'active'
                      AND d.driver_type = ?
                      AND d.driver_id NOT IN (
                            SELECT driver_id FROM schedules
                            WHERE trip_date = ?
                              AND status NOT IN ('cancelled')
                              AND driver_id IS NOT NULL
                              AND (
                                (start_time <  ? AND end_time > ?)
                             OR (start_time <  ? AND end_time > ?)
                             OR (start_time >= ? AND end_time <= ?)
                              )
                      )";
                $stmt = $conn->prepare($avail_sql);
                $stmt->bind_param(
                    "ssssssss",
                    $required_driver_type,
                    $trip_date,
                    $end_time,   $start_time,
                    $end_time,   $start_time,
                    $start_time, $end_time
                );
                $stmt->execute();
                $avail_drivers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                $eligible = [];
                foreach ($avail_drivers as $drv) {
                    $did = $drv['driver_id'];

                    $conflict = false;
                    if (isset($daily_assigned_slots[$did][$trip_date])) {
                        foreach ($daily_assigned_slots[$did][$trip_date] as $slot) {
                            if ($start_time < $slot['end'] && $end_time > $slot['start']) {
                                $conflict = true;
                                break;
                            }
                        }
                    }
                    if ($conflict) continue;

                    $existing_hours = $daily_hours[$did][$trip_date] ?? 0;
                    if (!isset($daily_hours[$did][$trip_date])) {
                        $wl = getDriverWorkload($conn, $did, $trip_date);
                        $existing_hours = (float)$wl['total_hours'];
                        $daily_hours[$did][$trip_date] = $existing_hours;
                        $daily_trips[$did][$trip_date] = (int)$wl['count'];
                    }
                    if ($existing_hours + $trip_hours > 8) continue;

                    $priority = calculatePriorityScore(
                        $drv['experience_years'],
                        $drv['attendance_rate'],
                        $drv['performance_score'],
                        $drv['certification_score']
                    );

                    $eligible[] = [
                        'driver'      => $drv,
                        'priority'    => $priority,
                        'daily_trips' => $daily_trips[$did][$trip_date] ?? 0,
                        'daily_hours' => $existing_hours,
                    ];
                }

                if (empty($eligible)) {
                    $errors[] = "No available driver for schedule #{$schedule['schedule_id']} — {$schedule['destination']} on {$schedule['trip_date']} {$schedule['start_time']}–{$schedule['end_time']}.";
                    $assignments[] = [
                        'schedule' => $schedule,
                        'driver'   => null,
                        'priority' => null,
                        'status'   => 'failed',
                        'reason'   => 'No eligible driver (all busy or at daily limit)',
                    ];
                    continue;
                }

                usort($eligible, function ($a, $b) {
                    if ($a['daily_trips'] !== $b['daily_trips']) {
                        return $a['daily_trips'] - $b['daily_trips'];
                    }
                    return $b['priority'] <=> $a['priority'];
                });

                $chosen        = $eligible[0];
                $chosen_driver = $chosen['driver'];
                $did           = $chosen_driver['driver_id'];

                $daily_hours[$did][$trip_date]          = ($daily_hours[$did][$trip_date] ?? 0) + $trip_hours;
                $daily_trips[$did][$trip_date]          = ($daily_trips[$did][$trip_date] ?? 0) + 1;
                $daily_assigned_slots[$did][$trip_date][] = ['start' => $start_time, 'end' => $end_time];

                if ($confirm) {
                    $upd = $conn->prepare("UPDATE schedules SET driver_id = ?, priority_score = ?, status = 'approved', updated_at = NOW() WHERE schedule_id = ?");
                    $upd->bind_param("idi", $did, $chosen['priority'], $schedule['schedule_id']);
                    $upd->execute();
                    $success_count++;
                }

                $assignments[] = [
                    'schedule' => $schedule,
                    'driver'   => $chosen_driver,
                    'priority' => $chosen['priority'],
                    'status'   => 'assigned',
                    'reason'   => '',
                ];
            }

            if ($confirm && $success_count > 0) {
                setFlash('success', "$success_count schedule(s) successfully assigned.");
            }
            $preview_mode = !$confirm;
        }
    }
}

// ── Counts ───────────────────────────────────────────────────
$unassigned_count = (int)$conn->query(
    "SELECT COUNT(*) AS cnt FROM schedules WHERE driver_id IS NULL AND status='pending'"
)->fetch_assoc()['cnt'];

$active_drivers_count = (int)$conn->query(
    "SELECT COUNT(*) AS cnt FROM drivers WHERE status='active'"
)->fetch_assoc()['cnt'];

$assignments_made  = count(array_filter($assignments, fn($a) => $a['status'] === 'assigned'));
$failed_count      = count($errors);

// ── Top drivers ──────────────────────────────────────────────
$drivers_result = $conn->query(
    "SELECT *, (SELECT COUNT(*) FROM schedules
                WHERE driver_id = d.driver_id
                  AND status NOT IN ('cancelled')
                  AND trip_date = CURDATE()) AS today_trips
     FROM drivers d WHERE status = 'active' ORDER BY name"
);
$all_drivers = [];
while ($row = $drivers_result->fetch_assoc()) {
    $row['priority_score'] = calculatePriorityScore(
        $row['experience_years'], $row['attendance_rate'],
        $row['performance_score'], $row['certification_score']
    );
    $all_drivers[] = $row;
}
usort($all_drivers, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);

// ── Unassigned schedules preview ────────────────────────────
$preview_result = $conn->query(
    "SELECT s.*, v.plate_number, v.vehicle_type
     FROM schedules s
     LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
     WHERE s.driver_id IS NULL AND s.status = 'pending'
     ORDER BY s.trip_date, s.start_time
     LIMIT 20"
);
$preview_schedules = [];
while ($row = $preview_result->fetch_assoc()) {
    $preview_schedules[] = $row;
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<main class="main-content">

    <!-- Desktop Top Navbar moved to sidebar.php -->

    <?php showFlash(); ?>

    <!-- ================================================================
         STAT CARDS
         ================================================================ -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-3">
            <div class="stat-card stat-amber">
                <div class="stat-card-icon"><i class="fas fa-calendar-xmark" style="font-size:1.5rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo $unassigned_count; ?></div>
                    <div class="stat-card-label">Unassigned Schedules</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card stat-green">
                <div class="stat-card-icon"><i class="fas fa-user-check" style="font-size:1.5rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo $active_drivers_count; ?></div>
                    <div class="stat-card-label">Active Drivers</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card stat-blue">
                <div class="stat-card-icon"><i class="fas fa-check-double" style="font-size:1.5rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo $assignments_made; ?></div>
                    <div class="stat-card-label">Assignments Made</div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card stat-red">
                <div class="stat-card-icon"><i class="fas fa-triangle-exclamation" style="font-size:1.5rem;"></i></div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo $failed_count; ?></div>
                    <div class="stat-card-label">Failed Assignments</div>
                </div>
            </div>
        </div>

    </div>

    <!-- ================================================================
         MAIN LAYOUT
         ================================================================ -->
    <div class="row g-3">

        <!-- ── LEFT COLUMN ─────────────────────────────────────── -->
        <div class="col-12 col-lg-4">

            <!-- Priority Score Formula -->
            <div class="content-card mb-3">
                <div class="content-card-header" style="background:linear-gradient(135deg,var(--uis-primary),#1d4ed8);">
                    <h5 class="content-card-title" style="color:#fff;">
                        <i class="fas fa-info-circle"></i> Priority Score Formula
                    </h5>
                </div>
                <div class="content-card-body">
                    <div class="rounded-3 p-3 mb-3" style="background:#f0f4ff;border:1px solid #c7d7fe;font-family:monospace;font-size:0.82rem;color:#1e3a8a;line-height:1.8;">
                        Priority Score =<br>
                        &nbsp;&nbsp;(Experience &times; 30%) +<br>
                        &nbsp;&nbsp;(Attendance &times; 20%) +<br>
                        &nbsp;&nbsp;(Performance &times; 30%) +<br>
                        &nbsp;&nbsp;(Certification &times; 20%)
                    </div>
                    <ul class="list-unstyled mb-0" style="font-size:0.82rem;color:#374151;">
                        <li class="mb-1"><i class="fas fa-circle fa-xs me-2" style="color:var(--uis-primary);"></i>Experience normalised to 0–10 (max 20 yrs)</li>
                        <li class="mb-1"><i class="fas fa-circle fa-xs me-2" style="color:var(--uis-primary);"></i>Attendance rate (%) normalised to 0–10</li>
                        <li><i class="fas fa-circle fa-xs me-2" style="color:var(--uis-primary);"></i>Performance &amp; Certification on 0–10 scale</li>
                    </ul>
                </div>
            </div>

            <!-- Optimisation Rules -->
            <div class="content-card mb-3">
                <div class="content-card-header" style="background:linear-gradient(135deg,#0891b2,#0e7490);">
                    <h5 class="content-card-title" style="color:#fff;">
                        <i class="fas fa-sliders"></i> Optimisation Rules
                    </h5>
                </div>
                <div class="content-card-body">
                    <ul class="list-unstyled mb-0" style="font-size:0.83rem;color:#374151;">
                        <?php
                        $rules = [
                            'Max 8 working hours per driver per day',
                            'No overlapping schedule conflicts',
                            'Lower-workload drivers prioritised first',
                            'Highest priority score as tiebreaker',
                            'Only active drivers are considered',
                        ];
                        foreach ($rules as $i => $rule):
                        ?>
                        <li class="d-flex align-items-start gap-2 mb-2">
                            <span class="d-flex align-items-center justify-content-center flex-shrink-0 rounded-circle fw-bold"
                                  style="width:22px;height:22px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-size:0.7rem;margin-top:1px;">
                                <?php echo $i + 1; ?>
                            </span>
                            <?php echo htmlspecialchars($rule); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- Run Assignment -->
            <?php if ($unassigned_count > 0): ?>
            <div class="content-card mb-3">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-play-circle"></i> Run Assignment
                    </h5>
                </div>
                <div class="content-card-body">
                    <form method="POST" class="mb-2">
                        <input type="hidden" name="action" value="preview">
                        <button type="submit" class="btn w-100 fw-semibold"
                                style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;padding:10px;">
                            <i class="fas fa-eye me-2"></i>Preview Assignments
                        </button>
                    </form>

                    <?php
                    $confirm_count = count(array_filter($assignments, fn($a) => $a['status'] === 'assigned'));
                    if (!empty($assignments) && $preview_mode && $confirm_count > 0):
                    ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="confirm">
                        <button type="submit"
                                class="btn w-100 fw-semibold"
                                style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;padding:10px;"
                                onclick="return confirm('Confirm all <?php echo $confirm_count; ?> assignment(s)?')">
                            <i class="fas fa-circle-check me-2"></i>Confirm &amp; Apply (<?php echo $confirm_count; ?>)
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Top Drivers by Priority -->
            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-ranking-star"></i> Top Drivers
                    </h5>
                    <span class="badge" style="background:var(--uis-light);color:var(--uis-primary);font-size:0.7rem;">By Priority</span>
                </div>
                <div class="content-card-body p-0">
                    <?php if (empty($all_drivers)): ?>
                    <div class="text-center py-4" style="color:#9ca3af;font-size:0.84rem;">
                        <i class="fas fa-user-slash fa-lg mb-2 d-block"></i>No active drivers
                    </div>
                    <?php else: ?>
                    <?php $medals = ['🥇','🥈','🥉']; ?>
                    <?php foreach (array_slice($all_drivers, 0, 5) as $i => $drv): ?>
                    <?php
                        $sc = (float)$drv['priority_score'];
                        $bar_pct = min(($sc / 10) * 100, 100);
                        $bar_color = $sc >= 7 ? '#059669' : ($sc >= 5 ? '#d97706' : '#dc2626');
                        $parts = array_filter(explode(' ', trim($drv['name'])));
                        $di = '';
                        foreach (array_slice($parts, 0, 2) as $p) { $di .= strtoupper($p[0]); }
                    ?>
                    <div class="d-flex align-items-center gap-3 px-3 py-2<?php echo $i < 4 ? ' border-bottom' : ''; ?>"
                         style="border-color:#f0f4f8!important;">
                        <div class="text-center flex-shrink-0" style="width:24px;font-size:<?php echo $i < 3 ? '1.1rem' : '0.82rem'; ?>;color:#9ca3af;font-weight:700;">
                            <?php echo $i < 3 ? $medals[$i] : '#' . ($i + 1); ?>
                        </div>
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:34px;height:34px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:0.72rem;font-weight:700;">
                            <?php echo htmlspecialchars($di ?: 'D'); ?>
                        </div>
                        <div class="flex-grow-1">
                            <div style="font-size:0.82rem;font-weight:600;color:#1a2035;">
                                <?php echo htmlspecialchars($drv['name']); ?>
                            </div>
                            <div style="font-size:0.72rem;color:#9ca3af;">
                                <?php echo $drv['experience_years']; ?> yrs exp
                                · Today: <?php echo (int)$drv['today_trips']; ?> trip(s)
                            </div>
                            <div class="progress mt-1" style="height:4px;border-radius:99px;background:#e8edf5;">
                                <div class="progress-bar" style="width:<?php echo number_format($bar_pct, 1); ?>%;background:<?php echo $bar_color; ?>;border-radius:99px;"></div>
                            </div>
                        </div>
                        <span class="badge rounded-pill flex-shrink-0"
                              style="background:<?php echo $bar_color; ?>;font-size:0.72rem;">
                            <?php echo number_format($sc, 1); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.col-lg-4 -->

        <!-- ── RIGHT COLUMN ────────────────────────────────────── -->
        <div class="col-12 col-lg-8">

            <!-- Error alerts -->
            <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert"
                 style="border-radius:var(--radius-md);border:none;font-size:0.85rem;">
                <i class="fas fa-circle-exclamation flex-shrink-0"></i>
                <div><?php echo htmlspecialchars($err); ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
            <?php endforeach; ?>

            <!-- Assignment Results (after preview or confirm) -->
            <?php if (!empty($assignments)): ?>
            <div class="content-card mb-3">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-list-check"></i>
                        <?php echo $preview_mode ? 'Preview Results' : 'Assignment Results'; ?>
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge"
                              style="background:<?php echo $preview_mode ? '#fef3c7;color:#92400e' : '#d1fae5;color:#065f46'; ?>;font-size:0.72rem;">
                            <?php echo $preview_mode ? 'Preview' : 'Confirmed'; ?>
                        </span>
                        <span style="font-size:0.78rem;color:#6b7280;"><?php echo count($assignments); ?> processed</span>
                    </div>
                </div>
                <div class="content-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:0.83rem;">
                            <thead>
                                <tr style="background:linear-gradient(135deg,var(--uis-primary),#1d4ed8);">
                                    <th class="ps-3" style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;white-space:nowrap;">#</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Destination</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Date / Time</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Driver</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Score</th>
                                    <th class="pe-3" style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td class="ps-3" style="padding:0.6rem 0.5rem;color:#6b7280;">
                                        #<?php echo $a['schedule']['schedule_id']; ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?php echo htmlspecialchars($a['schedule']['destination']); ?>">
                                        <?php echo htmlspecialchars($a['schedule']['destination']); ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;white-space:nowrap;">
                                        <div style="font-weight:600;color:#1a2035;">
                                            <?php echo formatDate($a['schedule']['trip_date']); ?>
                                        </div>
                                        <div style="font-size:0.75rem;color:#6b7280;">
                                            <?php echo substr($a['schedule']['start_time'], 0, 5); ?> –
                                            <?php echo substr($a['schedule']['end_time'], 0, 5); ?>
                                        </div>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;">
                                        <?php if ($a['driver']): ?>
                                            <span style="font-weight:600;color:#1a2035;">
                                                <?php echo htmlspecialchars($a['driver']['name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#dc2626;font-size:0.8rem;">
                                                <i class="fas fa-xmark me-1"></i>Unassignable
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;">
                                        <?php if ($a['priority'] !== null):
                                            $sc = (float)$a['priority'];
                                            $bc = $sc >= 7 ? '#059669' : ($sc >= 5 ? '#d97706' : '#dc2626');
                                        ?>
                                        <span class="badge rounded-pill" style="background:<?php echo $bc; ?>;">
                                            <?php echo number_format($sc, 1); ?>
                                        </span>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-3" style="padding:0.6rem 0.5rem;">
                                        <?php if ($a['status'] === 'assigned'): ?>
                                        <span class="badge"
                                              style="background:<?php echo $preview_mode ? '#fef3c7;color:#92400e' : '#d1fae5;color:#065f46'; ?>;">
                                            <?php echo $preview_mode ? 'Will Assign' : 'Assigned'; ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge" style="background:#fee2e2;color:#991b1b;"
                                              title="<?php echo htmlspecialchars($a['reason']); ?>">
                                            Failed
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pending Unassigned Schedules (initial view) -->
            <?php if (!empty($preview_schedules) && empty($assignments)): ?>
            <div class="content-card">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-calendar-xmark"></i> Pending Unassigned Schedules
                    </h5>
                    <span style="font-size:0.78rem;color:#6b7280;"><?php echo $unassigned_count; ?> total</span>
                </div>
                <div class="content-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:0.83rem;">
                            <thead>
                                <tr style="background:linear-gradient(135deg,var(--uis-primary),#1d4ed8);">
                                    <th class="ps-3" style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">#</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Destination</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Date</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Time</th>
                                    <th style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Vehicle</th>
                                    <th class="pe-3" style="color:#fff;font-weight:600;padding:0.7rem 0.5rem;">Pax</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_schedules as $ps): ?>
                                <tr>
                                    <td class="ps-3" style="padding:0.6rem 0.5rem;color:#6b7280;">
                                        #<?php echo $ps['schedule_id']; ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?php echo htmlspecialchars($ps['destination']); ?>">
                                        <?php echo htmlspecialchars($ps['destination']); ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;white-space:nowrap;font-weight:600;color:#1a2035;">
                                        <?php echo formatDate($ps['trip_date']); ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;white-space:nowrap;color:#6b7280;">
                                        <?php echo substr($ps['start_time'], 0, 5); ?> –
                                        <?php echo substr($ps['end_time'], 0, 5); ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;">
                                        <?php if (!empty($ps['plate_number'])): ?>
                                        <code style="background:#f0f4f8;padding:2px 6px;border-radius:4px;font-size:0.78rem;">
                                            <?php echo htmlspecialchars($ps['plate_number']); ?>
                                        </code>
                                        <span style="font-size:0.75rem;color:#9ca3af;margin-left:4px;">
                                            <?php echo ucfirst($ps['vehicle_type'] ?? ''); ?>
                                        </span>
                                        <?php else: ?>
                                        <span style="color:#9ca3af;font-style:italic;font-size:0.8rem;">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-3" style="padding:0.6rem 0.5rem;">
                                        <i class="fas fa-users fa-xs me-1" style="color:#9ca3af;"></i>
                                        <?php echo (int)$ps['passenger_count']; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($unassigned_count > 20): ?>
                    <div class="px-3 py-2" style="background:#f8fafc;border-top:1px solid #e8edf5;font-size:0.78rem;color:#6b7280;">
                        Showing 20 of <?php echo $unassigned_count; ?> unassigned schedules.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- All Assigned State -->
            <?php if ($unassigned_count === 0 && empty($assignments)): ?>
            <div class="content-card">
                <div class="content-card-body text-center py-5">
                    <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                         style="width:72px;height:72px;background:linear-gradient(135deg,#059669,#047857);">
                        <i class="fas fa-circle-check fa-2x" style="color:#fff;"></i>
                    </div>
                    <h5 style="font-weight:700;color:#1a2035;" class="mb-2">All Schedules Assigned</h5>
                    <p style="color:#6b7280;font-size:0.88rem;" class="mb-4">
                        There are no pending schedules without an assigned driver.
                    </p>
                    <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
                       class="btn btn-uis-primary">
                        <i class="fas fa-list me-2"></i>View All Schedules
                    </a>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.col-lg-8 -->
    </div><!-- /.row -->

</main>

<?php require_once '../includes/footer.php'; ?>
