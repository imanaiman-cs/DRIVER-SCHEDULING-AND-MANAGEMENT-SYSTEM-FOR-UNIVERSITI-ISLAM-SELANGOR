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
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-bus text-success me-2"></i><?= htmlspecialchars($v['plate_number']) ?>
            <span class="badge <?= vehicleStatusBadgeClass($shown) ?> fs-6 ms-2"><?= vehicleStatusLabel($shown) ?></span></h4>
        <div class="d-flex flex-wrap gap-1">
            <a class="btn btn-outline-secondary btn-sm" href="edit_vehicle.php?id=<?= $id ?>"><i class="fas fa-pen me-1"></i>Edit</a>
            <a class="btn btn-outline-warning btn-sm" href="vehicle_maintenance.php?id=<?= $id ?>"><i class="fas fa-screwdriver-wrench me-1"></i>Maintenance</a>
            <?php if ($v['status'] === 'inactive'): ?>
                <button class="btn btn-success btn-sm" data-fleet-url="../ajax/update_vehicle_status.php" data-fleet-id="<?= $id ?>" data-fleet-extra="status=available" data-fleet-confirm="Make this vehicle Available again?">Reactivate</button>
            <?php else: ?>
                <button class="btn btn-outline-danger btn-sm" data-fleet-url="../ajax/deactivate_vehicle.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make this vehicle Inactive?">Deactivate</button>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="vehicles.php">Back</a>
        </div>
    </div>
    <div id="fleetResult"></div>
    <div class="row g-3">
        <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body">
            <img src="<?= htmlspecialchars(vehiclePhotoUrl($v['photo'], $v['vehicle_type'])) ?>" alt="" class="rounded mb-3" style="max-height:140px;max-width:100%">
            <dl class="row mb-0 small">
                <dt class="col-5">Type</dt><dd class="col-7"><?= htmlspecialchars($v['vehicle_type']) ?></dd>
                <dt class="col-5">Model</dt><dd class="col-7"><?= htmlspecialchars(trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''))) ?></dd>
                <dt class="col-5">Passenger capacity</dt><dd class="col-7"><?= (int)$v['capacity'] ?></dd>
                <dt class="col-5">Licence required</dt><dd class="col-7"><span class="badge bg-light text-dark border"><?= htmlspecialchars($v['license_required']) ?></span></dd>
                <?php if ($v['year']): ?><dt class="col-5">Year</dt><dd class="col-7"><?= (int)$v['year'] ?></dd><?php endif; ?>
                <dt class="col-5">Fuel</dt><dd class="col-7"><?= htmlspecialchars($v['fuel_type']) ?></dd>
                <?php if ($v['notes']): ?><dt class="col-5">Notes</dt><dd class="col-7"><?= nl2br(htmlspecialchars($v['notes'])) ?></dd><?php endif; ?>
            </dl>
            <?php if ($v['status'] === 'maintenance' && $v['back_on']): ?>
                <div class="alert alert-warning mt-3 mb-0 small"><strong>Under maintenance</strong> at <?= htmlspecialchars((string)$v['workshop']) ?> since <?= date('d M Y', strtotime($v['maint_from'])) ?>.
                    Expected back: <strong><?= date('d M Y', strtotime($v['back_on'])) ?></strong>.</div>
            <?php endif; ?>
        </div></div></div>
        <div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body">
            <h6 class="fw-bold">Upcoming trips</h6>
            <?php if (!$trips): ?><p class="text-muted small mb-0">No upcoming trips. This vehicle can be deactivated.</p>
            <?php else: ?><?= fleetItemsList($trips) ?><?php endif; ?>
        </div></div></div>
        <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body">
            <h6 class="fw-bold">Usage updates by drivers <span class="fw-normal text-muted small">(mileage <?= (int)$v['mileage'] ?> km now)</span></h6>
            <?php if (!$usage): ?><p class="text-muted small mb-0">No driver has recorded the mileage yet.</p>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Date and time</th><th>Driver</th><th class="text-end">Mileage (km)</th><th>Fuel</th><th>Condition issue</th></tr></thead>
                <tbody>
                <?php foreach ($usage as $u): ?>
                    <tr><td class="text-nowrap"><?= date('d M Y H:i', strtotime($u['recorded_at'])) ?></td><td><?= htmlspecialchars($u['driver']) ?></td>
                        <td class="text-end"><?= (int)$u['mileage'] ?></td><td><?= htmlspecialchars(VEHICLE_FUEL_LEVELS[$u['fuel_level']] ?? $u['fuel_level']) ?></td>
                        <td><?= $u['condition_issue'] ? '<span class="text-danger">' . htmlspecialchars($u['condition_issue']) . '</span>' : '&mdash;' ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </div></div></div>
    </div>
<?php fleetActionScript(); adminPageEnd(); ?>
