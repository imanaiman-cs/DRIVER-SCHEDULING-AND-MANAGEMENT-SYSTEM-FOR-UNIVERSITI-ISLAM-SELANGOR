<?php
// ============================================================
// admin/dashboard.php  -  Administrative Assistant / Head of Admin: what needs attention, today's tasks, fleet
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
require_once '../includes/dashboard_ui.php';
requireAdmin();

$isHoa = isHeadAdmin();
$assignQ = dbAll($conn, requestSelectSql() . ' WHERE ' . SQL_WAITING_ASSIGNMENT . ' ORDER BY r.is_urgent DESC, r.depart_at LIMIT 6');
$assignN = (int)dbValue($conn, 'SELECT COUNT(*) FROM requests r WHERE ' . SQL_WAITING_ASSIGNMENT);
$hoaN = (int)dbValue($conn, 'SELECT COUNT(*) FROM requests r WHERE ' . SQL_WAITING_HOA)
      + (int)dbValue($conn, "SELECT COUNT(*) FROM tasks WHERE task_source = 'internal' AND status = 'driver_assigned'")
      + (int)dbValue($conn, "SELECT COUNT(*) FROM welfare_weeks WHERE status = 'driver_assigned'")
      + (int)dbValue($conn, "SELECT COUNT(*) FROM driver_replacements WHERE status = 'pending'");
$hodN = (int)dbValue($conn, "SELECT COUNT(*) FROM requests WHERE status = 'pending' AND purpose_type <> 'personal'");
$replace = tasksNeedingReplacement($conn);
$changesN = (int)dbValue($conn, "SELECT COUNT(*) FROM schedule_changes WHERE status = 'pending'");
$today = dbAll($conn, "SELECT t.*, v.plate_number, u.full_name AS driver_name, r.destination FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                       LEFT JOIN drivers d ON d.driver_id = t.driver_id LEFT JOIN users u ON u.user_id = d.user_id LEFT JOIN requests r ON r.request_id = t.request_id
                       WHERE t.status IN ('approved','completed','driver_assigned') AND t.start_dt < ? AND t.end_dt > ? ORDER BY t.task_source = 'welfare' DESC, t.start_dt",
                       [date('Y-m-d 23:59:59'), date('Y-m-d 00:00:00')]);
$fleet = array_column(dbAll($conn, 'SELECT status, COUNT(*) AS n FROM vehicles GROUP BY status'), 'n', 'status');
$problems = dbAll($conn, "SELECT tr.*, v.plate_number, v.vehicle_id, u.full_name AS driver_name FROM task_reports tr JOIN tasks t ON t.task_id = tr.task_id
                          JOIN vehicles v ON v.vehicle_id = t.vehicle_id JOIN drivers d ON d.driver_id = tr.driver_id JOIN users u ON u.user_id = d.user_id
                          WHERE tr.vehicle_condition = 'problem' AND tr.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) ORDER BY tr.created_at DESC LIMIT 5");
$expiring = dbAll($conn, "SELECT u.full_name, d.licence_expiry, u.user_id FROM drivers d JOIN users u ON u.user_id = d.user_id
                          WHERE u.status = 'active' AND d.licence_expiry < DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY d.licence_expiry");
$pendingRoles = (int)dbValue($conn, "SELECT COUNT(*) FROM users WHERE pending_role IS NOT NULL AND status = 'active'");
$monthReq = (int)dbValue($conn, 'SELECT COUNT(*) FROM requests WHERE created_at >= ?', [date('Y-m-01 00:00:00')]);

adminPageStart('Dashboard');
echo dashHeader($isHoa ? [['Pending Approval', 'approvals.php', 'stamp', true], ['Driver Schedule', 'schedule.php', 'calendar-days', false]]
                       : [['Assign Request Task', 'assign_driver.php', 'user-plus', true], ['Driver Schedule', 'schedule.php', 'calendar-days', false]],
                roleLabel($_SESSION['role']) . ' · ' . date('l, j F Y'));
?>
<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <?= $isHoa ? dashKpi('Waiting for your approval', $hoaN, 'stamp', 'primary', 'approvals.php') : dashKpi('Waiting for the Head of Admin', $hoaN, 'stamp', 'primary') ?>
    <?= dashKpi('Waiting for driver assignment', $assignN, 'user-plus', 'warning', 'assign_driver.php') ?>
    <?= dashKpi('Waiting for Heads of Department', $hodN, 'hourglass-half', 'info', 'vehicle_requests.php?status=pending') ?>
    <?= dashKpi('Tasks needing a new driver', count($replace), 'user-pen', count($replace) ? 'danger' : 'success', 'schedule.php?view=replace') ?>
</div>
<div class="grid grid-cols-12 gap-5">
    <section class="dash-card col-span-12 xl:col-span-7">
        <h2><span><i class="fas fa-user-plus"></i>Waiting for driver assignment</span><a href="assign_driver.php">Open</a></h2>
        <?php if (!$assignQ): ?><?= dashEmpty('No request is waiting for a driver.', 'circle-check') ?><?php endif; ?>
        <?php foreach ($assignQ as $r): ?>
        <a class="dash-row" href="assign_driver.php?id=<?= (int)$r['request_id'] ?>"><span><strong>#<?= (int)$r['request_id'] ?></strong> <?= h($r['requester_name']) ?> &rarr; <?= h($r['destination']) ?><?= urgentBadge($r) ?>
            <span class="block text-xs text-base-content/65"><?= h(fmtPeriod($r['depart_at'], $r['return_at'])) ?> &middot; <?= h(requestVehicleSummary($r)) ?></span></span>
            <span class="btn btn-xs btn-primary">Assign</span></a>
        <?php endforeach; ?>
    </section>
    <section class="dash-card col-span-12 xl:col-span-5">
        <h2><span><i class="fas fa-bell"></i>Attention</span></h2>
        <?php $any = false; ?>
        <?php if ($isHoa && $changesN): $any = true; ?><a class="dash-row" href="schedule_changes.php"><span><i class="fas fa-calendar-xmark me-1 text-warning"></i><?= $changesN ?> schedule change(s) waiting</span><span class="text-xs">Open</span></a><?php endif; ?>
        <?php if ($pendingRoles): $any = true; ?><a class="dash-row" href="users.php?tab=pending"><span><i class="fas fa-user-tie me-1 text-info"></i><?= $pendingRoles ?> Head of Department role(s) to confirm</span><span class="text-xs">Open</span></a><?php endif; ?>
        <?php foreach ($problems as $p): $any = true; ?><a class="dash-row" href="task.php?id=<?= (int)$p['task_id'] ?>"><span><i class="fas fa-car-burst me-1 text-error"></i><?= h($p['plate_number']) ?>: <?= h($p['problem_type']) ?><span class="block text-xs text-base-content/65"><?= h($p['problem_desc']) ?> (<?= h($p['driver_name']) ?>)</span></span><span class="text-xs"><?= $p['can_be_used'] ? 'usable' : 'not usable' ?></span></a><?php endforeach; ?>
        <?php foreach ($expiring as $e): $any = true; ?><a class="dash-row" href="view_user.php?id=<?= (int)$e['user_id'] ?>"><span><i class="fas fa-id-card me-1 text-warning"></i><?= h($e['full_name']) ?>: licence <?= $e['licence_expiry'] < date('Y-m-d') ? 'expired' : 'expires' ?> <?= h(fmtD($e['licence_expiry'])) ?></span></a><?php endforeach; ?>
        <?php if (!$any): ?><?= dashEmpty('Nothing needs attention.', 'circle-check') ?><?php endif; ?>
    </section>
    <section class="dash-card col-span-12 xl:col-span-8">
        <h2><span><i class="fas fa-calendar-day"></i>Today</span><a href="schedule.php">Driver Schedule</a></h2>
        <?php if (!$today): ?><?= dashEmpty('No task today.', 'mug-hot') ?><?php endif; ?>
        <?php foreach ($today as $t): ?>
        <a class="dash-row" href="task.php?id=<?= (int)$t['task_id'] ?>"><span><strong><?= $t['task_source'] === 'welfare' ? '24 h' : h(date('H:i', strtotime($t['start_dt']))) ?></strong> <?= h(taskTypeLabel($t)) ?><?= $t['destination'] ? ' &rarr; ' . h($t['destination']) : '' ?>
            <span class="block text-xs text-base-content/65"><?= h($t['driver_name'] ?? '-') ?></span></span><span><?= plate($t['plate_number']) ?> <?= taskStatusBadge($t['status']) ?></span></a>
        <?php endforeach; ?>
    </section>
    <section class="dash-card col-span-12 xl:col-span-4">
        <h2><span><i class="fas fa-bus"></i>Fleet</span><a href="vehicles.php">Vehicle</a></h2>
        <div class="dash-row"><span>Active</span><strong><?= (int)($fleet['active'] ?? 0) ?></strong></div>
        <div class="dash-row"><span>Under Maintenance</span><strong><?= (int)($fleet['maintenance'] ?? 0) ?></strong></div>
        <div class="dash-row"><span>Inactive</span><strong><?= (int)($fleet['inactive'] ?? 0) ?></strong></div>
        <div class="dash-row"><span>Requests this month</span><strong><?= $monthReq ?></strong></div>
    </section>
</div>
<?php adminPageEnd(); ?>
