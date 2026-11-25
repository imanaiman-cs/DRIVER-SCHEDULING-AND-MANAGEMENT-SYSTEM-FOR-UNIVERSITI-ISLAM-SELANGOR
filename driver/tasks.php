<?php
// ============================================================
// driver/tasks.php  -  View Layer: DriverTaskUI (SDD_CLASS_108) displayTaskList() - UC023 View Assigned Task
// Upcoming approved tasks; Waiting for report; Past Tasks (completed, UC024B). Request tasks, internal tasks, welfare duty.
// ============================================================
$current_page = 'tasks.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireRoles([User::DRIVER]);

$did = (int)$_SESSION['user_id'];
$dt = new DriverTaskController();
$view = in_array($_GET['view'] ?? '', ['past', 'report'], true) ? $_GET['view'] : 'upcoming';
$rows = $dt->listAssignedTasks($did, $view);
$counts = ['report' => count($dt->listAssignedTasks($did, 'report'))];

adminPageStart('My Task');
pageHeader('My Task', 'route', 'Your approved tasks. Open a task to see the details, ask to be removed from it, or write the report after the trip.');
echo tabsNav(['upcoming' => 'Upcoming', 'report' => 'Waiting for report', 'past' => 'Past Tasks'], $view, fn($k) => '?view=' . $k, $counts);
echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Date and time</th><th>Task</th><th>Vehicle</th><th>Destination</th><th>Status</th><th></th></tr></thead><tbody>';
foreach ($rows as $t) {
    echo '<tr>' . taskRowCells($t) . '<td>' . h($t['destination'] ?? $t['description']) . '</td><td>' . taskStatusBadge($t['status']) . '</td>'
       . '<td class="text-end"><a class="btn btn-sm btn-outline" href="task.php?id=' . (int)$t['task_id'] . '">' . ($view === 'report' ? 'Create Report' : 'View') . '</a></td></tr>';
}
if (!$rows) { echo emptyRow(6, $view === 'upcoming' ? 'No task is assigned.' : 'No record is found.'); }
echo '</tbody></table></div></div>';
adminPageEnd();
