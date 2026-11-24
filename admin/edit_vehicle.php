<?php
// ============================================================
// admin/edit_vehicle.php  -  UC002C Update Vehicle: current fuel level, current mileage and status
// (Active / Under Maintenance). A lower mileage is refused. Under Maintenance with upcoming bookings is refused:
// the bookings are listed on the vehicle page to move each one to another vehicle.
// ============================================================
$current_page = 'edit_vehicle.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['vehicle_id'] ?? 0);
$v = loadVehicle($conn, $id);
if (!$v || $v['status'] === 'inactive') { setFlash('warning', 'Only an active vehicle can be updated.'); header('Location: vehicles.php'); exit(); }
$errors = []; $formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = updateVehicle($conn, $id, $_POST);
    if ($res['ok']) { redirectWithResult($res, 'view_vehicle.php?id=' . $id); }
    if (!empty($res['bookings'])) { redirectWithResult($res, 'view_vehicle.php?id=' . $id . '#bookings'); }
    $errors = $res['errors'] ?? []; $formError = $res['message'];
}
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $v;
adminPageStart('Edit Vehicle');
pageHeader('Edit ' . $v['plate_number'], 'pen', 'Only the current fuel level, current mileage and status can be updated.', '<a class="btn btn-ghost" href="view_vehicle.php?id=' . $id . '"><i class="fas fa-arrow-left"></i>Back</a>');
if ($formError) { echo '<div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>' . h($formError) . '</span></div>'; }
cardStart();
?>
<form method="post" class="grid grid-cols-12 gap-4" novalidate>
    <input type="hidden" name="vehicle_id" value="<?= $id ?>">
    <div class="col-span-12 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="current_mileage">Current mileage (km)</label>
        <input type="number" class="input w-full<?= errClass($errors, 'current_mileage') ?>" id="current_mileage" name="current_mileage" min="<?= (int)$v['current_mileage'] ?>" required value="<?= h($src['current_mileage']) ?>"><?= fieldError($errors, 'current_mileage') ?></div>
    <div class="col-span-6 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="fuel_level">Current fuel level</label>
        <select class="select w-full<?= errClass($errors, 'fuel_level', 'select') ?>" id="fuel_level" name="fuel_level"><?php foreach (FUEL_LEVELS as $f): ?><option <?= $src['fuel_level'] === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select><?= fieldError($errors, 'fuel_level') ?></div>
    <div class="col-span-6 md:col-span-4"><label class="mb-1 block text-sm font-medium" for="status">Status</label>
        <select class="select w-full<?= errClass($errors, 'status', 'select') ?>" id="status" name="status"><option value="active" <?= $src['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="maintenance" <?= $src['status'] === 'maintenance' ? 'selected' : '' ?>>Under Maintenance</option></select><?= fieldError($errors, 'status') ?></div>
    <div class="col-span-12 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button><a class="btn btn-ghost" href="view_vehicle.php?id=<?= $id ?>">Cancel</a></div>
</form>
<?php cardEnd(); adminPageEnd(); ?>
