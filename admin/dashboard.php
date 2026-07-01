<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/dashboard.php  –  Main Admin Dashboard
// Universiti Islam Selangor (UIS)
// ============================================================

$page_title   = 'Dashboard';
$current_page = 'dashboard.php';

require_once '../config/database.php';
requireAdmin();

// ============================================================
// STATS QUERIES
// ============================================================

// 1. Total active drivers
$res = $conn->query("SELECT COUNT(*) AS cnt FROM drivers WHERE status = 'active'");
$total_active_drivers = (int) $res->fetch_assoc()['cnt'];

// 2. Total vehicles with per-status breakdown
$res = $conn->query("SELECT status, COUNT(*) AS cnt FROM vehicles GROUP BY status");
$vehicle_counts = ['available' => 0, 'in_use' => 0, 'maintenance' => 0, 'retired' => 0];
while ($row = $res->fetch_assoc()) {
    $vehicle_counts[$row['status']] = (int) $row['cnt'];
}
$total_vehicles = array_sum($vehicle_counts);

// 3. Active schedules (pending + approved + in_progress)
$res = $conn->query(
    "SELECT COUNT(*) AS cnt FROM schedules
     WHERE status IN ('pending','approved','in_progress')"
);
$total_active_schedules = (int) $res->fetch_assoc()['cnt'];

// 4. In-progress count (for subtext)
$res = $conn->query("SELECT COUNT(*) AS cnt FROM schedules WHERE status = 'in_progress'");
$in_progress_count = (int) $res->fetch_assoc()['cnt'];

// 5. Completed trips this month
$res = $conn->query(
    "SELECT COUNT(*) AS cnt FROM schedules
     WHERE status = 'completed'
       AND MONTH(trip_date) = MONTH(CURDATE())
       AND YEAR(trip_date)  = YEAR(CURDATE())"
);
$completed_this_month = (int) $res->fetch_assoc()['cnt'];

// 6. Completed trips last month (for improvement hint)
$res = $conn->query(
    "SELECT COUNT(*) AS cnt FROM schedules
     WHERE status = 'completed'
       AND MONTH(trip_date) = MONTH(CURDATE() - INTERVAL 1 MONTH)
       AND YEAR(trip_date)  = YEAR(CURDATE()  - INTERVAL 1 MONTH)"
);
$completed_last_month = (int) $res->fetch_assoc()['cnt'];
if ($completed_last_month > 0) {
    $completion_change = round((($completed_this_month - $completed_last_month) / $completed_last_month) * 100, 1);
} else {
    $completion_change = $completed_this_month > 0 ? 100 : 0;
}

// 7. Pending assignments (schedules without a driver)
$res = $conn->query(
    "SELECT COUNT(*) AS cnt FROM schedules
     WHERE driver_id IS NULL AND status NOT IN ('completed','cancelled')"
);
$pending_assignments = (int) $res->fetch_assoc()['cnt'];

// 8. Total schedules this month
$res = $conn->query(
    "SELECT COUNT(*) AS cnt FROM schedules
     WHERE MONTH(trip_date) = MONTH(CURDATE())
       AND YEAR(trip_date)  = YEAR(CURDATE())"
);
$total_schedules_month = (int) $res->fetch_assoc()['cnt'];

// ============================================================
// RECENT SCHEDULES  (last 8, joined with drivers + vehicles)
// ============================================================
$recent_sql = "
    SELECT
        s.schedule_id,
        s.destination,
        s.trip_date,
        s.status,
        COALESCE(d.name, 'Unassigned') AS driver_name,
        COALESCE(v.plate_number, 'Unassigned') AS plate_number
    FROM schedules s
    LEFT JOIN drivers  d ON s.driver_id  = d.driver_id
    LEFT JOIN vehicles v ON s.vehicle_id = v.vehicle_id
    ORDER BY s.created_at DESC
    LIMIT 8
";
$recent_result   = $conn->query($recent_sql);
$recent_schedules = [];
if ($recent_result) {
    while ($row = $recent_result->fetch_assoc()) {
        $recent_schedules[] = $row;
    }
}

// ============================================================
// TOP 5 DRIVERS BY PRIORITY SCORE
// ============================================================
$top_drivers_sql = "
    SELECT
        driver_id,
        name,
        experience_years,
        attendance_rate,
        performance_score,
        certification_score,
        ROUND(
            (LEAST(experience_years / 20.0, 1.0) * 10.0 * 0.30)
          + ((attendance_rate / 100.0) * 10.0 * 0.20)
          + (performance_score * 0.30)
          + (certification_score * 0.20),
        2) AS priority_score
    FROM drivers
    WHERE status = 'active'
    ORDER BY priority_score DESC
    LIMIT 5
";
$top_drivers_result = $conn->query($top_drivers_sql);
$top_drivers = [];
if ($top_drivers_result) {
    while ($row = $top_drivers_result->fetch_assoc()) {
        $top_drivers[] = $row;
    }
}

// ============================================================
// CHART DATA – Schedules by Status (Doughnut)
// ============================================================
$status_labels = ['Pending', 'Approved', 'In Progress', 'Completed', 'Cancelled'];
$status_keys   = ['pending', 'approved', 'in_progress', 'completed', 'cancelled'];

$status_res    = $conn->query("SELECT status, COUNT(*) AS cnt FROM schedules GROUP BY status");
$status_raw    = [];
if ($status_res) {
    while ($row = $status_res->fetch_assoc()) {
        $status_raw[$row['status']] = (int) $row['cnt'];
    }
}
$status_data = [];
foreach ($status_keys as $k) {
    $status_data[] = $status_raw[$k] ?? 0;
}

// ============================================================
// CHART DATA – Monthly Schedule Trend (last 6 months, Line)
// ============================================================
$monthly_labels = [];
$monthly_data   = [];

for ($i = 5; $i >= 0; $i--) {
    $ts             = strtotime("-{$i} months");
    $monthly_labels[] = date('M Y', $ts);

    $m = date('m', $ts);
    $y = date('Y', $ts);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM schedules
         WHERE MONTH(trip_date) = ? AND YEAR(trip_date) = ?"
    );
    $stmt->bind_param('ii', $m, $y);
    $stmt->execute();
    $monthly_data[] = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
}

// ============================================================
// Pass chart data to JS via JSON
// ============================================================
$chart_status_labels  = json_encode($status_labels);
$chart_status_data    = json_encode($status_data);
$chart_monthly_labels = json_encode($monthly_labels);
$chart_monthly_data   = json_encode($monthly_data);

// Current admin's name for the navbar
$admin_name = htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<!-- ============================================================
     MAIN CONTENT
     ============================================================ -->
<main class="main-content">

    <!-- ── Desktop Top Navbar ─────────────────────────────────── -->
    <div class="d-none d-lg-flex align-items-center justify-content-between mb-4 pb-3"
         style="border-bottom: 2px solid #e5e9f0;">

        <!-- Left: breadcrumb + title -->
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1" style="font-size:0.78rem;">
                    <li class="breadcrumb-item">
                        <a href="<?php echo SITE_URL; ?>/admin/dashboard.php"
                           class="text-decoration-none" style="color:var(--uis-primary);">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
                </ol>
            </nav>
            <h1 class="page-title mb-0" style="font-size:1.6rem;">
                <i class="fas fa-gauge-high me-2" style="color:var(--uis-primary);"></i>Dashboard
            </h1>
            <p class="page-subtitle mb-0">Welcome back, <?php echo $admin_name; ?>. Here's what's happening today.</p>
        </div>

        <!-- Right: date/time + notification bell + user dropdown -->
        <div class="d-flex align-items-center gap-3">

            <!-- Live clock -->
            <div class="text-end d-none d-xl-block">
                <div id="liveClock"
                     style="font-size:1.05rem;font-weight:700;color:var(--uis-primary);letter-spacing:0.03em;">
                </div>
                <div id="liveDate" style="font-size:0.75rem;color:#6b7280;"></div>
            </div>

            <!-- Notification Bell -->
            <div class="dropdown">
                <button class="btn btn-light btn-sm position-relative rounded-circle"
                        style="width:40px;height:40px;border:1.5px solid #e5e9f0;"
                        data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <i class="fas fa-bell" style="color:var(--uis-primary);"></i>
                    <?php if ($pending_assignments > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                          style="font-size:0.6rem;">
                        <?php echo $pending_assignments; ?>
                        <span class="visually-hidden">unassigned schedules</span>
                    </span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm"
                    style="min-width:280px;border:1px solid #e8edf5;border-radius:var(--radius-md);">
                    <li>
                        <h6 class="dropdown-header"
                            style="font-size:0.78rem;font-weight:700;color:var(--uis-primary);text-transform:uppercase;letter-spacing:0.06em;">
                            Notifications
                        </h6>
                    </li>
                    <?php if ($pending_assignments > 0): ?>
                    <li>
                        <a class="dropdown-item py-2" href="<?php echo SITE_URL; ?>/admin/schedules.php">
                            <div class="d-flex align-items-start gap-2">
                                <div class="rounded-circle bg-danger d-flex align-items-center justify-content-center flex-shrink-0"
                                     style="width:32px;height:32px;">
                                    <i class="fas fa-exclamation" style="color:#fff;font-size:0.75rem;"></i>
                                </div>
                                <div>
                                    <div style="font-size:0.82rem;font-weight:600;">
                                        <?php echo $pending_assignments; ?> Unassigned Schedule<?php echo $pending_assignments !== 1 ? 's' : ''; ?>
                                    </div>
                                    <div style="font-size:0.74rem;color:#6b7280;">Drivers not yet assigned</div>
                                </div>
                            </div>
                        </a>
                    </li>
                    <?php else: ?>
                    <li>
                        <span class="dropdown-item-text text-center py-3"
                              style="font-size:0.82rem;color:#9ca3af;">
                            <i class="fas fa-check-circle text-success me-1"></i>All schedules assigned
                        </span>
                    </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item text-center py-2"
                           href="<?php echo SITE_URL; ?>/admin/schedules.php"
                           style="font-size:0.8rem;color:var(--uis-primary);font-weight:600;">
                            View All Schedules
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Admin User Dropdown -->
            <div class="dropdown">
                <button class="btn btn-light btn-sm d-flex align-items-center gap-2"
                        style="border:1.5px solid #e5e9f0;border-radius:var(--radius-md);padding:6px 12px;"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:28px;height:28px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:0.7rem;font-weight:700;">
                        <?php
                            $initials_arr = array_filter(explode(' ', trim($_SESSION['full_name'] ?? 'Admin')));
                            $nav_initials = '';
                            foreach (array_slice($initials_arr, 0, 2) as $p) { $nav_initials .= strtoupper($p[0]); }
                            echo htmlspecialchars($nav_initials ?: 'A');
                        ?>
                    </div>
                    <span style="font-size:0.82rem;font-weight:600;color:#1a2035;">
                        <?php echo $admin_name; ?>
                    </span>
                    <i class="fas fa-chevron-down" style="font-size:0.65rem;color:#9ca3af;"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm"
                    style="border:1px solid #e8edf5;border-radius:var(--radius-md);">
                    <li>
                        <span class="dropdown-item-text" style="font-size:0.78rem;color:#6b7280;">
                            Signed in as <strong style="color:#1a2035;"><?php echo $admin_name; ?></strong>
                        </span>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item" href="<?php echo SITE_URL; ?>/logout.php"
                           onclick="return confirm('Are you sure you want to log out?');"
                           style="font-size:0.84rem;color:#dc2626;">
                            <i class="fas fa-right-from-bracket me-2"></i>Log Out
                        </a>
                    </li>
                </ul>
            </div>

        </div>
    </div><!-- /.desktop navbar -->

    <?php showFlash(); ?>

    <!-- ================================================================
         ROW 1 – STAT CARDS
         ================================================================ -->
    <div class="row g-3 mb-4">

        <!-- Card 1: Total Active Drivers -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-blue">
                <div class="stat-card-icon">
                    <i class="fas fa-users" style="font-size:1.5rem;"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo number_format($total_active_drivers); ?></div>
                    <div class="stat-card-label">Active Drivers</div>
                    <div class="mt-1" style="font-size:.72rem;color:rgba(255,255,255,.65);">
                        <i class="fas fa-circle me-1" style="font-size:.45rem;vertical-align:middle;"></i>
                        <?php echo $total_active_drivers; ?> available now
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 2: Total Vehicles -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-green">
                <div class="stat-card-icon">
                    <i class="fas fa-truck" style="font-size:1.5rem;"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo number_format($total_vehicles); ?></div>
                    <div class="stat-card-label">Total Vehicles</div>
                    <div class="mt-1 d-flex flex-wrap gap-1" style="font-size:.70rem;">
                        <span style="background:rgba(255,255,255,.20);color:#fff;padding:1px 7px;border-radius:10px;"><?php echo $vehicle_counts['available']; ?> Avail</span>
                        <span style="background:rgba(255,255,255,.20);color:#fff;padding:1px 7px;border-radius:10px;"><?php echo $vehicle_counts['in_use']; ?> In Use</span>
                        <span style="background:rgba(255,255,255,.20);color:#fff;padding:1px 7px;border-radius:10px;"><?php echo $vehicle_counts['maintenance']; ?> Maint</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 3: Active Schedules -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-amber">
                <div class="stat-card-icon">
                    <i class="fas fa-calendar-check" style="font-size:1.5rem;"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo number_format($total_active_schedules); ?></div>
                    <div class="stat-card-label">Active Schedules</div>
                    <div class="mt-1" style="font-size:.72rem;color:rgba(255,255,255,.65);">
                        <i class="fas fa-spinner me-1"></i>
                        <?php echo $in_progress_count; ?> currently in progress
                    </div>
                </div>
            </div>
        </div>

        <!-- Card 4: Completed This Month -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-teal">
                <div class="stat-card-icon">
                    <i class="fas fa-circle-check" style="font-size:1.5rem;"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?php echo number_format($completed_this_month); ?></div>
                    <div class="stat-card-label">Completed This Month</div>
                    <div class="mt-1" style="font-size:.72rem;color:rgba(255,255,255,.65);">
                        <?php if ($completion_change > 0): ?>
                            <i class="fas fa-arrow-trend-up me-1"></i>+<?php echo $completion_change; ?>% vs last month
                        <?php elseif ($completion_change < 0): ?>
                            <i class="fas fa-arrow-trend-down me-1"></i><?php echo $completion_change; ?>% vs last month
                        <?php else: ?>
                            <i class="fas fa-minus me-1"></i>Same as last month
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.row stat cards -->

    <!-- ================================================================
         ROW 2 – RECENT SCHEDULES + TOP DRIVERS
         ================================================================ -->
    <div class="row g-3 mb-4">

        <!-- Recent Schedules Table -->
        <div class="col-12 col-xl-7">
            <div class="content-card h-100">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-clock-rotate-left"></i>
                        Recent Schedules
                    </h5>
                    <a href="<?php echo SITE_URL; ?>/admin/schedules.php"
                       class="btn btn-sm btn-uis-primary">
                        <i class="fas fa-list me-1"></i>View All
                    </a>
                </div>
                <div class="content-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:0.84rem;">
                            <thead style="background:var(--uis-primary);">
                                <tr>
                                    <th class="ps-3" style="color:#fff;font-weight:600;padding:0.75rem 0.5rem;white-space:nowrap;">#</th>
                                    <th style="color:#fff;font-weight:600;padding:0.75rem 0.5rem;">Destination</th>
                                    <th style="color:#fff;font-weight:600;padding:0.75rem 0.5rem;">Driver</th>
                                    <th style="color:#fff;font-weight:600;padding:0.75rem 0.5rem;">Vehicle</th>
                                    <th style="color:#fff;font-weight:600;padding:0.75rem 0.5rem;">Date</th>
                                    <th style="color:#fff;font-weight:600;padding:0.75rem 0.5rem;">Status</th>
                                    <th class="pe-3 text-end" style="color:#fff;font-weight:600;padding:0.75rem 0.5rem;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_schedules)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4" style="color:#9ca3af;">
                                        <i class="fas fa-calendar-xmark fa-lg mb-2 d-block"></i>
                                        No schedules found
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($recent_schedules as $s): ?>
                                <tr>
                                    <td class="ps-3" style="padding:0.6rem 0.5rem;color:#6b7280;">
                                        #<?php echo htmlspecialchars((string)$s['schedule_id']); ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                        title="<?php echo htmlspecialchars($s['destination']); ?>">
                                        <?php echo htmlspecialchars($s['destination']); ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;">
                                        <?php if ($s['driver_name'] === 'Unassigned'): ?>
                                            <span class="text-muted fst-italic">Unassigned</span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($s['driver_name']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;">
                                        <?php if ($s['plate_number'] === 'Unassigned'): ?>
                                            <span class="text-muted fst-italic">Unassigned</span>
                                        <?php else: ?>
                                            <code style="background:#f0f4f8;padding:2px 6px;border-radius:4px;font-size:0.78rem;">
                                                <?php echo htmlspecialchars($s['plate_number']); ?>
                                            </code>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;white-space:nowrap;">
                                        <?php echo formatDate($s['trip_date']); ?>
                                    </td>
                                    <td style="padding:0.6rem 0.5rem;">
                                        <span class="badge <?php echo statusBadgeClass($s['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $s['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="pe-3 text-end" style="padding:0.6rem 0.5rem;white-space:nowrap;">
                                        <a href="<?php echo SITE_URL; ?>/admin/view_schedule.php?id=<?php echo (int)$s['schedule_id']; ?>"
                                           class="btn btn-sm btn-outline-primary"
                                           title="View" style="padding:3px 8px;">
                                            <i class="fas fa-eye" style="font-size:0.75rem;"></i>
                                        </a>
                                        <a href="<?php echo SITE_URL; ?>/admin/edit_schedule.php?id=<?php echo (int)$s['schedule_id']; ?>"
                                           class="btn btn-sm btn-outline-secondary"
                                           title="Edit" style="padding:3px 8px;">
                                            <i class="fas fa-pen" style="font-size:0.75rem;"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Drivers by Priority Score -->
        <div class="col-12 col-xl-5">
            <div class="content-card h-100">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-ranking-star"></i>
                        Top Drivers
                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.68rem;">Priority Score</span>
                    </h5>
                    <a href="<?php echo SITE_URL; ?>/admin/drivers.php"
                       class="btn btn-sm btn-uis-primary">
                        <i class="fas fa-users me-1"></i>All Drivers
                    </a>
                </div>
                <div class="content-card-body">
                    <?php if (empty($top_drivers)): ?>
                    <div class="text-center py-4" style="color:#9ca3af;">
                        <i class="fas fa-user-slash fa-lg mb-2 d-block"></i>
                        No active drivers found
                    </div>
                    <?php else: ?>
                    <?php
                    $medals = ['1' => '🥇', '2' => '🥈', '3' => '🥉'];
                    foreach ($top_drivers as $rank => $driver):
                        $rank_num   = $rank + 1;
                        $score      = (float) $driver['priority_score'];
                        $score_pct  = min(($score / 10) * 100, 100);
                        $bar_color  = $score >= 7 ? '#059669' : ($score >= 4 ? '#d97706' : '#dc2626');
                    ?>
                    <div class="d-flex align-items-center gap-3 mb-3<?php echo $rank_num < count($top_drivers) ? ' pb-3' : ''; ?>"
                         <?php echo $rank_num < count($top_drivers) ? 'style="border-bottom:1px solid #f0f4f8;"' : ''; ?>>

                        <!-- Rank medal / number -->
                        <div class="flex-shrink-0 text-center" style="width:28px;">
                            <?php if (isset($medals[(string)$rank_num])): ?>
                                <span style="font-size:1.3rem;line-height:1;"><?php echo $medals[(string)$rank_num]; ?></span>
                            <?php else: ?>
                                <span style="font-size:0.9rem;font-weight:700;color:#9ca3af;">#<?php echo $rank_num; ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Avatar initials -->
                        <div class="flex-shrink-0 rounded-circle d-flex align-items-center justify-content-center"
                             style="width:38px;height:38px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:0.75rem;font-weight:700;">
                            <?php
                                $parts = array_filter(explode(' ', trim($driver['name'])));
                                $di    = '';
                                foreach (array_slice($parts, 0, 2) as $p) { $di .= strtoupper($p[0]); }
                                echo htmlspecialchars($di ?: 'D');
                            ?>
                        </div>

                        <!-- Name, progress bar, exp -->
                        <div class="flex-grow-1 min-width-0">
                            <div class="d-flex justify-content-between align-items-baseline mb-1">
                                <span style="font-size:0.84rem;font-weight:600;color:#1a2035;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;"
                                      title="<?php echo htmlspecialchars($driver['name']); ?>">
                                    <?php echo htmlspecialchars($driver['name']); ?>
                                </span>
                                <span class="badge ms-2 flex-shrink-0"
                                      style="background:<?php echo $bar_color; ?>;font-size:0.72rem;">
                                    <?php echo number_format($score, 1); ?>/10
                                </span>
                            </div>
                            <div class="progress mb-1" style="height:6px;border-radius:99px;background:#e8edf5;">
                                <div class="progress-bar"
                                     role="progressbar"
                                     style="width:<?php echo number_format($score_pct, 1); ?>%;background:<?php echo $bar_color; ?>;border-radius:99px;"
                                     aria-valuenow="<?php echo $score_pct; ?>"
                                     aria-valuemin="0"
                                     aria-valuemax="100">
                                </div>
                            </div>
                            <div style="font-size:0.72rem;color:#9ca3af;">
                                <i class="fas fa-briefcase me-1"></i>
                                <?php echo number_format((float)$driver['experience_years'], 1); ?> yrs experience
                            </div>
                        </div>

                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /.row recent + top drivers -->

    <!-- ================================================================
         ROW 3 – CHARTS
         ================================================================ -->
    <div class="row g-3 mb-4">

        <!-- Doughnut: Schedules by Status -->
        <div class="col-12 col-md-5">
            <div class="content-card h-100">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-chart-pie"></i>
                        Schedules by Status
                    </h5>
                </div>
                <div class="content-card-body d-flex flex-column align-items-center justify-content-center">
                    <div style="position:relative;max-width:280px;width:100%;">
                        <canvas id="statusDoughnutChart"
                                data-labels='<?php echo $chart_status_labels; ?>'
                                data-values='<?php echo $chart_status_data; ?>'></canvas>
                    </div>
                    <!-- Legend -->
                    <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                        <?php
                        $legend_colors = ['#ffc107','#003580','#0dcaf0','#198754','#6c757d'];
                        foreach ($status_labels as $li => $label):
                        ?>
                        <div class="d-flex align-items-center gap-1" style="font-size:0.75rem;">
                            <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $legend_colors[$li]; ?>;display:inline-block;flex-shrink:0;"></span>
                            <span style="color:#374151;"><?php echo htmlspecialchars($label); ?>
                                <strong>(<?php echo $status_data[$li]; ?>)</strong>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line: Monthly Schedule Trend -->
        <div class="col-12 col-md-7">
            <div class="content-card h-100">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-chart-line"></i>
                        Monthly Schedule Trend
                    </h5>
                    <span class="badge"
                          style="background:var(--uis-light);color:var(--uis-primary);font-size:0.72rem;">
                        Last 6 Months
                    </span>
                </div>
                <div class="content-card-body">
                    <canvas id="monthlyTrendChart"
                            style="max-height:260px;"
                            data-labels='<?php echo $chart_monthly_labels; ?>'
                            data-values='<?php echo $chart_monthly_data; ?>'></canvas>
                </div>
            </div>
        </div>

    </div><!-- /.row charts -->

    <!-- ================================================================
         ROW 4 – VEHICLE STATUS OVERVIEW + QUICK ACTIONS
         ================================================================ -->
    <div class="row g-3 mb-4">

        <!-- Vehicle Status Overview -->
        <div class="col-12 col-md-6">
            <div class="content-card h-100">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-car"></i>
                        Vehicle Status Overview
                    </h5>
                    <a href="<?php echo SITE_URL; ?>/admin/vehicles.php"
                       class="btn btn-sm btn-uis-primary">
                        <i class="fas fa-eye me-1"></i>View All
                    </a>
                </div>
                <div class="content-card-body">
                    <div class="row g-2">

                        <!-- Available -->
                        <div class="col-6">
                            <div class="rounded-3 p-3 h-100"
                                 style="background:#ecfdf5;border:1.5px solid #a7f3d0;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width:34px;height:34px;background:#059669;">
                                        <i class="fas fa-check" style="color:#fff;font-size:0.75rem;"></i>
                                    </div>
                                    <span style="font-size:0.78rem;font-weight:600;color:#065f46;">Available</span>
                                </div>
                                <div style="font-size:2rem;font-weight:700;color:#059669;line-height:1;">
                                    <?php echo $vehicle_counts['available']; ?>
                                </div>
                                <div style="font-size:0.72rem;color:#6b7280;margin-top:4px;">vehicles ready</div>
                            </div>
                        </div>

                        <!-- In Use -->
                        <div class="col-6">
                            <div class="rounded-3 p-3 h-100"
                                 style="background:#eff6ff;border:1.5px solid #bfdbfe;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width:34px;height:34px;background:#003580;">
                                        <i class="fas fa-road" style="color:#fff;font-size:0.75rem;"></i>
                                    </div>
                                    <span style="font-size:0.78rem;font-weight:600;color:#1e40af;">In Use</span>
                                </div>
                                <div style="font-size:2rem;font-weight:700;color:#003580;line-height:1;">
                                    <?php echo $vehicle_counts['in_use']; ?>
                                </div>
                                <div style="font-size:0.72rem;color:#6b7280;margin-top:4px;">on active trips</div>
                            </div>
                        </div>

                        <!-- Maintenance -->
                        <div class="col-6">
                            <div class="rounded-3 p-3 h-100"
                                 style="background:#fffbeb;border:1.5px solid #fde68a;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width:34px;height:34px;background:#d97706;">
                                        <i class="fas fa-wrench" style="color:#fff;font-size:0.75rem;"></i>
                                    </div>
                                    <span style="font-size:0.78rem;font-weight:600;color:#92400e;">Maintenance</span>
                                </div>
                                <div style="font-size:2rem;font-weight:700;color:#d97706;line-height:1;">
                                    <?php echo $vehicle_counts['maintenance']; ?>
                                </div>
                                <div style="font-size:0.72rem;color:#6b7280;margin-top:4px;">under service</div>
                            </div>
                        </div>

                        <!-- Retired -->
                        <div class="col-6">
                            <div class="rounded-3 p-3 h-100"
                                 style="background:#f9fafb;border:1.5px solid #e5e7eb;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width:34px;height:34px;background:#6b7280;">
                                        <i class="fas fa-ban" style="color:#fff;font-size:0.75rem;"></i>
                                    </div>
                                    <span style="font-size:0.78rem;font-weight:600;color:#4b5563;">Retired</span>
                                </div>
                                <div style="font-size:2rem;font-weight:700;color:#6b7280;line-height:1;">
                                    <?php echo $vehicle_counts['retired']; ?>
                                </div>
                                <div style="font-size:0.72rem;color:#6b7280;margin-top:4px;">decommissioned</div>
                            </div>
                        </div>

                    </div><!-- /.row g-2 vehicle status -->

                    <!-- Total vehicles mini-bar -->
                    <?php if ($total_vehicles > 0): ?>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1" style="font-size:0.75rem;color:#6b7280;">
                            <span>Fleet utilisation</span>
                            <span><?php echo $vehicle_counts['in_use']; ?>/<?php echo $total_vehicles; ?> in use</span>
                        </div>
                        <div class="progress" style="height:8px;border-radius:99px;background:#e8edf5;">
                            <?php $avail_pct = round(($vehicle_counts['available'] / $total_vehicles) * 100, 1); ?>
                            <?php $use_pct   = round(($vehicle_counts['in_use']    / $total_vehicles) * 100, 1); ?>
                            <?php $maint_pct = round(($vehicle_counts['maintenance']/ $total_vehicles) * 100, 1); ?>
                            <div class="progress-bar" style="width:<?php echo $avail_pct; ?>%;background:#059669;" title="Available"></div>
                            <div class="progress-bar" style="width:<?php echo $use_pct; ?>%;background:#003580;" title="In Use"></div>
                            <div class="progress-bar" style="width:<?php echo $maint_pct; ?>%;background:#d97706;" title="Maintenance"></div>
                        </div>
                        <div class="d-flex gap-3 mt-1" style="font-size:0.7rem;color:#9ca3af;">
                            <span><span style="color:#059669;">&#9632;</span> Available</span>
                            <span><span style="color:#003580;">&#9632;</span> In Use</span>
                            <span><span style="color:#d97706;">&#9632;</span> Maintenance</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="col-12 col-md-6">
            <div class="content-card h-100">
                <div class="content-card-header">
                    <h5 class="content-card-title">
                        <i class="fas fa-bolt"></i>
                        Quick Actions
                    </h5>
                </div>
                <div class="content-card-body">
                    <div class="d-grid gap-2">

                        <a href="<?php echo SITE_URL; ?>/admin/add_schedule.php"
                           class="btn d-flex align-items-center gap-3 text-start p-3"
                           style="background:linear-gradient(135deg,#003580,#0056b3);color:#fff;border-radius:var(--radius-md);border:none;transition:all 0.2s;"
                           onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(0,53,128,0.35)';"
                           onmouseout="this.style.transform='';this.style.boxShadow='';">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:38px;height:38px;background:rgba(255,255,255,0.15);">
                                <i class="fas fa-calendar-plus"></i>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:0.88rem;">Create New Schedule</div>
                                <div style="font-size:0.74rem;opacity:0.8;">Plan a new trip or assignment</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto" style="opacity:0.6;"></i>
                        </a>

                        <a href="<?php echo SITE_URL; ?>/admin/add_driver.php"
                           class="btn d-flex align-items-center gap-3 text-start p-3"
                           style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:var(--radius-md);border:none;transition:all 0.2s;"
                           onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(5,150,105,0.35)';"
                           onmouseout="this.style.transform='';this.style.boxShadow='';">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:38px;height:38px;background:rgba(255,255,255,0.15);">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:0.88rem;">Add New Driver</div>
                                <div style="font-size:0.74rem;opacity:0.8;">Register a new driver profile</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto" style="opacity:0.6;"></i>
                        </a>

                        <a href="<?php echo SITE_URL; ?>/admin/add_vehicle.php"
                           class="btn d-flex align-items-center gap-3 text-start p-3"
                           style="background:linear-gradient(135deg,#d97706,#b45309);color:#fff;border-radius:var(--radius-md);border:none;transition:all 0.2s;"
                           onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(217,119,6,0.35)';"
                           onmouseout="this.style.transform='';this.style.boxShadow='';">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:38px;height:38px;background:rgba(255,255,255,0.15);">
                                <i class="fas fa-truck-medical"></i>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:0.88rem;">Add Vehicle</div>
                                <div style="font-size:0.74rem;opacity:0.8;">Add a vehicle to the fleet</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto" style="opacity:0.6;"></i>
                        </a>

                        <a href="<?php echo SITE_URL; ?>/admin/report_monthly.php"
                           class="btn d-flex align-items-center gap-3 text-start p-3"
                           style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:var(--radius-md);border:none;transition:all 0.2s;"
                           onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(124,58,237,0.35)';"
                           onmouseout="this.style.transform='';this.style.boxShadow='';">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:38px;height:38px;background:rgba(255,255,255,0.15);">
                                <i class="fas fa-file-chart-column"></i>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:0.88rem;">Generate Report</div>
                                <div style="font-size:0.74rem;opacity:0.8;">View monthly analytics report</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto" style="opacity:0.6;"></i>
                        </a>

                        <a href="<?php echo SITE_URL; ?>/admin/auto_assign.php"
                           class="btn d-flex align-items-center gap-3 text-start p-3"
                           style="background:linear-gradient(135deg,#0891b2,#0e7490);color:#fff;border-radius:var(--radius-md);border:none;transition:all 0.2s;"
                           onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(8,145,178,0.35)';"
                           onmouseout="this.style.transform='';this.style.boxShadow='';">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:38px;height:38px;background:rgba(255,255,255,0.15);">
                                <i class="fas fa-wand-magic-sparkles"></i>
                            </div>
                            <div>
                                <div style="font-weight:600;font-size:0.88rem;">Auto-Assign Drivers</div>
                                <div style="font-size:0.74rem;opacity:0.8;">Smart assignment by priority score</div>
                            </div>
                            <i class="fas fa-chevron-right ms-auto" style="opacity:0.6;"></i>
                        </a>

                    </div><!-- /.d-grid -->
                </div>
            </div>
        </div>

    </div><!-- /.row vehicle status + quick actions -->

</main><!-- /.main-content -->

<?php
// ============================================================
// INLINE CHARTS SCRIPT – injected via $extra_js
// ============================================================
$extra_js = <<<HTML
<script>
(function () {
    'use strict';

    /* ── Shared defaults ──────────────────────────────────────── */
    Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
    Chart.defaults.font.size   = 12;
    Chart.defaults.color       = '#6b7280';

    /* ── Doughnut: Schedules by Status ───────────────────────── */
    (function () {
        var el = document.getElementById('statusDoughnutChart');
        if (!el) return;

        var labels = JSON.parse(el.getAttribute('data-labels'));
        var values = JSON.parse(el.getAttribute('data-values'));

        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data:            values,
                    backgroundColor: ['#ffc107','#003580','#0dcaf0','#198754','#6c757d'],
                    borderColor:     '#ffffff',
                    borderWidth:     3,
                    hoverOffset:     8
                }]
            },
            options: {
                responsive:       true,
                maintainAspectRatio: true,
                cutout:           '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                var pct   = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : '0.0';
                                return ' ' + ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    })();

    /* ── Line: Monthly Schedule Trend ────────────────────────── */
    (function () {
        var el = document.getElementById('monthlyTrendChart');
        if (!el) return;

        var labels = JSON.parse(el.getAttribute('data-labels'));
        var values = JSON.parse(el.getAttribute('data-values'));

        /* Gradient fill */
        var ctx  = el.getContext('2d');
        var grad = ctx.createLinearGradient(0, 0, 0, 260);
        grad.addColorStop(0,   'rgba(0, 53, 128, 0.30)');
        grad.addColorStop(0.6, 'rgba(0, 53, 128, 0.06)');
        grad.addColorStop(1,   'rgba(0, 53, 128, 0.00)');

        new Chart(el, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label:           'Schedules',
                    data:            values,
                    borderColor:     '#003580',
                    borderWidth:     2.5,
                    backgroundColor: grad,
                    fill:            true,
                    tension:         0.4,
                    pointBackgroundColor: '#003580',
                    pointBorderColor:     '#ffffff',
                    pointBorderWidth:     2,
                    pointRadius:          5,
                    pointHoverRadius:     7
                }]
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                interaction: {
                    mode:      'index',
                    intersect: false
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            stepSize:  1,
                            font:      { size: 11 },
                            precision: 0
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(0,31,77,0.92)',
                        titleFont:       { size: 12, weight: '600' },
                        bodyFont:        { size: 12 },
                        padding:         10,
                        cornerRadius:    8,
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.parsed.y + ' schedule' + (ctx.parsed.y !== 1 ? 's' : '');
                            }
                        }
                    }
                }
            }
        });
    })();

    /* ── Live clock ───────────────────────────────────────────── */
    function updateClock() {
        var now     = new Date();
        var hh      = String(now.getHours()).padStart(2, '0');
        var mm      = String(now.getMinutes()).padStart(2, '0');
        var ss      = String(now.getSeconds()).padStart(2, '0');
        var days    = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        var clockEl = document.getElementById('liveClock');
        var dateEl  = document.getElementById('liveDate');

        if (clockEl) clockEl.textContent = hh + ':' + mm + ':' + ss;
        if (dateEl)  dateEl.textContent  =
            days[now.getDay()] + ', ' +
            String(now.getDate()).padStart(2, '0') + ' ' +
            months[now.getMonth()] + ' ' +
            now.getFullYear();
    }

    updateClock();
    setInterval(updateClock, 1000);

})();
</script>
HTML;

require_once '../includes/footer.php';
?>
