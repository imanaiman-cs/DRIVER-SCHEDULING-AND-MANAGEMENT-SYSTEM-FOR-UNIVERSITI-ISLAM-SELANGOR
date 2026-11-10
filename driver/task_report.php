<?php
// ============================================================
// driver/task_report.php  -  UC024 Manage Assigned Task Report (driver): create, view, update the report of a task
// Opened from a task in My Tasks: ?trip=<schedule_id> or ?internal=<task_id>. The report records the actual times, the end mileage, the fuel
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
$veh = driverTaskVehicle($conn, $driver_id, $kind, $task_id);
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
    <h4 class="fw-bold mb-1"><i class="fas fa-clipboard-check text-success me-2"></i>Task report</h4>
    <p class="text-muted mb-3"><?= $h($veh['plate_number']) ?> &middot; <?= $h(trim(($veh['brand'] ?? '') . ' ' . ($veh['model'] ?? ''))) ?> &middot; current mileage <strong><?= (int)$veh['mileage'] ?> km</strong></p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <?php if ($view): ?>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Actual departure</dt><dd class="col-sm-8"><?= $h(date('d M Y, H:i', strtotime($report['actual_depart']))) ?></dd>
                <dt class="col-sm-4">Actual return</dt><dd class="col-sm-8"><?= $h(date('d M Y, H:i', strtotime($report['actual_return']))) ?></dd>
                <dt class="col-sm-4">End mileage</dt><dd class="col-sm-8"><?= (int)$report['end_mileage'] ?> km</dd>
                <dt class="col-sm-4">Fuel level</dt><dd class="col-sm-8"><?= $h(VEHICLE_FUEL_LEVELS[$report['fuel_level']] ?? $report['fuel_level']) ?></dd>
                <dt class="col-sm-4">Vehicle condition</dt><dd class="col-sm-8"><?= $report['vehicle_condition'] === 'ok' ? 'No problem' : '<span class="badge bg-danger">Problem</span> ' . $h($report['problem_type']) . ': ' . $h($report['problem_desc']) . ($report['can_be_used'] === '0' ? ' <span class="badge bg-warning text-dark">Cannot be used: Under Maintenance</span>' : ' (can still be used)') ?></dd>
                <?php if ($report['photo']): ?><dt class="col-sm-4">Photo</dt><dd class="col-sm-8"><a href="<?= $h(SITE_URL . '/uploads/reports/' . basename($report['photo'])) ?>" target="_blank" rel="noopener">View photo</a></dd><?php endif; ?>
            </dl>
            <div class="d-flex gap-2 mt-3"><a class="btn btn-success" href="task_report.php?<?= $h($q) ?>&amp;edit=1"><i class="fas fa-pen me-1"></i>Edit</a><a class="btn btn-outline-secondary" href="schedules.php">Back</a></div>
            <p class="small text-muted mt-3 mb-0">A report cannot be deleted because it updates the vehicle's records.</p>
        </div></div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm" novalidate><div class="card-body">
        <input type="hidden" name="<?= $kind ?>" value="<?= $task_id ?>">
        <div class="row g-3">
            <div class="col-12 col-md-6"><label class="form-label fs-5" for="actual_depart">Actual departure *</label><input type="datetime-local" class="form-control form-control-lg" id="actual_depart" name="actual_depart" value="<?= $h($f['actual_depart']) ?>" required></div>
            <div class="col-12 col-md-6"><label class="form-label fs-5" for="actual_return">Actual return *</label><input type="datetime-local" class="form-control form-control-lg" id="actual_return" name="actual_return" value="<?= $h($f['actual_return']) ?>" required></div>
            <div class="col-12"><label class="form-label fs-5" for="end_mileage">End mileage (km) *</label>
                <input class="form-control form-control-lg" id="end_mileage" name="end_mileage" inputmode="numeric" pattern="[0-9]*" maxlength="7" value="<?= $h($f['end_mileage']) ?>" required></div>
            <div class="col-12"><span class="form-label fs-5 d-block">Fuel level *</span>
                <?php foreach (VEHICLE_FUEL_LEVELS as $k => $l): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="fuel_level" id="fl_<?= $k ?>" value="<?= $k ?>" <?= $f['fuel_level'] === $k ? 'checked' : '' ?>><label class="form-check-label fs-5" for="fl_<?= $k ?>"><?= $h($l) ?></label></div>
                <?php endforeach; ?></div>
            <div class="col-12"><span class="form-label fs-5 d-block">Vehicle condition *</span>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="vehicle_condition" id="vc_ok" value="ok" <?= $f['vehicle_condition'] !== 'problem' ? 'checked' : '' ?>><label class="form-check-label fs-5" for="vc_ok">No problem</label></div>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="vehicle_condition" id="vc_problem" value="problem" <?= $f['vehicle_condition'] === 'problem' ? 'checked' : '' ?>><label class="form-check-label fs-5" for="vc_problem">Problem</label></div></div>
        </div>
        <div id="problemBox" class="border rounded p-3 mt-3" style="display:none;">
            <h6 class="fw-bold">Vehicle problem</h6>
            <div class="row g-3">
                <div class="col-12 col-md-6"><label class="form-label" for="problem_type">Problem type *</label>
                    <select class="form-select" id="problem_type" name="problem_type"><option value="">Choose...</option>
                        <?php foreach (REPORT_PROBLEM_TYPES as $t): ?><option <?= $f['problem_type'] === $t ? 'selected' : '' ?>><?= $h($t) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label" for="problem_desc">Description *</label><textarea class="form-control" id="problem_desc" name="problem_desc" rows="3" maxlength="500"><?= $h($f['problem_desc']) ?></textarea></div>
                <div class="col-12 col-md-6"><label class="form-label" for="photo">Photo <span class="text-muted">(JPG, PNG or WebP, up to 5 MB)</span></label><input type="file" class="form-control" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"></div>
                <div class="col-12"><span class="form-label d-block">Can the vehicle still be used? *</span>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="can_be_used" id="cu1" value="1" <?= $f['can_be_used'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="cu1">Yes, it can still be used</label></div>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="can_be_used" id="cu0" value="0" <?= $f['can_be_used'] === '0' ? 'checked' : '' ?>><label class="form-check-label" for="cu0">No (the vehicle becomes Under Maintenance)</label></div></div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-4"><button class="btn btn-success btn-lg" type="submit"><?= $report ? 'Save changes' : 'Submit report' ?></button><a class="btn btn-outline-secondary btn-lg" href="schedules.php">Cancel</a></div>
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
