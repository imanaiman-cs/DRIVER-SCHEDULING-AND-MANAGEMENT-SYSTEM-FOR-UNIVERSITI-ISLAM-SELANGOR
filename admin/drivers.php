<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/drivers.php  -  UC002 View Drivers (search, filter by status and licence class)
// Drivers are Active or Inactive. They are never deleted (UC004 Deactivate).
// ============================================================
$current_page = 'drivers.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
requireAdmin();

$q       = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$status  = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$licence = isset(LICENCE_CLASSES[$_GET['licence'] ?? '']) ? $_GET['licence'] : '';

$sql = 'SELECT d.*, u.username FROM drivers d LEFT JOIN users u ON u.driver_id = d.driver_id WHERE 1=1';
$types = ''; $args = [];
if ($q !== '') {
    $sql .= ' AND (d.name LIKE ? OR d.employee_id LIKE ? OR d.phone LIKE ? OR d.email LIKE ?)';
    $like = '%' . $q . '%'; $types .= 'ssss'; array_push($args, $like, $like, $like, $like);
}
if ($status !== '') { $sql .= ' AND d.status = ?'; $types .= 's'; $args[] = $status; }
if ($licence !== '') { $sql .= ' AND FIND_IN_SET(?, d.license_class)'; $types .= 's'; $args[] = $licence; }
$sql .= ' ORDER BY d.status, d.name';
$stmt = $conn->prepare($sql);
if ($args) { $stmt->bind_param($types, ...$args); }
$stmt->execute();
$drivers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$counts = driverMonthCounts($conn, date('Y-m'));

adminPageStart('Drivers');
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-id-card text-success me-2"></i>Drivers</h4>
        <a href="add_driver.php" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i>Add driver</a>
    </div>
    <p class="text-muted small">Driver accounts come from the UIS staff system. Here you register an existing driver account as a driver and record the licence classes. Drivers are never deleted: they are made Inactive.</p>

    <form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label small mb-1" for="q">Search</label>
            <input class="form-control form-control-sm" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name, staff no., phone or e-mail"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status"><option value="">All</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1" for="licence">Licence class</label>
            <select class="form-select form-select-sm" id="licence" name="licence"><option value="">All</option>
                <?php foreach (LICENCE_CLASSES as $k => $l): ?><option value="<?= $k ?>" <?= $licence === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-success btn-sm" type="submit"><i class="fas fa-search me-1"></i>Search</button>
            <a class="btn btn-outline-secondary btn-sm" href="drivers.php">Clear</a></div>
    </div></form>

    <div id="fleetResult"></div>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Driver</th><th>Staff no.</th><th>Phone</th><th>Licence</th><th class="text-end">Tasks this month</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($drivers as $d): $id = (int)$d['driver_id']; $c = $counts[$id] ?? ['tasks' => 0]; ?>
                    <tr>
                        <td class="fw-semibold"><a class="text-decoration-none" href="view_driver.php?id=<?= $id ?>"><?= htmlspecialchars($d['name']) ?></a></td>
                        <td class="text-nowrap"><?= htmlspecialchars($d['employee_id']) ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars((string)$d['phone']) ?></td>
                        <td><?= licenceBadges($d['license_class']) ?></td>
                        <td class="text-end"><?= (int)$c['tasks'] ?></td>
                        <td><span class="badge <?= $d['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= $d['status'] === 'active' ? 'Active' : 'Inactive' ?></span></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-success" href="view_driver.php?id=<?= $id ?>" title="View"><i class="fas fa-eye"></i></a>
                            <a class="btn btn-sm btn-outline-secondary" href="edit_driver.php?id=<?= $id ?>" title="Edit"><i class="fas fa-pen"></i></a>
                            <?php if ($d['status'] === 'active'): ?>
                                <button class="btn btn-sm btn-outline-danger" data-fleet-url="../ajax/deactivate_driver.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make <?= htmlspecialchars($d['name'], ENT_QUOTES) ?> Inactive?">Deactivate</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-outline-success" data-fleet-url="../ajax/deactivate_driver.php" data-fleet-id="<?= $id ?>" data-fleet-extra="reactivate=1" data-fleet-confirm="Make this driver Active again?">Reactivate</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-md-none list-group list-group-flush">
            <?php foreach ($drivers as $d): $id = (int)$d['driver_id']; $c = $counts[$id] ?? ['tasks' => 0]; ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div><a class="fw-bold text-decoration-none" href="view_driver.php?id=<?= $id ?>"><?= htmlspecialchars($d['name']) ?></a>
                            <div class="small text-muted"><?= htmlspecialchars($d['employee_id']) ?> &middot; <?= htmlspecialchars((string)$d['phone']) ?> &middot; <?= (int)$c['tasks'] ?> task(s) this month</div>
                            <div class="mt-1"><?= licenceBadges($d['license_class']) ?></div></div>
                        <span class="badge <?= $d['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= $d['status'] === 'active' ? 'Active' : 'Inactive' ?></span>
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-1">
                        <a class="btn btn-sm btn-outline-success" href="view_driver.php?id=<?= $id ?>">View</a>
                        <a class="btn btn-sm btn-outline-secondary" href="edit_driver.php?id=<?= $id ?>">Edit</a>
                        <?php if ($d['status'] === 'active'): ?>
                            <button class="btn btn-sm btn-outline-danger" data-fleet-url="../ajax/deactivate_driver.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make <?= htmlspecialchars($d['name'], ENT_QUOTES) ?> Inactive?">Deactivate</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-success" data-fleet-url="../ajax/deactivate_driver.php" data-fleet-id="<?= $id ?>" data-fleet-extra="reactivate=1" data-fleet-confirm="Make this driver Active again?">Reactivate</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$drivers): ?><div class="text-center text-muted py-4">No driver matches your search.</div><?php endif; ?>
    </div>
    <p class="text-muted small mt-2"><?= count($drivers) ?> driver(s)</p>
<?php fleetActionScript(); adminPageEnd(); ?>
