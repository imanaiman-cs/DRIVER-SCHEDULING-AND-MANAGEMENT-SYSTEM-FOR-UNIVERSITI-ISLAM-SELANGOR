<?php
/** @var array $user @var array $counts */
$role = $user['role'];

/** One dashboard card: number + label + link. */
$card = function (string $label, $number, string $page, string $hint = '') {
    ?>
    <div class="col-6 col-lg-3">
        <a class="text-decoration-none text-dark" href="<?= e(url($page)) ?>">
            <div class="card h-100 dash-card">
                <div class="card-body">
                    <div class="display-6 fw-semibold"><?= (int)$number ?></div>
                    <div><?= e($label) ?></div>
                    <?php if ($hint): ?><div class="small text-muted"><?= e($hint) ?></div><?php endif; ?>
                </div>
            </div>
        </a>
    </div>
    <?php
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h4 mb-0">Welcome, <?= e($user['name']) ?></h1>
        <div class="text-muted small"><?= e(role_label($role)) ?> &middot; <?= e(date('l, d F Y')) ?></div>
    </div>
    <a class="btn btn-dark" href="<?= e(url('requests/create')) ?>">+ New vehicle request</a>
</div>

<h2 class="h6 text-uppercase text-muted">My requests</h2>
<div class="row g-3 mb-4">
    <?php
    $card('Waiting for approval', $counts['my_pending'], 'requests/index', 'My requests');
    $card('Upcoming trips', $counts['my_upcoming'], 'requests/index');
    $card('All my requests', $counts['my_total'], 'requests/index');
    ?>
</div>

<?php if ($role === 'driver'): ?>
<h2 class="h6 text-uppercase text-muted">My driver tasks</h2>
<div class="row g-3 mb-4">
    <?php
    $card('Tasks today', $counts['tasks_today'], 'tasks/index');
    $card('Upcoming trips', $counts['tasks_upcoming'], 'tasks/index');
    $card('Welfare duty today', $counts['welfare_today'], 'tasks/index');
    ?>
</div>
<?php endif; ?>

<?php if ($role === 'hod'): ?>
<h2 class="h6 text-uppercase text-muted">Approvals</h2>
<div class="row g-3 mb-4">
    <?php $card('Requests to approve', $counts['to_approve'], 'approvals/index', 'Not approved within 2 days = lapsed'); ?>
</div>
<?php endif; ?>

<?php if (in_array($role, ['admin_assistant', 'head_admin'], true)): ?>
<h2 class="h6 text-uppercase text-muted">Vehicle Section</h2>
<div class="row g-3 mb-4">
    <?php
    $card('Waiting for HOD approval', $counts['awaiting_approval'], 'reminders/index', 'Send reminders');
    $card('Approved: need a driver', $counts['to_assign'], 'assignments/index');
    $card('Trips today', $counts['trips_today'], 'calendar/index');
    $card('Vehicles available', $counts['vehicles_available'], 'vehicles/index');
    if ($role === 'head_admin') {
        $card('Driver assignments to approve', $counts['to_approve_assignment'], 'assignments/approve');
    }
    ?>
</div>
<?php endif; ?>
