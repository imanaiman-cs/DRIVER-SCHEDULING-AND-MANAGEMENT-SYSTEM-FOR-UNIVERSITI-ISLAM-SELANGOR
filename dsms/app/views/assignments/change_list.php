<?php /** @var array $rows */ ?>
<h1 class="h4 mb-1">Change driver</h1>
<p class="text-muted small">Trips with drivers that have not finished yet. Every change is logged with the reason.</p>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>No.</th><th>Destination</th><th>Vehicle</th><th>Departure</th><th>Drivers</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
                <td><?= e($r['destination_name']) ?></td><td><?= e($r['reg_no']) ?></td>
                <td class="text-nowrap"><?= e(fmt_datetime($r['departure_datetime'])) ?></td>
                <td class="small"><?php foreach ($r['legs'] as $l): ?><div><?= e(ucfirst($l['leg_type'])) ?>: <?= e($l['driver_name'] ?? '-') ?></div><?php endforeach; ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= e(url('assignments/change', ['id' => $r['id']])) ?>">Change driver</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No trip with assigned drivers.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
