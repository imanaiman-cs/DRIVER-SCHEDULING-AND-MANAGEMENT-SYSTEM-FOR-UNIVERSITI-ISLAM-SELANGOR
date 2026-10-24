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
    <div class="dash-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h4 class="fw-bold mb-1">Welcome, <?= dh($_SESSION['full_name'] ?? '') ?></h4>
            <div class="text-muted">Driver &middot; <?= date('l, d F Y') ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light" href="schedules.php"><i class="fas fa-route me-1"></i>All my tasks</a>
            <a class="btn btn-outline-light" href="../staff/request_vehicle.php"><i class="fas fa-car-side me-1"></i>Request a vehicle</a>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><?= dashKpi('Tasks today', count($todayTasks), 'calendar-day', 'success', 'schedules.php') ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Next 7 days', count($laterTasks), 'calendar-week', 'info', 'schedules.php') ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Tasks this month', $month['tasks'], 'list-check', 'primary', null, null, true, $month['outside'] . ' outside working hours') ?></div>
        <div class="col-6 col-xl-3"><?= dashKpi('Welfare duty days', count($welfareDays), 'hand-holding-heart', 'warning', null, null, true, $welfareDays ? 'Next: ' . date('D, d M', strtotime($welfareDays[0]['duty_date'])) : 'None coming up') ?></div>
    </div>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="dash-card">
                <h6><span><i class="fas fa-sun text-warning me-1"></i>Today</span></h6>
                <?php if (!$todayTasks): ?><div class="text-muted small py-3 text-center">No task today.</div><?php endif; ?>
                <?php foreach ($todayTasks as $t): ?>
                    <div class="dash-row">
                        <span><span class="dash-time"><?= date('H:i', strtotime($t['start'])) ?>-<?= date('H:i', strtotime($t['end'])) ?></span>
                            <span class="badge bg-<?= $t['kind'] === 'Internal' ? 'secondary' : 'success' ?> me-1"><?= dh($t['kind']) ?></span><?= dh($t['title']) ?></span>
                        <span class="text-muted"><?= dh($t['vehicle']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="dash-card">
                <h6><span><i class="fas fa-calendar-week text-success me-1"></i>Coming up</span></h6>
                <?php if (!$laterTasks): ?><div class="text-muted small py-3 text-center">Nothing in the next 7 days.</div><?php endif; ?>
                <?php foreach ($laterTasks as $t): ?>
                    <div class="dash-row">
                        <span><strong><?= date('D, d M', strtotime($t['start'])) ?></strong> <?= date('H:i', strtotime($t['start'])) ?> &middot; <?= dh($t['title']) ?></span>
                        <span class="text-muted"><?= dh($t['kind']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="small text-muted border-top pt-2 mt-2"><i class="fas fa-circle-info me-1"></i>You see a task once its request is Confirmed. This page is view only.</div>
            </div>
        </div>
    </div>
<?php adminPageEnd(); ?>
