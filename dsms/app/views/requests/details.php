<?php /** @var array $trip @var array $vehicle @var array $v @var array $errors @var array $pickupPoints @var int $maxMb @var ?array $approver */
$step = 3;
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$dep = $trip['departure']->format('Y-m-d H:i:s'); $ret = $trip['return']->format('Y-m-d H:i:s');
?>
<h1 class="h4 mb-3">Request details</h1>
<?php include __DIR__ . '/_stepper.php'; ?>
<?php if (isset($errors['vehicle'])): ?><div class="alert alert-danger"><?= e($errors['vehicle']) ?></div><?php endif; ?>
<div class="card mb-3"><div class="card-body py-2 small">
    <strong><?= e($vehicle['reg_no']) ?></strong> <?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?> (<?= (int)$vehicle['capacity'] ?> seats)
    &middot; <?= e(fmt_datetime($dep)) ?> &rarr; <?= e(fmt_datetime($ret)) ?> &middot; <?= (int)$trip['passengers'] ?> passenger(s)
</div></div>

<form method="post" action="<?= e(url('requests/store')) ?>" enctype="multipart/form-data" id="request-form" class="card"><div class="card-body">
    <?= Csrf::field() ?>
    <input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>">
    <input type="hidden" name="dep_date" value="<?= e($trip['departure']->format('Y-m-d')) ?>"><input type="hidden" name="dep_time" value="<?= e($trip['departure']->format('H:i')) ?>">
    <input type="hidden" name="ret_date" value="<?= e($trip['return']->format('Y-m-d')) ?>"><input type="hidden" name="ret_time" value="<?= e($trip['return']->format('H:i')) ?>">
    <input type="hidden" name="passengers" value="<?= (int)$trip['passengers'] ?>">
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label small" for="destination_name">Destination</label>
            <input class="form-control" id="destination_name" name="destination_name" maxlength="150" value="<?= old($v, 'destination_name') ?>" required><?= $err('destination_name') ?></div>
        <div class="col-md-6"><label class="form-label small" for="pickup_point_id">Pickup point</label>
            <select class="form-select" id="pickup_point_id" name="pickup_point_id" required>
                <option value="">Choose...</option>
                <?php foreach ($pickupPoints as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($v['pickup_point_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select><?= $err('pickup_point_id') ?></div>
        <div class="col-12"><label class="form-label small" for="full_address">Full address of the destination</label>
            <textarea class="form-control" id="full_address" name="full_address" rows="2" required><?= old($v, 'full_address') ?></textarea><?= $err('full_address') ?></div>
        <div class="col-12"><label class="form-label small" for="purpose">Purpose of the trip</label>
            <textarea class="form-control" id="purpose" name="purpose" rows="2" required><?= old($v, 'purpose') ?></textarea><?= $err('purpose') ?></div>
        <div class="col-md-6"><label class="form-label small" for="contact_phone">Contact phone (during the trip)</label>
            <input class="form-control" id="contact_phone" name="contact_phone" maxlength="30" value="<?= old($v, 'contact_phone') ?>" required><?= $err('contact_phone') ?></div>
        <div class="col-md-6"><label class="form-label small" for="passenger_names">Passenger names (optional)</label>
            <textarea class="form-control" id="passenger_names" name="passenger_names" rows="2"><?= old($v, 'passenger_names') ?></textarea><?= $err('passenger_names') ?></div>
        <div class="col-12"><label class="form-label small" for="documents">Supporting documents (optional)</label>
            <input type="file" class="form-control" id="documents" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png">
            <div class="form-text">PDF, JPG or PNG, up to 5 files, <?= (int)$maxMb ?> MB each. If this page shows an error, please choose the files again.</div><?= $err('documents') ?></div>
    </div>
    <div class="small text-muted mt-3">
        This request will be sent to <strong><?= $approver ? e($approver['name']) : 'the Head Admin' ?></strong> for approval.
        A driver is arranged by the Vehicle Section after approval.
    </div>
</div>
<div class="card-footer bg-white d-flex justify-content-between">
    <a class="btn btn-link" href="<?= e(url('requests/vehicles', ['dep_date' => $trip['departure']->format('Y-m-d'), 'dep_time' => $trip['departure']->format('H:i'), 'ret_date' => $trip['return']->format('Y-m-d'), 'ret_time' => $trip['return']->format('H:i'), 'passengers' => $trip['passengers']])) ?>">&larr; Back</a>
    <button class="btn btn-dark" type="button" id="review-btn">Review and submit</button>
</div></form>

<!-- Step 4: confirm dialog. Filled in by JavaScript from the form, then the real submit happens. -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title h5" id="confirmTitle">Confirm your request</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <dl class="row mb-0 small" id="confirm-summary"></dl>
        <p class="small text-muted mt-3 mb-0">After you submit, the vehicle is held for you while your request waits for approval.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Back to edit</button><button type="button" class="btn btn-dark" id="submit-btn">Submit request</button></div>
</div></div></div>
<script>
// Fill the confirm dialog from the form values. Text is inserted with textContent, so nothing typed can run as HTML.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('request-form');
    var summary = document.getElementById('confirm-summary');
    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    var rows = [
        ['Vehicle', <?= json_encode($vehicle['reg_no'] . ' - ' . $vehicle['brand'] . ' ' . $vehicle['model']) ?>],
        ['Departure', <?= json_encode(fmt_datetime($dep)) ?>], ['Return', <?= json_encode(fmt_datetime($ret)) ?>],
        ['Passengers', <?= json_encode((string)$trip['passengers']) ?>]
    ];
    document.getElementById('review-btn').addEventListener('click', function () {
        if (!form.reportValidity()) { return; }
        summary.textContent = '';
        var pickup = form.pickup_point_id.options[form.pickup_point_id.selectedIndex].text;
        var all = rows.concat([['Destination', form.destination_name.value], ['Pickup point', pickup], ['Purpose', form.purpose.value], ['Contact phone', form.contact_phone.value]]);
        all.forEach(function (r) {
            var dt = document.createElement('dt'); dt.className = 'col-4'; dt.textContent = r[0];
            var dd = document.createElement('dd'); dd.className = 'col-8'; dd.textContent = r[1];
            summary.appendChild(dt); summary.appendChild(dd);
        });
        modal.show();
    });
    document.getElementById('submit-btn').addEventListener('click', function () { this.disabled = true; form.submit(); });
});
</script>
