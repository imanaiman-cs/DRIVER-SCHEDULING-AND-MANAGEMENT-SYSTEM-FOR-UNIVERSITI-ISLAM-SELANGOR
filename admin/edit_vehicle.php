<?php
// ============================================================
// admin/edit_vehicle.php  -  UC007 Update Vehicle (details only)
// The status is changed on vehicle_maintenance.php / the vehicle list (UC008, UC009).
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
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = vehicleInput($_POST);
    $errors = validateVehicle($conn, $v, $id);
    $photo = $cur['photo'];
    $newPhoto = null;
    if (!$errors) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'vehicles');
        if (!$up['ok']) { $errors['photo'] = $up['error']; } elseif ($up['path']) { $newPhoto = $up['path']; $photo = $newPhoto; }
    }
    if (!$errors) {
        $lic  = licenceForVehicleType($v['vehicle_type']);
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
adminPageStart('Edit Vehicle');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-pen text-success me-2"></i>Edit Vehicle</h4>
    <p class="text-muted small">Current status: <span class="badge <?= vehicleStatusBadgeClass($cur['status']) ?>"><?= vehicleStatusLabel($cur['status']) ?></span>
        &middot; <a href="vehicle_maintenance.php?id=<?= $id ?>">change status / maintenance</a></p>
    <?php if ($errors): ?><div class="alert alert-danger" role="alert">Please correct the highlighted fields.<?= isset($errors['_']) ? ' ' . htmlspecialchars($errors['_']) : '' ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm" novalidate><div class="card-body">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php vehicleFormFields($v, $errors, $cur['photo']); ?>
        <div class="mt-4 d-flex gap-2"><button class="btn btn-success" type="submit">Save changes</button><a class="btn btn-outline-secondary" href="view_vehicle.php?id=<?= $id ?>">Cancel</a></div>
    </div></form>
<?php adminPageEnd(); ?>
