<?php
// ============================================================
// admin/replace_driver.php  -  UC008 Replace Driver (includes UC009)
// For an approved task that has not started: the recommended drivers and a field for the reason. The replacement
// is recorded (previous driver, new driver, reason, date, user) and sent to the Head of Admin (UC026). After the
// approval the previous driver, the new driver and the requester are notified. It does not go to the Head of Department.
// ============================================================
$current_page = 'replace_driver.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$id = (int)($_GET['task'] ?? $_POST['task'] ?? 0);
$t = $id ? loadTask($conn, $id) : null;
if (!$t || !canReplaceDriver($conn, $t)) {
    setFlash('warning', 'The driver of this task cannot be replaced: the task must be approved, not started, and without a replacement waiting.');
    header('Location: ' . ($t ? 'task.php?id=' . $id : 'schedule.php'));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = requestReplacement($conn, $id, (int)($_POST['driver_id'] ?? 0), (string)($_POST['reason'] ?? ''));
    redirectWithResult($res, $res['ok'] ? 'task.php?id=' . $id : 'replace_driver.php?task=' . $id);
}
$rec = recommendDrivers($conn, ['licence' => $t['licence_required'], 'start' => $t['start_dt'], 'end' => $t['end_dt'], 'except_task' => $id, 'exclude_driver' => (int)$t['driver_id']]);
$sc = driverScheduleChangeOn($conn, (int)$t['driver_id'], $t['start_dt'], $t['end_dt']);
adminPageStart('Replace Driver');
pageHeader('Replace Driver', 'user-pen', taskText($t), '<a class="btn btn-ghost" href="task.php?id=' . $id . '"><i class="fas fa-arrow-left"></i>Back</a>');
cardStart('Current driver: ' . $t['driver_name'], 'user');
if ($sc) { echo '<p class="mb-3 text-sm text-warning"><i class="fas fa-calendar-xmark me-1"></i>Approved schedule change: ' . h($sc['reason']) . '</p>'; }
?>
<form method="post" class="mb-4 grid grid-cols-12 items-end gap-3" data-outside-check novalidate>
    <input type="hidden" name="task" value="<?= $id ?>">
    <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="driver_id">New driver</label>
        <select class="select w-full" id="driver_id" name="driver_id" required><?= driverOptionsHtml($rec) ?></select></div>
    <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="reason">Reason</label>
        <input class="input w-full" id="reason" name="reason" maxlength="500" required placeholder="e.g. The driver is on medical leave"></div>
    <div class="col-span-12 md:col-span-2"><button class="btn btn-primary w-full" type="submit" <?= $rec['ranked'] ? '' : 'disabled' ?>><i class="fas fa-check"></i>Confirm</button></div>
</form>
<?= recommendationTableHtml($rec) ?>
<?php cardEnd(); adminPageEnd(); ?>
