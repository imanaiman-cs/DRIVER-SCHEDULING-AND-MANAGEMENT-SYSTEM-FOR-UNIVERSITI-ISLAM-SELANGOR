<?php
// ============================================================
// admin/internal_tasks.php  -  UC004 Manage Internal Task + UC005 Assign Internal Task (includes UC009)
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

$editId = (int)($_GET['edit'] ?? 0);
$assignId = (int)($_GET['assign'] ?? 0);
$errors = []; $formError = null;
$form = ['task_type' => 'workshop', 'task_date' => date('Y-m-d', strtotime('+1 day')), 'start_time' => '09:00', 'end_time' => '10:00', 'description' => '', 'vehicle_id' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $id = (int)($_POST['task_id'] ?? 0);
    if ($act === 'delete') { redirectWithResult(deleteInternalTask($conn, $id), 'internal_tasks.php'); }
    if ($act === 'assign') {
        $res = assignInternalTask($conn, $id, (int)($_POST['driver_id'] ?? 0));
        redirectWithResult($res, $res['ok'] ? 'internal_tasks.php' : 'internal_tasks.php?assign=' . $id);
    }
    if ($act === 'save') {
        $res = saveInternalTask($conn, $id, $_POST);
        if ($res['ok']) { redirectWithResult($res, 'internal_tasks.php'); }
        $errors = $res['errors'] ?? []; $formError = $res['message'];
        $form = array_merge($form, array_intersect_key($_POST, $form));
        $editId = $id;
    }
}
if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $et = loadTask($conn, $editId);
    if (!$et || $et['task_source'] !== 'internal' || in_array($et['status'], ['completed', 'cancelled'], true)) { setFlash('warning', 'This task can no longer be updated.'); header('Location: internal_tasks.php'); exit(); }
    $form = ['task_type' => $et['task_type'], 'task_date' => substr($et['start_dt'], 0, 10), 'start_time' => substr($et['start_dt'], 11, 5), 'end_time' => substr($et['end_dt'], 11, 5), 'description' => $et['description'], 'vehicle_id' => $et['vehicle_id']];
}
$vehicles = dbAll($conn, "SELECT * FROM vehicles WHERE status = 'active' ORDER BY vehicle_type, plate_number");

// filters (UC004B)
$fDate = isValidDate((string)($_GET['date'] ?? '')) ? $_GET['date'] : '';
$fType = isset(INTERNAL_TASK_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$fStatus = in_array($_GET['status'] ?? '', ['waiting', 'driver_assigned', 'approved', 'completed'], true) ? $_GET['status'] : '';
$w = ["t.task_source = 'internal'", "t.status <> 'cancelled'"]; $p = [];
if ($fDate) { $w[] = 'DATE(t.start_dt) = ?'; $p[] = $fDate; }
if ($fType) { $w[] = 't.task_type = ?'; $p[] = $fType; }
if ($fStatus) { $w[] = 't.status = ?'; $p[] = $fStatus; }
if (!$fDate && !$fStatus) { $w[] = "(t.end_dt >= DATE_SUB(NOW(), INTERVAL 30 DAY) OR t.status IN ('waiting','driver_assigned'))"; }
$tasks = dbAll($conn, 'SELECT t.*, v.plate_number, v.brand, v.model, u.full_name AS driver_name FROM tasks t LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                       LEFT JOIN drivers d ON d.driver_id = t.driver_id LEFT JOIN users u ON u.user_id = d.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.start_dt DESC LIMIT 200', $p);

adminPageStart('Internal Task');
pageHeader('Internal Task', 'wrench', 'Workshop runs, refuelling and test drives. They are counted in the driver\'s task count.');
if ($formError) { echo '<div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>' . h($formError) . '</span></div>'; }

if ($assignId && ($at = loadTask($conn, $assignId)) && $at['task_source'] === 'internal' && $at['status'] === 'waiting'):
    $rec = recommendDrivers($conn, ['licence' => $at['licence_required'], 'start' => $at['start_dt'], 'end' => $at['end_dt'], 'except_task' => $assignId]);
    cardStart('Assign Driver: ' . taskText($at), 'user-plus', 'border-primary');
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
        <select class="select w-full<?= errClass($errors, 'task_type', 'select') ?>" id="task_type" name="task_type"><?php foreach (INTERNAL_TASK_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $form['task_type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><?= fieldError($errors, 'task_type') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="task_date">Date</label>
        <input type="date" class="input w-full<?= errClass($errors, 'task_date') ?>" id="task_date" name="task_date" min="<?= date('Y-m-d') ?>" value="<?= h($form['task_date']) ?>" required><?= fieldError($errors, 'task_date') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="start_time">Start time</label>
        <input type="time" class="input w-full<?= errClass($errors, 'start_time') ?>" id="start_time" name="start_time" value="<?= h($form['start_time']) ?>" required><?= fieldError($errors, 'start_time') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="end_time">End time</label>
        <input type="time" class="input w-full<?= errClass($errors, 'end_time') ?>" id="end_time" name="end_time" value="<?= h($form['end_time']) ?>" required><?= fieldError($errors, 'end_time') ?></div>
    <div class="col-span-12 md:col-span-5"><label class="mb-1 block text-sm font-medium" for="vehicle_id">Vehicle</label>
        <select class="select w-full<?= errClass($errors, 'vehicle_id', 'select') ?>" id="vehicle_id" name="vehicle_id" required><option value="">Select the vehicle</option>
            <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['vehicle_id'] ?>" <?= (int)$form['vehicle_id'] === (int)$v['vehicle_id'] ? 'selected' : '' ?>><?= h($v['plate_number'] . ' - ' . $v['vehicle_type'] . ' ' . $v['brand'] . ' ' . $v['model']) ?></option><?php endforeach; ?></select><?= fieldError($errors, 'vehicle_id') ?></div>
    <div class="col-span-12 md:col-span-7"><label class="mb-1 block text-sm font-medium" for="description">Description</label>
        <input class="input w-full<?= errClass($errors, 'description') ?>" id="description" name="description" maxlength="255" required value="<?= h($form['description']) ?>" placeholder="e.g. Service at Auto Service Klang, 20,000 km"><?= fieldError($errors, 'description') ?></div>
    <div class="col-span-12 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button><?php if ($editId): ?><a class="btn btn-ghost" href="internal_tasks.php">Cancel</a><?php endif; ?></div>
</form>
<?php cardEnd(); ?>

<form method="get" class="mb-4 flex flex-wrap items-end gap-2">
    <div><label class="mb-1 block text-xs font-medium" for="f_date">Date</label><input type="date" class="input input-sm" id="f_date" name="date" value="<?= h($fDate) ?>"></div>
    <div><label class="mb-1 block text-xs font-medium" for="f_type">Task type</label><select class="select select-sm" id="f_type" name="type"><option value="">All types</option><?php foreach (INTERNAL_TASK_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $fType === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label class="mb-1 block text-xs font-medium" for="f_status">Status</label><select class="select select-sm" id="f_status" name="status"><option value="">All statuses</option><?php foreach (['waiting', 'driver_assigned', 'approved', 'completed'] as $s): ?><option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= h(taskStatusLabel($s)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i>Filter</button><a class="btn btn-sm btn-ghost" href="internal_tasks.php">Reset</a>
</form>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table [&_tbody_tr:hover]:bg-base-200/60">
<thead><tr><th>Date and time</th><th>Task type</th><th>Vehicle</th><th>Driver</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($tasks as $t): $tid = (int)$t['task_id']; $open = !in_array($t['status'], ['completed', 'cancelled'], true); ?>
<tr>
    <?= taskRowCells($t) ?>
    <td><?= h($t['driver_name'] ?? '-') ?></td>
    <td><?= taskStatusBadge($t['status']) ?><div class="max-w-56 truncate text-xs text-base-content/60" title="<?= h($t['description']) ?>"><?= h($t['description']) ?></div></td>
    <td class="text-end whitespace-nowrap">
        <a class="btn btn-sm btn-ghost" href="task.php?id=<?= $tid ?>">View</a>
        <?php if ($t['status'] === 'waiting' && strtotime($t['start_dt']) > time()): ?><a class="btn btn-sm btn-primary" href="?assign=<?= $tid ?>"><i class="fas fa-user-plus"></i>Assign Driver</a><?php endif; ?>
        <?php if ($open): ?><a class="btn btn-sm btn-outline" href="?edit=<?= $tid ?>#taskForm" aria-label="Edit"><i class="fas fa-pen"></i></a>
        <form method="post" class="inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="<?= $tid ?>">
            <button class="btn btn-sm btn-outline btn-error" type="submit" aria-label="Delete" data-confirm="<?= h(taskText($t)) ?>. The vehicle reservation is released<?= $t['driver_id'] ? ' and the driver is notified' : '' ?>." data-confirm-title="Delete this task?" data-confirm-button="Confirm" data-confirm-tone="danger"><i class="fas fa-trash"></i></button></form><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!$tasks): ?><?= emptyRow(6) ?><?php endif; ?>
</tbody></table></div></div>
<?php adminPageEnd(); ?>
