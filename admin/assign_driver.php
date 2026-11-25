<?php
// ============================================================
// admin/assign_driver.php  -  View Layer: AssignmentUI (SDD_CLASS_106) - UC003 Assign Request Task (includes UC009)
//   displayWaitingList(Request): requests waiting for driver assignment, Urgent requests at the top
//   a request: its details and the units reserved for it; for each unit select the vehicle, then a driver for the
//   outbound and for the return trip from the recommended drivers (A1 Same Driver for Return Trip; A2 a driver
//   outside the recommendation needs a confirmation); displayVendorForm() for vendor buses (A3); distance and charge
//   of a Personal request (A4, A5)
//   submitAssignment() -> AssignmentController::assignRequestTask -> Driver Assigned -> Head of Admin (UC026)
// ============================================================
$current_page = 'assign_driver.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$me = currentUser();
$ac = new AssignmentController();
$rc = new RequestController();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $plan = [];
    foreach ((array)($_POST['plan'] ?? []) as $resID => $p) {
        $p = (array)$p;
        if (!empty($_POST['same'][$resID])) { $p['return'] = $p['outbound'] ?? 0; }
        $plan[(int)$resID] = $p;
    }
    $distance = isset($_POST['distance_km']) && $_POST['distance_km'] !== '' ? (float)$_POST['distance_km'] : null;
    $res = $ac->assignRequestTask($me, $id, $plan, (float)($_POST['task_hours'] ?? 1), $distance);
    if ($res['ok']) { redirectWithResult($res, 'assign_driver.php'); }
    setFlash('danger', $res['message']);
    header('Location: assign_driver.php?id=' . $id . '&hours=' . urlencode((string)($_POST['task_hours'] ?? '')));
    exit();
}

if (!$id) {
    $rows = $ac->listWaiting('Request');
    adminPageStart('Assign Request Task');
    pageHeader('Assign Request Task', 'user-plus', 'Requests approved by the head of the department (and Personal lorry requests) waiting for driver assignment. Urgent requests are at the top.');
    echo '<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table"><thead><tr><th>Request</th><th>Requester</th><th>Vehicle</th><th>Departure</th><th>Destination</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td class="font-semibold">#' . (int)$r['request_id'] . urgentBadge($r) . '</td>'
           . '<td>' . h($r['requester_name']) . '<div class="text-xs text-base-content/60">' . h($r['department_name']) . '</div></td>'
           . '<td>' . h($r['vehicle_type'] . ' x ' . $r['quantity'] . ', ' . $r['driver_option']) . ($r['purpose_type'] === Request::PERSONAL ? ' <span class="badge badge-sm badge-secondary">Personal</span>' : '') . '</td>'
           . '<td class="whitespace-nowrap">' . h(fmtDT($r['depart_datetime'])) . '</td><td>' . h($r['destination']) . '</td>'
           . '<td class="text-end"><a class="btn btn-sm btn-primary" href="?id=' . (int)$r['request_id'] . '">' . ($r['driver_option'] === Request::SELF_DRIVE ? 'Confirm charge' : 'Assign') . '</a></td></tr>';
    }
    if (!$rows) { echo emptyRow(6, 'No request is waiting for driver assignment.'); }
    echo '</tbody></table></div></div>';
    adminPageEnd();
    exit();
}

$r = $rc->getRequestDetails($id);
if (!$r || !$rc->isWaitingForAssignment($r)) {
    setFlash('warning', 'That request is not waiting for driver assignment.');
    header('Location: assign_driver.php');
    exit();
}
$hours = max(0.5, min(24, (float)($_GET['hours'] ?? 0) ?: (float)setting('TASK_DURATION_HOURS')));
$win = $ac->taskWindows($r, $hours);
$units = array_values(array_filter($r['reservations'], fn($x) => $x['status'] === VehicleReservation::ACTIVE));
$personal = $r['purpose_type'] === Request::PERSONAL;
$selfDrive = $r['driver_option'] === Request::SELF_DRIVE;
$licence = licenceForVehicleType($r['vehicle_type']);
$rs = new RecommendationService();
$recOut = $selfDrive ? null : $rs->recommendDrivers($licence, $win['Outbound'][0], $win['Outbound'][1], ['except_request' => $id]);
$recRet = $selfDrive ? null : $rs->recommendDrivers($licence, $win['Return'][0], $win['Return'][1], ['except_request' => $id]);
$returned = '';
foreach (array_reverse($r['history']) as $hst) { if (str_starts_with((string)$hst['remark'], 'Driver assignment returned')) { $returned = $hst['remark']; break; } }

adminPageStart('Assign Request Task');
pageHeader('Assign request #' . $id, 'user-plus', $r['vehicle_type'] . ' x ' . $r['quantity'] . ', ' . $r['driver_option'] . ' to ' . $r['destination'],
    '<a class="btn btn-ghost" href="assign_driver.php"><i class="fas fa-arrow-left"></i>Back</a><a class="btn btn-outline" href="../staff/request.php?id=' . $id . '">Request details</a>');
if ($returned && $r['status'] === Request::APPROVED_BY_HOD) { echo '<div class="alert alert-soft alert-warning mb-5"><i class="fas fa-rotate-left"></i><span>' . h($returned) . '</span></div>'; }
?>
<div class="grid grid-cols-12 gap-5">
<div class="col-span-12 xl:col-span-4">
<?php cardStart('Request', 'file-lines'); ?>
<dl>
    <?= dlRow('Requester', h($r['requester_name']) . '<div class="text-xs text-base-content/60">' . h($r['department_name']) . ' &middot; ' . h($r['requester_phone']) . '</div>') ?>
    <?= dlRow('Departure', h(fmtDT($r['depart_datetime'])) . urgentBadge($r)) ?>
    <?= dlRow('Return', h(fmtDT($r['return_datetime']))) ?>
    <?= dlRow('Destination', h($r['destination']) . '<div class="text-xs">' . h($r['address']) . '</div>') ?>
    <?= dlRow('Waiting location', h($r['waiting_location'])) ?>
    <?= dlRow('Passengers', (string)(int)$r['no_of_passengers']) ?>
    <?= dlRow('Purpose', h($r['purpose_type']) . ': ' . h($r['purpose'])) ?>
</dl>
<?php if (!$selfDrive): ?>
<form method="get" class="mt-4 flex items-end gap-2">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div><label class="mb-1 block text-sm font-medium" for="hours">Task duration (hours)</label><input type="number" class="input input-sm w-28" id="hours" name="hours" min="0.5" max="24" step="0.5" value="<?= h($hours) ?>"></div>
    <button class="btn btn-sm" type="submit">Update</button>
</form>
<p class="mt-2 text-xs text-base-content/60">Outbound task <?= h(fmtPeriod($win['Outbound'][0], $win['Outbound'][1])) ?>; return task <?= h(fmtPeriod($win['Return'][0], $win['Return'][1])) ?>. The driver is free between them. Increase the duration for a long-distance trip.</p>
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
            <div class="text-sm">Charge: <strong id="charge"><?= h(money((float)$r['charge'])) ?></strong> <span class="text-xs text-base-content/60">(RM <?= number_format($rc->getChargePerKm(), 2) ?>/km)</span></div>
        </div>
        <p class="mt-2 text-xs text-base-content/60">Correct the distance if the requester's figure is not right; the charge is calculated again.</p>
        <script>document.getElementById('distance_km').addEventListener('input', function () { document.getElementById('charge').textContent = 'RM ' + ((parseFloat(this.value) || 0) * <?= json_encode($rc->getChargePerKm()) ?>).toFixed(2); });</script>
    <?php cardEnd(); ?>
    <?php endif; ?>

    <?php if ($selfDrive): ?>
        <div class="alert alert-soft alert-info mb-5"><i class="fas fa-circle-info"></i><span>Self Drive: the requester drives the lorry. No driver is assigned. Confirm the distance and the charge, then Submit to send the request to the Head of Admin.</span></div>
    <?php else: $n = 0; foreach ($units as $u): $rid = (int)$u['reservation_id']; $n++; ?>
        <?php if ($u['is_vendor']): ?>
            <?php cardStart('Unit ' . $n . ': vendor bus', 'bus'); ?>
            <p class="mb-3 text-sm text-base-content/65">Not enough UIS buses were left, so this bus is hired from a vendor. The vendor provides the driver. Enter the vendor details.</p>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="mb-1 block text-sm font-medium" for="vn<?= $rid ?>">Vendor name</label><input class="input w-full" id="vn<?= $rid ?>" name="plan[<?= $rid ?>][vendor_name]" maxlength="150" required></div>
                <div><label class="mb-1 block text-sm font-medium" for="vp<?= $rid ?>">Bus plate number</label><input class="input w-full uppercase" id="vp<?= $rid ?>" name="plan[<?= $rid ?>][vendor_plate_no]" maxlength="15" required></div>
            </div>
            <?php cardEnd(); ?>
        <?php else: $free = (new VehicleController())->freeForReservation($u); ?>
            <?php cardStart('Unit ' . $n . ': ' . $u['vehicle_type'], 'car-side'); ?>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12">
                    <label class="mb-1 block text-sm font-medium" for="v<?= $rid ?>">Vehicle</label>
                    <select class="select w-full" id="v<?= $rid ?>" name="plan[<?= $rid ?>][vehicle_id]" required>
                        <option value="">Select the vehicle</option>
                        <?php foreach ($free as $i => $fv): ?><option value="<?= (int)$fv['vehicle_id'] ?>" <?= ((int)$u['vehicle_id'] === (int)$fv['vehicle_id'] || (!$u['vehicle_id'] && $i === $n - 1)) ? 'selected' : '' ?>><?= h($fv['plate_no'] . ' ' . $fv['brand'] . ' ' . $fv['model'] . ' (' . $fv['capacity'] . ' seats, ' . number_format((int)$fv['current_mileage']) . ' km, fuel ' . $fv['fuel_level'] . ')') ?></option><?php endforeach; ?>
                    </select>
                    <?php if (!$free): ?><div class="mt-1 text-xs text-error">No <?= h($u['vehicle_type']) ?> is free for this period.</div><?php endif; ?>
                </div>
                <div class="col-span-12 md:col-span-6">
                    <label class="mb-1 block text-sm font-medium" for="o<?= $rid ?>">Outbound trip driver</label>
                    <select class="select w-full" id="o<?= $rid ?>" name="plan[<?= $rid ?>][outbound]" required><?= driverOptionsHtml($recOut) ?></select>
                    <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox checkbox-sm" name="same[<?= $rid ?>]" value="1" data-same="<?= $rid ?>">Same Driver for Return Trip</label>
                </div>
                <div class="col-span-12 md:col-span-6" data-ret="<?= $rid ?>">
                    <label class="mb-1 block text-sm font-medium" for="r<?= $rid ?>">Return trip driver</label>
                    <select class="select w-full" id="r<?= $rid ?>" name="plan[<?= $rid ?>][return]"><?= driverOptionsHtml($recRet) ?></select>
                </div>
            </div>
            <?php cardEnd(); ?>
        <?php endif; ?>
    <?php endforeach; endif; ?>
    <?php if (!$selfDrive && $recOut): ?>
    <?php cardStart('Recommended drivers (licence ' . $licence . ')', 'ranking-star'); ?>
        <details open><summary class="cursor-pointer text-sm font-medium">Outbound task</summary><div class="mt-2"><?= recommendationTableHtml($recOut) ?></div></details>
        <details class="mt-2"><summary class="cursor-pointer text-sm font-medium">Return task</summary><div class="mt-2"><?= recommendationTableHtml($recRet) ?></div></details>
        <p class="mt-2 text-xs text-base-content/60">Ranking: tasks this month <?= h(setting('W_TASKS') * 100) ?>%, weekend tasks <?= h(setting('W_WEEKEND') * 100) ?>%, experience <?= h(setting('W_EXPERIENCE') * 100) ?>%. The recommendation supports your decision; you make the final choice.</p>
    <?php cardEnd(); ?>
    <?php endif; ?>
    <?php if (!$units && !$selfDrive): ?><div class="alert alert-soft alert-error mb-5"><i class="fas fa-circle-exclamation"></i><span>No vehicle is reserved for this request.</span></div><?php endif; ?>
    <div class="flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i>Submit</button><a class="btn btn-ghost" href="assign_driver.php">Cancel</a></div>
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
