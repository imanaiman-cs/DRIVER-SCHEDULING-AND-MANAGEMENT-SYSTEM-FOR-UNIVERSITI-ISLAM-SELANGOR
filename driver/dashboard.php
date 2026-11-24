<?php
// ============================================================
// driver/dashboard.php  -  Driver start page: next tasks, reports to write, this month, my own requests
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
require_once '../includes/dashboard_my.php';
requireRoles(['driver']);

$me = currentUser();
$did = (int)($me['driver_id'] ?? 0);
$next = dbAll($conn, "SELECT t.*, v.plate_number, r.destination FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id LEFT JOIN requests r ON r.request_id = t.request_id
                       WHERE t.driver_id = ? AND t.status = 'approved' AND t.end_dt > NOW() ORDER BY t.start_dt LIMIT 6", [$did]);
$toReport = (int)dbValue($conn, "SELECT COUNT(*) FROM tasks t WHERE t.driver_id = ? AND t.status = 'approved' AND t.start_dt <= NOW()
                                 AND NOT EXISTS (SELECT 1 FROM task_reports tr WHERE tr.task_id = t.task_id)", [$did]);
$c = driverMonthCounts($conn, date('Y-m'))[$did] ?? ['tasks' => 0, 'weekend' => 0, 'welfare' => 0];
adminPageStart('Dashboard');
echo dashHeader([['My Task', 'tasks.php', 'route', true], ['Schedule Change', 'schedule_change.php', 'calendar-xmark', false]], 'Driver · ' . date('l, j F Y'));
if ($me['licence_expiry'] && $me['licence_expiry'] < date('Y-m-d', strtotime('+30 days'))) {
    echo '<div class="alert alert-soft alert-warning mb-5"><i class="fas fa-id-card"></i><span>Your licence ' . ($me['licence_expiry'] < date('Y-m-d') ? 'expired' : 'expires') . ' on ' . h(fmtD($me['licence_expiry'])) . '. Please renew it and inform the Vehicle Section.</span></div>';
}
echo '<div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">'
   . dashKpi('Tasks this month', $c['tasks'], 'route', 'primary', 'tasks.php')
   . dashKpi('Weekend tasks this month', $c['weekend'], 'calendar-week', 'warning')
   . dashKpi('Welfare duties this month', $c['welfare'], 'moon', 'info')
   . dashKpi('Reports to write', $toReport, 'clipboard-check', $toReport ? 'danger' : 'success', 'tasks.php?view=report') . '</div>';
echo '<section class="dash-card mb-6"><h2><span><i class="fas fa-route"></i>My next tasks</span><a href="tasks.php">See all</a></h2>';
if (!$next) { echo dashEmpty('No task is assigned.', 'mug-hot'); }
foreach ($next as $t) {
    echo '<a class="dash-row" href="task.php?id=' . (int)$t['task_id'] . '"><span><strong>' . h(taskTypeLabel($t)) . '</strong>' . ($t['destination'] ? ' &rarr; ' . h($t['destination']) : '')
       . '<span class="block text-xs text-base-content/65">' . h(fmtPeriod($t['start_dt'], $t['end_dt'])) . '</span></span><span>' . plate($t['plate_number']) . '</span></a>';
}
echo '</section><h2 class="mb-4 text-xl font-bold tracking-tight">My own requests</h2>';
dashMyRequestsSection($conn, (int)$me['user_id']);
adminPageEnd();
