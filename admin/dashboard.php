<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/dashboard.php  -  Administrative Assistant / Head of Admin: what needs attention, trends, today, fleet and drivers
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_ui.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$thisMonth = date('Y-m');
$lastMonth = date('Y-m', strtotime('first day of last month'));
$reqNow = dashRequestsInMonth($conn, $thisMonth);
$reqBefore = dashRequestsInMonth($conn, $lastMonth);
$badNow = dashRequestsInMonth($conn, $thisMonth, ['rejected', 'lapsed']);
$badBefore = dashRequestsInMonth($conn, $lastMonth, ['rejected', 'lapsed']);

$status = [];
foreach (dashRows($conn, 'SELECT status, COUNT(*) AS n FROM vehicle_requests GROUP BY status') as $r) { $status[$r['status']] = (int)$r['n']; }
$needDriver = dashQueue($conn, 'approved', 5, 0, 1);
$noDriver = dashQueue($conn, 'approved', 5, 0, 0);          // without driver: only to be sent to the Head of Admin
$needDriverCount = (int)dashValue($conn, "SELECT COUNT(*) FROM vehicle_requests WHERE status = 'approved' AND need_driver = 1");
$waitingHead = dashQueue($conn, 'assigned');
$stalePending = dashQueue($conn, 'pending', 5, 24);
$fleet = dashFleet($conn);
$alerts = dashAlerts($conn);
$workload = array_slice(dashDriverWorkload($conn), 0, 10);
$util = dashVehicleUtilisation($conn);
$monthly = dashMonthlyRequests($conn, 6);
$byStatus = dashStatusCounts($conn, 6);
$boardDate = isValidDate((string)($_GET['board'] ?? '')) ? $_GET['board'] : date('Y-m-d');

$statusRows = [];
foreach ($byStatus as $k => $n) { if ($n > 0) { $statusRows[requestStatusLabel($k)] = $n; } }

$queueLink = isHeadAdmin() ? 'approve_assignments.php' : 'vehicle_requests.php?tab=assigned';
adminPageStart('Dashboard');
?>
    <?= dashHeader('<a class="btn btn-primary btn-sm" href="vehicle_requests.php?tab=approved">Assign drivers<span class="font-mono">' . $needDriverCount . '</span></a>'
      . '<a class="btn btn-sm" href="calendar.php">Calendar</a>') ?>

    <?= dashStats([
        ['label' => 'Requests this month', 'value' => $reqNow, 'href' => 'vehicle_requests.php', 'trend' => dashTrend($reqNow, $reqBefore)],
        ['label' => 'Confirmed trips', 'value' => $status['processed'] ?? 0, 'href' => 'vehicle_requests.php?tab=processed'],
        ['label' => 'Rejected or lapsed this month', 'value' => $badNow, 'href' => 'vehicle_requests.php', 'trend' => dashTrend($badNow, $badBefore), 'upIsGood' => false],
        ['label' => 'Vehicles free now', 'value' => $fleet['available'], 'href' => 'vehicles.php', 'hint' => $fleet['in_use'] . ' in use, ' . $fleet['maintenance'] . ' in maintenance'],
    ]) ?>

    <?= dispatchBoardHtml($conn, $boardDate) ?>

    <div class="grid-12 mb-6 grid grid-cols-12 gap-6">
        <div class="col-span-12 lg:col-span-7">
            <div class="dash-card">
                <h6>Needs your action</h6>
                <?php if (!$needDriver && !$noDriver && !$waitingHead && !$stalePending): ?><?= dashEmpty('No requests waiting for you') ?><?php endif; ?>
                <?php foreach ([['Assign a driver', $needDriver, 'vehicle_requests.php?tab=approved'], ['Without driver: send to the Head of Admin', $noDriver, 'vehicle_requests.php?tab=approved'], ['Waiting for the Head of Admin', $waitingHead, $queueLink], ['Pending over 24 hours (send a reminder)', $stalePending, 'vehicle_requests.php?tab=pending']] as [$title, $list, $href]): if (!$list) { continue; } ?>
                    <div class="dash-group"><?= dh($title) ?></div>
                    <?php foreach ($list as $r): ?>
                        <a class="dash-row" href="<?= dh($href) ?>">
                            <span><span class="font-mono text-base-content/60">#<?= (int)$r['request_id'] ?></span> <?= dh($r['destination']) ?> <span class="text-base-content/60">&middot; <?= dh($r['staff_name']) ?></span></span>
                            <span class="text-base-content/60"><?= requestPeriodText($r) ?>, waiting <?= dashWaiting($r['waiting_h']) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-span-12 lg:col-span-5">
            <div class="dash-card">
                <h6>Alerts</h6>
                <?php if (!$alerts): ?><?= dashEmpty('No alerts') ?><?php endif; ?>
                <?php foreach ($alerts as $a): ?>
                    <a class="alert alert-soft alert-<?= dashTone($a['tone']) ?> mb-2 text-sm" href="<?= dh($a['href']) ?>"><i class="fas fa-<?= dh($a['icon']) ?>"></i><span><?= dh($a['text']) ?></span></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="grid-12 mb-6 grid grid-cols-12 gap-6">
        <div class="col-span-12 lg:col-span-8"><div class="dash-card"><h6>Requests per month</h6>
            <?= dashChart('chMonthly', 'line', array_keys($monthly), [['label' => 'Requests', 'data' => array_values($monthly), 'color' => '#0b5d3b']], ['height' => 280]) ?></div></div>
        <div class="col-span-12 lg:col-span-4"><div class="dash-card"><h6>Requests by status <small class="font-normal text-base-content/60">last 6 months</small></h6>
            <?= $statusRows ? dashRanked($statusRows) : dashEmpty('No requests yet') ?></div></div>
    </div>

    <div class="grid-12 grid grid-cols-12 gap-6">
        <div class="col-span-12 lg:col-span-6"><div class="dash-card"><h6>Driver workload <small class="font-normal text-base-content/60">this month</small></h6>
            <?= $workload ? dashChart('chDrivers', 'bar', array_column($workload, 'name'), [
                ['label' => 'Tasks in working hours', 'data' => array_map(fn($w) => $w['tasks'] - $w['outside'], $workload), 'color' => '#d4d4d8'],
                ['label' => 'Outside working hours', 'data' => array_column($workload, 'outside'), 'color' => '#0b5d3b']], ['stacked' => true, 'horizontal' => true, 'height' => 320]) : dashEmpty('No active drivers') ?>
            <p class="mt-2 text-xs text-base-content/60">The same counts the driver recommendation uses. Welfare days count as tasks.</p></div></div>
        <div class="col-span-12 lg:col-span-6"><div class="dash-card"><h6>Vehicle booked hours <small class="font-normal text-base-content/60">this month, upcoming included</small></h6>
            <?= array_sum(array_column($util, 'hours')) > 0 ? dashChart('chVehicles', 'bar', array_column($util, 'plate_number'), [['label' => 'Booked hours', 'data' => array_map('floatval', array_column($util, 'hours')), 'color' => '#a1a1aa']], ['horizontal' => true, 'height' => 320]) : dashEmpty('No vehicle is booked this month yet') ?></div></div>
    </div>
    <?php dashChartScript(); ?>
<?php adminPageEnd(); ?>
