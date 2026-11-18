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
    <div class="flex flex-wrap justify-between items-center mb-2 gap-2">
        <h1 class="text-2xl font-extrabold tracking-tight mb-0"><?= plate($v['plate_number']) ?>
            <span class="badge <?= vehicleStatusBadgeClass($shown) ?> text-base ms-2"><?= vehicleStatusLabel($shown) ?></span></h1>
        <a class="btn btn-outline btn-sm" href="view_vehicle.php?id=<?= $id ?>">Back to vehicle</a>
    </div>
    <?php if ($error): ?><div class="alert alert-soft alert-error" role="alert"><?= htmlspecialchars($error) ?><?= fleetItemsList($blocked) ?></div><?php endif; ?>
    <div class="grid-12 grid grid-cols-12 gap-4">
        <div class="col-span-12 lg:col-span-5"><div class="card border border-base-300 bg-base-100"><div class="card-body">
            <h2 class="text-base font-bold">Change status</h2>
            <p class="text-sm text-base-content/60">In Use cannot be chosen: the system shows it while a Confirmed trip is running.</p>
            <form method="post" id="statusForm" class="grid-12 grid grid-cols-12 gap-2">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="col-span-12"><label class="mb-1 block text-sm font-medium" for="status">New status</label>
                    <select class="select w-full" id="status" name="status">
                        <?php foreach (VEHICLE_ADMIN_STATUSES as $s): ?><option value="<?= $s ?>" <?= $v['status'] === $s ? 'selected' : '' ?>><?= vehicleStatusLabel($s) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-span-12 mt-2" id="maintFields">
                    <label class="mb-1 block text-sm font-medium" for="workshop">Workshop <span class="text-error">*</span></label>
                    <input class="input w-full mb-2" id="workshop" name="workshop" maxlength="120" value="<?= htmlspecialchars($old['workshop']) ?>">
                    <label class="mb-1 block text-sm font-medium" for="return_date">Expected return date <span class="text-error">*</span></label>
                    <input type="date" class="input w-full" id="return_date" name="return_date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($old['return_date']) ?>">
                    <div class="mt-1 block text-xs text-base-content/60">Today or later. Trips in this period are listed as a warning after saving.</div>
                </div>
                <div class="col-span-12"><button class="btn btn-success" type="submit">Save status</button></div>
            </form>
        </div></div></div>
        <div class="col-span-12 lg:col-span-7"><div class="card border border-base-300 bg-base-100"><div class="card-body">
            <h2 class="text-base font-bold">Maintenance history</h2>
            <div class="overflow-x-auto"><table class="table align-middle mb-0">
                <thead class=""><tr><th>Workshop</th><th>Started</th><th>Expected return</th><th>Closed</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr><td><?= htmlspecialchars((string)($h['workshop'] ?? '-')) ?></td>
                        <td class="whitespace-nowrap"><?= date('d M Y', strtotime($h['start_date'])) ?></td>
                        <td class="whitespace-nowrap"><?= date('d M Y', strtotime($h['end_date'])) ?></td>
                        <td class="whitespace-nowrap"><?= $h['closed_at'] ? date('d M Y H:i', strtotime($h['closed_at'])) : '<span class="badge badge-warning">Open</span>' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="4" class="text-center text-base-content/60 py-4">No maintenance recorded.</td></tr><?php endif; ?>
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
