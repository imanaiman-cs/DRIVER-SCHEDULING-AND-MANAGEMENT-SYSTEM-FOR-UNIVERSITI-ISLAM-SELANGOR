<?php /** @var array $rows */ ?>
<h1 class="h4 mb-1">Approve assignment</h1>
<p class="text-muted small">Drivers chosen by the Vehicle Section. When you approve, the request becomes Confirmed and the drivers are told.</p>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>No.</th><th>Destination</th><th>Vehicle</th><th>Departure</th><th>Drivers</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
                <td><?= e($r['destination_name']) ?></td><td><?= e($r['reg_no']) ?></td>
                <td class="text-nowrap"><?= e(fmt_datetime($r['departure_datetime'])) ?></td>
                <td class="small"><?php foreach ($r['legs'] as $l): ?><div><?= e(ucfirst($l['leg_type'])) ?>: <?= e($l['driver_name'] ?? '-') ?></div><?php endforeach; ?></td>
                <td class="text-end"><form method="post" action="<?= e(url('assignments/confirm')) ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-dark" data-confirm="Approve the drivers for request #<?= (int)$r['id'] ?>?">Approve</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">No assignment is waiting for approval.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
