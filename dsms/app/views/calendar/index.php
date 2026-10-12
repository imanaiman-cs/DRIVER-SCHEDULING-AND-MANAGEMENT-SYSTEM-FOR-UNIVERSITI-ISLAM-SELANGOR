<?php /** @var bool $isAdmin @var bool $showExtras @var array $vehicles */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0">Calendar</h1>
    <div class="d-flex flex-wrap align-items-center gap-3 small">
        <?php if ($isAdmin): ?>
        <select class="form-select form-select-sm w-auto" id="cal-vehicle" aria-label="Filter by vehicle">
            <option value="0">All vehicles</option>
            <?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['reg_no'] . ' - ' . $v['brand'] . ' ' . $v['model']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($showExtras): ?>
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="cal-tasks" checked><label class="form-check-label" for="cal-tasks">Internal tasks</label></div>
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="cal-welfare" checked><label class="form-check-label" for="cal-welfare">Welfare duty</label></div>
        <?php endif; ?>
    </div>
</div>
<div class="d-flex flex-wrap gap-2 small mb-2 cal-legend">
    <span class="ev-legend ev-pending-approval">Pending approval</span><span class="ev-legend ev-approved">Approved</span>
    <span class="ev-legend ev-driver-assigned">Driver assigned</span><span class="ev-legend ev-confirmed">Confirmed</span>
    <span class="ev-legend ev-completed">Completed</span><?php if ($showExtras): ?><span class="ev-legend ev-internal">Internal task</span><span class="ev-legend ev-welfare">Welfare duty</span><?php endif; ?>
</div>
<div class="card"><div class="card-body p-2 p-md-3">
    <div id="calendar" data-feed="<?= e(base_url() . '/index.php') ?>"></div>
</div></div>
<noscript><p class="text-muted small mt-2">The calendar needs JavaScript. You can still see your requests under "My requests".</p></noscript>
