<?php
// ============================================================
// admin/internal_tasks.php  -  View Layer: InternalTaskUI (SDD_CLASS_107) + AssignmentUI (SDD_CLASS_106)
//   UC004 Manage Internal Task + UC005 Assign Internal Task (includes UC009)
//   Add Task: task type (Workshop, Refuelling, Test Drive), date, start, end, description, vehicle
//             -> Waiting for Driver; the vehicle is reserved for that period
//   View: filter by date, task type or status     Edit (not completed)     Delete (not completed)
//   Assign Driver: recommended drivers -> Driver Assigned -> the Head of Admin approves (UC026)
// Internal tasks are counted in each driver's task count.
// ============================================================
$current_page = 'internal_tasks.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$me = currentUser();
$tc = new TaskController();
$ac = new AssignmentController();
$editId = (int)($_GET['edit'] ?? 0);
$assignId = (int)($_GET['assign'] ?? 0);
$errors = []; $formError = null;
$form = ['task_type' => 'Workshop', 'task_date' => date('Y-m-d', strtotime('+1 day')), 'start_time' => '09:00', 'end_time' => '10:00', 'description' => '', 'vehicle_id' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['task_id'] ?? 0);
    if ($act === 'delete') { redirectWithResult($tc->deleteInternalTask($id), 'internal_tasks.php'); }
    if ($act === 'assign') {
        $res = $ac->assignInternalTask($me, $id, (int)($_POST['driver_id'] ?? 0));
        redirectWithResult($res, $res['ok'] ? 'internal_tasks.php' : 'internal_tasks.php?assign=' . $id);
    }
    if ($act === 'save') {
        $res = $id ? $tc->updateInternalTask($id, $_POST) : $tc->addInternalTask($me, $_POST);
        if ($res['ok']) { redirectWithResult($res, 'internal_tasks.php'); }
        $errors = $res['errors'] ?? []; $formError = $res['message'];
        $form = array_merge($form, array_intersect_key($_POST, $form));
        $editId = $id;
    }
}
if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $et = $tc->getTask($editId);
    if (!$et || $et['task_source'] !== Task::INTERNAL || in_array($et['status'], [Task::COMPLETED, Task::CANCELLED], true)) { setFlash('warning', 'This task can no longer be updated.'); header('Location: internal_tasks.php'); exit(); }
    $form = ['task_type' => $et['task_type'], 'task_date' => substr($et['start_datetime'], 0, 10), 'start_time' => substr($et['start_datetime'], 11, 5), 'end_time' => substr($et['end_datetime'], 11, 5), 'description' => $et['description'], 'vehicle_id' => $et['vehicle_id']];
}
$vehicles = (new VehicleController())->listVehicles(['status' => Vehicle::ACTIVE]);
$fDate = isValidDate((string)($_GET['date'] ?? '')) ? $_GET['date'] : '';
$fType = in_array($_GET['type'] ?? '', Task::INTERNAL_TYPES, true) ? $_GET['type'] : '';
$statuses = [Task::WAITING, Task::DRIVER_ASSIGNED, Task::APPROVED, Task::REJECTED, Task::COMPLETED, Task::CANCELLED];
$fStatus = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$tasks = $tc->listInternalTasks(['date' => $fDate, 'task_type' => $fType, 'status' => $fStatus]);

adminPageStart('Internal Task');
pageHeader('Internal Task', 'wrench', 'Workshop runs, refuelling and test drives. They are counted in the driver\'s task count.');
if ($formError) { echo '<div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>' . h($formError) . '</span></div>'; }

if ($assignId && ($at = $tc->getTask($assignId)) && $at['task_source'] === Task::INTERNAL && in_array($at['status'], [Task::WAITING, Task::REJECTED], true)):
    $rec = (new RecommendationService())->recommendDrivers($at['licence_required'], $at['start_datetime'], $at['end_datetime'], ['except_task' => [$assignId]]);
    cardStart('Assign Driver: ' . $at['task_type'] . ', ' . fmtPeriod($at['start_datetime'], $at['end_datetime']) . ', ' . $at['plate_no'], 'user-plus', 'border-primary');
    if ($at['reject_reason']) { echo '<div class="alert alert-soft alert-warning mb-3 text-sm"><i class="fas fa-rotate-left"></i><span>Returned by the Head of Admin: ' . h($at['reject_reason']) . '</span></div>'; }
    ?>
    <p class="mb-3 text-sm"><?= h($at['description']) ?></p>
    <form method="post" class="mb-4 flex flex-wrap items-end gap-2" data-outside-check>
        <input type="hidden" name="action" value="assign"><input type="hidden" name="task_id" value="<?= $assignId ?>">
        <div><label class="mb-1 block text-sm font-medium" for="driver_id">Driver</label><select class="select w-96 max-w-full" id="driver_id" name="driver_id" required><?= driverOptionsHtml($rec) ?></select></div>
        <button class="btn btn-primary" type="submit" <?= $rec['ranked'] ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i>Submit</button><a class="btn btn-ghost" href="internal_tasks.php">Cancel</a>
    </form>
    <?= recommendationTableHtml($rec) ?>
    <?php cardEnd();
endif;
?>
<?php cardStart($editId ? 'Edit task' : 'Add Task', $editId ? 'pen' : 'plus'); ?>
<form method="post" class="grid grid-cols-12 gap-4" id="taskForm" novalidate>
    <input type="hidden" name="action" value="save"><input type="hidden" name="task_id" value="<?= $editId ?>">
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="task_type">Task type</label>
        <select class="select w-full<?= errClass($errors, 'task_type', 'select') ?>" id="task_type" name="task_type"><?php foreach (Task::INTERNAL_TYPES as $k): ?><option <?= $form['task_type'] === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select><?= fieldError($errors, 'task_type') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="task_date">Date</label>
        <input type="date" class="input w-full<?= errClass($errors, 'task_date') ?>" id="task_date" name="task_date" min="<?= date('Y-m-d') ?>" value="<?= h($form['task_date']) ?>" required><?= fieldError($errors, 'task_date') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="start_time">Start time</label>
        <input type="time" class="input w-full<?= errClass($errors, 'start_time') ?>" id="start_time" name="start_time" value="<?= h($form['start_time']) ?>" required><?= fieldError($errors, 'start_time') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="end_time">End time</label>
        <input type="time" class="input w-full<?= errClass($errors, 'end_time') ?>" id="end_time" name="end_time" value="<?= h($form['end_time']) ?>" required><?= fieldError($errors, 'end_time') ?></div>
    <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="vehicle_id">Vehicle</label>
        <select class="select w-full<?= errClass($errors, 'vehicle_id', 'select') ?>" id="vehicle_id" name="vehicle_id" required><option value="">Select the vehicle</option>
            <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= (int)$form['vehicle_id'] === (int)$v['vehicle_id'] ? 'selected' : '' ?>><?= h($v['plate_no'] . ' - ' . $v['type'] . ' ' . $v['brand'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select><?= fieldError($errors, 'vehicle_id') ?></div>
    <div class="col-span-12 md:col-span-7"><label class="mb-1 block text-sm font-medium" for="description">Description</label>
        <input class="input w-full<?= errClass($errors, 'description') ?>" id="description" name="description" maxlength="255" required value="<?= h($form['description']) ?>" placeholder="e.g. Service at Auto Service Klang, 20,000 km"><?= fieldError($errors, 'description') ?></div>
    <div class="col-span-12 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button><?php if ($editId): ?><a class="btn btn-ghost" href="internal_tasks.php">Cancel</a><?php endif; ?></div>
</form>
<?php cardEnd(); ?>

<form method="get" class="mb-4 flex flex-wrap items-end gap-2">
    <div><label class="mb-1 block text-xs font-medium" for="f_date">Date</label><input type="date" class="input input-sm" id="f_date" name="date" value="<?= h($fDate) ?>"></div>
    <div><label class="mb-1 block text-xs font-medium" for="f_type">Task type</label><select class="select select-sm" id="f_type" name="type"><option value="">All types</option><?php foreach (Task::INTERNAL_TYPES as $k): ?><option <?= $fType === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select></div>
    <div><label class="mb-1 block text-xs font-medium" for="f_status">Status</label><select class="select select-sm" id="f_status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $s): ?><option <?= $fStatus === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i>Filter</button><a class="btn btn-sm btn-ghost" href="internal_tasks.php">Reset</a>
</form>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table [&_tbody_tr:hover]:bg-base-200/60">
<thead><tr><th>Date and time</th><th>Task type</th><th>Vehicle</th><th>Driver</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($tasks as $t): $tid = (int)$t['task_id']; $open = !in_array($t['status'], [Task::COMPLETED, Task::CANCELLED], true); ?>
<tr>
    <?= taskRowCells($t) ?>
    <td><?= h($t['driver_name'] ?? '-') ?></td>
    <td><?= taskStatusBadge($t['status']) ?><?php if ($t['status'] === Task::REJECTED && $t['reject_reason']): ?><div class="text-xs text-error">Returned: <?= h($t['reject_reason']) ?></div><?php endif; ?><div class="max-w-56 truncate text-xs text-base-content/60" title="<?= h($t['description']) ?>"><?= h($t['description']) ?></div></td>
    <td class="text-end whitespace-nowrap">
        <a class="btn btn-sm btn-ghost" href="task.php?id=<?= $tid ?>">View</a>
        <?php if (in_array($t['status'], [Task::WAITING, Task::REJECTED], true) && strtotime($t['start_datetime']) > time()): ?><a class="btn btn-sm btn-primary" href="?assign=<?= $tid ?>"><i class="fas fa-user-plus"></i>Assign Driver</a><?php endif; ?>
        <?php if ($open): ?><a class="btn btn-sm btn-outline" href="?edit=<?= $tid ?>#taskForm" aria-label="Edit"><i class="fas fa-pen"></i></a>
        <form method="post" class="inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="<?= $tid ?>">
            <button class="btn btn-sm btn-outline btn-error" type="submit" aria-label="Delete" data-confirm="<?= h($t['task_type'] . ', ' . fmtPeriod($t['start_datetime'], $t['end_datetime'])) ?>. The vehicle reservation is released<?= $t['driver_id'] ? ' and the driver is notified' : '' ?>." data-confirm-title="Delete this task?" data-confirm-button="Confirm" data-confirm-tone="danger"><i class="fas fa-trash"></i></button></form><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!$tasks): ?><?= emptyRow(6) ?><?php endif; ?>
</tbody></table></div></div>
<?php adminPageEnd(); ?>
