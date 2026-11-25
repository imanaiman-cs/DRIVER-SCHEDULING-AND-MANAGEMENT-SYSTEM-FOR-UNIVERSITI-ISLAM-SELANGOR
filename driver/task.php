<?php
// ============================================================
// driver/task.php  -  View Layer: DriverTaskUI (SDD_CLASS_108) displayTaskDetails(), displayReportForm() - one task
//   UC023 step 2   date, time, vehicle, destination with full address, waiting location, purpose, officer name and phone
//   UC022 A1       Request Change: ask to be removed from the task (reason; the Head of Admin decides)
//   UC024A         Create Report after the departure time (actual times, end mileage, fuel level, condition, problem)
//   UC024B / C     View Report / Update Report
// ============================================================
$current_page = 'task.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireRoles([User::DRIVER]);

$did = (int)$_SESSION['user_id'];
$dt = new DriverTaskController();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$t = $id ? $dt->getTaskDetails($did, $id) : null;
if (!$t) {
    setFlash('danger', 'That task was not found.');
    header('Location: tasks.php');
    exit();
}
$report = $dt->getReport($id);
$errors = [];
$formError = null;
$editing = isset($_GET['edit']) && $report && $dt->canUpdateReport($report);
$creating = isset($_GET['report']) && !$report;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'request_change') {
        redirectWithResult($dt->requestScheduleChange($did, ['task_id' => $id, 'reason' => (string)($_POST['reason'] ?? '')]), 'task.php?id=' . $id);
    }
    if ($act === 'report') {
        $res = $report ? $dt->updateReport($did, (int)$report['report_id'], $_POST, $_FILES['photo'] ?? null) : $dt->submitReport($did, $id, $_POST, $_FILES['photo'] ?? null);
        if ($res['ok']) { redirectWithResult($res, 'task.php?id=' . $id); }
        $errors = $res['errors'] ?? [];
        $formError = $res['message'];
        $report ? $editing = true : $creating = true;
    }
}

$pendingChange = $dt->pendingChangeForTask($did, $id);
$canChange = in_array($t['status'], [Task::APPROVED, Task::DRIVER_ASSIGNED], true) && strtotime($t['start_datetime']) > time() && !$pendingChange;
$canReport = $dt->canCreateReport($t, $did);
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($report ? [
    'actual_depart' => date('Y-m-d\TH:i', strtotime($report['actual_depart'])), 'actual_return' => date('Y-m-d\TH:i', strtotime($report['actual_return'])),
    'end_mileage' => $report['end_mileage'], 'fuel_level' => $report['fuel_level'], 'vehicle_condition' => $report['vehicle_condition'],
    'problem_type' => $report['problem_type'], 'problem_desc' => $report['problem_desc'], 'can_be_used' => (string)$report['can_be_used'],
] : ['actual_depart' => date('Y-m-d\TH:i', strtotime($t['start_datetime'])), 'actual_return' => date('Y-m-d\TH:i', min(time(), strtotime($t['end_datetime']))), 'vehicle_condition' => 'Good']);
$v = fn($k) => h($src[$k] ?? '');

$actions = '<a class="btn btn-ghost" href="tasks.php"><i class="fas fa-arrow-left"></i>Back</a>';
if ($canReport && !$creating) { $actions .= '<a class="btn btn-primary" href="task.php?id=' . $id . '&report=1#report"><i class="fas fa-clipboard-check"></i>Create Report</a>'; }
if ($canChange) {
    $actions .= '<form method="post"><input type="hidden" name="action" value="request_change"><button class="btn btn-outline btn-warning" type="submit" data-reason="Tell the Head of Admin why you cannot do this task. If it is approved, the Vehicle Section assigns another driver." data-reason-title="Request Change" data-reason-button="Submit"><i class="fas fa-calendar-xmark"></i>Request Change</button></form>';
}
adminPageStart('Task');
pageHeader(taskTypeLabel($t), 'route', fmtPeriod($t['start_datetime'], $t['end_datetime']), $actions);
if ($pendingChange) { echo '<div class="alert alert-soft alert-warning mb-5"><i class="fas fa-hourglass-half"></i><span>You asked to be removed from this task. Waiting for the Head of Admin.</span></div>'; }
if ($formError) { echo '<div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>' . h($formError) . '</span></div>'; }
?>
<div class="grid grid-cols-12 gap-5">
<div class="col-span-12 lg:col-span-6">
<?php cardStart('Task details', 'circle-info'); ?>
<dl>
    <?= dlRow('Status', taskStatusBadge($t['status'])) ?>
    <?= dlRow('Date and time', h(fmtPeriod($t['start_datetime'], $t['end_datetime']))) ?>
    <?= dlRow('Task', h(taskTypeLabel($t))) ?>
    <?= dlRow('Vehicle', plate($t['plate_no']) . ' ' . h(trim(($t['brand'] ?? '') . ' ' . ($t['model'] ?? '')))) ?>
    <?php if ($t['task_source'] === Task::REQUEST): ?>
        <?= dlRow('Destination', '<strong>' . h($t['destination']) . '</strong><div class="text-sm">' . nl2br(h($t['address'])) . '</div>') ?>
        <?= dlRow('Waiting location', h($t['waiting_location'])) ?>
        <?= dlRow('Purpose', h($t['purpose'])) ?>
        <?= dlRow('Passengers', h($t['no_of_passengers'])) ?>
        <?= dlRow('Officer', h($t['officer_name']) . ($t['officer_phone'] ? ' &middot; <a class="link" href="tel:' . h(preg_replace('/[^0-9+]/', '', $t['officer_phone'])) . '">' . h($t['officer_phone']) . '</a>' : '')) ?>
    <?php elseif ($t['task_source'] === Task::WELFARE): ?>
        <?= dlRow('Duty', '24-hour welfare standby') ?>
    <?php else: ?>
        <?= dlRow('Description', h($t['description'])) ?>
    <?php endif; ?>
</dl>
<?php cardEnd(); ?>
</div>
<div class="col-span-12 lg:col-span-6" id="report">
<?php if ($creating || $editing): ?>
    <?php cardStart($editing ? 'Update Report' : 'Create Report', 'clipboard-check'); ?>
    <form method="post" enctype="multipart/form-data" class="grid grid-cols-2 gap-4" novalidate>
        <input type="hidden" name="action" value="report"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="col-span-2 text-sm text-base-content/65">Current mileage of <?= plate($t['plate_no']) ?>: <strong><?= number_format((int)$t['current_mileage']) ?> km</strong></div>
        <div class="col-span-2 sm:col-span-1"><label class="mb-1 block text-sm font-medium" for="actual_depart">Actual departure time</label>
            <input type="datetime-local" class="input w-full<?= errClass($errors, 'actual_depart') ?>" id="actual_depart" name="actual_depart" value="<?= $v('actual_depart') ?>" required><?= fieldError($errors, 'actual_depart') ?></div>
        <div class="col-span-2 sm:col-span-1"><label class="mb-1 block text-sm font-medium" for="actual_return">Actual return time</label>
            <input type="datetime-local" class="input w-full<?= errClass($errors, 'actual_return') ?>" id="actual_return" name="actual_return" value="<?= $v('actual_return') ?>" required><?= fieldError($errors, 'actual_return') ?></div>
        <div class="col-span-2 sm:col-span-1"><label class="mb-1 block text-sm font-medium" for="end_mileage">End mileage (km)</label>
            <input type="number" class="input w-full<?= errClass($errors, 'end_mileage') ?>" id="end_mileage" name="end_mileage" min="0" value="<?= $v('end_mileage') ?>" required><?= fieldError($errors, 'end_mileage') ?></div>
        <div class="col-span-2 sm:col-span-1"><label class="mb-1 block text-sm font-medium" for="fuel_level">Fuel level</label>
            <select class="select w-full<?= errClass($errors, 'fuel_level', 'select') ?>" id="fuel_level" name="fuel_level" required><option value="">Select</option>
                <?php foreach (Vehicle::FUEL_LEVELS as $f): ?><option <?= ($src['fuel_level'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select><?= fieldError($errors, 'fuel_level') ?></div>
        <fieldset class="col-span-2"><legend class="mb-1 text-sm font-medium">Vehicle condition</legend>
            <label class="me-5 inline-flex items-center gap-2"><input type="radio" class="radio radio-sm" name="vehicle_condition" value="Good" <?= ($src['vehicle_condition'] ?? '') === 'Good' ? 'checked' : '' ?>>Good</label>
            <label class="inline-flex items-center gap-2"><input type="radio" class="radio radio-sm radio-error" name="vehicle_condition" value="Problem" <?= ($src['vehicle_condition'] ?? '') === 'Problem' ? 'checked' : '' ?>>Problem</label>
            <?= fieldError($errors, 'vehicle_condition') ?></fieldset>
        <div class="col-span-2 hidden grid-cols-2 gap-4 rounded-lg border border-error/30 bg-error/5 p-3" id="problemBox">
            <div class="col-span-2 sm:col-span-1"><label class="mb-1 block text-sm font-medium" for="problem_type">Problem type</label>
                <select class="select w-full<?= errClass($errors, 'problem_type', 'select') ?>" id="problem_type" name="problem_type"><option value="">Select</option>
                    <?php foreach (TaskReport::PROBLEM_TYPES as $pt): ?><option <?= ($src['problem_type'] ?? '') === $pt ? 'selected' : '' ?>><?= $pt ?></option><?php endforeach; ?></select><?= fieldError($errors, 'problem_type') ?></div>
            <div class="col-span-2 sm:col-span-1"><label class="mb-1 block text-sm font-medium" for="photo">Photo</label>
                <input type="file" class="file-input w-full" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"><?= fieldError($errors, 'photo') ?></div>
            <div class="col-span-2"><label class="mb-1 block text-sm font-medium" for="problem_desc">Description</label>
                <textarea class="textarea w-full<?= errClass($errors, 'problem_desc', 'textarea') ?>" id="problem_desc" name="problem_desc" rows="2" maxlength="500"><?= $v('problem_desc') ?></textarea><?= fieldError($errors, 'problem_desc') ?></div>
            <fieldset class="col-span-2"><legend class="mb-1 text-sm font-medium">Can the vehicle still be used?</legend>
                <label class="me-5 inline-flex items-center gap-2"><input type="radio" class="radio radio-sm" name="can_be_used" value="1" <?= ($src['can_be_used'] ?? '') === '1' ? 'checked' : '' ?>>Yes</label>
                <label class="inline-flex items-center gap-2"><input type="radio" class="radio radio-sm" name="can_be_used" value="0" <?= ($src['can_be_used'] ?? '') === '0' ? 'checked' : '' ?>>No (the vehicle is set Under Maintenance)</label>
                <?= fieldError($errors, 'can_be_used') ?></fieldset>
        </div>
        <div class="col-span-2 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i><?= $editing ? 'Save' : 'Submit' ?></button><a class="btn btn-ghost" href="task.php?id=<?= $id ?>">Cancel</a></div>
    </form>
    <script>
    (function () {
        var box = document.getElementById('problemBox');
        function sync() { var p = document.querySelector('input[name="vehicle_condition"]:checked'); box.classList.toggle('hidden', !p || p.value !== 'Problem'); box.classList.toggle('grid', !!p && p.value === 'Problem'); }
        document.querySelectorAll('input[name="vehicle_condition"]').forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    })();
    </script>
    <?php cardEnd(); ?>
<?php elseif ($report): ?>
    <?php cardStart('Task Report', 'clipboard-check'); ?>
    <dl>
        <?= dlRow('Actual departure', h(fmtDT($report['actual_depart']))) ?>
        <?= dlRow('Actual return', h(fmtDT($report['actual_return']))) ?>
        <?= dlRow('End mileage', '<strong>' . number_format((int)$report['end_mileage']) . ' km</strong>') ?>
        <?= dlRow('Fuel level', h($report['fuel_level'])) ?>
        <?= dlRow('Vehicle condition', $report['vehicle_condition'] === 'Good' ? '<span class="badge badge-success">Good</span>' : '<span class="badge badge-error">Problem: ' . h($report['problem_type']) . '</span><div class="mt-1 text-sm">' . h($report['problem_desc']) . '</div><div class="text-xs">' . ($report['can_be_used'] ? 'Can still be used' : 'Cannot be used') . '</div>') ?>
        <?php if ($report['photo']): ?><?= dlRow('Photo', '<a href="' . h(SITE_URL . '/' . $report['photo']) . '" target="_blank" rel="noopener"><img class="max-h-40 rounded" src="' . h(SITE_URL . '/' . $report['photo']) . '" alt="Photo of the problem"></a>') ?><?php endif; ?>
    </dl>
    <?php if ($dt->canUpdateReport($report)): ?><a class="btn btn-sm btn-outline mt-3" href="task.php?id=<?= $id ?>&edit=1#report"><i class="fas fa-pen"></i>Edit</a>
    <?php else: ?><p class="mt-3 text-xs text-base-content/60">A report can only be corrected on the day it was submitted.</p><?php endif; ?>
    <?php cardEnd(); ?>
<?php elseif ($t['status'] === Task::APPROVED): ?>
    <div class="alert alert-soft alert-info"><i class="fas fa-circle-info"></i><span><?= strtotime($t['start_datetime']) > time() ? 'You can create the report after the departure time.' : 'Create the report after the trip.' ?></span></div>
<?php endif; ?>
</div>
</div>
<?php adminPageEnd(); ?>
