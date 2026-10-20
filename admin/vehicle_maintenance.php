<?php
// ============================================================
// admin/vehicle_maintenance.php  -  UC008 Update Vehicle Status for ONE vehicle
//   * Under Maintenance: workshop + expected return date are required; a warning lists upcoming trips in that period
//   * back to Available: the open maintenance record is closed
//   * the maintenance history of the vehicle
// ============================================================
$current_page = 'vehicle_maintenance.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM vehicles WHERE vehicle_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$v) { setFlash('danger', 'Vehicle not found.'); header('Location: vehicles.php'); exit(); }

$old = ['workshop' => '', 'return_date' => date('Y-m-d', strtotime('+7 days'))];
$error = null; $blocked = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = (string)($_POST['status'] ?? '');
    $old = ['workshop' => mb_substr(trim((string)($_POST['workshop'] ?? '')), 0, 120), 'return_date' => trim((string)($_POST['return_date'] ?? ''))];
    $res = setVehicleStatus($conn, $id, $to, $old['workshop'], $old['return_date']);
    if ($res['ok']) {
        $msg = $res['message'];
        if ($res['items']) { $msg .= ' ' . implode(' | ', array_map(fn($i) => $i['text'], $res['items'])); }
        setFlash($res['items'] ? 'warning' : 'success', $msg);
        header('Location: vehicle_maintenance.php?id=' . $id);
        exit();
    }
    $error = $res['message'];
    $blocked = $res['items'];
}

$stmt = $conn->prepare('SELECT * FROM vehicle_maintenance WHERE vehicle_id = ? ORDER BY start_date DESC, maintenance_id DESC');
$stmt->bind_param('i', $id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$shown = vehicleDisplayStatus($conn, $id, $v['status']);
adminPageStart('Vehicle Maintenance');
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-screwdriver-wrench text-success me-2"></i><?= htmlspecialchars($v['plate_number']) ?>
            <span class="badge <?= vehicleStatusBadgeClass($shown) ?> fs-6 ms-2"><?= vehicleStatusLabel($shown) ?></span></h4>
        <a class="btn btn-outline-secondary btn-sm" href="view_vehicle.php?id=<?= $id ?>">Back to vehicle</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?><?= fleetItemsList($blocked) ?></div><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body">
            <h6 class="fw-bold">Change status</h6>
            <p class="small text-muted">In Use cannot be chosen: the system shows it while a Confirmed trip is running.</p>
            <form method="post" id="statusForm" class="row g-2">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="col-12"><label class="form-label small" for="status">New status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (VEHICLE_ADMIN_STATUSES as $s): ?><option value="<?= $s ?>" <?= $v['status'] === $s ? 'selected' : '' ?>><?= vehicleStatusLabel($s) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-12 mt-2" id="maintFields">
                    <label class="form-label small" for="workshop">Workshop <span class="text-danger">*</span></label>
                    <input class="form-control mb-2" id="workshop" name="workshop" maxlength="120" value="<?= htmlspecialchars($old['workshop']) ?>">
                    <label class="form-label small" for="return_date">Expected return date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="return_date" name="return_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($old['return_date']) ?>">
                    <div class="form-text">Today or later. Trips in this period are listed as a warning after saving.</div>
                </div>
                <div class="col-12"><button class="btn btn-success" type="submit">Save status</button></div>
            </form>
        </div></div></div>
        <div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body">
            <h6 class="fw-bold">Maintenance history</h6>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Workshop</th><th>Started</th><th>Expected return</th><th>Closed</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr><td><?= htmlspecialchars((string)($h['workshop'] ?? '-')) ?></td>
                        <td class="text-nowrap"><?= date('d M Y', strtotime($h['start_date'])) ?></td>
                        <td class="text-nowrap"><?= date('d M Y', strtotime($h['end_date'])) ?></td>
                        <td class="text-nowrap"><?= $h['closed_at'] ? date('d M Y H:i', strtotime($h['closed_at'])) : '<span class="badge bg-warning text-dark">Open</span>' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="4" class="text-center text-muted py-3">No maintenance recorded.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div></div></div>
    </div>
    <script>
    (function () {
        var s = document.getElementById('status'), f = document.getElementById('maintFields');
        function sync() { var m = s.value === 'maintenance'; f.style.display = m ? '' : 'none'; document.getElementById('workshop').required = m; document.getElementById('return_date').required = m; }
        s.addEventListener('change', sync); sync();
    })();
    </script>
<?php adminPageEnd(); ?>
