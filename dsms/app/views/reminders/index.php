<?php /** @var array $rows @var int $lapseHours @var int $minHours */ ?>
<h1 class="h4 mb-1">Reminders</h1>
<p class="text-muted small">Requests waiting for approval. They lapse after <?= (int)$lapseHours ?> hours. A reminder to the approver can be sent once every <?= (int)$minHours ?> hours.</p>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>No.</th><th>Requester</th><th>Destination</th><th>Approver</th><th>Waiting</th><th>Reminders</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $left = $lapseHours - (int)$r['hours_waiting']; $recent = $r['last_reminder'] && strtotime($r['last_reminder']) > time() - $minHours * 3600; ?>
            <tr>
                <td><a href="<?= e(url('requests/show', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
                <td><?= e($r['requester_name']) ?></td><td><?= e($r['destination_name']) ?></td>
                <td><?= e($r['approver_name'] ?? 'Head Admin') ?></td>
                <td><?= (int)$r['hours_waiting'] ?> h <span class="text-muted small">(<?= $left > 0 ? "$left h left" : 'lapsing' ?>)</span></td>
                <td><?= (int)$r['reminders_sent'] ?><?= $r['last_reminder'] ? ' <span class="text-muted small">last ' . e(fmt_datetime($r['last_reminder'])) . '</span>' : '' ?></td>
                <td class="text-end">
                    <form method="post" action="<?= e(url('reminders/send')) ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-dark" <?= $recent ? 'disabled title="Sent recently"' : '' ?>>Send reminder</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No request is waiting for approval.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
