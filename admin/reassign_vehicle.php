<?php
// ============================================================
// admin/reassign_vehicle.php  -  Assign another vehicle to each upcoming booking of a vehicle (SRS 14 Nov UC002.3 3.2, UC002.4 3.1)
// Needed before a vehicle can go Under Maintenance or be deactivated. Booking = request, internal task or welfare duty day.
// ============================================================
$current_page = 'vehicles.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/schedule_view.php';
require_once 'fleet_helpers.php';
requireAdmin();

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$vid = (int)($_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM vehicles WHERE vehicle_id = ?');
$stmt->bind_param('i', $vid);
$stmt->execute();
$veh = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$veh) { setFlash('danger', 'Vehicle not found.'); header('Location: vehicles.php'); exit(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = reassignBookingVehicle($conn, (string)($_POST['kind'] ?? ''), (int)($_POST['id'] ?? 0), $vid, (int)($_POST['new_vehicle'] ?? 0));
    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    header('Location: reassign_vehicle.php?vehicle_id=' . $vid);
    exit();
}

$items = vehicleUpcomingTrips($conn, $vid);
$types = $conn->query("SELECT vehicle_id, plate_number, vehicle_type, model, capacity FROM vehicles WHERE status IN ('available', 'in_use') ORDER BY plate_number")->fetch_all(MYSQLI_ASSOC);
adminPageStart('Assign Another Vehicle');
?>
    <a class="small text-decoration-none" href="view_vehicle.php?id=<?= $vid ?>"><i class="fas fa-arrow-left me-1"></i><?= $h($veh['plate_number']) ?></a>
    <h4 class="fw-bold my-2"><i class="fas fa-right-left text-success me-2"></i>Assign another vehicle &mdash; <?= $h($veh['plate_number']) ?></h4>
    <p class="text-muted small">Choose another vehicle for each upcoming booking below. A request needs a vehicle of the requested type; a welfare duty day needs a car or van. Then the vehicle can be set Under Maintenance or deactivated.</p>
    <?php if (!$items): ?><div class="alert alert-success">No upcoming booking is left. <a href="edit_vehicle.php?id=<?= $vid ?>">Update the vehicle</a> or deactivate it in the vehicle list.</div><?php endif; ?>
    <?php foreach ($items as $i): if (!isset($i['id'])) { continue; } ?>
    <form method="post" class="card border-0 shadow-sm mb-2"><div class="card-body py-2">
        <input type="hidden" name="vehicle_id" value="<?= $vid ?>"><input type="hidden" name="kind" value="<?= $h($i['kind']) ?>"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
        <div class="small mb-2"><?= $h($i['text']) ?></div>
        <div class="input-group input-group-sm">
            <select class="form-select" name="new_vehicle" required aria-label="New vehicle"><option value="">Choose another vehicle</option>
                <?php foreach ($types as $t): if ((int)$t['vehicle_id'] === $vid) { continue; } if ($i['kind'] === 'welfare' && !in_array($t['vehicle_type'], WELFARE_VEHICLE_TYPES, true)) { continue; } ?>
                <option value="<?= (int)$t['vehicle_id'] ?>"><?= $h($t['plate_number'] . ' - ' . $t['vehicle_type'] . ' ' . $t['model'] . ' (' . $t['capacity'] . ' seats)') ?></option><?php endforeach; ?></select>
            <button class="btn btn-warning">Assign</button>
        </div>
    </div></form>
    <?php endforeach; ?>
<?php adminPageEnd(); ?>
