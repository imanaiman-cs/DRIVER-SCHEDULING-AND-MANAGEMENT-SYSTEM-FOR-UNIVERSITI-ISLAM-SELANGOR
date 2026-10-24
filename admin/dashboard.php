<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/dashboard.php  -  Administrative Assistant / Head of Admin: what needs attention, trends, today, fleet and drivers
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_ui.php';
requireAdmin();

$thisMonth = date('Y-m');
$lastMonth = date('Y-m', strtotime('first day of last month'));
$reqNow = dashRequestsInMonth($conn, $thisMonth);
$reqBefore = dashRequestsInMonth($conn, $lastMonth);
$badNow = dashRequestsInMonth($conn, $thisMonth, ['rejected', 'lapsed']);
$badBefore = dashRequestsInMonth($conn, $lastMonth, ['rejected', 'lapsed']);

$status = [];
foreach (dashRows($conn, 'SELECT status, COUNT(*) AS n FROM vehicle_requests GROUP BY status') as $r) { $status[$r['status']] = (int)$r['n']; }
$needDriver = dashQueue($conn, 'approved');
$waitingHead = dashQueue($conn, 'assigned');
$stalePending = dashQueue($conn, 'pending', 5, 24);
$fleet = dashFleet($conn);
$alerts = dashAlerts($conn);
$today = dashToday($conn);
$welfare = dashWelfareToday($conn);
$workload = array_slice(dashDriverWorkload($conn), 0, 10);
$util = dashVehicleUtilisation($conn);
$monthly = dashMonthlyRequests($conn, 6);
$byStatus = dashStatusCounts($conn, 6);
$activeDrivers = (int)dashValue($conn, "SELECT COUNT(*) FROM drivers WHERE status = 'active'");

$statusColour = ['pending' => '#ffc107', 'approved' => '#198754', 'assigned' => '#0dcaf0', 'processed' => '#0d6efd',
                 'rejected' => '#dc3545', 'cancelled' => '#6c757d', 'lapsed' => '#fd7e14', 'completed' => '#212529'];
$doLabels = []; $doData = []; $doColours = [];
foreach ($statusColour as $k => $col) { if (!empty($byStatus[$k])) { $doLabels[] = requestStatusLabel($k); $doData[] = $byStatus[$k]; $doColours[] = $col; } }

$queueLink = isHeadAdmin() ? 'approve_assignments.php' : 'vehicle_requests.php?tab=assigned';
adminPageStart('Dashboard');
?>
    <div class="dash-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h4 class="fw-bold mb-1">Welcome, <?= dh($_SESSION['full_name'] ?? '') ?></h4>
            <div class="text-muted"><?= dh(roleLabel($_SESSION['role'])) ?> &middot; <?= date('l, d F Y') ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-light btn-sm" href="vehicle_requests.php?tab=approved"><i class="fas fa-user-check me-1"></i>Assign drivers (<?= count($needDriver) ?>)</a>
            <a class="btn btn-outline-light btn-sm" href="calendar.php"><i class="fas fa-calendar-days me-1"></i>Calendar</a>
            <a class="btn btn-outline-light btn-sm" href="reports.php"><i class="fas fa-chart-column me-1"></i>Reports</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><?= dashKpi('Requests this month', $reqNow, 'file-lines', 'primary', 'vehicle_requests.php', dashTrend($reqNow, $reqBefore)) ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Need a driver', $status['approved'] ?? 0, 'user-clock', 'success', 'vehicle_requests.php?tab=approved', null, true, 'Approved by the Head of Department') ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Waiting for the Head of Admin', $status['assigned'] ?? 0, 'stamp', 'info', $queueLink, null, true, 'Driver Assigned') ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Rejected + lapsed this month', $badNow, 'ban', 'danger', 'vehicle_requests.php', dashTrend($badNow, $badBefore), false) ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Confirmed trips', $status['processed'] ?? 0, 'circle-check', 'primary', 'vehicle_requests.php?tab=processed') ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Pending with Heads of Department', $status['pending'] ?? 0, 'hourglass-half', 'warning', 'reminders.php', null, true, count($stalePending) . ' waiting over 24 h') ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Active drivers', $activeDrivers, 'id-badge', 'secondary', 'drivers.php', null, true, ($welfare ? 'Welfare today: ' . $welfare : 'No welfare duty set today')) ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Vehicles available now', $fleet['available'], 'car-side', 'success', 'vehicles.php', null, true, $fleet['in_use'] . ' in use, ' . $fleet['maintenance'] . ' in maintenance') ?></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="dash-card">
                <h6><span><i class="fas fa-bolt text-warning me-1"></i>Needs your action</span></h6>
                <?php if (!$needDriver && !$waitingHead && !$stalePending): ?><div class="text-muted small py-3 text-center"><i class="fas fa-circle-check text-success me-1"></i>Nothing is waiting. Well done.</div><?php endif; ?>
                <?php foreach ([['Assign a driver', $needDriver, 'vehicle_requests.php?tab=approved', 'success'], ['Waiting for the Head of Admin', $waitingHead, $queueLink, 'info'], ['Pending over 24 hours (send a reminder)', $stalePending, 'reminders.php', 'warning']] as [$title, $list, $href, $tone]): if (!$list) { continue; } ?>
                    <div class="small fw-semibold text-<?= $tone === 'warning' ? 'warning-emphasis' : $tone ?> mt-2"><?= dh($title) ?></div>
                    <?php foreach ($list as $r): ?>
                        <a class="dash-row text-decoration-none text-dark" href="<?= dh($href) ?>">
                            <span><strong>#<?= (int)$r['request_id'] ?></strong> <?= dh($r['destination']) ?> <span class="text-muted">&middot; <?= dh($r['staff_name']) ?></span></span>
                            <span class="text-muted"><?= requestPeriodText($r) ?> &middot; waiting <?= dashWaiting($r['waiting_h']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dash-card">
                <h6><span><i class="fas fa-triangle-exclamation text-danger me-1"></i>Alerts</span></h6>
                <?php if (!$alerts): ?><div class="text-muted small py-3 text-center">No alerts.</div><?php endif; ?>
                <?php foreach ($alerts as $a): ?>
                    <a class="dash-alert dash-alert-<?= dh($a['tone']) ?>" href="<?= dh($a['href']) ?>"><i class="fas fa-<?= dh($a['icon']) ?> mt-1"></i><span><?= dh($a['text']) ?></span></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4"><div class="dash-card"><h6>Requests by status <small class="text-muted fw-normal">last 6 months</small></h6>
            <?= $doData ? dashChart('chStatus', 'doughnut', $doLabels, [['label' => 'Requests', 'data' => $doData, 'colors' => $doColours]]) : '<div class="text-muted small py-4 text-center">No requests yet.</div>' ?></div></div>
        <div class="col-lg-8"><div class="dash-card"><h6>Requests per month</h6>
            <?= dashChart('chMonthly', 'line', array_keys($monthly), [['label' => 'Requests', 'data' => array_values($monthly), 'color' => '#198754']]) ?></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="dash-card"><h6>Driver workload <small class="text-muted fw-normal">this month</small></h6>
            <?= $workload ? dashChart('chDrivers', 'bar', array_column($workload, 'name'), [
                ['label' => 'Tasks in working hours', 'data' => array_map(fn($w) => $w['tasks'] - $w['outside'], $workload), 'color' => '#198754'],
                ['label' => 'Outside working hours', 'data' => array_column($workload, 'outside'), 'color' => '#fd7e14']], ['stacked' => true, 'horizontal' => true, 'height' => 320]) : '<div class="text-muted small py-4 text-center">No drivers.</div>' ?>
            <div class="small text-muted mt-2">The same counts the driver recommendation uses (welfare days count as tasks).</div></div></div>
        <div class="col-lg-6"><div class="dash-card"><h6>Vehicle booked hours <small class="text-muted fw-normal">last 30 days</small></h6>
            <?= $util ? dashChart('chVehicles', 'bar', array_column($util, 'plate_number'), [['label' => 'Booked hours', 'data' => array_map('floatval', array_column($util, 'hours')), 'color' => '#0d6efd']], ['horizontal' => true, 'height' => 320]) : '<div class="text-muted small py-4 text-center">No vehicles.</div>' ?></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="dash-card">
                <h6><span><i class="fas fa-timeline text-success me-1"></i>Today &middot; <?= date('D, d M Y') ?></span><a class="small fw-normal" href="calendar.php">Open calendar</a></h6>
                <?php if (!$today): ?><div class="text-muted small py-3 text-center">No trips or internal tasks today.</div><?php endif; ?>
                <?php foreach ($today as $t): ?>
                    <div class="dash-row">
                        <span><span class="dash-time"><?= date('H:i', strtotime($t['start'])) ?>-<?= date('H:i', strtotime($t['end'])) ?></span>
                            <span class="badge bg-<?= $t['kind'] === 'Internal' ? 'secondary' : 'success' ?> me-1"><?= dh($t['kind']) ?></span><?= dh($t['title']) ?></span>
                        <span class="text-muted"><?= dh($t['who']) ?><?= $t['vehicle'] !== '' ? ' &middot; ' . dh($t['vehicle']) : '' ?><?= $t['status'] ? ' &middot; ' . dh(requestStatusLabel($t['status'])) : '' ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="small text-muted border-top pt-2 mt-2"><i class="fas fa-hand-holding-heart me-1"></i>Welfare duty today: <strong><?= dh($welfare ?? 'not set') ?></strong></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="dash-card">
                <h6>Fleet now</h6>
                <?php $totalV = max(1, array_sum($fleet)); foreach (['available', 'in_use', 'maintenance', 'inactive'] as $st): ?>
                    <div class="d-flex justify-content-between small mt-2"><span><span class="badge <?= vehicleStatusBadgeClass($st) ?>"><?= dh(vehicleStatusLabel($st)) ?></span></span><strong><?= (int)$fleet[$st] ?></strong></div>
                    <div class="dash-bar"><span style="width:<?= round($fleet[$st] / $totalV * 100) ?>%"></span></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php dashChartScript(); ?>
<?php adminPageEnd(); ?>
