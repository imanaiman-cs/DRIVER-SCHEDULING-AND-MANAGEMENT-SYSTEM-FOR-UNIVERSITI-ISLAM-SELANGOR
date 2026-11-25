<?php
// ============================================================
// admin/add_vehicle.php  -  View Layer: VehicleUI (SDD_CLASS_103) displayVehicleForm() - UC002A Add Vehicle (plate number, type, brand, model, fuel type, seating capacity,
// required licence class, current mileage, current fuel level). The vehicle is added with the Active status.
// ============================================================
$current_page = 'add_vehicle.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$errors = []; $formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = (new VehicleController())->addVehicle($_POST);
    if ($res['ok']) { redirectWithResult($res, 'view_vehicle.php?id=' . $res['vehicle_id']); }
    $errors = $res['errors'] ?? []; $formError = $res['message'];
}
$v = fn($k, $d = '') => h($_POST[$k] ?? $d);
$sel = fn($k, $val) => (($_POST[$k] ?? '') === $val) ? 'selected' : '';
adminPageStart('Add Vehicle');
pageHeader('Add Vehicle', 'plus', 'The required licence class is used to filter drivers in Recommend Driver.', '<a class="btn btn-ghost" href="vehicles.php"><i class="fas fa-arrow-left"></i>Back</a>');
if ($formError) { echo '<div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>' . h($formError) . '</span></div>'; }
cardStart();
?>
<form method="post" class="grid grid-cols-12 gap-4" novalidate>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="plate_number">Plate number</label>
        <input class="input w-full uppercase<?= errClass($errors, 'plate_no') ?>" id="plate_number" name="plate_no" maxlength="15" required value="<?= $v('plate_no') ?>"><?= fieldError($errors, 'plate_no') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="vehicle_type">Vehicle type</label>
        <select class="select w-full<?= errClass($errors, 'type', 'select') ?>" id="vehicle_type" name="type" required><option value="">Select</option>
            <?php foreach (Vehicle::TYPES as $t): ?><option <?= $sel('type', $t) ?>><?= $t ?></option><?php endforeach; ?></select><?= fieldError($errors, 'type') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="brand">Brand</label>
        <input class="input w-full<?= errClass($errors, 'brand') ?>" id="brand" name="brand" maxlength="50" required value="<?= $v('brand') ?>"><?= fieldError($errors, 'brand') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="model">Model</label>
        <input class="input w-full<?= errClass($errors, 'model') ?>" id="model" name="model" maxlength="50" required value="<?= $v('model') ?>"><?= fieldError($errors, 'model') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="fuel_type">Fuel type</label>
        <select class="select w-full<?= errClass($errors, 'fuel_type', 'select') ?>" id="fuel_type" name="fuel_type" required><option value="">Select</option>
            <?php foreach (Vehicle::FUEL_TYPES as $f): ?><option <?= $sel('fuel_type', $f) ?>><?= $f ?></option><?php endforeach; ?></select><?= fieldError($errors, 'fuel_type') ?></div>
    <div class="col-span-6 md:col-span-2"><label class="mb-1 block text-sm font-medium" for="capacity">Seating capacity</label>
        <input type="number" class="input w-full<?= errClass($errors, 'capacity') ?>" id="capacity" name="capacity" min="1" max="100" required value="<?= $v('capacity') ?>"><?= fieldError($errors, 'capacity') ?></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="licence_required">Required licence class</label>
        <select class="select w-full<?= errClass($errors, 'licence_required', 'select') ?>" id="licence_required" name="licence_required" required><option value="">Select</option>
            <?php foreach (Driver::LICENCE_CLASSES as $k => $l): ?><option value="<?= $k ?>" <?= $sel('licence_required', $k) ?>><?= h($l) ?></option><?php endforeach; ?></select><?= fieldError($errors, 'licence_required') ?></div>
    <div class="col-span-6 md:col-span-2"><label class="mb-1 block text-sm font-medium" for="current_mileage">Current mileage (km)</label>
        <input type="number" class="input w-full<?= errClass($errors, 'current_mileage') ?>" id="current_mileage" name="current_mileage" min="0" required value="<?= $v('current_mileage') ?>"><?= fieldError($errors, 'current_mileage') ?></div>
    <div class="col-span-6 md:col-span-2"><label class="mb-1 block text-sm font-medium" for="fuel_level">Current fuel level</label>
        <select class="select w-full<?= errClass($errors, 'fuel_level', 'select') ?>" id="fuel_level" name="fuel_level" required><option value="">Select</option>
            <?php foreach (Vehicle::FUEL_LEVELS as $f): ?><option <?= $sel('fuel_level', $f) ?>><?= $f ?></option><?php endforeach; ?></select><?= fieldError($errors, 'fuel_level') ?></div>
    <div class="col-span-12 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button><a class="btn btn-ghost" href="vehicles.php">Cancel</a></div>
</form>
<script>
document.getElementById('vehicle_type').addEventListener('change', function () {
    var m = { Car: 'D', MPV: 'D', Van: 'D', Bus: 'PSV', Lorry: 'GDL' }, l = document.getElementById('licence_required');
    if (m[this.value]) { l.value = m[this.value]; }
});
</script>
<?php cardEnd(); adminPageEnd(); ?>
