<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/assign_driver.php  -  UC017 Assign Driver (+ UC018 View Driver Recommendation)
//
// An Approved request has up to TWO tasks: the outbound trip and the return trip.
//   1. the recommendation for the outbound trip is always shown first (UC018); the administrator picks the driver
//   2. the return trip uses the same driver and vehicle, or a separate driver and vehicle (with its own recommendation)
// The system only recommends; it never assigns by itself, and a driver with a clashing task cannot be chosen.
// After assigning the request is "Driver Assigned" and the Head of Admin is notified (system + e-mail).
// ============================================================
$current_page = 'assign_driver.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$rid = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$stmt = $conn->prepare(requestSelectSql() . ' WHERE vr.request_id = ?');
$stmt->bind_param('i', $rid);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($req && $req['status'] === 'approved' && isRequestWithoutDriver($req)) {
    setFlash('warning', 'That request is without driver. Send it to the Head of Admin instead.');
    header('Location: vehicle_requests.php');
    exit();
}
if (!$req || $req['status'] !== 'approved') {
    setFlash('warning', 'That request is not waiting for a driver.');
    header('Location: vehicle_requests.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $out = (int)($_POST['out_driver'] ?? 0);
    $mode = (string)($_POST['return_mode'] ?? 'same');
    $same = $mode === 'same';
    $vendorOut = ($_POST['out_mode'] ?? '') === 'vendor' ? ['name' => $_POST['out_vendor_name'] ?? '', 'plate' => $_POST['out_vendor_plate'] ?? '', 'driver' => $_POST['out_vendor_driver'] ?? ''] : null;   // UC017 A2
    $vendorRet = $mode === 'vendor' ? ['name' => $_POST['ret_vendor_name'] ?? '', 'plate' => $_POST['ret_vendor_plate'] ?? '', 'driver' => $_POST['ret_vendor_driver'] ?? ''] : null;
    $retDriver = ($same || $vendorRet) ? null : (int)($_POST['ret_driver'] ?? 0);
    $retVehicle = ($same || $vendorRet) ? null : (int)($_POST['ret_vehicle'] ?? 0);
    if ($out <= 0 && !$vendorOut) {
        setFlash('danger', 'Choose the driver of the outbound trip.');
    } elseif (!$same && !$vendorRet && ($retDriver <= 0 || $retVehicle <= 0)) {
        setFlash('danger', 'Choose the driver and the vehicle of the return trip, or use the same driver and vehicle.');
    } else {
        $res = assignRequestDrivers($conn, $rid, $out, $retDriver, $retVehicle, $vendorOut, $vendorRet);
        setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
        if ($res['ok']) { header('Location: vehicle_requests.php?tab=assigned&open=' . $rid); exit(); }
    }
    header('Location: assign_driver.php?request_id=' . $rid);
    exit();
}

$win = requestTaskWindows($conn, $req);
$lq = $conn->prepare('SELECT license_required FROM vehicles WHERE vehicle_id = ?');
$vid = (int)$req['vehicle_id'];
$lq->bind_param('i', $vid);
$lq->execute();
$licence = $lq->get_result()->fetch_row()[0] ?? 'D';
$lq->close();
$rec = recommendDrivers($conn, ['start' => $win['out']['start_dt'], 'end' => $win['out']['end_dt'], 'licence' => $licence]);
$vendorOutAllowed = $licence === 'PSV' && !$rec['ranked'];          // UC017 A2: no UIS driver with a PSV licence is available
$vendorRetAllowed = $licence === 'PSV';                             // checked again on the server for the return window
$vendorBox = function (string $p): string {
    return '<div class="row g-2 mt-1">'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . '_vendor_name">Vendor name *</label><input class="form-control form-control-sm" id="' . $p . '_vendor_name" name="' . $p . '_vendor_name" maxlength="150"></div>'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . '_vendor_plate">Bus registration number *</label><input class="form-control form-control-sm" id="' . $p . '_vendor_plate" name="' . $p . '_vendor_plate" maxlength="20"></div>'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . '_vendor_driver">Vendor driver name *</label><input class="form-control form-control-sm" id="' . $p . '_vendor_driver" name="' . $p . '_vendor_driver" maxlength="100"></div></div>';
};

adminPageStart('Assign Driver');
?>
    <a class="small text-decoration-none" href="vehicle_requests.php"><i class="fas fa-arrow-left me-1"></i>Vehicle requests</a>
    <h4 class="fw-bold my-2"><i class="fas fa-user-plus text-success me-2"></i>Assign driver &mdash; request #<?= $rid ?></h4>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><?= requestDetailsHtml($conn, $req, ['show_assignment' => false, 'history' => false]) ?></div></div>

    <form method="post">
        <input type="hidden" name="request_id" value="<?= $rid ?>">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">Outbound trip <span class="fw-normal text-muted small">
                <?= date('D, d M H:i', strtotime($win['out']['start_dt'])) ?>&ndash;<?= substr($win['out']['end'], 0, 5) ?> &middot; <?= htmlspecialchars((string)$req['plate_number']) ?> (licence <?= htmlspecialchars($licence) ?>)</span></h6>
            <p class="small text-muted mb-2">Recommendation: fewer tasks this month and fewer outside working hours rank higher (weights <?= htmlspecialchars((string)$rec['weights']['tasks']) ?> / <?= htmlspecialchars((string)$rec['weights']['outside']) ?>). You make the final choice.</p>
            <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light"><tr><th></th><th>#</th><th>Driver</th><th class="text-center">Tasks this month</th><th class="text-center">Outside working hours</th><th class="text-center">Score</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($rec['ranked'] as $d): ?>
                    <tr>
                        <td><input class="form-check-input" type="radio" name="out_driver" value="<?= (int)$d['driver_id'] ?>" id="od<?= (int)$d['driver_id'] ?>" required></td>
                        <td><?= (int)$d['rank'] ?></td>
                        <td><label for="od<?= (int)$d['driver_id'] ?>" class="mb-0"><?= htmlspecialchars($d['name']) ?></label> <span class="text-muted small"><?= htmlspecialchars((string)$d['license_class']) ?></span></td>
                        <td class="text-center"><?= (int)$d['tasks'] ?></td>
                        <td class="text-center"><?= (int)$d['outside'] ?></td>
                        <td class="text-center"><span class="badge bg-success"><?= number_format((float)$d['score'], 1) ?></span></td>
                        <td class="small"><?= $d['welfare'] ? '<span class="badge bg-warning text-dark">On welfare duty that day</span>' : '' ?><?= $d['rank'] === 1 ? ' <span class="badge bg-primary">Recommended</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rec['ranked']): ?><tr><td colspan="7" class="text-danger">No driver is available for this trip. See the list below for the reasons.<?= $vendorOutAllowed ? '' : ' You can cancel the request with a reason.' ?></td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php if ($vendorOutAllowed): ?>
            <div class="alert alert-warning py-2 mt-2 mb-2"><input type="hidden" name="out_mode" value="vendor"><strong>Vendor bus.</strong> No UIS driver with a PSV licence is available, so use a vendor bus. The quotation and payment are handled outside DSMS.
                <?= $vendorBox('out') ?></div>
            <?php endif; ?>
            <?php if ($rec['excluded']): ?>
            <details class="small"><summary class="text-muted" style="cursor:pointer;">Drivers who cannot be chosen (<?= count($rec['excluded']) ?>)</summary>
                <ul class="mb-0"><?php foreach ($rec['excluded'] as $x): ?><li><?= htmlspecialchars($x['name']) ?> &mdash; <?= htmlspecialchars($x['reason']) ?></li><?php endforeach; ?></ul>
            </details>
            <?php endif; ?>
        </div></div>

        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6 class="fw-bold">Return trip <span class="fw-normal text-muted small"><?= date('D, d M H:i', strtotime($win['ret']['start_dt'])) ?>&ndash;<?= substr($win['ret']['end'], 0, 5) ?></span></h6>
            <div class="form-check"><input class="form-check-input" type="radio" name="return_mode" id="rm_same" value="same" checked>
                <label class="form-check-label" for="rm_same">Same driver and vehicle as the outbound trip</label></div>
            <div class="form-check"><input class="form-check-input" type="radio" name="return_mode" id="rm_sep" value="separate">
                <label class="form-check-label" for="rm_sep">A separate driver and vehicle for the return trip</label></div>
            <?php if ($vendorRetAllowed): ?><div class="form-check"><input class="form-check-input" type="radio" name="return_mode" id="rm_vendor" value="vendor">
                <label class="form-check-label" for="rm_vendor">A vendor bus for the return trip <span class="text-muted small">(only when no UIS driver with a PSV licence is free then)</span></label></div>
            <div id="vendorBox" class="mt-2" style="display:none;"><?= $vendorBox('ret') ?></div><?php endif; ?>
            <div id="sepBox" class="mt-3" style="display:none;">
                <label class="form-label small" for="ret_vehicle">Return vehicle (free in the return period, enough seats)</label>
                <select class="form-select form-select-sm mb-2" id="ret_vehicle" name="ret_vehicle"><option value="">Loading&hellip;</option></select>
                <div id="retDrivers" class="small text-muted">Choose a vehicle to see the recommendation for the return trip.</div>
            </div>
        </div></div>
        <button class="btn btn-success"><i class="fas fa-check me-1"></i>Assign and send to the Head of Admin</button>
    </form>

<script>
(function () {
    var base = '<?= SITE_URL ?>/ajax/';
    var R = { start: <?= json_encode($win['ret']['start_dt']) ?>, end: <?= json_encode($win['ret']['end_dt']) ?>, rid: <?= $rid ?>, pax: <?= (int)$req['passenger_count'] ?> };
    var vehicles = [];
    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
    var sepBox = document.getElementById('sepBox');
    document.querySelectorAll('input[name=return_mode]').forEach(function (r) {
        r.addEventListener('change', function () {
            sepBox.style.display = document.getElementById('rm_sep').checked ? '' : 'none';
            var vb = document.getElementById('vendorBox'); if (vb) { vb.style.display = document.getElementById('rm_vendor') && document.getElementById('rm_vendor').checked ? '' : 'none'; }
            if (document.getElementById('rm_sep').checked) { loadVehicles(); }
        });
    });
    function loadVehicles() {
        if (vehicles.length) { return; }
        var s = R.start.split(' '), e = R.end.split(' ');
        var q = new URLSearchParams({ depart_date: s[0], depart_time: s[1].slice(0, 5), return_date: e[0], return_time: e[1].slice(0, 5), passengers: R.pax, request_id: R.rid, for_assignment: 1 });
        fetch(base + 'get_free_vehicles.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            vehicles = d.vehicles || [];
            var sel = document.getElementById('ret_vehicle');
            sel.innerHTML = '<option value="">Choose a vehicle</option>' + vehicles.map(function (v) {
                return '<option value="' + v.vehicle_id + '" data-lic="' + esc(v.license_required) + '">' + esc(v.plate_number) + ' - ' + esc(v.model) + ' (' + esc(v.vehicle_type) + ', ' + v.capacity + ' seats)</option>';
            }).join('');
            sel.addEventListener('change', loadDrivers);
        });
    }
    function loadDrivers() {
        var sel = document.getElementById('ret_vehicle'), box = document.getElementById('retDrivers');
        if (!sel.value) { box.textContent = 'Choose a vehicle to see the recommendation for the return trip.'; return; }
        var q = new URLSearchParams({ start: R.start, end: R.end, licence: sel.options[sel.selectedIndex].getAttribute('data-lic') });
        fetch(base + 'get_available_drivers.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success || !d.drivers.length) { box.innerHTML = '<span class="text-danger">No driver is available for the return trip.</span>'; return; }
            box.innerHTML = '<table class="table table-sm align-middle"><thead class="table-light"><tr><th></th><th>#</th><th>Driver</th><th class="text-center">Tasks</th><th class="text-center">Outside hours</th><th class="text-center">Score</th><th></th></tr></thead><tbody>' +
                d.drivers.map(function (x) {
                    return '<tr><td><input class="form-check-input" type="radio" name="ret_driver" value="' + x.driver_id + '" required></td><td>' + x.rank + '</td><td>' + esc(x.name) + '</td><td class="text-center">' + x.tasks +
                           '</td><td class="text-center">' + x.outside + '</td><td class="text-center"><span class="badge bg-success">' + Number(x.score).toFixed(1) + '</span></td><td>' +
                           (x.welfare ? '<span class="badge bg-warning text-dark">Welfare duty</span>' : '') + (x.rank === 1 ? ' <span class="badge bg-primary">Recommended</span>' : '') + '</td></tr>';
                }).join('') + '</tbody></table>';
        });
    }
})();
</script>
<?php adminPageEnd(); ?>
