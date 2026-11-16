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
adminPageStart($v['plate_number']);
?>
    <div class="flex flex-wrap justify-between items-center mb-4 gap-2">
        <h1 class="text-2xl font-extrabold tracking-tight mb-0"><?= plate($v['plate_number']) ?>
            <span class="badge <?= vehicleStatusBadgeClass($shown) ?> text-base ms-2"><?= vehicleStatusLabel($shown) ?></span></h1>
        <div class="flex flex-wrap gap-1">
            <a class="btn btn-outline btn-sm" href="edit_vehicle.php?id=<?= $id ?>"><i class="fas fa-pen me-1"></i>Edit</a>
            <a class="btn btn-outline btn-warning btn-sm" href="vehicle_maintenance.php?id=<?= $id ?>"><i class="fas fa-screwdriver-wrench me-1"></i>Maintenance</a>
            <?php if ($v['status'] === 'inactive'): ?>
                <button class="btn btn-success btn-sm" data-fleet-url="../ajax/update_vehicle_status.php" data-fleet-id="<?= $id ?>" data-fleet-extra="status=available" data-fleet-confirm="Make this vehicle Available again?">Reactivate</button>
            <?php else: ?>
                <button class="btn btn-outline btn-error btn-sm" data-fleet-url="../ajax/deactivate_vehicle.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make this vehicle Inactive?">Deactivate</button>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="vehicles.php">Back</a>
        </div>
    </div>
    <div id="fleetResult"></div>
    <div class="grid-12 grid grid-cols-12 gap-4">
        <div class="col-span-12 lg:col-span-5"><div class="card border border-base-300 bg-base-100"><div class="card-body">
            <img src="<?= htmlspecialchars(vehiclePhotoUrl($v['photo'], $v['vehicle_type'])) ?>" alt="" class="rounded mb-4" style="max-height:140px;max-width:100%">
            <dl class="grid-12 grid grid-cols-12 gap-x-6 mb-0 text-sm">
                <dt class="col-span-5">Type</dt><dd class="col-span-7"><?= htmlspecialchars($v['vehicle_type']) ?></dd>
                <dt class="col-span-5">Model</dt><dd class="col-span-7"><?= htmlspecialchars(trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''))) ?></dd>
                <dt class="col-span-5">Passenger capacity</dt><dd class="col-span-7"><?= (int)$v['capacity'] ?></dd>
                <dt class="col-span-5">Licence required</dt><dd class="col-span-7"><span class="badge badge-ghost"><?= htmlspecialchars($v['license_required']) ?></span></dd>
                <?php if ($v['year']): ?><dt class="col-span-5">Year</dt><dd class="col-span-7"><?= (int)$v['year'] ?></dd><?php endif; ?>
                <dt class="col-span-5">Fuel</dt><dd class="col-span-7"><?= htmlspecialchars($v['fuel_type']) ?></dd>
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
            <?php else: ?><?= fleetItemsList($trips) ?><?php endif; ?>
        </div></div></div>
    </div>
<?php fleetActionScript(); adminPageEnd(); ?>
