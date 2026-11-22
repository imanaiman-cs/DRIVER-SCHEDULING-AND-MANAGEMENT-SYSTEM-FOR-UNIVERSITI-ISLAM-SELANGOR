<?php
// ============================================================
// admin/view_vehicle.php  -  UC006 vehicle details
// Layout: a header card (photo, plate, status, model, actions, three key numbers), a row of spec tiles,
// the fuel gauge (last level a driver recorded after a task), upcoming trips and the maintenance history.
// ============================================================
$current_page = 'view_vehicle.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once 'fleet_helpers.php';
require_once '../includes/schedule_view.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT v.*, m.workshop, m.end_date AS back_on, m.start_date AS maint_from, d.name AS fuel_by
                          FROM vehicles v
                          LEFT JOIN vehicle_maintenance m ON m.vehicle_id = v.vehicle_id AND m.closed_at IS NULL AND v.status = 'maintenance'
                          LEFT JOIN drivers d ON d.driver_id = v.fuel_updated_by
                         WHERE v.vehicle_id = ? ORDER BY m.maintenance_id DESC LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$v) { setFlash('danger', 'Vehicle not found.'); header('Location: vehicles.php'); exit(); }

$shown = vehicleDisplayStatus($conn, $id, $v['status']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Upcoming: requests that hold this vehicle, confirmed trip legs and internal tasks
$upcoming = [];
$stmt = $conn->prepare("SELECT vr.request_id, vr.depart_at, vr.return_at, vr.destination, vr.status, u.full_name
                          FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id
                         WHERE vr.vehicle_id = ? AND vr.status IN ('pending','approved','assigned','processed') AND vr.return_at > NOW() ORDER BY vr.depart_at LIMIT 20");
$stmt->bind_param('i', $id);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $upcoming[] = ['start' => $r['depart_at'], 'end' => $r['return_at'], 'title' => $r['destination'], 'sub' => 'Request #' . $r['request_id'] . ' · ' . $r['full_name'],
                   'status' => $r['status'], 'url' => 'vehicle_requests.php?tab=all&open=' . (int)$r['request_id']];
}
$stmt->close();
$stmt = $conn->prepare("SELECT t.task_type, t.start_dt, t.end_dt, t.location, d.name FROM internal_tasks t JOIN drivers d ON d.driver_id = t.driver_id
                         WHERE t.vehicle_id = ? AND t.status = 'active' AND t.end_dt > NOW() ORDER BY t.start_dt LIMIT 20");
$stmt->bind_param('i', $id);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $upcoming[] = ['start' => $r['start_dt'], 'end' => $r['end_dt'], 'title' => 'Internal task: ' . internalTaskTypeLabel($r['task_type']),
                   'sub' => trim(($r['location'] ? $r['location'] . ' · ' : '') . 'Driver: ' . $r['name']), 'status' => 'internal', 'url' => 'internal_tasks.php'];
}
$stmt->close();
usort($upcoming, fn($a, $b) => strcmp($a['start'], $b['start']));

// Trips this month (trip legs and internal tasks that start this month)
$stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM schedules WHERE vehicle_id = ? AND status <> 'cancelled' AND DATE_FORMAT(start_dt, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m'))
                             + (SELECT COUNT(*) FROM internal_tasks WHERE vehicle_id = ? AND status = 'active' AND DATE_FORMAT(start_dt, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m'))");
$stmt->bind_param('ii', $id, $id);
$stmt->execute();
$tripsMonth = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

// Maintenance history, newest first
$stmt = $conn->prepare('SELECT start_date, end_date, workshop, note, closed_at FROM vehicle_maintenance WHERE vehicle_id = ? ORDER BY start_date DESC LIMIT 8');
$stmt->bind_param('i', $id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Next service: overdue / due within 30 days / fine
$svcState = null;
if ($v['next_maintenance']) {
    $days = (int)floor((strtotime($v['next_maintenance']) - strtotime(date('Y-m-d'))) / 86400);
    $svcState = $days < 0 ? ['error', 'Overdue by ' . abs($days) . ' day(s)'] : ($days <= 30 ? ['warning', 'Due in ' . $days . ' day(s)'] : null);
}
$fuel = $v['fuel_level'] === null ? null : (int)$v['fuel_level'];
$fuelWords = [0 => 'Almost empty', 1 => '¼ tank', 2 => '½ tank', 3 => '¾ tank', 4 => 'Full'];
$model = trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''));
$dot = ['available' => 'vh-av', 'in_use' => 'vh-use', 'maintenance' => 'vh-mt', 'inactive' => 'vh-in'];
$statusPill = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'assigned' => 'badge-info', 'processed' => 'badge-primary', 'internal' => 'badge-ghost'];

adminPageStart($v['plate_number']);
?>
    <a class="btn btn-ghost btn-sm mb-3" href="vehicles.php"><i class="fas fa-arrow-left" aria-hidden="true"></i>All vehicles</a>
    <div id="fleetResult"></div>

    <!-- Header -->
    <section class="card mb-4 bg-base-100" aria-labelledby="vTitle">
        <div class="card-body grid gap-6 md:grid-cols-[minmax(0,20rem)_minmax(0,1fr)] md:items-center">
            <div class="vh-img rounded-[10px]"><img src="<?= $h(vehiclePhotoUrl($v['photo'], $v['vehicle_type'])) ?>" alt="<?= $h($v['plate_number'] . ' ' . $model) ?>" class="<?= $v['status'] === 'inactive' ? 'grayscale opacity-60' : '' ?>"></div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-3"><?= plate($v['plate_number']) ?><span class="vh-st <?= $dot[$shown] ?? 'vh-in' ?>"><?= vehicleStatusLabel($shown) ?></span></div>
                        <h1 id="vTitle" class="mt-2"><?= $h($model ?: $v['vehicle_type']) ?></h1>
                        <div class="text-sm text-base-content/70"><?= $h(implode(' · ', array_filter([$v['vehicle_type'], (int)$v['capacity'] . ' seats', $v['fuel_type'], $v['year'] ? (string)$v['year'] : '']))) ?></div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a class="btn btn-sm" href="edit_vehicle.php?id=<?= $id ?>"><i class="fas fa-pen" aria-hidden="true"></i>Edit</a>
                        <a class="btn btn-sm" href="vehicle_maintenance.php?id=<?= $id ?>"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i>Maintenance</a>
                        <?php if ($v['status'] === 'inactive'): ?>
                            <button class="btn btn-sm btn-primary" data-fleet-url="../ajax/update_vehicle_status.php" data-fleet-id="<?= $id ?>" data-fleet-extra="status=available" data-fleet-confirm="Make this vehicle Available again?"><i class="fas fa-rotate-left" aria-hidden="true"></i>Reactivate</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-ghost text-error" data-fleet-url="../ajax/deactivate_vehicle.php" data-fleet-id="<?= $id ?>" data-fleet-confirm="Make this vehicle Inactive?"><i class="fas fa-ban" aria-hidden="true"></i>Deactivate</button>
                        <?php endif; ?>
                    </div>
                </div>
                <dl class="mt-5 flex flex-wrap gap-x-10 gap-y-3">
                    <div><dt class="text-xs text-base-content/70">Trips this month</dt><dd class="text-xl font-bold tabular-nums"><?= $tripsMonth ?></dd></div>
                    <div><dt class="text-xs text-base-content/70">Next trip</dt><dd class="text-xl font-bold"><?= $upcoming ? $h(date('d M', strtotime($upcoming[0]['start']))) : '-' ?></dd></div>
                    <div><dt class="text-xs text-base-content/70">Next service</dt><dd class="text-xl font-bold"><?= $v['next_maintenance'] ? $h(date('d M Y', strtotime($v['next_maintenance']))) : '-' ?>
                        <?php if ($svcState): ?><span class="e-status block text-<?= $svcState[0] ?>"><?= $h($svcState[1]) ?></span><?php endif; ?></dd></div>
                </dl>
                <?php if ($v['status'] === 'maintenance' && $v['back_on']): ?>
                    <div class="alert alert-soft alert-warning mt-4 text-sm"><i class="fas fa-screwdriver-wrench" aria-hidden="true"></i><span><?= $h('Under maintenance at ' . $v['workshop'] . ' since ' . date('d M Y', strtotime($v['maint_from'])) . '. Expected back ' . date('d M Y', strtotime($v['back_on'])) . '.') ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Spec tiles + fuel -->
    <div class="vv-tiles mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        <div class="e-tile"><span class="e-tile-label">Type</span><span class="e-tile-value text-lg"><?= $h($v['vehicle_type']) ?></span></div>
        <div class="e-tile"><span class="e-tile-label">Seats</span><span class="e-tile-value text-lg"><?= (int)$v['capacity'] ?></span></div>
        <div class="e-tile"><span class="e-tile-label">Licence</span><span class="e-tile-value text-lg"><?= $h($v['license_required']) ?></span></div>
        <div class="e-tile"><span class="e-tile-label">Mileage</span><span class="e-tile-value text-lg"><?= (int)$v['mileage'] > 0 ? number_format((int)$v['mileage']) . ' km' : '-' ?></span></div>
        <div class="e-tile"><span class="e-tile-label">Last service</span><span class="e-tile-value text-lg"><?= $v['last_maintenance'] ? $h(date('d M Y', strtotime($v['last_maintenance']))) : '-' ?></span></div>
        <div class="e-tile <?= $fuel !== null && $fuel <= 1 ? ($fuel === 0 ? 'fuel-empty' : 'fuel-low') : '' ?>">
            <span class="e-tile-label">Fuel level</span>
            <?php if ($fuel === null): ?>
                <span class="e-tile-value text-lg">-</span><span class="text-xs text-base-content/70">Not recorded yet</span>
            <?php else: ?>
                <span class="e-tile-value text-lg"><?= $h($fuelWords[$fuel]) ?></span>
                <div class="fuel-gauge my-1" role="img" aria-label="Fuel <?= $fuel ?> of 4"><?php for ($i = 1; $i <= 4; $i++): ?><span class="<?= $i <= $fuel ? 'on' : '' ?>"></span><?php endfor; ?></div>
                <span class="text-xs text-base-content/70"><?= $h(date('d M, H:i', strtotime($v['fuel_updated_at']))) ?><?= $v['fuel_by'] ? ' · ' . $h($v['fuel_by']) : '' ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <!-- Upcoming trips -->
        <section class="e-card min-w-0" aria-labelledby="upTitle">
            <div class="e-head"><h2 id="upTitle">Upcoming trips</h2><span class="text-sm text-base-content/70"><?= count($upcoming) ?></span></div>
            <?php if (!$upcoming): ?>
                <p class="py-4 text-sm text-base-content/70">No upcoming trips. This vehicle can be deactivated.</p>
            <?php endif; ?>
            <ul class="divide-y divide-base-200">
                <?php foreach ($upcoming as $u): $st = strtotime($u['start']); $en = strtotime($u['end']); ?>
                <li class="flex items-center gap-4 py-3">
                    <div class="grid w-14 shrink-0 place-items-center rounded-lg bg-base-200 py-1.5 text-center"><b class="text-lg leading-tight"><?= date('d', $st) ?></b><span class="text-[0.7rem] text-base-content/70 uppercase"><?= $h(date('M', $st)) ?></span></div>
                    <div class="min-w-0 grow"><div class="font-semibold"><?= $h($u['title']) ?></div>
                        <div class="text-xs text-base-content/70"><span><?= $h(date('D, H:i', $st) . ' → ' . (date('Y-m-d', $st) === date('Y-m-d', $en) ? date('H:i', $en) : date('d M H:i', $en))) ?></span> · <span><?= $h($u['sub']) ?></span></div></div>
                    <?php if ($u['status'] !== 'internal'): ?><span class="badge <?= $statusPill[$u['status']] ?? 'badge-ghost' ?> shrink-0"><?= $h(requestStatusLabel($u['status'])) ?></span><?php endif; ?>
                    <a class="btn btn-sm shrink-0" href="<?= $h($u['url']) ?>">Open</a>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- Maintenance history -->
        <section class="e-card min-w-0" aria-labelledby="mtTitle">
            <div class="e-head"><h2 id="mtTitle">Maintenance history</h2><a class="text-sm text-primary hover:underline" href="vehicle_maintenance.php?id=<?= $id ?>">Manage</a></div>
            <?php if (!$history): ?><p class="py-4 text-sm text-base-content/70">No maintenance recorded.</p><?php endif; ?>
            <ol class="relative ms-2 border-s border-base-300">
                <?php foreach ($history as $m): $open = $m['closed_at'] === null; ?>
                <li class="mb-4 ms-5 last:mb-0">
                    <span class="absolute -start-[7px] mt-1.5 size-3 rounded-full border-2 <?= $open ? 'border-warning bg-warning' : 'border-secondary bg-base-100' ?>"></span>
                    <div class="text-sm font-semibold"><?= $h($m['workshop'] ?: 'Workshop') ?><?= $open ? ' <span class="badge badge-warning badge-sm ms-1">Now</span>' : '' ?></div>
                    <div class="text-xs text-base-content/70"><?= $h(date('d M Y', strtotime($m['start_date'])) . ' – ' . date('d M Y', strtotime($m['end_date']))) ?><?= $m['note'] ? ' · ' . $h($m['note']) : '' ?></div>
                </li>
                <?php endforeach; ?>
            </ol>
        </section>
    </div>
    <?php if ($v['notes']): ?>
        <section class="e-card mt-4"><div class="e-head"><h2>Notes</h2></div><p class="text-sm"><?= nl2br($h($v['notes'])) ?></p></section>
    <?php endif; ?>
<?php fleetActionScript(); adminPageEnd(); ?>
