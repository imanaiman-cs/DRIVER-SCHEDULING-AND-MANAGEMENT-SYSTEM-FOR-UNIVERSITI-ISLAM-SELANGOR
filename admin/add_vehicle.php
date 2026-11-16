<?php
// ============================================================
// admin/add_vehicle.php  -  UC005 Add Vehicle
// The licence class needed is set from the type (Car/Van D, Lorry GDL, Bus PSV). New vehicles are Available.
// ============================================================
$current_page = 'add_vehicle.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/upload.php';
require_once 'fleet_helpers.php';
requireAdmin();

$v = ['plate_number' => '', 'vehicle_type' => '', 'brand' => '', 'model' => '', 'year' => '', 'capacity' => '', 'fuel_type' => 'Petrol', 'notes' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = vehicleInput($_POST);
    $errors = validateVehicle($conn, $v);
    $photo = null;
    if (!$errors) {
        $up = saveUploadedImage($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], 'vehicles');
        if (!$up['ok']) { $errors['photo'] = $up['error']; } else { $photo = $up['path']; }
    }
    if (!$errors) {
        $lic  = licenceForVehicleType($v['vehicle_type']);
        $year = $v['year'] === '' ? null : (int)$v['year'];
        $cap  = (int)$v['capacity'];
        $brand = $v['brand'] === '' ? null : $v['brand'];
        $notes = $v['notes'] === '' ? null : $v['notes'];
        try {
            $stmt = $conn->prepare("INSERT INTO vehicles (plate_number, vehicle_type, brand, model, year, capacity, fuel_type, status, license_required, notes, photo)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'available', ?, ?, ?)");
            $stmt->bind_param('ssssiissss', $v['plate_number'], $v['vehicle_type'], $brand, $v['model'], $year, $cap, $v['fuel_type'], $lic, $notes, $photo);
            $stmt->execute();
            $stmt->close();
            auditLog($conn, 'vehicle_add', null, $v['plate_number']);
            setFlash('success', 'Vehicle ' . $v['plate_number'] . ' was added.');
            header('Location: vehicles.php');
            exit();
        } catch (mysqli_sql_exception $e) {
            deleteUploadedImage($photo);
            if ((int)$e->getCode() === 1062) { $errors['plate_number'] = 'A vehicle with registration number ' . $v['plate_number'] . ' already exists.'; }
            else { error_log('add_vehicle: ' . $e->getMessage()); $errors['_'] = 'Database error. Please try again.'; }
        }
    }
}
adminPageStart('Add Vehicle');
?>
    <h1 class="text-2xl font-medium mb-4">Add Vehicle</h1>
    <?php if ($errors): ?><div class="alert alert-soft alert-error" role="alert">Please correct the highlighted fields.<?= isset($errors['_']) ? ' ' . htmlspecialchars($errors['_']) : '' ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="card border border-base-300 bg-base-100" novalidate><div class="card-body">
        <?php vehicleFormFields($v, $errors); ?>
        <div class="mt-6 flex gap-2"><button class="btn btn-success" type="submit">Save vehicle</button><a class="btn btn-outline" href="vehicles.php">Cancel</a></div>
    </div></form>
<?php adminPageEnd(); ?>
