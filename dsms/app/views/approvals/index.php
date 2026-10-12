<?php /** @var array $pending @var array $recent @var int $lapseHours */ ?>
<h1 class="h4 mb-1">Approvals</h1>
<p class="text-muted small">A request that is not decided within <?= (int)$lapseHours ?> hours lapses automatically.</p>
<h2 class="h6 text-uppercase text-muted">Waiting for your decision</h2>
<div class="card mb-4"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>No.</th><th>Requester</th><th>Destination</th><th>Vehicle</th><th>Departure</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r): ?>
            <tr>
                <td>#<?= (int)$r['id'] ?></td><td><?= e($r['requester_name']) ?></td><td><?= e($r['destination_name']) ?></td>
                <td><?= e($r['reg_no']) ?></td><td class="text-nowrap"><?= e(fmt_datetime($r['departure_datetime'])) ?></td>
                <td class="text-nowrap"><?= e(fmt_datetime($r['submitted_at'])) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-dark" href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">Review</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$pending): ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing is waiting for you.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<h2 class="h6 text-uppercase text-muted">Recently decided</h2>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>No.</th><th>Requester</th><th>Destination</th><th>Departure</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr><td>#<?= (int)$r['id'] ?></td><td><?= e($r['requester_name']) ?></td><td><?= e($r['destination_name']) ?></td>
                <td class="text-nowrap"><?= e(fmt_datetime($r['departure_datetime'])) ?></td><td><?= status_badge($r['status']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">View</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="6" class="text-center text-muted py-3">No decisions yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
