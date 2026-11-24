<?php
// ============================================================
// admin/assign_driver.php  -  UC003 Assign Request Task (includes UC009 Recommend Driver)
//   1. the requests waiting for driver assignment, Urgent requests at the top
//   2. a request: its details and the vehicles reserved for it
//   3-4. for each vehicle: the recommended drivers, a driver for the outbound and for the return trip
//        (A1 Same Driver for Return Trip; A2 a driver outside the recommendation needs a confirmation)
//   A3 vendor buses: vendor name and bus plate number (no UIS driver)
//   A4/A5 Personal request: distance and charge (RM3/km); Personal Self Drive is submitted without a driver
//   5. Submit -> Driver Assigned -> the Head of Admin approves (UC026)
// Each request has an outbound task at the departure time and a return task at the return time; each lasts the
// task duration (default 1 hour, can be changed for a long-distance trip).
// ============================================================
$current_page = 'assign_driver.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $plan = [];
    foreach ((array)($_POST['plan'] ?? []) as $rv => $p) {
        $p = (array)$p;
        if (!empty($_POST['same'][$rv])) { $p['return'] = $p['outbound'] ?? 0; }
        $plan[(int)$rv] = $p;
    }
    $distance = isset($_POST['distance_km']) && $_POST['distance_km'] !== '' ? (float)$_POST['distance_km'] : null;
    $res = assignRequest($conn, $id, $plan, (float)($_POST['task_hours'] ?? 1), $distance);
    if ($res['ok']) { redirectWithResult($res, 'assign_driver.php'); }
    setFlash('danger', $res['message']);
    header('Location: assign_driver.php?id=' . $id . '&hours=' . urlencode((string)($_POST['task_hours'] ?? '')));
    exit();
}

if (!$id) {
    $rows = dbAll($conn, requestSelectSql() . ' WHERE ' . SQL_WAITING_ASSIGNMENT . ' ORDER BY r.is_urgent DESC, r.depart_at');
    adminPageStart('Assign Request Task');
    pageHeader('Assign Request Task', 'user-plus', 'Requests approved by the Head of Department (and Personal lorry requests) waiting for driver assignment. Urgent requests are at the top.');
    echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Request</th><th>Requester</th><th>Vehicle</th><th>Departure</th><th>Destination</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td class="font-semibold">#' . (int)$r['request_id'] . urgentBadge($r) . ($r['assign_reject_reason'] ? '<div class="text-xs text-warning">Returned: ' . h($r['assign_reject_reason']) . '</div>' : '') . '</td>'
           . '<td>' . h($r['requester_name']) . '<div class="text-xs text-base-content/60">' . h($r['department_name'] ?? '') . '</div></td>'
           . '<td>' . h(requestVehicleSummary($r)) . ($r['purpose_type'] === 'personal' ? ' <span class="badge badge-sm badge-secondary">Personal</span>' : '') . '</td>'
           . '<td class="whitespace-nowrap">' . h(fmtDT($r['depart_at'])) . '</td><td>' . h($r['destination']) . '</td>'
           . '<td class="text-end"><a class="btn btn-sm btn-primary" href="?id=' . (int)$r['request_id'] . '">' . ($r['driver_option'] === 'self_drive' ? 'Confirm charge' : 'Assign') . '</a></td></tr>';
    }
    if (!$rows) { echo emptyRow(6, 'No request is waiting for driver assignment.'); }
    echo '</tbody></table></div></div>';
    adminPageEnd();
    exit();
}

$r = loadRequest($conn, $id);
if (!$r || !waitingForAssignment($r)) {
    setFlash('warning', 'That request is not waiting for driver assignment.');
    header('Location: assign_driver.php');
    exit();
}
$hours = (float)($_GET['hours'] ?? 0) ?: (float)$r['task_hours'];
$hours = max(0.5, min(24, $hours));
$win = requestTaskWindows($r, $hours);
$rvs = array_filter(requestVehicles($conn, $id), fn($rv) => $rv['active']);
$personal = $r['purpose_type'] === 'personal';
$selfDrive = $r['driver_option'] === 'self_drive';
$recCache = [];
$rec = function (string $licence, string $leg) use (&$recCache, $conn, $win, $id) {
    return $recCache["$licence|$leg"] ??= recommendDrivers($conn, ['licence' => $licence, 'start' => $win[$leg][0], 'end' => $win[$leg][1], 'except_request' => $id]);
};
$prev = [];
foreach (dbAll($conn, "SELECT rv_id, trip_type, driver_id FROM tasks WHERE request_id = ?", [$id]) as $t) { $prev[(int)$t['rv_id']][$t['trip_type']] = (int)$t['driver_id']; }

adminPageStart('Assign Request Task');
pageHeader('Assign request #' . $id, 'user-plus', requestVehicleSummary($r) . ' to ' . $r['destination'], '<a class="btn btn-ghost" href="assign_driver.php"><i class="fas fa-arrow-left"></i>Back</a><a class="btn btn-outline" href="../staff/request.php?id=' . $id . '">Request details</a>');
if ($r['assign_reject_reason']) { echo '<div class="alert alert-soft alert-warning mb-5"><i class="fas fa-rotate-left"></i><span>The Head of Admin returned the last assignment: ' . h($r['assign_reject_reason']) . '</span></div>'; }
?>
<div class="grid grid-cols-12 gap-5">
<div class="col-span-12 xl:col-span-4">
<?php cardStart('Request', 'file-lines'); ?>
<dl>
    <?= dlRow('Requester', h($r['requester_name']) . '<div class="text-xs text-base-content/60">' . h($r['department_name'] ?? '') . ' &middot; ' . h($r['requester_phone'] ?? '') . '</div>') ?>
    <?= dlRow('Departure', h(fmtDT($r['depart_at'])) . urgentBadge($r)) ?>
    <?= dlRow('Return', h(fmtDT($r['return_at']))) ?>
    <?= dlRow('Destination', h($r['destination']) . '<div class="text-xs">' . h($r['address']) . '</div>') ?>
    <?= dlRow('Waiting location', h($r['waiting_location'])) ?>
    <?= dlRow('Passengers', (string)(int)$r['passengers']) ?>
    <?= dlRow('Purpose', h(purposeTypeLabel($r['purpose_type'])) . ': ' . h($r['purpose'])) ?>
</dl>
<?php if (!$selfDrive): ?>
<form method="get" class="mt-4 flex items-end gap-2">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div><label class="mb-1 block text-sm font-medium" for="hours">Task duration (hours)</label><input type="number" class="input input-sm w-28" id="hours" name="hours" min="0.5" max="24" step="0.5" value="<?= h($hours) ?>"></div>
    <button class="btn btn-sm" type="submit">Update</button>
</form>
<p class="mt-2 text-xs text-base-content/60">Outbound task <?= h(fmtPeriod($win['outbound'][0], $win['outbound'][1])) ?>; return task <?= h(fmtPeriod($win['return'][0], $win['return'][1])) ?>. The driver is free between them. Increase the duration for a long-distance trip.</p>
<?php endif; ?>
<?php cardEnd(); ?>
</div>
<div class="col-span-12 xl:col-span-8">
<form method="post" data-outside-check novalidate>
    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="task_hours" value="<?= h($hours) ?>">
    <?php if ($personal): ?>
    <?php cardStart('Distance and charge (Personal)', 'calculator'); ?>
        <div class="flex flex-wrap items-end gap-4">
            <div><label class="mb-1 block text-sm font-medium" for="distance_km">Round-trip distance (km)</label>
                <input type="number" class="input w-40" id="distance_km" name="distance_km" min="1" step="0.1" value="<?= h($r['distance_km']) ?>" required></div>
            <div class="text-sm">Charge: <strong id="charge"><?= h(money((float)$r['charge'])) ?></strong> <span class="text-xs text-base-content/60">(RM <?= number_format(getSettingFloat($conn, 'CHARGE_PER_KM'), 2) ?>/km)</span></div>
        </div>
        <p class="mt-2 text-xs text-base-content/60">Correct the distance if the requester's figure is not right; the charge is calculated again.</p>
        <script>document.getElementById('distance_km').addEventListener('input', function () { document.getElementById('charge').textContent = 'RM ' + ((parseFloat(this.value) || 0) * <?= json_encode(getSettingFloat($conn, 'CHARGE_PER_KM')) ?>).toFixed(2); });</script>
    <?php cardEnd(); ?>
    <?php endif; ?>

    <?php if ($selfDrive): ?>
        <div class="alert alert-soft alert-info mb-5"><i class="fas fa-circle-info"></i><span>Self Drive: the requester drives the lorry. No driver is assigned. Confirm the distance and the charge, then Submit to send the request to the Head of Admin.</span></div>
    <?php else: foreach ($rvs as $rv): $rid = (int)$rv['rv_id']; ?>
        <?php if ($rv['is_vendor']): ?>
            <?php cardStart('Vendor bus', 'bus'); ?>
            <p class="mb-3 text-sm text-base-content/65">Not enough UIS buses were left, so this bus is hired from a vendor. The vendor provides the driver. Enter the vendor details.</p>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm font-medium" for="vn<?= $rid ?>">Vendor name</label><input class="input w-full" id="vn<?= $rid ?>" name="plan[<?= $rid ?>][vendor_name]" maxlength="150" value="<?= h($rv['vendor_name']) ?>" required></div>
                <div><label class="mb-1 block text-sm font-medium" for="vp<?= $rid ?>">Bus plate number</label><input class="input w-full uppercase" id="vp<?= $rid ?>" name="plan[<?= $rid ?>][vendor_plate]" maxlength="20" value="<?= h($rv['vendor_plate']) ?>" required></div>
            </div>
            <?php cardEnd(); ?>
        <?php else: $ro = $rec($rv['licence_required'], 'outbound'); $rr = $rec($rv['licence_required'], 'return'); $po = $prev[$rid]['outbound'] ?? 0; $pr = $prev[$rid]['return'] ?? 0; ?>
            <?php cardStart('', ''); ?>
            <h2 class="mb-3 flex flex-wrap items-center gap-2 text-base font-bold"><?= plate($rv['plate_number']) ?> <?= h($rv['brand'] . ' ' . $rv['model']) ?> <span class="badge badge-ghost badge-sm"><?= h($rv['vehicle_type']) ?>, <?= (int)$rv['capacity'] ?> seats, licence <?= h($rv['licence_required']) ?></span></h2>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-6">
                    <label class="mb-1 block text-sm font-medium" for="o<?= $rid ?>">Outbound trip driver</label>
                    <select class="select w-full" id="o<?= $rid ?>" name="plan[<?= $rid ?>][outbound]" required data-same-src="<?= $rid ?>"><?= driverOptionsHtml($ro, $po) ?></select>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox checkbox-sm" name="same[<?= $rid ?>]" value="1" data-same="<?= $rid ?>" <?= $po && $po === $pr ? 'checked' : '' ?>>Same Driver for Return Trip</label>
                </div>
                <div class="col-span-12 md:col-span-6" data-ret="<?= $rid ?>">
                    <label class="mb-1 block text-sm font-medium" for="r<?= $rid ?>">Return trip driver</label>
                    <select class="select w-full" id="r<?= $rid ?>" name="plan[<?= $rid ?>][return]"><?= driverOptionsHtml($rr, $pr) ?></select>
                </div>
            </div>
            <details class="mt-3" <?= $rv === reset($rvs) ? 'open' : '' ?>><summary class="cursor-pointer text-sm font-medium">Recommended drivers (outbound task)</summary><div class="mt-2"><?= recommendationTableHtml($ro) ?></div></details>
            <details class="mt-2"><summary class="cursor-pointer text-sm font-medium">Recommended drivers (return task)</summary><div class="mt-2"><?= recommendationTableHtml($rr) ?></div></details>
            <?php cardEnd(); ?>
        <?php endif; ?>
    <?php endforeach; endif; ?>
    <?php if (!$rvs && !$selfDrive): ?><div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>No vehicle is reserved for this request.</span></div><?php endif; ?>
    <div class="flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i>Submit</button><a class="btn btn-ghost" href="assign_driver.php">Cancel</a></div>
    <p class="mt-2 text-xs text-base-content/60">Ranking: tasks this month <?= h(getSetting($conn, 'W_TASKS') * 100) ?>%, weekend tasks <?= h(getSetting($conn, 'W_WEEKEND') * 100) ?>%, experience <?= h(getSetting($conn, 'W_EXPERIENCE') * 100) ?>%. The recommendation supports your decision; you make the final choice.</p>
</form>
</div>
</div>
<script>
document.querySelectorAll('[data-same]').forEach(function (cb) {
    function sync() { var box = document.querySelector('[data-ret="' + cb.dataset.same + '"]'); box.classList.toggle('opacity-40', cb.checked); box.querySelector('select').disabled = cb.checked; }
    cb.addEventListener('change', sync); sync();
});
</script>
<?php adminPageEnd(); ?>
