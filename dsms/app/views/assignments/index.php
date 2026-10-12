<?php /** @var array $rows */ ?>
<h1 class="h4 mb-1">Assign driver</h1>
<p class="text-muted small">Approved requests that still need a driver. Trips where the requester drives themselves can be confirmed as self-drive.</p>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>No.</th><th>Requester</th><th>Destination</th><th>Vehicle</th><th>Departure</th><th>Return</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
                <td><?= e($r['requester_name']) ?></td><td><?= e($r['destination_name']) ?></td>
                <td><?= e($r['reg_no']) ?> <span class="text-muted small">(<?= e($r['required_licence']) ?>)</span></td>
                <td class="text-nowrap"><?= e(fmt_datetime($r['departure_datetime'])) ?></td><td class="text-nowrap"><?= e(fmt_datetime($r['return_datetime'])) ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-dark" href="<?= e(url('assignments/assign', ['id' => $r['id']])) ?>">Assign driver</a>
                    <form method="post" action="<?= e(url('assignments/self_drive')) ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-dark" data-confirm="Confirm request #<?= (int)$r['id'] ?> as self-drive (no driver)?">Self-drive</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No approved request is waiting for a driver.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
