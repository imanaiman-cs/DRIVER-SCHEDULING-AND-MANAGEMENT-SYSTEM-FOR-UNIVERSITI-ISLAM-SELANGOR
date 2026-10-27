<?php
// ============================================================
// UIS Driver Scheduling and Management System
// driver/update_vehicle.php  -  UC007 A1 Update Vehicle (by the driver)
//
// Opened from a task in My Tasks (UC028): ?trip=<schedule_id> or ?internal=<task_id>. Shows ONLY the mileage, fuel level and
// condition fields. The update is saved with the driver's name, date and time. A mileage lower than the last recorded
// mileage is refused. If a condition issue is entered the Administrative Assistant is told.
// A driver can only update a vehicle that is used for one of their own tasks.
// ============================================================
$current_page = 'schedules.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireDriver();

$driver_id = (int)($_SESSION['driver_id'] ?? 0);
$kind = isset($_REQUEST['internal']) ? 'internal' : 'trip';
$task_id = (int)($_REQUEST[$kind] ?? 0);
$veh = driverTaskVehicle($conn, $driver_id, $kind, $task_id);
if (!$veh) {
    setFlash('warning', 'You can only update a vehicle that is used for one of your tasks.');
    header('Location: schedules.php');
    exit();
}

$error = null;
$f = ['mileage' => '', 'fuel_level' => '', 'condition_issue' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) { $f[$k] = trim((string)($_POST[$k] ?? '')); }
    $res = recordVehicleUsage($conn, $driver_id, $kind, $task_id, $f['mileage'], $f['fuel_level'], $f['condition_issue']);
    if ($res['ok']) {
        setFlash('success', $res['message']);
        header('Location: schedules.php');
        exit();
    }
    $error = $res['message'];
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
adminPageStart('Update Vehicle');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-gauge-high text-success me-2"></i>Update Vehicle</h4>
    <p class="text-muted mb-3"><?= $h($veh['plate_number']) ?> &middot; <?= $h(trim(($veh['brand'] ?? '') . ' ' . ($veh['model'] ?? ''))) ?> &middot; last recorded mileage <strong><?= (int)$veh['mileage'] ?> km</strong></p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <form method="post" class="card border-0 shadow-sm" novalidate><div class="card-body">
        <input type="hidden" name="<?= $kind ?>" value="<?= $task_id ?>">
        <div class="mb-3"><label class="form-label fs-5" for="mileage">Current mileage (km) *</label>
            <input class="form-control form-control-lg" id="mileage" name="mileage" inputmode="numeric" pattern="[0-9]*" maxlength="7" value="<?= $h($f['mileage']) ?>" required></div>
        <div class="mb-3"><span class="form-label fs-5 d-block">Fuel level *</span>
            <?php foreach (VEHICLE_FUEL_LEVELS as $k => $l): ?>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="fuel_level" id="fl_<?= $k ?>" value="<?= $k ?>" <?= $f['fuel_level'] === $k ? 'checked' : '' ?>><label class="form-check-label fs-5" for="fl_<?= $k ?>"><?= $h($l) ?></label></div>
            <?php endforeach; ?></div>
        <div class="mb-3"><label class="form-label fs-5" for="condition_issue">Condition issue <span class="text-muted fs-6">(optional: a warning light, damage ...)</span></label>
            <textarea class="form-control" id="condition_issue" name="condition_issue" rows="3" maxlength="500"><?= $h($f['condition_issue']) ?></textarea></div>
        <div class="d-flex gap-2"><button class="btn btn-success btn-lg" type="submit">Save</button><a class="btn btn-outline-secondary btn-lg" href="schedules.php">Cancel</a></div>
    </div></form>
<?php adminPageEnd(); ?>
