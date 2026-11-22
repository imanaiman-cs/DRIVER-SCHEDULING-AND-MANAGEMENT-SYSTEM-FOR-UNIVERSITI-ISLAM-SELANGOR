<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/vehicles.php  -  UC006 View Vehicles (search, filter by type and status)
// Layout: status tiles (also filters) and a gallery of vehicle cards: photo or type illustration, plate, model,
// seats, licence, status and one line on what the vehicle is doing (next service, current trip, workshop).
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

// Counts for the status tiles (whole fleet, before the filters)
$counts = ['all' => 0, 'available' => 0, 'in_use' => 0, 'maintenance' => 0, 'inactive' => 0];
foreach ($conn->query('SELECT vehicle_id, status FROM vehicles')->fetch_all(MYSQLI_ASSOC) as $r) {
    $counts['all']++; $counts[vehicleDisplayStatus($conn, (int)$r['vehicle_id'], $r['status'])]++;
}

// The trip a vehicle is on right now (for "In Use" cards)
$onTrip = [];
foreach ($conn->query("SELECT s.vehicle_id, vr.destination, s.end_dt FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id
                        WHERE s.status <> 'cancelled' AND vr.status = 'processed' AND s.start_dt <= NOW() AND s.end_dt > NOW()")->fetch_all(MYSQLI_ASSOC) as $r) {
    $onTrip[(int)$r['vehicle_id']] = $r;
}
$filterLink = fn(string $st) => 'vehicles.php?' . http_build_query(array_filter(['q' => $q, 'type' => $type, 'status' => $st]));

adminPageStart('Vehicles');
$tiles = ['' => 'All', 'available' => 'Available', 'in_use' => 'In Use', 'maintenance' => 'Under Maintenance', 'inactive' => 'Inactive'];
$dot = ['available' => 'vh-av', 'in_use' => 'vh-use', 'maintenance' => 'vh-mt', 'inactive' => 'vh-in'];
?>
    <div class="mb-1 flex flex-wrap items-end justify-between gap-3">
        <h1>Vehicles</h1>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            <form method="get" class="flex grow gap-2" role="search">
                <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"><?php endif; ?>
                <label class="input grow sm:w-64">
                    <i class="fas fa-magnifying-glass text-base-content/60" aria-hidden="true"></i>
                    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="grow" placeholder="Plate, model or brand" aria-label="Search vehicles">
                </label>
                <select class="select w-auto min-w-36" name="type" aria-label="Vehicle type" onchange="this.form.submit()">
                    <option value="">All types</option>
                    <?php foreach (VEHICLE_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= htmlspecialchars($l) ?></option><?php endforeach; ?>
                </select>
            </form>
            <a href="add_vehicle.php" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i>Add vehicle</a>
        </div>
    </div>
    <p class="mb-5 text-sm text-base-content/70">Vehicles are never deleted. A vehicle that is not used any more is made Inactive. "In Use" is shown by the system while a Confirmed trip is running.</p>

    <nav class="mb-5 grid grid-cols-2 gap-3 sm:flex sm:flex-wrap" aria-label="Filter by status">
        <?php foreach ($tiles as $k => $label): $on = $status === $k; ?>
            <a class="vh-tile <?= $on ? 'is-on' : '' ?>" href="<?= htmlspecialchars($filterLink($k)) ?>" <?= $on ? 'aria-current="true"' : '' ?>>
                <span class="<?= $k ? 'vh-st ' . $dot[$k] : 'text-base-content/70' ?>"><?= htmlspecialchars($label) ?></span>
                <b class="tabular-nums"><?= $counts[$k ?: 'all'] ?></b>
            </a>
        <?php endforeach; ?>
    </nav>

    <div id="fleetResult"></div>
    <?php if (!$vehicles): ?>
        <div class="card bg-base-100 py-10 text-center text-base-content/70">No vehicle matches your search.</div>
    <?php endif; ?>
    <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Vehicles">
        <?php foreach ($vehicles as $v): $id = (int)$v['vehicle_id']; $model = trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''));
            if ($v['shown'] === 'in_use' && isset($onTrip[$id]))       { $line = ['route', 'On a trip to ' . $onTrip[$id]['destination'] . ' until ' . date('H:i', strtotime($onTrip[$id]['end_dt']))]; }
            elseif ($v['status'] === 'maintenance')                    { $line = ['screwdriver-wrench', 'At ' . (trim((string)$v['workshop']) ?: 'the workshop') . ($v['back_on'] ? ', back on ' . date('d M Y', strtotime($v['back_on'])) : '')]; }
            elseif ($v['status'] === 'inactive')                       { $line = ['ban', 'Not used any more; can be reactivated']; }
            elseif ($v['next_maintenance'])                            { $line = ['calendar-check', 'Next service ' . date('d M Y', strtotime($v['next_maintenance']))]; }
            else                                                       { $line = ['calendar-check', 'No service date set']; } ?>
        <li class="vh-card <?= $v['status'] === 'inactive' ? 'is-inactive' : '' ?>">
            <a class="vh-img" href="view_vehicle.php?id=<?= $id ?>" tabindex="-1" aria-hidden="true"><img src="<?= htmlspecialchars(vehiclePhotoUrl($v['photo'], $v['vehicle_type'])) ?>" alt="" loading="lazy"></a>
            <div class="flex grow flex-col p-4">
                <div class="flex items-center justify-between gap-2">
                    <a href="view_vehicle.php?id=<?= $id ?>" aria-label="View <?= htmlspecialchars($v['plate_number']) ?>"><?= plate($v['plate_number']) ?></a>
                    <span class="vh-st <?= $dot[$v['shown']] ?? 'vh-in' ?>"><?= vehicleStatusLabel($v['shown']) ?></span>
                </div>
                <div class="mt-2 font-semibold"><?= htmlspecialchars($model ?: $v['vehicle_type']) ?></div>
                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-base-content/70">
                    <span><i class="fas fa-shapes me-1" aria-hidden="true"></i><?= htmlspecialchars($v['vehicle_type']) ?></span>
                    <span><i class="fas fa-users me-1" aria-hidden="true"></i><?= (int)$v['capacity'] ?> seats</span>
                    <span><i class="fas fa-id-card me-1" aria-hidden="true"></i>Licence <?= htmlspecialchars($v['license_required']) ?></span>
                </div>
                <div class="mt-3 grow text-sm text-base-content/70"><i class="fas fa-<?= $line[0] ?> me-1.5" aria-hidden="true"></i><?= htmlspecialchars($line[1]) ?></div>
                <div class="mt-4 flex gap-2">
                    <a class="btn btn-sm grow" href="view_vehicle.php?id=<?= $id ?>">View</a>
                    <details class="dropdown dropdown-end dropdown-top">
                        <summary class="btn btn-sm btn-square" aria-label="More actions for <?= htmlspecialchars($v['plate_number']) ?>"><i class="fas fa-ellipsis" aria-hidden="true"></i></summary>
                        <ul class="menu dropdown-content z-10 mb-1 w-52 rounded-box border border-base-300 bg-base-100 p-1 shadow-lg">
                            <li><a href="edit_vehicle.php?id=<?= $id ?>"><i class="fas fa-pen" aria-hidden="true"></i>Edit</a></li>
                            <li><a href="vehicle_maintenance.php?id=<?= $id ?>"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i>Maintenance</a></li>
                            <?php if ($v['status'] === 'inactive'): ?>
                                <li><button type="button" data-fleet-url="../ajax/update_vehicle_status.php" data-fleet-id="<?= $id ?>" data-fleet-extra="status=available" data-fleet-confirm="Make this vehicle Available again?"><i class="fas fa-rotate-left" aria-hidden="true"></i>Reactivate</button></li>
                            <?php else: ?>
                                <li><button type="button" class="text-error" data-fleet-url="../ajax/deactivate_vehicle.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make <?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?> Inactive?"><i class="fas fa-ban" aria-hidden="true"></i>Deactivate</button></li>
                            <?php endif; ?>
                        </ul>
                    </details>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <p class="mt-3 text-sm text-base-content/70"><?= count($vehicles) ?> vehicle(s)</p>
<script>
// Close an open "more actions" menu when another opens or on a click elsewhere
document.addEventListener('click', function (e) {
    document.querySelectorAll('details.dropdown[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } });
});
</script>
<?php fleetActionScript(); adminPageEnd(); ?>
