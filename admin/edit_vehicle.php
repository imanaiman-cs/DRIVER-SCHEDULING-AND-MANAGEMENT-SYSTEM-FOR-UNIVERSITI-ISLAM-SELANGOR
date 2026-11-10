<?php
// ============================================================
// admin/edit_vehicle.php  -  Update Vehicle (SRS 10 Nov UC002 A2)
// Only the current fuel level, the current mileage and the status (Active / Under Maintenance) are updated here. The mileage cannot be lowered (A2.1);
// Under Maintenance asks for the workshop and the expected return date and lists the bookings in that period (A2.2).
// The other details (typing mistakes) are corrected in the collapsed section below.
// ============================================================
$current_page = 'edit_vehicle.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/upload.php';
require_once 'fleet_helpers.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM vehicles WHERE vehicle_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$cur = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cur) { setFlash('danger', 'Vehicle not found.'); header('Location: vehicles.php'); exit(); }

$v = [
    'plate_number' => $cur['plate_number'], 'vehicle_type' => isset(VEHICLE_TYPES[$cur['vehicle_type']]) ? $cur['vehicle_type'] : '',
    'brand' => (string)$cur['brand'], 'model' => (string)$cur['model'], 'year' => $cur['year'] === null ? '' : (string)$cur['year'],
    'capacity' => (string)$cur['capacity'], 'fuel_type' => $cur['fuel_type'], 'notes' => (string)$cur['notes'],
    'license_required' => $cur['license_required'], 'mileage' => '', 'fuel_level' => '',
];
$usageError = null; $usageItems = [];
$old = ['workshop' => '', 'return_date' => date('Y-m-d', strtotime('+7 days'))];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'usage') {
    $km = trim((string)($_POST['mileage'] ?? '')); $fuel = (string)($_POST['fuel_level'] ?? ''); $to = (string)($_POST['status'] ?? '');
    $old = ['workshop' => mb_substr(trim((string)($_POST['workshop'] ?? '')), 0, 120), 'return_date' => trim((string)($_POST['return_date'] ?? ''))];
    $cur0 = $conn->query('SELECT status, mileage FROM vehicles WHERE vehicle_id = ' . $id)->fetch_assoc();
    if (!preg_match('/^\d{1,7}$/', $km)) { $usageError = 'Fill in the current mileage.'; }
    elseif ((int)$km < (int)$cur0['mileage']) { $usageError = 'The mileage is not valid: it is lower than the current mileage (' . (int)$cur0['mileage'] . ' km).'; }       // A2.1
    elseif (!isset(VEHICLE_FUEL_LEVELS[$fuel])) { $usageError = 'Choose the current fuel level.'; }
    elseif (!in_array($to, ['available', 'maintenance'], true)) { $usageError = 'Choose Active or Under Maintenance.'; }
    elseif ($cur0['status'] === 'inactive') { $usageError = 'The vehicle is inactive. Activate it first.'; }
    if (!$usageError) {
        $currentShown = $cur0['status'] === 'maintenance' ? 'maintenance' : 'available';
        if ($to !== $currentShown || ($to === 'maintenance' && $old['workshop'] !== '')) {
            $res = setVehicleStatus($conn, $id, $to, $old['workshop'], $old['return_date']);
            if (!$res['ok']) { $usageError = $res['message']; $usageItems = $res['items']; }
            elseif ($res['items']) { setFlash('warning', $res['message'] . ' ' . implode(' | ', array_map(fn($i) => $i['text'], $res['items']))); }
        }
    }
    if (!$usageError) {
        $kmi = (int)$km;
        $stmt = $conn->prepare('UPDATE vehicles SET mileage = ?, fuel_level = ? WHERE vehicle_id = ?');
        $stmt->bind_param('isi', $kmi, $fuel, $id);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, 'vehicle_update', null, $cur['plate_number'] . ": mileage $kmi km, fuel " . VEHICLE_FUEL_LEVELS[$fuel]);
        if (empty($_SESSION['flash'])) { setFlash('success', 'Vehicle ' . $cur['plate_number'] . ' was updated.'); }
        header('Location: view_vehicle.php?id=' . $id);
        exit();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = vehicleInput($_POST);
    if ($v['license_required'] === '') { $v['license_required'] = $cur['license_required']; }
    $errors = validateVehicle($conn, $v, $id);
    $photo = $cur['photo'];
    $newPhoto = null;
    if (!$errors) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'vehicles');
        if (!$up['ok']) { $errors['photo'] = $up['error']; } elseif ($up['path']) { $newPhoto = $up['path']; $photo = $newPhoto; }
    }
    if (!$errors) {
        $lic  = in_array($v['license_required'], ['D', 'GDL', 'PSV'], true) ? $v['license_required'] : licenceForVehicleType($v['vehicle_type']);
        $year = $v['year'] === '' ? null : (int)$v['year'];
        $cap  = (int)$v['capacity'];
        $brand = $v['brand'] === '' ? null : $v['brand'];
        $notes = $v['notes'] === '' ? null : $v['notes'];
        try {
            $stmt = $conn->prepare('UPDATE vehicles SET plate_number = ?, vehicle_type = ?, brand = ?, model = ?, year = ?, capacity = ?, fuel_type = ?, license_required = ?, notes = ?, photo = ? WHERE vehicle_id = ?');
            $stmt->bind_param('ssssiissssi', $v['plate_number'], $v['vehicle_type'], $brand, $v['model'], $year, $cap, $v['fuel_type'], $lic, $notes, $photo, $id);
            $stmt->execute();
            $stmt->close();
            if ($newPhoto) { deleteUploadedImage($cur['photo']); }
            auditLog($conn, 'vehicle_update', null, $v['plate_number']);
            setFlash('success', 'Vehicle ' . $v['plate_number'] . ' was updated.');
            header('Location: view_vehicle.php?id=' . $id);
            exit();
        } catch (mysqli_sql_exception $e) {
            if ($newPhoto) { deleteUploadedImage($newPhoto); }
            if ((int)$e->getCode() === 1062) { $errors['plate_number'] = 'A vehicle with registration number ' . $v['plate_number'] . ' already exists.'; }
            else { error_log('edit_vehicle: ' . $e->getMessage()); $errors['_'] = 'Database error. Please try again.'; }
        }
    }
}
$shown = $cur['status'] === 'maintenance' ? 'maintenance' : ($cur['status'] === 'inactive' ? 'inactive' : 'available');
adminPageStart('Edit Vehicle');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-pen text-success me-2"></i>Update Vehicle <?= htmlspecialchars($cur['plate_number']) ?></h4>
    <p class="text-muted small">Current status: <span class="badge <?= vehicleStatusBadgeClass($cur['status']) ?>"><?= vehicleStatusLabel($cur['status']) ?></span>
        &middot; Deactivate or activate the vehicle in the vehicle list. <a href="vehicle_maintenance.php?id=<?= $id ?>">Maintenance history</a></p>
    <?php if ($usageError): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($usageError) ?><?= fleetItemsList($usageItems) ?></div><?php endif; ?>
    <form method="post" class="card border-0 shadow-sm mb-3" novalidate><div class="card-body">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="usage">
        <div class="row g-3">
            <div class="col-6 col-md-3"><label class="form-label" for="mileage">Current mileage (km) <span class="text-danger">*</span></label>
                <input type="number" min="<?= (int)$cur['mileage'] ?>" max="9999999" class="form-control" id="mileage" name="mileage" required value="<?= htmlspecialchars((string)($_POST['mileage'] ?? $cur['mileage'])) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="fuel_level">Current fuel level <span class="text-danger">*</span></label>
                <select class="form-select" id="fuel_level" name="fuel_level" required><?php foreach (array_reverse(VEHICLE_FUEL_LEVELS, true) as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['fuel_level'] ?? $cur['fuel_level']) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-3"><label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status" <?= $cur['status'] === 'inactive' ? 'disabled' : '' ?>><option value="available" <?= $shown === 'available' ? 'selected' : '' ?>>Active</option><option value="maintenance" <?= $shown === 'maintenance' ? 'selected' : '' ?>>Under Maintenance</option></select></div>
            <div class="col-12" id="maintFields">
                <div class="row g-2"><div class="col-md-6"><label class="form-label small" for="workshop">Workshop <span class="text-danger">*</span></label><input class="form-control" id="workshop" name="workshop" maxlength="120" value="<?= htmlspecialchars($old['workshop']) ?>"></div>
                <div class="col-md-6"><label class="form-label small" for="return_date">Expected return date <span class="text-danger">*</span></label><input type="date" class="form-control" id="return_date" name="return_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($old['return_date']) ?>"></div></div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2"><button class="btn btn-success" type="submit">Save</button><a class="btn btn-outline-secondary" href="view_vehicle.php?id=<?= $id ?>">Cancel</a></div>
    </div></form>
    <script>
    (function () { var s = document.getElementById('status'), f = document.getElementById('maintFields');
        function sync() { var m = s.value === 'maintenance' && <?= $shown === 'maintenance' ? 'false' : 'true' ?>; f.style.display = m ? '' : 'none'; document.getElementById('workshop').required = m; document.getElementById('return_date').required = m; }
        s.addEventListener('change', sync); sync(); })();
    </script>

    <details class="mb-3" <?= $errors ? 'open' : '' ?>><summary class="text-muted small" style="cursor:pointer;">Correct other vehicle details (typing mistakes)</summary>
    <?php if ($errors): ?><div class="alert alert-danger mt-2" role="alert">Please correct the highlighted fields.<?= isset($errors['_']) ? ' ' . htmlspecialchars($errors['_']) : '' ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm mt-2" novalidate><div class="card-body">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php vehicleFormFields($v, $errors, $cur['photo']); ?>
        <div class="mt-4 d-flex gap-2"><button class="btn btn-outline-success" type="submit">Save details</button></div>
    </div></form></details>
<?php adminPageEnd(); ?>
