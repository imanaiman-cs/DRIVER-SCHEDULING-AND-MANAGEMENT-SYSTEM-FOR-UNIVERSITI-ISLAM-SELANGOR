<?php
// ============================================================
// admin/dashboard.php  -  View Layer: Administrative Assistant / Head of Admin start page
// What needs attention, today's tasks and the fleet, all through the Domain Layer.
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
require_once '../includes/dashboard_ui.php';
requireAdmin();

$me = currentUser();
$isHoa = $me['role'] === User::HOA;
$ac = new AssignmentController();
$ap = new ApprovalController();
$rc = new RequestController();
$assignQ = $ac->listWaiting('Request');
$replace = $ac->listWaiting('Replacement');
$pending = $ap->countPending(currentUser());
$hoaN = $isHoa ? $pending['head'] + $pending['request'] + $pending['internal'] + $pending['welfare'] + $pending['replacement'] : null;
$hodN = $rc->listRequests($me, 'all', ['status' => Request::PENDING])['total'];
$today = (new ReportController())->getSchedule(date('Y-m-d'), date('Y-m-d', strtotime('+1 day')));
$vc = new VehicleController();
$fleet = array_count_values(array_column($vc->listVehicles(), 'status'));
$problems = $vc->recentProblems();
$expiring = (new AccountController())->expiringLicences();
$pendingRoles = (new AccountController())->listAccounts(['pending' => true])['total'];
$monthReq = $rc->listRequests($me, 'all', ['from' => date('Y-m-01')])['total'];

adminPageStart('Dashboard');
echo dashHeader($isHoa ? [['Pending Approval', 'approvals.php', 'stamp', true], ['Driver Schedule', 'schedule.php', 'calendar-days', false]]
                       : [['Assign Request Task', 'assign_driver.php', 'user-plus', true], ['Driver Schedule', 'schedule.php', 'calendar-days', false]],
                $me['role'] . ' · ' . date('l, j F Y'));
?>
<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <?= $isHoa ? dashKpi('Waiting for your approval', $hoaN, 'stamp', 'primary', 'approvals.php') : dashKpi('Requests this month', $monthReq, 'file-signature', 'primary', 'vehicle_requests.php') ?>
    <?= dashKpi('Waiting for driver assignment', count($assignQ), 'user-plus', 'warning', 'assign_driver.php') ?>
    <?= dashKpi('Pending (waiting for a head)', $hodN, 'hourglass-half', 'info', 'vehicle_requests.php?status=Pending') ?>
    <?= dashKpi('Tasks needing a new driver', count($replace), 'user-pen', count($replace) ? 'danger' : 'success', 'schedule.php?view=replace') ?>
</div>
<div class="grid grid-cols-12 gap-5">
    <section class="dash-card col-span-12 xl:col-span-7">
        <h2><span><i class="fas fa-user-plus"></i>Waiting for driver assignment</span><a href="assign_driver.php">Open</a></h2>
        <?php if (!$assignQ): ?><?= dashEmpty('No request is waiting for a driver.', 'circle-check') ?><?php endif; ?>
        <?php foreach (array_slice($assignQ, 0, 6) as $r): ?>
        <a class="dash-row" href="assign_driver.php?id=<?= (int)$r['request_id'] ?>"><span><strong>#<?= (int)$r['request_id'] ?></strong> <?= h($r['requester_name']) ?> &rarr; <?= h($r['destination']) ?><?= urgentBadge($r) ?>
            <span class="block text-xs text-base-content/65"><?= h(fmtPeriod($r['depart_datetime'], $r['return_datetime'])) ?> &middot; <?= h($r['vehicle_type'] . ' x ' . $r['quantity'] . ', ' . $r['driver_option']) ?></span></span>
            <span class="btn btn-xs btn-primary">Assign</span></a>
        <?php endforeach; ?>
    </section>
    <section class="dash-card col-span-12 xl:col-span-5">
        <h2><span><i class="fas fa-bell"></i>Attention</span></h2>
        <?php $any = false; ?>
        <?php if ($isHoa && $pending['schedule']): $any = true; ?><a class="dash-row" href="schedule_changes.php"><span><i class="fas fa-calendar-xmark me-1 text-warning"></i><?= (int)$pending['schedule'] ?> schedule change(s) waiting</span><span class="text-xs">Open</span></a><?php endif; ?>
        <?php if ($pendingRoles): $any = true; ?><a class="dash-row" href="users.php?tab=pending"><span><i class="fas fa-user-tie me-1 text-info"></i><?= $pendingRoles ?> Head of Department role(s) to confirm</span><span class="text-xs">Open</span></a><?php endif; ?>
        <?php foreach ($problems as $p): $any = true; ?><a class="dash-row" href="task.php?id=<?= (int)$p['task_id'] ?>"><span><i class="fas fa-car-burst me-1 text-error"></i><?= h($p['plate_no']) ?>: <?= h($p['problem_type']) ?><span class="block text-xs text-base-content/65"><?= h($p['problem_desc']) ?> (<?= h($p['driver_name']) ?>)</span></span><span class="text-xs"><?= $p['can_be_used'] ? 'usable' : 'not usable' ?></span></a><?php endforeach; ?>
        <?php foreach ($expiring as $e): $any = true; ?><a class="dash-row" href="view_user.php?id=<?= (int)$e['driver_id'] ?>"><span><i class="fas fa-id-card me-1 text-warning"></i><?= h($e['name']) ?>: licence <?= $e['licence_expiry'] < date('Y-m-d') ? 'expired' : 'expires' ?> <?= h(fmtD($e['licence_expiry'])) ?></span></a><?php endforeach; ?>
        <?php if (!$any): ?><?= dashEmpty('Nothing needs attention.', 'circle-check') ?><?php endif; ?>
    </section>
    <section class="dash-card col-span-12 xl:col-span-8">
        <h2><span><i class="fas fa-calendar-day"></i>Today</span><a href="schedule.php">Driver Schedule</a></h2>
        <?php if (!$today): ?><?= dashEmpty('No task today.', 'mug-hot') ?><?php endif; ?>
        <?php foreach ($today as $t): ?>
        <a class="dash-row" href="task.php?id=<?= (int)$t['task_id'] ?>"><span><strong><?= $t['task_source'] === Task::WELFARE ? '24 h' : h(date('H:i', strtotime($t['start_datetime']))) ?></strong> <?= h(taskTypeLabel($t)) ?><?= $t['destination'] ? ' &rarr; ' . h($t['destination']) : '' ?>
            <span class="block text-xs text-base-content/65"><?= h($t['driver_name'] ?? 'Waiting for driver') ?></span></span><span><?= plate($t['plate_no']) ?> <?= taskStatusBadge($t['status']) ?></span></a>
        <?php endforeach; ?>
    </section>
    <section class="dash-card col-span-12 xl:col-span-4">
        <h2><span><i class="fas fa-bus"></i>Fleet</span><a href="vehicles.php">Vehicle</a></h2>
        <div class="dash-row"><span>Active</span><strong><?= (int)($fleet['Active'] ?? 0) ?></strong></div>
        <div class="dash-row"><span>Under Maintenance</span><strong><?= (int)($fleet['Under Maintenance'] ?? 0) ?></strong></div>
        <div class="dash-row"><span>Inactive</span><strong><?= (int)($fleet['Inactive'] ?? 0) ?></strong></div>
        <div class="dash-row"><span>Requests this month</span><strong><?= $monthReq ?></strong></div>
    </section>
</div>
<?php adminPageEnd(); ?>
