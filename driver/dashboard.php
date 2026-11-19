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
    <?= dashHeader([['Request a vehicle', '../staff/request_vehicle.php', 'car-side', false], ['All my tasks', 'schedules.php', 'route', true]], 'Driver · ' . date('l, j F Y')) ?>

    <div class="grid grid-cols-12 gap-5">
        <!-- Left: this month as a pass card -->
        <section class="e-card col-span-12 md:col-span-6 xl:col-span-3">
            <div class="e-head"><h2>This month</h2><a href="schedules.php" class="e-icon-btn" aria-label="Open My Tasks"><i class="fas fa-arrow-right"></i></a></div>
            <div class="e-accent">
                <div class="flex items-center justify-between text-xs"><span class="font-bold tracking-[0.18em]">UIS &middot; DRIVER</span><i class="fas fa-id-badge" aria-hidden="true"></i></div>
                <div class="mt-5 text-5xl leading-none font-bold tabular-nums"><?= (int)$month['tasks'] ?></div>
                <div class="mt-1 text-xs text-white/85">Tasks this month</div>
                <div class="mt-5 flex justify-between gap-2 text-[0.7rem] text-white/85"><span><?= (int)$month['outside'] ?> outside working hours</span></div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="e-tile"><span class="e-tile-label">Tasks today</span><span class="font-medium tabular-nums"><?= count($todayTasks) ?></span></div>
                <div class="e-tile"><span class="e-tile-label">Next 7 days</span><span class="font-medium tabular-nums"><?= count($laterTasks) ?></span></div>
            </div>
        </section>

        <!-- Middle: today as a timeline -->
        <section class="e-card col-span-12 md:col-span-6 xl:col-span-6">
            <div class="e-head"><div><h2>Today</h2><p class="text-xs text-base-content/65"><?= date('l, j F Y') ?></p></div><span class="text-xs text-base-content/65"><?= count($todayTasks) ?> task<?= count($todayTasks) === 1 ? '' : 's' ?></span></div>
            <?php if (!$todayTasks): ?><?= dashEmpty('No task today. Enjoy your day.', 'sun') ?><?php endif; ?>
            <ol class="space-y-2.5">
                <?php foreach ($todayTasks as $t): $int = $t['kind'] === 'Internal'; ?>
                <li class="flex gap-3">
                    <span class="w-12 shrink-0 pt-2.5 text-xs font-semibold text-base-content/65 tabular-nums"><?= date('H:i', strtotime($t['start'])) ?></span>
                    <div class="flex min-w-0 flex-1 items-center gap-3 rounded-2xl border-s-4 <?= $int ? 'border-base-content/30 bg-base-200' : 'border-primary bg-primary/10' ?> px-3 py-2.5">
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold"><?= dh($t['title']) ?></span>
                            <span class="block text-xs text-base-content/65"><?= dh($t['kind']) ?> &middot; <?= date('H:i', strtotime($t['start'])) ?>–<?= date('H:i', strtotime($t['end'])) ?></span></span>
                        <?= plate($t['vehicle']) ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <!-- Right: coming up and welfare duty -->
        <div class="col-span-12 flex flex-col gap-5 xl:col-span-3">
            <section class="e-card">
                <div class="e-head"><h2>Coming up</h2><span class="text-xs text-base-content/65">7 days</span></div>
                <?php if (!$laterTasks): ?><?= dashEmpty('Nothing in the next 7 days.', 'calendar-check') ?><?php endif; ?>
                <ol class="space-y-3">
                    <?php foreach ($laterTasks as $t): ?>
                    <li class="flex items-start gap-3 text-sm">
                        <span class="grid w-11 shrink-0 place-items-center rounded-xl bg-base-200 py-1 text-center leading-tight"><span class="text-[0.65rem] text-base-content/65"><?= date('D', strtotime($t['start'])) ?></span><b><?= date('j', strtotime($t['start'])) ?></b></span>
                        <span class="min-w-0"><span class="block truncate font-semibold"><?= dh($t['title']) ?></span><span class="block text-xs text-base-content/65"><?= date('H:i', strtotime($t['start'])) ?> &middot; <?= dh($t['kind']) ?></span></span>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <section class="e-card">
                <div class="e-head mb-3"><h2>Welfare duty</h2><span class="text-xs text-base-content/65"><?= count($welfareDays) ?></span></div>
                <?php if (!$welfareDays): ?><p class="text-sm text-base-content/65">None coming up</p><?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($welfareDays as $w): ?><span class="rounded-full bg-secondary/20 px-3 py-1 text-xs font-semibold"><?= date('D, d M', strtotime($w['duty_date'])) ?></span><?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
    <p class="mt-4 text-sm text-base-content/65"><i class="fas fa-circle-info me-1" aria-hidden="true"></i>You see a task once its request is Confirmed. This page is view only.</p>
<?php adminPageEnd(); ?>
