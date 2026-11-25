<?php
// ============================================================
// admin/replace_driver.php  -  View Layer: AssignmentUI (SDD_CLASS_106) displayReplaceForm() - UC008 Replace Driver (includes UC009)
// For an approved task that has not started: the recommended drivers and the reason. The replacement is recorded
// (old driver, new driver, reason, date) and sent to the Head of Admin (UC026). It does not go to the Head of Department.
// ============================================================
$current_page = 'replace_driver.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$ac = new AssignmentController();
$id = (int)($_GET['task'] ?? $_POST['task'] ?? 0);
$t = $id ? (new TaskController())->getTask($id) : null;
if (!$t || !$ac->canReplace($t)) {
    setFlash('warning', 'The driver of this task cannot be replaced: the task must be approved, not started, and without a replacement waiting.');
    header('Location: ' . ($t ? 'task.php?id=' . $id : 'schedule.php'));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = $ac->replaceDriver(currentUser(), $id, (int)($_POST['driver_id'] ?? 0), (string)($_POST['reason'] ?? ''));
    redirectWithResult($res, $res['ok'] ? 'task.php?id=' . $id : 'replace_driver.php?task=' . $id);
}
$rec = (new RecommendationService())->recommendDrivers($t['licence_required'], $t['start_datetime'], $t['end_datetime'], ['except_task' => [$id], 'exclude_driver' => (int)$t['driver_id']]);
adminPageStart('Replace Driver');
pageHeader('Replace Driver', 'user-pen', taskTypeLabel($t) . ', ' . fmtPeriod($t['start_datetime'], $t['end_datetime']) . ', ' . $t['plate_no'], '<a class="btn btn-ghost" href="task.php?id=' . $id . '"><i class="fas fa-arrow-left"></i>Back</a>');
cardStart('Current driver: ' . $t['driver_name'], 'user');
?>
<form method="post" class="mb-4 grid grid-cols-12 items-end gap-3" data-outside-check novalidate>
    <input type="hidden" name="task" value="<?= $id ?>">
    <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="driver_id">New driver</label>
        <select class="select w-full" id="driver_id" name="driver_id" required><?= driverOptionsHtml($rec) ?></select></div>
    <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="reason">Reason</label>
        <input class="input w-full" id="reason" name="reason" maxlength="255" required placeholder="e.g. The driver is on medical leave"></div>
    <div class="col-span-12 md:col-span-2"><button class="btn btn-primary w-full" type="submit" <?= $rec['ranked'] ? '' : 'disabled' ?>><i class="fas fa-check"></i>Confirm</button></div>
</form>
<?= recommendationTableHtml($rec) ?>
<?php cardEnd(); adminPageEnd(); ?>
