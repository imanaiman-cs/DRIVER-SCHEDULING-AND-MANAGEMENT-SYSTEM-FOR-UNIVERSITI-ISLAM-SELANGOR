<?php
// ============================================================
// admin/view_driver.php  -  UC002 driver details: licence, this month's workload, upcoming tasks
// ============================================================
$current_page = 'view_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT d.*, u.username FROM drivers d LEFT JOIN users u ON u.driver_id = d.driver_id WHERE d.driver_id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$d) { setFlash('danger', 'Driver not found.'); header('Location: drivers.php'); exit(); }

$c = driverMonthCounts($conn, date('Y-m'))[$id] ?? ['tasks' => 0, 'outside' => 0, 'weekend' => 0, 'welfare' => 0];
$upcoming = driverUpcomingTasks($conn, $id);
$expired = $d['license_expiry'] && $d['license_expiry'] < date('Y-m-d');
adminPageStart($d['name']);
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-id-card text-success me-2"></i><?= htmlspecialchars($d['name']) ?>
            <span class="badge <?= $d['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> fs-6 ms-2"><?= $d['status'] === 'active' ? 'Active' : 'Inactive' ?></span></h4>
        <div class="d-flex flex-wrap gap-1">
            <a class="btn btn-outline-secondary btn-sm" href="edit_driver.php?id=<?= $id ?>"><i class="fas fa-pen me-1"></i>Edit</a>
            <?php if ($d['status'] === 'active'): ?>
                <button class="btn btn-outline-danger btn-sm" data-fleet-url="../ajax/deactivate_driver.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make this driver Inactive?">Deactivate</button>
            <?php else: ?>
                <button class="btn btn-success btn-sm" data-fleet-url="../ajax/deactivate_driver.php" data-fleet-id="<?= $id ?>" data-fleet-extra="reactivate=1" data-fleet-confirm="Make this driver Active again?">Reactivate</button>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="drivers.php">Back</a>
        </div>
    </div>
    <div id="fleetResult"></div>
    <div class="row g-3">
        <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body">
            <img src="<?= htmlspecialchars(driverPhotoUrl($d['photo'])) ?>" alt="" class="rounded-circle mb-3" style="width:90px;height:90px;object-fit:cover">
            <dl class="row mb-0 small">
                <dt class="col-5">Staff no.</dt><dd class="col-7"><?= htmlspecialchars($d['employee_id']) ?></dd>
                <dt class="col-5">E-mail</dt><dd class="col-7 text-break"><?= htmlspecialchars((string)$d['email']) ?></dd>
                <dt class="col-5">Phone</dt><dd class="col-7"><?= htmlspecialchars((string)$d['phone']) ?></dd>
                <dt class="col-5">Licence classes</dt><dd class="col-7"><?= licenceBadges($d['license_class']) ?></dd>
                <dt class="col-5">Licence no.</dt><dd class="col-7"><?= htmlspecialchars((string)($d['license_number'] ?? '-')) ?></dd>
                <dt class="col-5">Licence expiry</dt><dd class="col-7"><?= $d['license_expiry'] ? date('d M Y', strtotime($d['license_expiry'])) : '-' ?>
                    <?= $expired ? '<span class="badge bg-danger ms-1">Expired</span>' : '' ?></dd>
                <dt class="col-5">Date joined</dt><dd class="col-7"><?= !empty($d['date_joined']) ? date('d M Y', strtotime($d['date_joined'])) . ' (' . driverExperienceYears($d['date_joined']) . ' years)' : '-' ?></dd>
                <dt class="col-5">Address</dt><dd class="col-7"><?= htmlspecialchars((string)($d['address'] ?? '-')) ?></dd>
            </dl>
        </div></div></div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <h6 class="fw-bold">Workload in <?= date('F Y') ?></h6>
                <div class="row text-center g-2">
                    <div class="col-4"><div class="fs-4 fw-bold"><?= (int)$c['tasks'] ?></div><div class="small text-muted">Tasks</div></div>
                    <div class="col-4"><div class="fs-4 fw-bold"><?= (int)$c['weekend'] ?></div><div class="small text-muted">Weekend tasks</div></div>
                    <div class="col-4"><div class="fs-4 fw-bold"><?= (int)$c['welfare'] ?></div><div class="small text-muted">Welfare duty days</div></div>
                </div>
            </div></div>
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Upcoming tasks</h6>
                <?php if (!$upcoming): ?><p class="text-muted small mb-0">No upcoming tasks.</p>
                <?php else: ?><?= fleetItemsList($upcoming, true) ?><?php endif; ?>
            </div></div>
        </div>
    </div>
<?php fleetActionScript(); adminPageEnd(); ?>
