<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicle_maintenance.php  –  maintenance periods of one vehicle (R4)
//
// A vehicle cannot be requested or scheduled on a day inside a maintenance period.
// Requests that already use the vehicle in that period are listed as a warning, so the
// transport unit can contact the requester or change the vehicle.
// ============================================================
$page_title   = 'Vehicle Maintenance';
$current_page = 'vehicle_maintenance.php';
require_once '../config/database.php';
requireAdmin();

$vehicle_id = (int)($_GET['id'] ?? $_POST['vehicle_id'] ?? 0);
$stmt = $conn->prepare('SELECT vehicle_id, plate_number, brand, model, vehicle_type FROM vehicles WHERE vehicle_id = ?');
$stmt->bind_param('i', $vehicle_id);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$vehicle) {
    setFlash('danger', 'Vehicle not found.');
    header('Location: vehicles.php');
    exit();
}

function validDateStr(string $d): bool
{
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $start = trim($_POST['start_date'] ?? '');
        $end   = trim($_POST['end_date'] ?? '');
        $shop  = mb_substr(trim($_POST['workshop'] ?? ''), 0, 120);
        $note  = mb_substr(trim($_POST['note'] ?? ''), 0, 255);
        if (!validDateStr($start) || !validDateStr($end) || $end < $start) {
            setFlash('danger', 'Please enter a valid start date and an end date that is not before it.');
        } else {
            $ins = $conn->prepare('INSERT INTO vehicle_maintenance (vehicle_id, start_date, end_date, workshop, note) VALUES (?, ?, ?, ?, ?)');
            $ins->bind_param('issss', $vehicle_id, $start, $end, $shop, $note);
            $ins->execute();
            $ins->close();
            auditLog($conn, 'vehicle_maintenance', null, "{$vehicle['plate_number']}: maintenance $start to $end");

            // Requests / trips that already use this vehicle in the period
            $w = $conn->prepare("SELECT DISTINCT vr.request_id, vr.destination, vr.trip_date, vr.status
                                   FROM vehicle_day_holds h JOIN vehicle_requests vr ON vr.request_id = h.request_id
                                  WHERE h.vehicle_id = ? AND h.hold_date BETWEEN ? AND ? ORDER BY vr.trip_date");
            $w->bind_param('iss', $vehicle_id, $start, $end);
            $w->execute();
            $hits = $w->get_result()->fetch_all(MYSQLI_ASSOC);
            $w->close();
            if ($hits) {
                $list = implode('; ', array_map(fn($h) => '#' . $h['request_id'] . ' (' . $h['destination'] . ', ' . date('d M Y', strtotime($h['trip_date'])) . ', ' . $h['status'] . ')', $hits));
                setFlash('warning', "Maintenance saved, but these requests already use this vehicle in that period: $list. Please contact the requesters or change the vehicle.");
            } else {
                setFlash('success', 'Maintenance period saved.');
            }
        }
    } elseif ($act === 'delete') {
        $mid = (int)($_POST['maintenance_id'] ?? 0);
        $del = $conn->prepare('DELETE FROM vehicle_maintenance WHERE maintenance_id = ? AND vehicle_id = ?');
        $del->bind_param('ii', $mid, $vehicle_id);
        $del->execute();
        $del->close();
        setFlash('success', 'Maintenance period removed.');
    }
    header('Location: vehicle_maintenance.php?id=' . $vehicle_id);
    exit();
}

$rows = $conn->query("SELECT * FROM vehicle_maintenance WHERE vehicle_id = {$vehicle_id} ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Maintenance | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require_once '../includes/sidebar.php'; ?>
<main class="main-content p-4" id="mainContent">
    <?php showFlash(); ?>
    <a class="small text-decoration-none" href="vehicles.php"><i class="fas fa-arrow-left me-1"></i>All vehicles</a>
    <h4 class="fw-bold my-2"><i class="fas fa-screwdriver-wrench text-success me-2"></i>Maintenance &mdash; <?= htmlspecialchars($vehicle['plate_number']) ?>
        <small class="text-muted fs-6"><?= htmlspecialchars(trim($vehicle['brand'] . ' ' . $vehicle['model'])) ?></small></h4>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold">Add a maintenance period</h6>
                <form method="post" class="row g-2">
                    <input type="hidden" name="action" value="add"><input type="hidden" name="vehicle_id" value="<?= $vehicle_id ?>">
                    <div class="col-6"><label class="form-label small" for="start_date">From</label><input type="date" class="form-control form-control-sm" id="start_date" name="start_date" required></div>
                    <div class="col-6"><label class="form-label small" for="end_date">To</label><input type="date" class="form-control form-control-sm" id="end_date" name="end_date" required></div>
                    <div class="col-12"><label class="form-label small" for="workshop">Workshop</label><input class="form-control form-control-sm" id="workshop" name="workshop" maxlength="120"></div>
                    <div class="col-12"><label class="form-label small" for="note">Note</label><input class="form-control form-control-sm" id="note" name="note" maxlength="255"></div>
                    <div class="col-12"><button class="btn btn-success btn-sm" type="submit">Add</button></div>
                </form>
                <div class="form-text mt-2">The vehicle cannot be requested or scheduled on these days.</div>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm"><div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>From</th><th>To</th><th>Workshop</th><th>Note</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $m): ?>
                        <tr>
                            <td class="text-nowrap"><?= date('d M Y', strtotime($m['start_date'])) ?></td>
                            <td class="text-nowrap"><?= date('d M Y', strtotime($m['end_date'])) ?></td>
                            <td><?= htmlspecialchars($m['workshop'] ?? '') ?></td>
                            <td class="small"><?= htmlspecialchars($m['note'] ?? '') ?></td>
                            <td class="text-end">
                                <form method="post" class="d-inline"><input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="vehicle_id" value="<?= $vehicle_id ?>"><input type="hidden" name="maintenance_id" value="<?= (int)$m['maintenance_id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" data-confirm="Remove this maintenance period?" data-confirm-title="Remove?" data-confirm-button="Remove" data-confirm-tone="danger">Remove</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No maintenance recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
</main>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
