<?php /** @var array $tasks @var string $range @var array $ranges @var string $today @var array $summary @var array $welfare */
$lastDay = null; ?>
<h1 class="h4 mb-3">My tasks</h1>
<div class="row g-2 mb-3">
    <div class="col-6"><div class="card"><div class="card-body py-2"><div class="h4 mb-0"><?= (int)$summary['tasks'] ?></div><div class="small text-muted">Tasks this month</div></div></div></div>
    <div class="col-6"><div class="card"><div class="card-body py-2"><div class="h4 mb-0"><?= (int)$summary['after_hours'] ?></div><div class="small text-muted">After-hours this month</div></div></div></div>
</div>
<?php if ($welfare): ?>
    <div class="alert alert-light border small py-2">Welfare duty: <?= e(implode(', ', array_map('fmt_date', $welfare))) ?>. Please stay reachable on those days.</div>
<?php endif; ?>
<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
    <?php foreach ($ranges as $k => $label): ?>
        <li class="nav-item"><a class="nav-link text-nowrap <?= $range === $k ? 'active bg-dark' : 'text-dark' ?>" href="<?= e(url('tasks/index', ['range' => $k])) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php foreach ($tasks as $t): $day = substr($t['start'], 0, 10); ?>
    <?php if ($day !== $lastDay): $lastDay = $day; ?>
        <h2 class="h6 text-uppercase text-muted mt-3"><?= e(date('l, d M Y', strtotime($day))) ?><?= $day === $today ? ' (today)' : '' ?></h2>
    <?php endif; ?>
    <div class="card mb-2"><div class="card-body py-2">
        <?php if ($t['kind'] === 'trip'): ?>
            <div class="d-flex justify-content-between align-items-start">
                <div><strong><?= e(substr($t['start'], 11, 5)) ?> - <?= e(substr($t['finish'], 11, 5)) ?></strong>
                    <span class="badge text-bg-light border ms-1"><?= e(ucfirst($t['leg_type'])) ?></span>
                    <?= $t['status'] === 'Completed' ? '<span class="badge text-bg-dark ms-1">Completed</span>' : '' ?></div>
                <a class="small" href="<?= e(url('requests/show', ['id' => $t['request_id']])) ?>">Request #<?= (int)$t['request_id'] ?></a>
            </div>
            <div><?= e($t['destination_name']) ?></div>
            <div class="small text-muted"><?= e($t['reg_no']) ?> <?= e($t['vehicle_name']) ?> &middot; <?= (int)$t['passenger_count'] ?> passenger(s) &middot; pickup: <?= e($t['pickup_name'] ?? '-') ?></div>
            <div class="small">Contact: <?= e($t['requester_name']) ?> <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)($t['contact_phone'] ?: $t['requester_phone']))) ?>"><?= e($t['contact_phone'] ?: $t['requester_phone']) ?></a></div>
        <?php else: ?>
            <div><strong><?= e(substr($t['start'], 11, 5)) ?> - <?= e(substr($t['finish'], 11, 5)) ?></strong>
                <span class="badge text-bg-secondary ms-1">Internal task</span></div>
            <div><?= e(DriverTask::TYPES[$t['task_type']] ?? $t['task_type']) ?><?= $t['location'] ? ' &middot; ' . e($t['location']) : '' ?></div>
            <div class="small text-muted"><?= $t['reg_no'] ? e($t['reg_no'] . ' ' . $t['vehicle_name']) . ' &middot; ' : '' ?><?= e($t['description'] ?? '') ?></div>
        <?php endif; ?>
    </div></div>
<?php endforeach; ?>
<?php if (!$tasks): ?><div class="card"><div class="card-body text-center text-muted py-4">No tasks in this period.</div></div><?php endif; ?>
