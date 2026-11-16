<?php
// ============================================================
// driver/task_report.php  -  UC024 Manage Assigned Task Report (driver): create, view, update the report of a task
// Opened from a task in My Tasks: ?trip=, ?internal= or ?welfare=, each with a tasks.task_id (the task_source must match the kind). The report records the actual times, the end mileage, the fuel
// level and the condition of the vehicle (a problem: type, description, photo, can it still be used) and updates the vehicle.
// Only the driver of the task can open it. Create Report is available once the task has started. A report is never deleted.
// ============================================================
$current_page = 'schedules.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/upload.php';
requireDriver();

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$kind = isset($_REQUEST['internal']) ? 'internal' : (isset($_REQUEST['welfare']) ? 'welfare' : 'trip');
$task_id = (int)($_REQUEST[$kind] ?? 0);
$veh = driverTaskVehicle($conn, $driver_id, $kind, $task_id);   // also checks that the task's source matches the kind
if (!$veh) {
    setFlash('warning', 'You can only report a task that is assigned to you.');
    header('Location: schedules.php');
    exit();
}
$report = taskReportOf($conn, $kind, $task_id);
$startDt = taskStartDt($conn, $kind, $task_id);
$started = $startDt !== null && strtotime($startDt) <= time();
if (!$report && !$started) {                                    // UC024 exception 1.1: no Create Report before the departure time
    setFlash('warning', 'The task has not started yet. You can create the report after the departure time.');
    header('Location: schedules.php');
    exit();
}
$editing = $report && isset($_GET['edit']);
$error = null;
$f = ['actual_depart' => $report ? date('Y-m-d\TH:i', strtotime($report['actual_depart'])) : date('Y-m-d\TH:i', strtotime($startDt)),
      'actual_return' => $report ? date('Y-m-d\TH:i', strtotime($report['actual_return'])) : date('Y-m-d\TH:i'),
      'end_mileage' => $report ? (string)$report['end_mileage'] : '', 'fuel_level' => $report ? $report['fuel_level'] : '',
      'vehicle_condition' => $report ? $report['vehicle_condition'] : 'ok', 'problem_type' => $report ? (string)$report['problem_type'] : '',
      'problem_desc' => $report ? (string)$report['problem_desc'] : '', 'can_be_used' => $report && $report['can_be_used'] !== null ? (string)$report['can_be_used'] : ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) { $f[$k] = trim((string)($_POST[$k] ?? $f[$k])); }
    $photo = null;
    if ($f['vehicle_condition'] === 'problem' && !empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $up = saveUploadedImage($_FILES['photo'], 'reports', MAX_DOCUMENT_BYTES);
        if (!$up['ok']) { $error = $up['error']; } else { $photo = $up['path']; }
    }
    if ($error === null) {
        $res = saveTaskReport($conn, $driver_id, $kind, $task_id, $f, $photo);
        if ($res['ok']) {
            setFlash('success', $res['message']);
            header('Location: schedules.php');
            exit();
        }
        $error = $res['message'];
        if ($photo) { @unlink(__DIR__ . '/../uploads/reports/' . basename($photo)); }
        $editing = (bool)$report;
    }
}
$view = $report && !$editing && $_SERVER['REQUEST_METHOD'] !== 'POST';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$q = $kind . '=' . $task_id;
adminPageStart('Task Report');
?>
    <h1 class="text-2xl font-medium mb-1">Task report</h1>
    <p class="text-base-content/70 mb-4"><?= plate($veh['plate_number']) ?> <?= $h(trim(($veh['brand'] ?? '') . ' ' . ($veh['model'] ?? ''))) ?> &middot; current mileage <strong><?= (int)$veh['mileage'] ?> km</strong></p>
    <?php if ($error): ?><div class="alert alert-soft alert-error mb-4" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <?php if ($view): ?>
        <div class="card border border-base-300 bg-base-100"><div class="card-body">
            <dl class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-[12rem_1fr] sm:gap-y-3 [&_dt]:text-base-content/60 [&_dd]:mb-2 sm:[&_dd]:mb-0">
                <dt>Actual departure</dt><dd><?= $h(date('d M Y, H:i', strtotime($report['actual_depart']))) ?></dd>
                <dt>Actual return</dt><dd><?= $h(date('d M Y, H:i', strtotime($report['actual_return']))) ?></dd>
                <dt>End mileage</dt><dd><?= (int)$report['end_mileage'] ?> km</dd>
                <dt>Fuel level</dt><dd><?= $h(VEHICLE_FUEL_LEVELS[$report['fuel_level']] ?? $report['fuel_level']) ?></dd>
                <dt>Vehicle condition</dt><dd><?= $report['vehicle_condition'] === 'ok' ? 'No problem' : '<span class="badge badge-soft badge-error">Problem</span> ' . $h($report['problem_type']) . ': ' . $h($report['problem_desc']) . ($report['can_be_used'] === '0' ? ' <span class="badge badge-soft badge-warning">Cannot be used: Under Maintenance</span>' : ' (can still be used)') ?></dd>
                <?php if ($report['photo']): ?><dt>Photo</dt><dd><a class="link link-primary" href="<?= $h(SITE_URL . '/uploads/reports/' . basename($report['photo'])) ?>" target="_blank" rel="noopener">View photo</a></dd><?php endif; ?>
            </dl>
            <div class="mt-4 flex gap-2"><a class="btn btn-primary" href="task_report.php?<?= $h($q) ?>&amp;edit=1"><i class="fas fa-pen"></i>Edit</a><a class="btn btn-ghost" href="schedules.php">Back</a></div>
            <p class="mt-3 text-sm text-base-content/60">A report cannot be deleted because it updates the vehicle's records.</p>
        </div></div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" class="card border border-base-300 bg-base-100" novalidate><div class="card-body">
        <input type="hidden" name="<?= $kind ?>" value="<?= $task_id ?>">
        <div class="grid-12 grid grid-cols-12 gap-4">
            <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-lg" for="actual_depart">Actual departure *</label><input type="datetime-local" class="input input-lg w-full" id="actual_depart" name="actual_depart" value="<?= $h($f['actual_depart']) ?>" required></div>
            <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-lg" for="actual_return">Actual return *</label><input type="datetime-local" class="input input-lg w-full" id="actual_return" name="actual_return" value="<?= $h($f['actual_return']) ?>" required></div>
            <div class="col-span-12"><label class="mb-1 block text-lg" for="end_mileage">End mileage (km) *</label>
                <input class="input input-lg w-full" id="end_mileage" name="end_mileage" inputmode="numeric" pattern="[0-9]*" maxlength="7" value="<?= $h($f['end_mileage']) ?>" required></div>
            <fieldset class="col-span-12"><legend class="mb-2 text-lg">Fuel level *</legend>
                <div class="flex flex-wrap gap-x-6 gap-y-3">
                <?php foreach (VEHICLE_FUEL_LEVELS as $k => $l): ?>
                    <label class="flex items-center gap-2 text-lg" for="fl_<?= $k ?>"><input class="radio radio-primary" type="radio" name="fuel_level" id="fl_<?= $k ?>" value="<?= $k ?>" <?= $f['fuel_level'] === $k ? 'checked' : '' ?>><?= $h($l) ?></label>
                <?php endforeach; ?></div></fieldset>
            <fieldset class="col-span-12"><legend class="mb-2 text-lg">Vehicle condition *</legend>
                <div class="flex flex-wrap gap-x-6 gap-y-3">
                <label class="flex items-center gap-2 text-lg" for="vc_ok"><input class="radio radio-primary" type="radio" name="vehicle_condition" id="vc_ok" value="ok" <?= $f['vehicle_condition'] !== 'problem' ? 'checked' : '' ?>>No problem</label>
                <label class="flex items-center gap-2 text-lg" for="vc_problem"><input class="radio radio-primary" type="radio" name="vehicle_condition" id="vc_problem" value="problem" <?= $f['vehicle_condition'] === 'problem' ? 'checked' : '' ?>>Problem</label></div></fieldset>
        </div>
        <div id="problemBox" class="mt-4 rounded-box border border-base-300 p-4" style="display:none;">
            <h2 class="mb-3 font-semibold">Vehicle problem</h2>
            <div class="grid-12 grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-sm font-medium" for="problem_type">Problem type *</label>
                    <select class="select w-full" id="problem_type" name="problem_type"><option value="">Choose...</option>
                        <?php foreach (REPORT_PROBLEM_TYPES as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= $f['problem_type'] === $t ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?></select></div>
                <div class="col-span-12"><label class="mb-1 block text-sm font-medium" for="problem_desc">Description *</label><textarea class="textarea w-full" id="problem_desc" name="problem_desc" rows="3" maxlength="500"><?= $h($f['problem_desc']) ?></textarea></div>
                <div class="col-span-12 md:col-span-6"><label class="mb-1 block text-sm font-medium" for="photo">Photo <span class="font-normal text-base-content/60">(JPG or PNG, up to 5 MB)</span></label><input type="file" class="file-input w-full" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"></div>
                <fieldset class="col-span-12"><legend class="mb-2 text-sm font-medium">Can the vehicle still be used? *</legend>
                    <div class="flex flex-col gap-3">
                    <label class="flex items-center gap-2" for="cu1"><input class="radio radio-primary" type="radio" name="can_be_used" id="cu1" value="1" <?= $f['can_be_used'] === '1' ? 'checked' : '' ?>>Yes, it can still be used</label>
                    <label class="flex items-center gap-2" for="cu0"><input class="radio radio-primary" type="radio" name="can_be_used" id="cu0" value="0" <?= $f['can_be_used'] === '0' ? 'checked' : '' ?>>No (the vehicle becomes Under Maintenance)</label></div></fieldset>
            </div>
        </div>
        <div class="mt-6 flex flex-wrap gap-2"><button class="btn btn-primary btn-lg" type="submit"><?= $report ? 'Save changes' : 'Submit report' ?></button><a class="btn btn-ghost btn-lg" href="schedules.php">Cancel</a></div>
    </div></form>
    <script>
    (function () {
        var box = document.getElementById('problemBox');
        function sync() { box.style.display = document.getElementById('vc_problem').checked ? '' : 'none'; }
        document.querySelectorAll('input[name=vehicle_condition]').forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    })();
    </script>
    <?php endif; ?>
<?php adminPageEnd(); ?>
