<?php
// ============================================================
// admin/vehicles.php  -  UC002 Manage Vehicle: list (plate number, type, seating capacity, status),
// filter by vehicle type or status, Add Vehicle, View, Edit, Deactivate.
// ============================================================
$current_page = 'vehicles.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate') {
    $id = (int)($_POST['vehicle_id'] ?? 0);
    $res = deactivateVehicle($conn, $id);
    redirectWithResult($res, $res['ok'] ? 'vehicles.php' : 'view_vehicle.php?id=' . $id);
}
$type = in_array($_GET['type'] ?? '', VEHICLE_TYPES, true) ? $_GET['type'] : '';
$status = in_array($_GET['status'] ?? '', ['active', 'maintenance', 'inactive'], true) ? $_GET['status'] : '';
$w = []; $p = [];
if ($type) { $w[] = 'vehicle_type = ?'; $p[] = $type; }
if ($status) { $w[] = 'status = ?'; $p[] = $status; }
$rows = dbAll($conn, 'SELECT * FROM vehicles' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY FIELD(status, 'active', 'maintenance', 'inactive'), vehicle_type, plate_number", $p);
adminPageStart('Vehicle');
pageHeader('Vehicle', 'bus', 'Cars, MPVs, vans, buses and lorries. A vehicle is never deleted: deactivate it.', '<a class="btn btn-primary" href="add_vehicle.php"><i class="fas fa-plus"></i>Add Vehicle</a>');
?>
<form method="get" class="mb-4 flex flex-wrap items-end gap-2">
    <div><label class="mb-1 block text-xs font-medium" for="type">Vehicle type</label><select class="select select-sm" id="type" name="type"><option value="">All types</option>
        <?php foreach (VEHICLE_TYPES as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div><label class="mb-1 block text-xs font-medium" for="status">Status</label><select class="select select-sm" id="status" name="status"><option value="">All statuses</option>
        <?php foreach (['active', 'maintenance', 'inactive'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= h(vehicleStatusLabel($s)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-filter"></i>Filter</button><a class="btn btn-sm btn-ghost" href="vehicles.php">Reset</a>
</form>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table [&_tbody_tr:hover]:bg-base-200/60">
<thead><tr><th>Plate number</th><th>Vehicle</th><th>Type</th><th class="text-end">Seats</th><th>Licence</th><th class="text-end">Mileage</th><th>Fuel</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($rows as $v): $id = (int)$v['vehicle_id']; ?>
<tr>
    <td><a href="view_vehicle.php?id=<?= $id ?>"><?= plate($v['plate_number']) ?></a></td>
    <td><?= h($v['brand'] . ' ' . $v['model']) ?><div class="text-xs text-base-content/60"><?= h($v['fuel_type']) ?></div></td>
    <td><?= h($v['vehicle_type']) ?></td><td class="text-end"><?= (int)$v['capacity'] ?></td><td><?= h($v['licence_required']) ?></td>
    <td class="text-end tabular-nums"><?= number_format((int)$v['current_mileage']) ?> km</td><td><?= h($v['fuel_level']) ?></td>
    <td><?= vehicleStatusBadge($v['status']) ?></td>
    <td class="text-end whitespace-nowrap"><a class="btn btn-sm btn-outline" href="view_vehicle.php?id=<?= $id ?>">View</a>
        <?php if ($v['status'] !== 'inactive'): ?><a class="btn btn-sm btn-outline" href="edit_vehicle.php?id=<?= $id ?>"><i class="fas fa-pen"></i><span class="hidden md:inline">Edit</span></a>
        <form method="post" class="inline"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="vehicle_id" value="<?= $id ?>">
        <button class="btn btn-sm btn-outline btn-error" type="submit" data-confirm="<?= h($v['plate_number']) ?> will no longer be offered for requests. Its records remain." data-confirm-title="Deactivate this vehicle?" data-confirm-button="Confirm" data-confirm-tone="danger"><i class="fas fa-ban"></i><span class="hidden md:inline">Deactivate</span></button></form><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><?= emptyRow(9) ?><?php endif; ?>
</tbody></table></div></div>
<?php adminPageEnd(); ?>
