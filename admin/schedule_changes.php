<?php
// ============================================================
// admin/schedule_changes.php  -  UC027 Approve/Reject Schedule Change (Head of Admin)
// Pending requests with the driver, dates, reason and any task the driver has on those dates. Approving marks the
// driver unavailable on those dates and sends the affected tasks to the Administrative Assistant (UC008).
// ============================================================
$current_page = 'schedule_changes.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireHeadAdmin();

$tab = ($_GET['tab'] ?? 'pending') === 'history' ? 'history' : 'pending';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    redirectWithResult(hoaDecideScheduleChange($conn, (int)($_POST['id'] ?? 0), $act === 'approve', (string)($_POST['reason'] ?? '')), 'schedule_changes.php');
}
$rows = dbAll($conn, "SELECT sc.*, u.full_name AS driver_name, du.full_name AS decided_name FROM schedule_changes sc JOIN drivers d ON d.driver_id = sc.driver_id JOIN users u ON u.user_id = d.user_id
                       LEFT JOIN users du ON du.user_id = sc.decided_by WHERE " . ($tab === 'pending' ? "sc.status = 'pending' ORDER BY sc.start_date" : "sc.status <> 'pending' ORDER BY sc.created_at DESC LIMIT 100"));
$pending = (int)dbValue($conn, "SELECT COUNT(*) FROM schedule_changes WHERE status = 'pending'");
adminPageStart('Schedule Change');
pageHeader('Schedule Change', 'calendar-xmark', 'Dates when drivers cannot work, and requests to be removed from a task. An approved driver is not recommended for tasks on those dates.');
echo tabsNav(['pending' => 'Pending', 'history' => 'Decided'], $tab, fn($k) => '?tab=' . $k, ['pending' => $pending]);
echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Driver</th><th>Dates</th><th>Reason</th><th>Tasks on those dates</th><th>' . ($tab === 'pending' ? '' : 'Status') . '</th></tr></thead><tbody>';
foreach ($rows as $sc) {
    $tasks = scheduleChangeAffectedTasks($conn, $sc);
    $tl = $tasks ? '<ul class="text-xs">' . implode('', array_map(fn($t) => '<li>' . h(taskTypeLabel($t)) . ', ' . h(fmtPeriod($t['start_dt'], $t['end_dt'])) . ' ' . h($t['plate_number'] ?? '') . '</li>', $tasks)) . '</ul>' : '<span class="text-xs text-base-content/60">None</span>';
    echo '<tr><td class="font-medium">' . h($sc['driver_name']) . '<div class="text-xs text-base-content/60">' . h(fmtDT($sc['created_at'])) . '</div></td>'
       . '<td class="whitespace-nowrap">' . h(fmtD($sc['start_date'])) . ($sc['end_date'] !== $sc['start_date'] ? ' - ' . h(fmtD($sc['end_date'])) : '') . ($sc['task_id'] ? '<div class="badge badge-ghost badge-sm">Remove from task</div>' : '') . '</td>'
       . '<td>' . h($sc['reason']) . ($sc['reject_reason'] ? '<div class="text-xs text-error">Rejected: ' . h($sc['reject_reason']) . '</div>' : '') . '</td><td>' . $tl . '</td><td class="text-end">';
    if ($tab === 'pending') {
        echo '<form method="post" class="flex justify-end gap-2"><input type="hidden" name="id" value="' . (int)$sc['change_id'] . '"><button class="btn btn-sm btn-success" name="action" value="approve" type="submit"><i class="fas fa-check"></i>Approve</button>'
           . '<button class="btn btn-sm btn-outline btn-error" name="action" value="reject" type="submit" data-reason="The driver is told the reason." data-reason-title="Reject the schedule change?" data-reason-button="Reject"><i class="fas fa-xmark"></i>Reject</button></form>';
    } else {
        echo scheduleChangeStatusBadge($sc['status']) . '<div class="text-xs text-base-content/60">' . h($sc['decided_name'] ?? '') . '</div>';
    }
    echo '</td></tr>';
}
if (!$rows) { echo emptyRow(5, $tab === 'pending' ? 'No schedule change is waiting.' : 'No record is found.'); }
echo '</tbody></table></div></div>';
adminPageEnd();
