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

$statusColour = ['pending' => '#d4a017', 'approved' => '#2e9d63', 'assigned' => '#3a8fb7', 'processed' => '#0b5d3b',
                 'rejected' => '#c0392b', 'cancelled' => '#a3aca8', 'lapsed' => '#e07b39', 'completed' => '#33413a'];
$doLabels = []; $doData = []; $doColours = [];
foreach ($statusColour as $k => $col) { if (!empty($byStatus[$k])) { $doLabels[] = requestStatusLabel($k); $doData[] = $byStatus[$k]; $doColours[] = $col; } }

$queueLink = isHeadAdmin() ? 'approve_assignments.php' : 'vehicle_requests.php?tab=assigned';
adminPageStart('Dashboard');
?>
    <?= dashHeader($_SESSION['full_name'] ?? '', [roleLabel($_SESSION['role']), date('l, d F Y')],
        '<a class="btn btn-primary btn-sm" href="vehicle_requests.php?tab=approved"><i class="fas fa-user-check"></i>Assign drivers (' . count($needDriver) . ')</a>'
      . '<a class="btn btn-sm" href="calendar.php"><i class="fas fa-calendar-days"></i>Calendar</a>'
      . '<a class="btn btn-sm" href="reports.php"><i class="fas fa-chart-column"></i>Reports</a>') ?>

    <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Requests this month', $reqNow, 'file-lines', 'primary', 'vehicle_requests.php', dashTrend($reqNow, $reqBefore)) ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Need a driver', $status['approved'] ?? 0, 'user-clock', 'success', 'vehicle_requests.php?tab=approved', null, true, 'Approved by the Head of Department') ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Waiting for the Head of Admin', $status['assigned'] ?? 0, 'stamp', 'info', $queueLink, null, true, 'Driver Assigned') ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Rejected + lapsed this month', $badNow, 'ban', 'danger', 'vehicle_requests.php', dashTrend($badNow, $badBefore), false) ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Confirmed trips', $status['processed'] ?? 0, 'circle-check', 'primary', 'vehicle_requests.php?tab=processed') ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Pending with Heads of Department', $status['pending'] ?? 0, 'hourglass-half', 'warning', 'reminders.php', null, true, count($stalePending) . ' waiting over 24 h') ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Active drivers', $activeDrivers, 'id-badge', 'secondary', 'drivers.php', null, true, ($welfare ? 'Welfare today: ' . $welfare : 'No welfare duty set today')) ?></div>
        <div class="col-span-6 xl:col-span-3"><?= dashKpi('Vehicles available now', $fleet['available'], 'car-side', 'success', 'vehicles.php', null, true, $fleet['in_use'] . ' in use, ' . $fleet['maintenance'] . ' in maintenance') ?></div>
    </div>

    <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-12 lg:col-span-7">
            <div class="dash-card">
                <h6><span><i class="fas fa-bolt me-2 opacity-50"></i>Needs your action</span></h6>
                <?php if (!$needDriver && !$waitingHead && !$stalePending): ?><?= dashEmpty('Nothing is waiting. Well done.', 'circle-check') ?><?php endif; ?>
                <?php foreach ([['Assign a driver', $needDriver, 'vehicle_requests.php?tab=approved', 'success'], ['Waiting for the Head of Admin', $waitingHead, $queueLink, 'info'], ['Pending over 24 hours (send a reminder)', $stalePending, 'reminders.php', 'warning']] as [$title, $list, $href, $tone]): if (!$list) { continue; } ?>
                    <div class="dash-group <?= $tone === 'warning' ? 'warn' : ($tone === 'info' ? 'info' : '') ?>"><?= dh($title) ?></div>
                    <?php foreach ($list as $r): ?>
                        <a class="dash-row no-underline text-base-content" href="<?= dh($href) ?>">
                            <span><strong>#<?= (int)$r['request_id'] ?></strong> <?= dh($r['destination']) ?> <span class="text-base-content/60">&middot; <?= dh($r['staff_name']) ?></span></span>
                            <span class="text-base-content/60"><?= requestPeriodText($r) ?> &middot; waiting <?= dashWaiting($r['waiting_h']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-span-12 lg:col-span-5">
            <div class="dash-card">
                <h6><span><i class="fas fa-triangle-exclamation me-2 opacity-50"></i>Alerts</span></h6>
                <?php if (!$alerts): ?><?= dashEmpty('All clear. No alerts.', 'shield-heart') ?><?php endif; ?>
                <?php foreach ($alerts as $a): ?>
                    <a class="alert alert-soft alert-<?= dashTone($a['tone']) ?> mb-2 text-sm" href="<?= dh($a['href']) ?>"><i class="fas fa-<?= dh($a['icon']) ?>"></i><span><?= dh($a['text']) ?></span></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-12 lg:col-span-4"><div class="dash-card"><h6>Requests by status <small class="text-base-content/60 font-normal">last 6 months</small></h6>
            <?= $doData ? dashChart('chStatus', 'doughnut', $doLabels, [['label' => 'Requests', 'data' => $doData, 'colors' => $doColours]], ['centre' => [array_sum($doData), 'requests']]) : dashEmpty('No requests yet.', 'inbox') ?></div></div>
        <div class="col-span-12 lg:col-span-8"><div class="dash-card"><h6>Requests per month</h6>
            <?= dashChart('chMonthly', 'line', array_keys($monthly), [['label' => 'Requests', 'data' => array_values($monthly), 'color' => '#0b5d3b']]) ?></div></div>
    </div>

    <div class="grid-12 grid grid-cols-12 gap-4 mb-4">
        <div class="col-span-12 lg:col-span-6"><div class="dash-card"><h6>Driver workload <small class="text-base-content/60 font-normal">this month</small></h6>
            <?= $workload ? dashChart('chDrivers', 'bar', array_column($workload, 'name'), [
                ['label' => 'Tasks in working hours', 'data' => array_map(fn($w) => $w['tasks'] - $w['outside'], $workload), 'color' => '#0b5d3b'],
                ['label' => 'Outside working hours', 'data' => array_column($workload, 'outside'), 'color' => '#d4a017']], ['stacked' => true, 'horizontal' => true, 'height' => 320]) : dashEmpty('No active drivers.', 'user-slash') ?>
            <div class="text-sm text-base-content/60 mt-2">The same counts the driver recommendation uses (welfare days count as tasks).</div></div></div>
        <div class="col-span-12 lg:col-span-6"><div class="dash-card"><h6>Vehicle booked hours <small class="text-base-content/60 font-normal">this month, upcoming included</small></h6>
            <?= array_sum(array_column($util, 'hours')) > 0 ? dashChart('chVehicles', 'bar', array_column($util, 'plate_number'), [['label' => 'Booked hours', 'data' => array_map('floatval', array_column($util, 'hours')), 'color' => '#3a8fb7']], ['horizontal' => true, 'height' => 320]) : dashEmpty('No vehicle is booked this month yet.', 'car-side') ?></div></div>
    </div>

    <div class="grid-12 grid grid-cols-12 gap-4">
        <div class="col-span-12 lg:col-span-8">
            <div class="dash-card">
                <h6><span><i class="fas fa-timeline me-2 opacity-50"></i>Today &middot; <?= date('D, d M Y') ?></span><a class="text-sm font-normal" href="calendar.php">Open calendar</a></h6>
                <?php if (!$today): ?><?= dashEmpty('No trips or internal tasks today.', 'sun') ?><?php endif; ?>
                <?php foreach ($today as $t): ?>
                    <div class="dash-row">
                        <span><span class="dash-time"><?= date('H:i', strtotime($t['start'])) ?>-<?= date('H:i', strtotime($t['end'])) ?></span>
                            <span class="badge badge-sm <?= $t['kind'] === 'Internal' ? 'badge-ghost' : 'badge-soft badge-success' ?> me-1"><?= dh($t['kind']) ?></span><?= dh($t['title']) ?></span>
                        <span class="text-base-content/60"><?= dh($t['who']) ?><?= $t['vehicle'] !== '' ? ' &middot; ' . dh($t['vehicle']) : '' ?><?= $t['status'] ? ' &middot; ' . dh(requestStatusLabel($t['status'])) : '' ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="text-sm text-base-content/60 border-t border-base-300 pt-2 mt-2"><i class="fas fa-hand-holding-heart me-1"></i>Welfare duty today: <strong><?= dh($welfare ?? 'not set') ?></strong></div>
            </div>
        </div>
        <div class="col-span-12 lg:col-span-4">
            <div class="dash-card">
                <h6>Fleet now</h6>
                <?php $totalV = max(1, array_sum($fleet)); foreach (['available', 'in_use', 'maintenance', 'inactive'] as $st): ?>
                    <div class="flex justify-between text-sm mt-2"><span><span class="badge <?= vehicleStatusBadgeClass($st) ?>"><?= dh(vehicleStatusLabel($st)) ?></span></span><strong><?= (int)$fleet[$st] ?></strong></div>
                    <progress class="progress <?= ['available' => 'progress-success', 'in_use' => 'progress-primary', 'maintenance' => 'progress-warning', 'inactive' => ''][$st] ?> mt-1 h-2" value="<?= (int)$fleet[$st] ?>" max="<?= $totalV ?>"></progress>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php dashChartScript(); ?>
<?php adminPageEnd(); ?>
