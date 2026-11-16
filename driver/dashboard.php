<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/dashboard.php  -  the driver's start page: today, the next 7 days and this month. VIEW ONLY (full list: My Tasks).
// A driver sees a task only once its request is Confirmed.
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_ui.php';
requireDriver();

$driverId = (int)($_SESSION['driver_id'] ?? 0);
$now = date('Y-m-d H:i:s');
$weekEnd = date('Y-m-d 23:59:59', strtotime('+7 days'));
$tasks = [];
if ($driverId > 0) {
    foreach (dashRows($conn,
        "SELECT s.start_dt, s.end_dt, s.leg_no, COALESCE(vr.destination, s.destination) AS destination, v.plate_number
           FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
          WHERE s.driver_id = ? AND s.status <> 'cancelled' AND s.end_dt >= ? AND s.start_dt <= ? AND " . SCHEDULE_CONFIRMED_ONLY_SQL,
        'iss', [$driverId, $now, $weekEnd]) as $r) {
        $tasks[] = ['start' => $r['start_dt'], 'end' => $r['end_dt'], 'kind' => (int)$r['leg_no'] === 2 ? 'Return' : 'Outbound', 'title' => $r['destination'], 'vehicle' => (string)$r['plate_number']];
    }
    foreach (dashRows($conn,
        "SELECT t.task_type, t.start_dt, t.end_dt, t.location, v.plate_number FROM internal_tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
          WHERE t.driver_id = ? AND t.status = 'active' AND t.end_dt >= ? AND t.start_dt <= ?", 'iss', [$driverId, $now, $weekEnd]) as $r) {
        $tasks[] = ['start' => $r['start_dt'], 'end' => $r['end_dt'], 'kind' => 'Internal', 'title' => ucfirst(str_replace('_', ' ', $r['task_type'])) . ($r['location'] ? ' - ' . $r['location'] : ''), 'vehicle' => (string)$r['plate_number']];
    }
}
usort($tasks, fn($a, $b) => strcmp($a['start'], $b['start']));
$todayKey = date('Y-m-d');
$todayTasks = array_filter($tasks, fn($t) => substr($t['start'], 0, 10) === $todayKey);
$laterTasks = array_filter($tasks, fn($t) => substr($t['start'], 0, 10) > $todayKey);
$month = driverMonthCounts($conn, date('Y-m'))[$driverId] ?? ['tasks' => 0, 'outside' => 0, 'welfare' => 0];
$welfareDays = $driverId > 0 ? dashRows($conn, 'SELECT duty_date FROM welfare_roster WHERE driver_id = ? AND duty_date >= ? ORDER BY duty_date LIMIT 5', 'is', [$driverId, $todayKey]) : [];
adminPageStart('Dashboard');
?>
    <?= dashHeader('Today, ' . date('l j F'),
        '<a class="btn btn-primary btn-sm" href="schedules.php">All my tasks</a>'
      . '<a class="btn btn-sm" href="../staff/request_vehicle.php">Request a vehicle</a>') ?>
    <?= dashStats([
        ['label' => 'Tasks today', 'value' => count($todayTasks), 'href' => 'schedules.php'],
        ['label' => 'Next 7 days', 'value' => count($laterTasks), 'href' => 'schedules.php'],
        ['label' => 'Tasks this month', 'value' => $month['tasks'], 'hint' => $month['outside'] . ' outside working hours'],
        ['label' => 'Welfare duty days', 'value' => count($welfareDays), 'hint' => $welfareDays ? 'Next: ' . date('D j M', strtotime($welfareDays[0]['duty_date'])) : 'None coming up'],
    ]) ?>
    <div class="grid-12 grid grid-cols-12 gap-6">
        <div class="col-span-12 lg:col-span-7">
            <div class="dash-card">
                <h6>Today</h6>
                <?php if (!$todayTasks): ?><?= dashEmpty('No tasks today') ?><?php endif; ?>
                <?php foreach ($todayTasks as $t): ?>
                    <div class="dash-row">
                        <span><span class="dash-time font-mono"><?= date('H:i', strtotime($t['start'])) ?>–<?= date('H:i', strtotime($t['end'])) ?></span> <?= dh($t['title']) ?> <span class="text-base-content/60">&middot; <?= dh($t['kind']) ?></span></span>
                        <?= plate($t['vehicle']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-span-12 lg:col-span-5">
            <div class="dash-card">
                <h6>Next 7 days</h6>
                <?php if (!$laterTasks): ?><?= dashEmpty('No tasks in the next 7 days') ?><?php endif; ?>
                <?php foreach ($laterTasks as $t): ?>
                    <div class="dash-row">
                        <span><span class="font-mono"><?= date('D j M H:i', strtotime($t['start'])) ?></span> <?= dh($t['title']) ?> <span class="text-base-content/60">&middot; <?= dh($t['kind']) ?></span></span>
                        <?= plate($t['vehicle']) ?>
                    </div>
                <?php endforeach; ?>
                <p class="mt-4 text-xs text-base-content/60">A task shows here once its request is Confirmed.</p>
            </div>
        </div>
    </div>
<?php adminPageEnd(); ?>
