<?php
// ============================================================
// admin/edit_vehicle.php  -  Update Vehicle (SRS 10 Nov UC002 A2)
// Only the current fuel level, the current mileage and the status (Active / Under Maintenance) are updated here. The mileage cannot be lowered (A2.1);
// Under Maintenance is refused while the vehicle has upcoming bookings: they are listed (A2.2 / 3.2).
// ============================================================
$current_page = 'edit_vehicle.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM vehicles WHERE vehicle_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$cur = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cur) { setFlash('danger', 'Vehicle not found.'); header('Location: vehicles.php'); exit(); }

$usageError = null; $usageItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'usage') {
    $km = trim((string)($_POST['mileage'] ?? '')); $fuel = (string)($_POST['fuel_level'] ?? ''); $to = (string)($_POST['status'] ?? '');
    $cur0 = $conn->query('SELECT status, mileage FROM vehicles WHERE vehicle_id = ' . $id)->fetch_assoc();
    if (!preg_match('/^\d{1,7}$/', $km)) { $usageError = 'Fill in the current mileage.'; }
    elseif ((int)$km < (int)$cur0['mileage']) { $usageError = 'The mileage is not valid: it is lower than the current mileage (' . (int)$cur0['mileage'] . ' km).'; }       // A2.1
    elseif (!isset(VEHICLE_FUEL_LEVELS[$fuel])) { $usageError = 'Choose the current fuel level.'; }
    elseif (!in_array($to, ['available', 'maintenance'], true)) { $usageError = 'Choose Active or Under Maintenance.'; }
    elseif ($cur0['status'] === 'inactive') { $usageError = 'The vehicle is inactive and cannot be updated.'; }
    if (!$usageError) {
        $currentShown = $cur0['status'] === 'maintenance' ? 'maintenance' : 'available';
        if ($to !== $currentShown) {
            $res = setVehicleStatus($conn, $id, $to);
            if (!$res['ok']) { $usageError = $res['message']; $usageItems = $res['items']; }
            elseif ($res['items']) { setFlash('warning', $res['message'] . ' ' . implode(' | ', array_map(fn($i) => $i['text'], $res['items']))); }
        }
    }
    if (!$usageError) {
        $kmi = (int)$km;
        $stmt = $conn->prepare('UPDATE vehicles SET mileage = ?, fuel_level = ? WHERE vehicle_id = ?');
        $stmt->bind_param('isi', $kmi, $fuel, $id);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, 'vehicle_update', null, $cur['plate_number'] . ": mileage $kmi km, fuel " . VEHICLE_FUEL_LEVELS[$fuel]);
        if (empty($_SESSION['flash'])) { setFlash('success', 'Vehicle ' . $cur['plate_number'] . ' was updated.'); }
        header('Location: view_vehicle.php?id=' . $id);
        exit();
    }
}
$shown = $cur['status'] === 'maintenance' ? 'maintenance' : ($cur['status'] === 'inactive' ? 'inactive' : 'available');
adminPageStart('Edit Vehicle');
?>
    <h4 class="fw-bold mb-1"><i class="fas fa-pen text-success me-2"></i>Update Vehicle <?= htmlspecialchars($cur['plate_number']) ?></h4>
    <p class="text-muted small">Current status: <span class="badge <?= vehicleStatusBadgeClass($cur['status']) ?>"><?= vehicleStatusLabel($cur['status']) ?></span>
        &middot; Deactivate the vehicle in the vehicle list.</p>
    <?php if ($usageError): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($usageError) ?><?= fleetItemsList($usageItems) ?></div><?php endif; ?>
    <form method="post" class="card border-0 shadow-sm mb-3" novalidate><div class="card-body">
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="usage">
        <div class="row g-3">
            <div class="col-6 col-md-3"><label class="form-label" for="mileage">Current mileage (km) <span class="text-danger">*</span></label>
                <input type="number" min="<?= (int)$cur['mileage'] ?>" max="9999999" class="form-control" id="mileage" name="mileage" required value="<?= htmlspecialchars((string)($_POST['mileage'] ?? $cur['mileage'])) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label" for="fuel_level">Current fuel level <span class="text-danger">*</span></label>
                <select class="form-select" id="fuel_level" name="fuel_level" required><?php foreach (array_reverse(VEHICLE_FUEL_LEVELS, true) as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['fuel_level'] ?? $cur['fuel_level']) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-3"><label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status" <?= $cur['status'] === 'inactive' ? 'disabled' : '' ?>><option value="available" <?= $shown === 'available' ? 'selected' : '' ?>>Active</option><option value="maintenance" <?= $shown === 'maintenance' ? 'selected' : '' ?>>Under Maintenance</option></select></div>
        </div>
        <div class="mt-3 d-flex gap-2"><button class="btn btn-success" type="submit">Save</button><a class="btn btn-outline-secondary" href="view_vehicle.php?id=<?= $id ?>">Cancel</a></div>
    </div></form>

<?php adminPageEnd(); ?>
