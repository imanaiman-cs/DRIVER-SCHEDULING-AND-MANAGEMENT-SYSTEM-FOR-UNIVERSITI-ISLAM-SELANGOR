<?php
// ============================================================
// admin/view_vehicle.php  -  UC002B vehicle details, current mileage, current fuel level and upcoming bookings.
// Each upcoming booking can be moved to another vehicle (needed before Under Maintenance or Deactivate).
// ============================================================
$current_page = 'view_vehicle.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['vehicle_id'] ?? 0);
$v = loadVehicle($conn, $id);
if (!$v) { setFlash('danger', 'No record is found.'); header('Location: vehicles.php'); exit(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move') {
    redirectWithResult(changeBookingVehicle($conn, (string)($_POST['kind'] ?? ''), (int)($_POST['booking_id'] ?? 0), (int)($_POST['new_vehicle'] ?? 0)), 'view_vehicle.php?id=' . $id . '#bookings');
}
$bookings = vehicleUpcomingBookings($conn, $id);
$reports = dbAll($conn, "SELECT tr.*, t.task_source, t.task_type, t.trip_type, t.request_id, u.full_name AS driver_name FROM task_reports tr JOIN tasks t ON t.task_id = tr.task_id
                          JOIN drivers d ON d.driver_id = tr.driver_id JOIN users u ON u.user_id = d.user_id WHERE t.vehicle_id = ? ORDER BY tr.actual_return DESC LIMIT 10", [$id]);
$actions = '<a class="btn btn-ghost" href="vehicles.php"><i class="fas fa-arrow-left"></i>Back</a>';
if ($v['status'] !== 'inactive') { $actions .= '<a class="btn btn-outline" href="edit_vehicle.php?id=' . $id . '"><i class="fas fa-pen"></i>Edit</a>'; }
adminPageStart('Vehicle');
pageHeader($v['plate_number'] . ' ' . $v['brand'] . ' ' . $v['model'], 'bus', $v['vehicle_type'] . ' · ' . vehicleStatusLabel($v['status']), $actions);
?>
<div class="grid grid-cols-12 gap-5">
<div class="col-span-12 lg:col-span-5">
<?php cardStart('Vehicle details', 'circle-info'); ?>
<img class="mb-3 h-32 w-full rounded-lg bg-base-200 object-contain" src="<?= h(vehiclePhotoUrl($v['photo'], $v['vehicle_type'])) ?>" alt="">
<dl>
    <?= dlRow('Plate number', plate($v['plate_number'])) ?>
    <?= dlRow('Vehicle type', h($v['vehicle_type'])) ?>
    <?= dlRow('Brand / model', h($v['brand'] . ' ' . $v['model'])) ?>
    <?= dlRow('Fuel type', h($v['fuel_type'])) ?>
    <?= dlRow('Seating capacity', (string)(int)$v['capacity']) ?>
    <?= dlRow('Required licence class', h(LICENCE_CLASSES[$v['licence_required']] ?? $v['licence_required'])) ?>
    <?= dlRow('Current mileage', '<strong>' . number_format((int)$v['current_mileage']) . ' km</strong>') ?>
    <?= dlRow('Current fuel level', '<strong>' . h($v['fuel_level']) . '</strong>') ?>
    <?= dlRow('Status', vehicleStatusBadge($v['status'])) ?>
</dl>
<?php cardEnd(); ?>
</div>
<div class="col-span-12 lg:col-span-7" id="bookings">
<?php cardStart('Upcoming bookings (' . count($bookings) . ')', 'calendar-check'); ?>
<?php if (!$bookings): ?><p class="text-sm text-base-content/60">No upcoming booking.</p><?php endif; ?>
<?php foreach ($bookings as $b): $alts = bookingAlternatives($conn, $b, $v); ?>
<div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-200 py-2.5 text-sm last:border-0">
    <div><a class="font-medium link-hover" href="<?= h(SITE_URL . '/' . $b['link']) ?>"><?= h($b['label']) ?></a><div class="text-xs text-base-content/60"><?= h(fmtPeriod($b['start'], $b['end'])) ?></div></div>
    <?php if ($v['status'] !== 'inactive'): ?>
    <form method="post" class="flex items-center gap-1"><input type="hidden" name="action" value="move"><input type="hidden" name="vehicle_id" value="<?= $id ?>">
        <input type="hidden" name="kind" value="<?= h($b['kind']) ?>"><input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
        <label class="sr-only" for="nv<?= h($b['kind'] . $b['id']) ?>">Another vehicle</label>
        <select class="select select-xs w-44" id="nv<?= h($b['kind'] . $b['id']) ?>" name="new_vehicle" required><option value=""><?= $alts ? 'Assign another vehicle' : 'No vehicle free' ?></option>
            <?php foreach ($alts as $a): ?><option value="<?= (int)$a['vehicle_id'] ?>"><?= h($a['plate_number'] . ' ' . $a['brand'] . ' ' . $a['model']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-xs btn-primary" type="submit" <?= $alts ? '' : 'disabled' ?>>Move</button></form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php cardEnd(); ?>
<?php cardStart('Latest task reports', 'clipboard-check'); ?>
<?php if (!$reports): ?><p class="text-sm text-base-content/60">No report yet.</p><?php endif; ?>
<?php foreach ($reports as $rp): ?>
<div class="border-b border-base-200 py-2 text-sm last:border-0"><span class="font-medium"><?= h(fmtDT($rp['actual_return'])) ?></span> &middot; <?= h($rp['driver_name']) ?> &middot; <?= h(taskTypeLabel($rp)) ?>
    <div class="text-xs text-base-content/65"><?= number_format((int)$rp['start_mileage']) ?> &rarr; <?= number_format((int)$rp['end_mileage']) ?> km, fuel <?= h($rp['fuel_level']) ?>
    <?php if ($rp['vehicle_condition'] === 'problem'): ?> <span class="badge badge-error badge-xs">Problem: <?= h($rp['problem_type']) ?></span> <?= h($rp['problem_desc']) ?><?php endif; ?></div></div>
<?php endforeach; ?>
<?php cardEnd(); ?>
</div>
</div>
<?php adminPageEnd(); ?>
