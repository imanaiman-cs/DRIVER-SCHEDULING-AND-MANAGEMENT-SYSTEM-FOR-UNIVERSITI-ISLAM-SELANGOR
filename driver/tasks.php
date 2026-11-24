<?php
// ============================================================
// driver/tasks.php  -  UC023 View Assigned Task (upcoming approved tasks; Past Tasks = completed tasks, UC024B)
// Request tasks, internal tasks and welfare duty. A task is shown once the Head of Admin approved it.
// ============================================================
$current_page = 'tasks.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireRoles(['driver']);

$did = (int)(currentUser()['driver_id'] ?? 0);
$view = in_array($_GET['view'] ?? '', ['past', 'report'], true) ? $_GET['view'] : 'upcoming';
$sel = "SELECT t.*, v.plate_number, v.brand, v.model, r.destination, r.waiting_location, tr.report_id, tr.vehicle_condition
          FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id LEFT JOIN requests r ON r.request_id = t.request_id
          LEFT JOIN task_reports tr ON tr.task_id = t.task_id WHERE t.driver_id = ?";
$rows = match ($view) {
    'past'   => dbAll($conn, "$sel AND t.status = 'completed' ORDER BY t.start_dt DESC LIMIT 200", [$did]),
    'report' => dbAll($conn, "$sel AND t.status = 'approved' AND t.start_dt <= NOW() AND tr.report_id IS NULL ORDER BY t.start_dt", [$did]),
    default  => dbAll($conn, "$sel AND t.status = 'approved' AND t.end_dt > NOW() ORDER BY t.start_dt", [$did]),
};
$counts = ['report' => (int)dbValue($conn, "SELECT COUNT(*) FROM tasks t WHERE t.driver_id = ? AND t.status = 'approved' AND t.start_dt <= NOW() AND NOT EXISTS (SELECT 1 FROM task_reports tr WHERE tr.task_id = t.task_id)", [$did])];

adminPageStart('My Task');
pageHeader('My Task', 'route', 'Your approved tasks. Open a task to see the details, ask to be removed from it, or write the report after the trip.');
echo tabsNav(['upcoming' => 'Upcoming', 'report' => 'Waiting for report', 'past' => 'Past Tasks'], $view, fn($k) => '?view=' . $k, $counts);
echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Date and time</th><th>Task</th><th>Vehicle</th><th>Destination</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($rows as $t) {
    echo '<tr>' . taskRowCells($t) . '<td>' . h($t['destination'] ?? ($t['description'] ?? '')) . '</td><td>' . taskStatusBadge($t['status'])
       . ($t['vehicle_condition'] === 'problem' ? ' <span class="badge badge-error badge-sm">Problem</span>' : '') . '</td><td class="text-end"><a class="btn btn-sm btn-outline" href="task.php?id=' . (int)$t['task_id'] . '">'
       . ($view === 'report' ? 'Create Report' : 'View') . '</a></td></tr>';
}
if (!$rows) { echo emptyRow(6, $view === 'upcoming' ? 'No task is assigned.' : 'No record is found.'); }
echo '</tbody></table></div></div>';
adminPageEnd();
