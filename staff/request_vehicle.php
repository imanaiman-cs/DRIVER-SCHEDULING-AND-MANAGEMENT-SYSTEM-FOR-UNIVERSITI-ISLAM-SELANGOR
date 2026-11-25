<?php
// ============================================================
// staff/request_vehicle.php  -  View Layer: RequestUI (SDD_CLASS_104) displayDateTimeForm(), displayAvailability(), displayRequestForm()
//   UC018 Submit Vehicle Request through RequestController (Staff, Head of Department, Driver, Administrative Assistant, Head of Admin)
//   1. departure date + time and return date + time -> Check Availability
//   2. the vehicle types (car, MPV, van, bus, lorry) with the units left for that period; type + quantity
//      With Driver | Pay and Claim (car) | Self Drive (lorry, drivers with a GDL licence only)
//   3. purpose type (Official / Unofficial; Personal for lorries), purpose, destination + full address, waiting
//      location, passengers, supporting letter (Official / Unofficial) or round-trip distance (Personal, RM3/km)
// The vehicles are reserved on Submit (first submitted, first served). Missing buses are hired from a vendor.
// ============================================================
$current_page = 'request_vehicle.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireLogin();   // every role may submit a request (UC018)

$me = currentUser();
$rc = new RequestController();
$errors = [];
$formError = null;
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$p = ['depart_date' => (string)($src['depart_date'] ?? ''), 'depart_time' => (string)($src['depart_time'] ?? ''),
      'return_date' => (string)($src['return_date'] ?? ''), 'return_time' => (string)($src['return_time'] ?? '')];
$checked = $p['depart_date'] !== '';
$depart = $return = null;
$periodOk = false;
if ($checked) {
    [$errors, $depart, $return] = $rc->checkPeriod($p['depart_date'], $p['depart_time'], $p['return_date'], $p['return_time']);
    $periodOk = !$errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $res = $rc->submitRequest($me, $_POST, $_FILES['document'] ?? null);
    if ($res['ok']) { redirectWithResult($res, 'request.php?id=' . $res['request_id']); }
    $errors = $res['errors'] ?? [];
    $formError = $res['message'] . (isset($errors['general']) ? ' ' . $errors['general'] : '');
}

$units = $periodOk ? $rc->checkAvailability($depart, $return) : null;
$urgent = $depart && $units !== null && $rc->isUrgent($depart);
$chargePerKm = $rc->getChargePerKm();
$v = fn($k, $d = '') => h($_POST[$k] ?? $d);
$selType = (string)($_POST['vehicle_type'] ?? '');
$approverName = $rc->approverForUser($me)['name'] ?? '';
$typeIcons = ['Car' => 'car', 'MPV' => 'car-side', 'Van' => 'van-shuttle', 'Bus' => 'bus', 'Lorry' => 'truck'];
$options = [];
foreach (Vehicle::TYPES as $t) { $options[$t] = $rc->driverOptionsFor($me, $t); }

adminPageStart('New Request');
pageHeader('New Vehicle Request', 'car-side', 'Enter the trip dates and times, select a vehicle type from the vehicles still available, then fill in the trip details.');
?>
<?php if ($formError): ?><div class="alert alert-soft alert-error mb-5" role="alert"><i class="fas fa-circle-exclamation"></i><span><?= h($formError) ?></span></div><?php endif; ?>

<?php cardStart('1. Trip period', 'clock'); ?>
<form method="get" class="grid grid-cols-12 items-end gap-3" novalidate>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="depart_date">Departure date</label>
        <input type="date" class="input w-full<?= errClass($errors, 'depart') ?>" id="depart_date" name="depart_date" min="<?= date('Y-m-d') ?>" value="<?= h($p['depart_date']) ?>" required></div>
    <div class="col-span-6 md:col-span-2"><label class="mb-1 block text-sm font-medium" for="depart_time">Departure time</label>
        <input type="time" class="input w-full<?= errClass($errors, 'depart') ?>" id="depart_time" name="depart_time" value="<?= h($p['depart_time'] ?: '08:00') ?>" required></div>
    <div class="col-span-6 md:col-span-3"><label class="mb-1 block text-sm font-medium" for="return_date">Return date</label>
        <input type="date" class="input w-full<?= errClass($errors, 'return') ?>" id="return_date" name="return_date" min="<?= date('Y-m-d') ?>" value="<?= h($p['return_date']) ?>" required></div>
    <div class="col-span-6 md:col-span-2"><label class="mb-1 block text-sm font-medium" for="return_time">Return time</label>
        <input type="time" class="input w-full<?= errClass($errors, 'return') ?>" id="return_time" name="return_time" value="<?= h($p['return_time'] ?: '17:00') ?>" required></div>
    <div class="col-span-12 md:col-span-2"><button class="btn btn-primary w-full" type="submit"><i class="fas fa-magnifying-glass"></i>Check Availability</button></div>
    <div class="col-span-12"><?= fieldError($errors, 'depart') ?><?= fieldError($errors, 'return') ?></div>
</form>
<?php cardEnd(); ?>

<?php if ($units !== null): ?>
<?php if ($urgent): ?>
<div class="alert alert-soft alert-warning mb-5" role="alert"><i class="fas fa-bolt"></i><span><strong>Urgent request.</strong> The departure is less than two days away. The request is saved as Urgent and shown at the top of the approvers' lists.</span></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" novalidate id="requestForm">
    <?php foreach ($p as $k => $val): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($val) ?>"><?php endforeach; ?>

    <?php cardStart('2. Vehicle', 'bus'); ?>
    <p class="mb-3 text-sm text-base-content/65">Units left for <?= h(fmtPeriod($depart, $return)) ?>. A vehicle stays reserved until <?= h((int)setting('BUFFER_HOURS')) ?> hour after the return time.</p>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" role="radiogroup" aria-label="Vehicle type">
        <?php foreach (Vehicle::TYPES as $t): $left = $units[$t]; $disabled = $left === 0 && !in_array($t, ['Bus', 'Car'], true); /* 0 units: cannot be selected (a bus can come from a vendor, a car can be Pay and Claim) */ ?>
        <label class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border border-base-300 p-3 text-center has-[:checked]:border-primary has-[:checked]:bg-primary/5 <?= $disabled ? 'cursor-not-allowed opacity-50' : '' ?>">
            <input type="radio" class="radio radio-primary radio-sm" name="vehicle_type" value="<?= $t ?>" data-left="<?= $left ?>" data-options="<?= h(implode(',', $options[$t])) ?>" <?= $selType === $t ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
            <i class="fas fa-<?= $typeIcons[$t] ?> text-2xl text-primary" aria-hidden="true"></i>
            <span class="font-semibold"><?= $t ?></span>
            <span class="text-xs <?= $left ? 'text-success' : 'text-error' ?>"><?= $left ?> unit<?= $left === 1 ? '' : 's' ?> left</span>
        </label>
        <?php endforeach; ?>
    </div>
    <?= fieldError($errors, 'vehicle_type') ?>
    <div class="mt-4 grid grid-cols-12 gap-4">
        <div class="col-span-12 md:col-span-4" id="optionBox">
            <label class="mb-1 block text-sm font-medium" for="driver_option">Driver</label>
            <select class="select w-full<?= errClass($errors, 'driver_option', 'select') ?>" id="driver_option" name="driver_option">
                <option value="With Driver">With Driver</option>
                <option value="Pay and Claim" <?= ($_POST['driver_option'] ?? '') === 'Pay and Claim' ? 'selected' : '' ?>>Pay and Claim (use my own car)</option>
                <option value="Self Drive" <?= ($_POST['driver_option'] ?? '') === 'Self Drive' ? 'selected' : '' ?>>Self Drive (I drive the lorry)</option>
            </select>
            <?= fieldError($errors, 'driver_option') ?>
            <div class="mt-1 text-xs text-base-content/60" id="optionHint"></div>
        </div>
        <div class="col-span-6 md:col-span-2" id="qtyBox">
            <label class="mb-1 block text-sm font-medium" for="quantity">Quantity</label>
            <input type="number" class="input w-full<?= errClass($errors, 'quantity') ?>" id="quantity" name="quantity" min="1" max="20" value="<?= $v('quantity', '1') ?>">
            <?= fieldError($errors, 'quantity') ?>
        </div>
        <div class="col-span-12 md:col-span-6">
            <div class="alert alert-soft alert-warning hidden text-sm" id="vendorWarn"><i class="fas fa-triangle-exclamation"></i><span></span></div>
            <div class="alert alert-soft alert-error hidden text-sm" id="qtyError"><i class="fas fa-circle-exclamation"></i><span>Not enough vehicles are available.</span></div>
        </div>
    </div>
    <?php cardEnd(); ?>

    <?php cardStart('3. Trip details', 'route'); ?>
    <div class="grid grid-cols-12 gap-4">
        <div class="col-span-12 md:col-span-4">
            <label class="mb-1 block text-sm font-medium" for="purpose_type">Purpose type</label>
            <select class="select w-full<?= errClass($errors, 'purpose_type', 'select') ?>" id="purpose_type" name="purpose_type" required>
                <option value="Official">Official</option>
                <option value="Unofficial" <?= ($_POST['purpose_type'] ?? '') === 'Unofficial' ? 'selected' : '' ?>>Unofficial</option>
                <option value="Personal" <?= ($_POST['purpose_type'] ?? '') === 'Personal' ? 'selected' : '' ?>>Personal (lorry only, charged per km)</option>
            </select>
            <?= fieldError($errors, 'purpose_type') ?>
        </div>
        <div class="col-span-12 md:col-span-8">
            <label class="mb-1 block text-sm font-medium" for="purpose">Purpose</label>
            <input class="input w-full<?= errClass($errors, 'purpose') ?>" id="purpose" name="purpose" maxlength="255" required value="<?= $v('purpose') ?>">
            <?= fieldError($errors, 'purpose') ?>
        </div>
        <div class="col-span-12 md:col-span-5">
            <label class="mb-1 block text-sm font-medium" for="destination">Destination</label>
            <input class="input w-full<?= errClass($errors, 'destination') ?>" id="destination" name="destination" maxlength="150" required value="<?= $v('destination') ?>" placeholder="e.g. Putrajaya International Convention Centre">
            <?= fieldError($errors, 'destination') ?>
        </div>
        <div class="col-span-12 md:col-span-7">
            <label class="mb-1 block text-sm font-medium" for="address">Full address of the destination</label>
            <textarea class="textarea w-full<?= errClass($errors, 'address', 'textarea') ?>" id="address" name="address" rows="2" maxlength="255" required><?= $v('address') ?></textarea>
            <?= fieldError($errors, 'address') ?>
        </div>
        <div class="col-span-12 md:col-span-6">
            <label class="mb-1 block text-sm font-medium" for="waiting_location">Waiting location</label>
            <input class="input w-full<?= errClass($errors, 'waiting_location') ?>" id="waiting_location" name="waiting_location" maxlength="150" required value="<?= $v('waiting_location') ?>" placeholder="Where the driver picks you up, e.g. Main entrance, Block A">
            <?= fieldError($errors, 'waiting_location') ?>
        </div>
        <div class="col-span-6 md:col-span-2">
            <label class="mb-1 block text-sm font-medium" for="passengers">Passengers</label>
            <input type="number" class="input w-full<?= errClass($errors, 'no_of_passengers') ?>" id="passengers" name="no_of_passengers" min="1" max="500" required value="<?= $v('no_of_passengers', '1') ?>">
            <?= fieldError($errors, 'no_of_passengers') ?>
        </div>
        <div class="col-span-12 md:col-span-4" id="docBox">
            <label class="mb-1 block text-sm font-medium" for="document">Supporting letter</label>
            <input type="file" class="file-input w-full<?= errClass($errors, 'document', 'file-input') ?>" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            <?= fieldError($errors, 'document') ?>
            <div class="mt-1 text-xs text-base-content/60">Required for Official and Unofficial. PDF, JPG or PNG, max 5 MB.</div>
        </div>
        <div class="col-span-12 hidden md:col-span-4" id="distanceBox">
            <label class="mb-1 block text-sm font-medium" for="distance_km">Round-trip distance (km)</label>
            <input type="number" class="input w-full<?= errClass($errors, 'distance_km') ?>" id="distance_km" name="distance_km" min="1" step="0.1" value="<?= $v('distance_km') ?>">
            <?= fieldError($errors, 'distance_km') ?>
            <div class="mt-1 text-sm">Estimated charge: <strong id="chargeOut">RM 0.00</strong> <span class="text-xs text-base-content/60">(RM <?= number_format($chargePerKm, 2) ?> per km)</span></div>
        </div>
    </div>
    <p class="mt-4 text-sm text-base-content/65" id="routeHint" data-hod="<?= h($approverName) ?>"></p>
    <div class="mt-4 flex gap-2"><button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i>Submit</button><a class="btn btn-ghost" href="my_requests.php">Cancel</a></div>
    <?php cardEnd(); ?>
</form>

<script>
(function () {
    var form = document.getElementById('requestForm');
    var types = form.querySelectorAll('input[name="vehicle_type"]');
    var opt = document.getElementById('driver_option'), qty = document.getElementById('quantity'), qtyBox = document.getElementById('qtyBox');
    var ptype = document.getElementById('purpose_type'), doc = document.getElementById('docBox'), dist = document.getElementById('distanceBox');
    var km = document.getElementById('distance_km'), out = document.getElementById('chargeOut'), rate = <?= json_encode($chargePerKm) ?>;
    var vendor = document.getElementById('vendorWarn'), qErr = document.getElementById('qtyError'), hint = document.getElementById('optionHint'), route = document.getElementById('routeHint');
    function sel() { var c = form.querySelector('input[name="vehicle_type"]:checked'); return c; }
    function refresh() {
        var c = sel(), type = c ? c.value : '', left = c ? parseInt(c.dataset.left, 10) : 0;
        var allowed = c ? c.dataset.options.split(',') : ['With Driver'];
        Array.prototype.forEach.call(opt.options, function (o) { o.hidden = o.disabled = allowed.indexOf(o.value) < 0; });
        if (allowed.indexOf(opt.value) < 0) { opt.value = 'With Driver'; }
        hint.textContent = type === 'Car' ? 'Pay and Claim: you use your own car; no vehicle is reserved.' : (type === 'Lorry' && allowed.indexOf('Self Drive') >= 0 ? 'Self Drive: you drive the lorry without a UIS driver.' : '');
        var single = opt.value !== 'With Driver';
        qtyBox.classList.toggle('hidden', single);
        if (single) { qty.value = 1; }
        var n = parseInt(qty.value || '1', 10);
        var short = opt.value !== 'Pay and Claim' && c && n > left;
        vendor.classList.toggle('hidden', !(short && type === 'Bus'));
        if (short && type === 'Bus') { vendor.querySelector('span').textContent = 'Only ' + left + ' bus(es) left. The remaining ' + (n - left) + ' bus(es) will be hired from a vendor.'; }
        qErr.classList.toggle('hidden', !(short && type !== 'Bus'));
        Array.prototype.forEach.call(ptype.options, function (o) { if (o.value === 'Personal') { o.hidden = o.disabled = type !== 'Lorry'; } });
        if (ptype.value === 'Personal' && type !== 'Lorry') { ptype.value = 'Official'; }
        var personal = ptype.value === 'Personal';
        doc.classList.toggle('hidden', personal); dist.classList.toggle('hidden', !personal);
        out.textContent = 'RM ' + ((parseFloat(km.value) || 0) * rate).toFixed(2);
        route.textContent = personal ? 'A Personal request goes to the Administrative Assistant, then the Head of Admin. You pay the charge.'
            : (route.dataset.hod ? 'The request goes to ' + route.dataset.hod + ' for approval' + (opt.value === 'With Driver' ? ', then the Vehicle Section assigns the driver.' : ', then the Head of Admin.') : '');
    }
    [opt, qty, ptype, km].forEach(function (el) { el.addEventListener('input', refresh); el.addEventListener('change', refresh); });
    types.forEach(function (r) { r.addEventListener('change', refresh); });
    form.addEventListener('submit', function (e) {
        var c = sel();
        if (!c) { e.preventDefault(); window.UIS.alert('Select a vehicle type.'); return; }
        if (!qErr.classList.contains('hidden')) { e.preventDefault(); window.UIS.alert('Not enough vehicles are available. Lower the quantity or pick another vehicle type.'); }
    });
    refresh();
})();
</script>
<?php endif; ?>
<?php adminPageEnd(); ?>
