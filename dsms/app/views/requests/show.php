<?php /** @var array $r @var array $legs @var array $documents @var array $comments @var array $replacements @var bool $canCancel @var bool $showDrivers @var array $timeline */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a class="small" href="<?= e(url('requests/index')) ?>">&larr; My requests</a>
        <h1 class="h4 mb-0">Request #<?= (int)$r['id'] ?> <?= status_badge($r['status']) ?></h1>
    </div>
    <div class="no-print">
        <?php if ($canCancel): ?><button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">Cancel request</button><?php endif; ?>
        <?php include __DIR__ . '/_actions.php'; ?>
    </div>
</div>
<?php if ($r['status_reason'] && in_array($r['status'], ['Rejected', 'Lapsed', 'Cancelled'], true)): ?>
    <div class="alert alert-light border"><strong><?= e($r['status']) ?>:</strong> <?= e($r['status_reason']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Trip</h2>
            <dl class="row mb-0 small">
                <dt class="col-sm-4">Requester</dt><dd class="col-sm-8"><?= e($r['requester_name']) ?> (<?= e($r['requester_staff_id']) ?>)<?= $r['ptj_name'] ? ' &middot; ' . e($r['ptj_name']) : '' ?></dd>
                <dt class="col-sm-4">Destination</dt><dd class="col-sm-8"><?= e($r['destination_name']) ?><br><span class="text-muted"><?= nl2br(e($r['full_address'])) ?></span></dd>
                <dt class="col-sm-4">Purpose</dt><dd class="col-sm-8"><?= nl2br(e($r['purpose'])) ?></dd>
                <dt class="col-sm-4">Departure</dt><dd class="col-sm-8"><?= e(fmt_datetime($r['departure_datetime'])) ?></dd>
                <dt class="col-sm-4">Return</dt><dd class="col-sm-8"><?= e(fmt_datetime($r['return_datetime'])) ?></dd>
                <dt class="col-sm-4">Vehicle</dt><dd class="col-sm-8"><?= e($r['reg_no']) ?> - <?= e($r['brand'] . ' ' . $r['model']) ?> (<?= e($r['vehicle_type']) ?>, <?= (int)$r['capacity'] ?> seats)</dd>
                <dt class="col-sm-4">Passengers</dt><dd class="col-sm-8"><?= (int)$r['passenger_count'] ?><?= $r['passenger_names'] ? ' &middot; ' . e($r['passenger_names']) : '' ?></dd>
                <dt class="col-sm-4">Pickup point</dt><dd class="col-sm-8"><?= e($r['pickup_name'] ?? '-') ?></dd>
                <dt class="col-sm-4">Contact phone</dt><dd class="col-sm-8"><?= e($r['contact_phone']) ?></dd>
                <dt class="col-sm-4">Approver</dt><dd class="col-sm-8"><?= e($r['approver_name'] ?? 'Head Admin') ?></dd>
            </dl>
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Driver</h2>
            <?php if ($r['is_self_drive']): ?>
                <p class="small mb-0">Self-drive: the requester drives, no driver is assigned.</p>
            <?php elseif ($showDrivers): ?>
                <table class="table table-sm small mb-0">
                    <thead><tr><th>Leg</th><th>Time</th><th>Driver</th><th>Phone</th></tr></thead>
                    <tbody>
                    <?php foreach ($legs as $l): ?>
                        <tr><td><?= e(ucfirst($l['leg_type'])) ?></td>
                            <td><?= e(fmt_datetime($l['start_datetime'])) ?> - <?= e(fmt_time($l['end_datetime'])) ?></td>
                            <td><?= e($l['driver_name'] ?? 'Not assigned') ?></td><td><?= e($l['driver_phone'] ?? '-') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($replacements): ?>
                    <div class="small fw-semibold mt-3">Driver changes</div>
                    <ul class="small mb-0"><?php foreach ($replacements as $p): ?>
                        <li><?= e(fmt_datetime($p['changed_at'])) ?> - <?= e(ucfirst($p['leg_type'])) ?> leg: <?= e($p['previous_name'] ?? 'nobody') ?> &rarr; <?= e($p['new_name']) ?> (<?= e($p['reason']) ?>)</li>
                    <?php endforeach; ?></ul>
                <?php endif; ?>
            <?php else: ?>
                <p class="small text-muted mb-0">A driver is arranged by the Vehicle Section after the request is approved.</p>
            <?php endif; ?>
        </div></div>

        <?php include __DIR__ . '/_comments.php'; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Timeline</h2>
            <ul class="list-unstyled small mb-0 timeline">
            <?php foreach ($timeline as [$label, $when, $who, $note]): ?>
                <li class="mb-2"><strong><?= e($label) ?></strong> <span class="text-muted">by <?= e($who) ?></span><br>
                    <span class="text-muted"><?= e(fmt_datetime($when)) ?></span><?= $note ? '<br>' . e($note) : '' ?></li>
            <?php endforeach; ?>
            </ul>
        </div></div>
        <div class="card"><div class="card-body">
            <h2 class="h6">Documents</h2>
            <?php if ($documents): ?>
                <ul class="list-unstyled small mb-0">
                <?php foreach ($documents as $d): ?>
                    <li><a href="<?= e(url('requests/document', ['id' => $d['id']])) ?>"><?= e($d['original_name']) ?></a> <span class="text-muted">(<?= e(number_format($d['file_size'] / 1024, 0)) ?> KB)</span></li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?><p class="small text-muted mb-0">No documents uploaded.</p><?php endif; ?>
        </div></div>
    </div>
</div>

<?php if ($canCancel): ?>
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(url('requests/cancel')) ?>">
    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <div class="modal-header"><h2 class="modal-title h5" id="cancelTitle">Cancel request #<?= (int)$r['id'] ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <label class="form-label small" for="reason">Reason for cancelling (required)</label>
        <textarea class="form-control" id="reason" name="reason" rows="3" maxlength="500" required></textarea>
        <div class="form-text">The vehicle will be released for other people and everyone involved is notified.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Keep request</button><button class="btn btn-dark" type="submit">Cancel the request</button></div>
</form></div></div>
<?php endif; ?>
