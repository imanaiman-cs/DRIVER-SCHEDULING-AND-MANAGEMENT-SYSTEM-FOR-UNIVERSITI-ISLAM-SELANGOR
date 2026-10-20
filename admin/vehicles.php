<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicles.php  -  UC006 View Vehicles (search, filter by type and status)
// Vehicles are never deleted: they are made Inactive (UC009) and can be reactivated.
// ============================================================
$current_page = 'vehicles.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
requireAdmin();

$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$type   = isset(VEHICLE_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$status = in_array($_GET['status'] ?? '', ['available', 'in_use', 'maintenance', 'inactive'], true) ? $_GET['status'] : '';

$sql = "SELECT v.*, m.workshop, m.end_date AS back_on
          FROM vehicles v
          LEFT JOIN vehicle_maintenance m ON m.vehicle_id = v.vehicle_id AND m.closed_at IS NULL AND v.status = 'maintenance'
         WHERE 1=1";
$types = ''; $args = [];
if ($q !== '') {
    $sql .= ' AND (v.plate_number LIKE ? OR v.model LIKE ? OR v.brand LIKE ?)';
    $like = '%' . $q . '%'; $types .= 'sss'; array_push($args, $like, $like, $like);
}
if ($type !== '') { $sql .= ' AND v.vehicle_type = ?'; $types .= 's'; $args[] = $type; }
if ($status === 'in_use') { $sql .= " AND v.status = 'available'"; }
elseif ($status !== '') { $sql .= ' AND v.status = ?'; $types .= 's'; $args[] = $status; }
$sql .= ' ORDER BY v.plate_number';
$stmt = $conn->prepare($sql);
if ($args) { $stmt->bind_param($types, ...$args); }
$stmt->execute();
$vehicles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
foreach ($vehicles as &$v) { $v['shown'] = vehicleDisplayStatus($conn, (int)$v['vehicle_id'], $v['status']); }
unset($v);
if ($status === 'in_use') { $vehicles = array_values(array_filter($vehicles, fn($v) => $v['shown'] === 'in_use')); }

adminPageStart('Vehicles');
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-bus text-success me-2"></i>Vehicles</h4>
        <a href="add_vehicle.php" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i>Add vehicle</a>
    </div>
    <p class="text-muted small">Vehicles are never deleted. A vehicle that is not used any more is made Inactive. "In Use" is shown by the system while a Confirmed trip is running.</p>

    <form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control form-control-sm" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Registration number, model or brand"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1" for="type">Type</label>
            <select class="form-select form-select-sm" id="type" name="type"><option value="">All</option>
                <?php foreach (VEHICLE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
                <?php foreach (['available', 'in_use', 'maintenance', 'inactive'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= vehicleStatusLabel($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-success btn-sm" type="submit"><i class="fas fa-search me-1"></i>Search</button>
            <a class="btn btn-outline-secondary btn-sm" href="vehicles.php">Clear</a></div>
    </div></form>

    <div id="fleetResult"></div>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Registration no.</th><th>Type</th><th>Model</th><th class="text-end">Seats</th><th>Licence</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($vehicles as $v): $id = (int)$v['vehicle_id']; ?>
                    <tr>
                        <td class="fw-semibold text-nowrap"><a class="text-decoration-none" href="view_vehicle.php?id=<?= $id ?>"><?= htmlspecialchars($v['plate_number']) ?></a></td>
                        <td><?= htmlspecialchars($v['vehicle_type']) ?></td>
                        <td><?= htmlspecialchars(trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''))) ?></td>
                        <td class="text-end"><?= (int)$v['capacity'] ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($v['license_required']) ?></span></td>
                        <td><span class="badge <?= vehicleStatusBadgeClass($v['shown']) ?>"><?= vehicleStatusLabel($v['shown']) ?></span>
                            <?php if ($v['status'] === 'maintenance' && $v['back_on']): ?><div class="small text-muted"><?= htmlspecialchars((string)$v['workshop']) ?>, back <?= date('d M Y', strtotime($v['back_on'])) ?></div><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-success" href="view_vehicle.php?id=<?= $id ?>" title="View"><i class="fas fa-eye"></i></a>
                            <a class="btn btn-sm btn-outline-secondary" href="edit_vehicle.php?id=<?= $id ?>" title="Edit"><i class="fas fa-pen"></i></a>
                            <a class="btn btn-sm btn-outline-warning" href="vehicle_maintenance.php?id=<?= $id ?>" title="Maintenance"><i class="fas fa-screwdriver-wrench"></i></a>
                            <?php if ($v['status'] === 'inactive'): ?>
                                <button class="btn btn-sm btn-outline-success" data-fleet-url="../ajax/update_vehicle_status.php" data-fleet-id="<?= $id ?>" data-fleet-extra="status=available" data-fleet-confirm="Make this vehicle Available again?">Reactivate</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-danger" data-fleet-url="../ajax/deactivate_vehicle.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make <?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?> Inactive?">Deactivate</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-md-none list-group list-group-flush">
            <?php foreach ($vehicles as $v): $id = (int)$v['vehicle_id']; ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div><a class="fw-bold text-decoration-none" href="view_vehicle.php?id=<?= $id ?>"><?= htmlspecialchars($v['plate_number']) ?></a>
                            <div class="small text-muted"><?= htmlspecialchars($v['vehicle_type']) ?> &middot; <?= htmlspecialchars(trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''))) ?> &middot; <?= (int)$v['capacity'] ?> seats &middot; licence <?= htmlspecialchars($v['license_required']) ?></div></div>
                        <span class="badge <?= vehicleStatusBadgeClass($v['shown']) ?>"><?= vehicleStatusLabel($v['shown']) ?></span>
                    </div>
                    <?php if ($v['status'] === 'maintenance' && $v['back_on']): ?><div class="small text-muted mt-1"><?= htmlspecialchars((string)$v['workshop']) ?>, back <?= date('d M Y', strtotime($v['back_on'])) ?></div><?php endif; ?>
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        <a class="btn btn-sm btn-outline-success" href="view_vehicle.php?id=<?= $id ?>">View</a>
                        <a class="btn btn-sm btn-outline-secondary" href="edit_vehicle.php?id=<?= $id ?>">Edit</a>
                        <a class="btn btn-sm btn-outline-warning" href="vehicle_maintenance.php?id=<?= $id ?>">Maintenance</a>
                        <?php if ($v['status'] === 'inactive'): ?>
                            <button class="btn btn-sm btn-outline-success" data-fleet-url="../ajax/update_vehicle_status.php" data-fleet-id="<?= $id ?>" data-fleet-extra="status=available" data-fleet-confirm="Make this vehicle Available again?">Reactivate</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-danger" data-fleet-url="../ajax/deactivate_vehicle.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make <?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?> Inactive?">Deactivate</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$vehicles): ?><div class="text-center text-muted py-4">No vehicle matches your search.</div><?php endif; ?>
    </div>
    <p class="text-muted small mt-2"><?= count($vehicles) ?> vehicle(s)</p>
<?php fleetActionScript(); adminPageEnd(); ?>
