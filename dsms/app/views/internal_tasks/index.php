<?php /** @var array $rows @var string $month @var string $prev @var string $next */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0">Internal tasks</h1>
    <a class="btn btn-dark btn-sm" href="<?= e(url('internal_tasks/create')) ?>">+ New task</a>
</div>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('internal_tasks/index', ['month' => $prev])) ?>">&laquo;</a>
    <strong><?= e(date('F Y', strtotime($month . '-01'))) ?></strong>
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('internal_tasks/index', ['month' => $next])) ?>">&raquo;</a>
</div>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Date</th><th>Time</th><th>Task</th><th>Driver</th><th>Vehicle</th><th>Location / note</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
            <tr>
                <td class="text-nowrap"><?= e(fmt_date($t['task_date'])) ?></td>
                <td class="text-nowrap"><?= e(substr($t['start_time'], 0, 5)) ?> - <?= e(substr($t['end_time'], 0, 5)) ?></td>
                <td><?= e(DriverTask::TYPES[$t['task_type']] ?? $t['task_type']) ?></td>
                <td><?= e($t['driver_name']) ?></td><td><?= e($t['reg_no'] ?? '-') ?></td>
                <td class="small"><?= e(trim(($t['location'] ?? '') . ' ' . ($t['description'] ?? ''))) ?></td>
                <td class="text-end"><form method="post" action="<?= e(url('internal_tasks/delete')) ?>" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" data-confirm="Remove this task?">Remove</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No internal tasks this month.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
