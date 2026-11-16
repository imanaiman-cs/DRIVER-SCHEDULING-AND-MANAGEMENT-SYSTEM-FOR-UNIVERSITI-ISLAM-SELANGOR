<?php
// ============================================================
// admin/view_vehicle.php  -  UC006 vehicle details: data, current maintenance, upcoming trips
// ============================================================
$current_page = 'view_vehicle.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT v.*, m.workshop, m.end_date AS back_on, m.start_date AS maint_from
                          FROM vehicles v LEFT JOIN vehicle_maintenance m ON m.vehicle_id = v.vehicle_id AND m.closed_at IS NULL AND v.status = 'maintenance'
                         WHERE v.vehicle_id = ? ORDER BY m.maintenance_id DESC LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$v) { setFlash('danger', 'Vehicle not found.'); header('Location: vehicles.php'); exit(); }

$shown = vehicleDisplayStatus($conn, $id, $v['status']);
$trips = vehicleUpcomingTrips($conn, $id);
$stmt = $conn->prepare('SELECT l.recorded_at, l.mileage, l.fuel_level, l.condition_issue, d.name AS driver FROM vehicle_usage_log l JOIN drivers d ON d.driver_id = l.driver_id WHERE l.vehicle_id = ? ORDER BY l.log_id DESC LIMIT 15');
$stmt->bind_param('i', $id);
$stmt->execute();
$usage = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);   // UC007 A1: what the drivers recorded (mileage history for servicing)
$stmt->close();
adminPageStart($v['plate_number']);
?>
    <div class="flex flex-wrap justify-between items-center mb-3 gap-2">
        <h1 class="text-2xl font-medium mb-0"><?= plate($v['plate_number']) ?>
            <span class="badge <?= vehicleStatusBadgeClass($shown) ?> text-base ms-2"><?= vehicleStatusLabel($shown) ?></span></h1>
        <div class="flex flex-wrap gap-1">
            <a class="btn btn-outline btn-sm" href="edit_vehicle.php?id=<?= $id ?>"><i class="fas fa-pen me-1"></i>Edit</a>
            <?php if ($v['status'] !== 'inactive'): ?>
                <button class="btn btn-outline btn-error btn-sm" data-fleet-url="../ajax/deactivate_vehicle.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make this vehicle Inactive?">Deactivate</button>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="vehicles.php">Back</a>
        </div>
    </div>
    <div id="fleetResult"></div>
    <div class="grid-12 grid grid-cols-12 gap-4">
        <div class="col-span-12 lg:col-span-5"><div class="card border border-base-300 bg-base-100"><div class="card-body">
            <img src="<?= htmlspecialchars(vehiclePhotoUrl($v['photo'], $v['vehicle_type'])) ?>" alt="" class="rounded mb-3" style="max-height:140px;max-width:100%">
            <dl class="grid-12 grid grid-cols-12 gap-x-6 gap-y-1 mb-0 text-sm">
                <dt class="col-span-5">Type</dt><dd class="col-span-7"><?= htmlspecialchars($v['vehicle_type']) ?></dd>
                <dt class="col-span-5">Model</dt><dd class="col-span-7"><?= htmlspecialchars(trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''))) ?></dd>
                <dt class="col-span-5">Passenger capacity</dt><dd class="col-span-7"><?= (int)$v['capacity'] ?></dd>
                <dt class="col-span-5">Licence required</dt><dd class="col-span-7"><span class="badge badge-ghost"><?= htmlspecialchars($v['license_required']) ?></span></dd>
                <?php if ($v['year']): ?><dt class="col-span-5">Year</dt><dd class="col-span-7"><?= (int)$v['year'] ?></dd><?php endif; ?>
                <dt class="col-span-5">Fuel type</dt><dd class="col-span-7"><?= htmlspecialchars($v['fuel_type']) ?></dd>
                <dt class="col-span-5">Current mileage</dt><dd class="col-span-7"><?= (int)$v['mileage'] ?> km</dd>
                <dt class="col-span-5">Current fuel level</dt><dd class="col-span-7"><?= htmlspecialchars((string)(VEHICLE_FUEL_LEVELS[$v['fuel_level']] ?? '-')) ?></dd>
                <?php if ($v['notes']): ?><dt class="col-span-5">Notes</dt><dd class="col-span-7"><?= nl2br(htmlspecialchars($v['notes'])) ?></dd><?php endif; ?>
            </dl>
            <?php if ($v['status'] === 'maintenance' && $v['back_on']): ?>
                <div class="alert alert-soft alert-warning mt-4 mb-0 text-sm"><strong>Under maintenance</strong> at <?= htmlspecialchars((string)$v['workshop']) ?> since <?= date('d M Y', strtotime($v['maint_from'])) ?>.
                    Expected back: <strong><?= date('d M Y', strtotime($v['back_on'])) ?></strong>.</div>
            <?php endif; ?>
        </div></div></div>
        <div class="col-span-12 lg:col-span-7"><div class="card border border-base-300 bg-base-100"><div class="card-body">
            <h6 class="font-bold">Upcoming trips</h6>
            <?php if (!$trips): ?><p class="text-base-content/60 text-sm mb-0">No upcoming trips. This vehicle can be deactivated.</p>
            <?php else: ?><?= fleetItemsList($trips, false, $id) ?><?php endif; ?>
        </div></div></div>
        <div class="col-span-12"><div class="card border border-base-300 bg-base-100"><div class="card-body">
            <h6 class="font-bold">Usage updates by drivers <span class="font-normal text-base-content/60 text-sm">(mileage <?= (int)$v['mileage'] ?> km now)</span></h6>
            <?php if (!$usage): ?><p class="text-base-content/60 text-sm mb-0">No driver has recorded the mileage yet.</p>
            <?php else: ?>
            <div class="overflow-x-auto"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Date and time</th><th>Driver</th><th class="text-end">Mileage (km)</th><th>Fuel</th><th>Condition issue</th></tr></thead>
                <tbody>
                <?php foreach ($usage as $u): ?>
                    <tr><td class="whitespace-nowrap"><?= date('d M Y H:i', strtotime($u['recorded_at'])) ?></td><td><?= htmlspecialchars($u['driver']) ?></td>
                        <td class="text-end"><?= (int)$u['mileage'] ?></td><td><?= htmlspecialchars(VEHICLE_FUEL_LEVELS[$u['fuel_level']] ?? $u['fuel_level']) ?></td>
                        <td><?= $u['condition_issue'] ? '<span class="text-error">' . htmlspecialchars($u['condition_issue']) . '</span>' : '&mdash;' ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div></div></div>
    </div>
<?php fleetActionScript(); adminPageEnd(); ?>
