<?php /** @var array $r @var array $legs @var array $recs @var array $replacements */ ?>
<a class="small" href="<?= e(url('assignments/change')) ?>">&larr; All trips</a>
<h1 class="h4 my-2">Change driver</h1>
<?php include __DIR__ . '/_summary.php'; ?>
<?php foreach ($legs as $leg): $started = $leg['start_datetime'] <= date('Y-m-d H:i:s'); ?>
    <div class="card mb-3"><div class="card-body">
        <h2 class="h6"><?= e(ucfirst($leg['leg_type'])) ?> leg <span class="text-muted fw-normal">&middot; <?= e(fmt_datetime($leg['start_datetime'])) ?> - <?= e(fmt_time($leg['end_datetime'])) ?> &middot; now: <strong><?= e($leg['driver_name'] ?? 'nobody') ?></strong></span></h2>
        <?php if ($started): ?>
            <p class="small text-muted mb-0">This leg has already started, so its driver can no longer be changed.</p>
        <?php else: ?>
        <form method="post" action="<?= e(url('assignments/update_driver')) ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="leg_id" value="<?= (int)$leg['id'] ?>">
            <?php
            $rec = $recs[(int)$leg['id']]; $inputName = 'driver_id'; $selected = 0; $currentDriverId = (int)$leg['driver_id'];
            include __DIR__ . '/_candidates.php';
            ?>
            <div class="row g-2 align-items-end">
                <div class="col-md-7"><label class="form-label small" for="reason<?= (int)$leg['id'] ?>">Reason for the change (required, logged)</label>
                    <input class="form-control form-control-sm" id="reason<?= (int)$leg['id'] ?>" name="reason" maxlength="500" required></div>
                <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_clash" value="1" id="cc<?= (int)$leg['id'] ?>"><label class="form-check-label small" for="cc<?= (int)$leg['id'] ?>">Assign anyway if there is a clash</label></div></div>
                <div class="col-md-2 text-md-end"><button class="btn btn-dark btn-sm" type="submit">Change driver</button></div>
            </div>
        </form>
        <?php endif; ?>
    </div></div>
<?php endforeach; ?>
<div class="card"><div class="card-body">
    <h2 class="h6">Change log</h2>
    <?php if ($replacements): ?>
    <table class="table table-sm small mb-0">
        <thead><tr><th>When</th><th>Leg</th><th>From</th><th>To</th><th>Reason</th><th>By</th></tr></thead>
        <tbody><?php foreach ($replacements as $p): ?>
            <tr><td><?= e(fmt_datetime($p['changed_at'])) ?></td><td><?= e(ucfirst($p['leg_type'])) ?></td><td><?= e($p['previous_name'] ?? '-') ?></td><td><?= e($p['new_name']) ?></td><td><?= e($p['reason']) ?></td><td><?= e($p['changed_by_name']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table>
    <?php else: ?><p class="small text-muted mb-0">No driver has been changed for this request.</p><?php endif; ?>
</div></div>
