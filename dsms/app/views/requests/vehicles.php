<?php /** @var array $trip @var array $vehicles @var array $query */
$step = 2;
$free = count(array_filter($vehicles, fn($v) => $v['state'] === 'available'));
?>
<h1 class="h4 mb-3">Choose a vehicle</h1>
<?php include __DIR__ . '/_stepper.php'; ?>
<div class="card mb-3"><div class="card-body py-2 small d-flex flex-wrap justify-content-between gap-2">
    <span><strong>From</strong> <?= e(fmt_datetime($trip['departure']->format('Y-m-d H:i:s'))) ?>
          <strong>to</strong> <?= e(fmt_datetime($trip['return']->format('Y-m-d H:i:s'))) ?>
          &middot; <?= (int)$trip['passengers'] ?> passenger(s)</span>
    <a href="<?= e(url('requests/create', $query)) ?>">Change dates</a>
</div></div>
<?php if ($free === 0): ?>
    <div class="alert alert-warning">No vehicle is free for these dates and this number of passengers. Try other dates, or contact the Vehicle Section.</div>
<?php endif; ?>
<div class="row g-3">
<?php foreach ($vehicles as $v): $ok = $v['state'] === 'available'; ?>
    <div class="col-sm-6 col-lg-4 col-xl-3">
        <div class="card h-100 vehicle-card <?= $ok ? '' : 'unavailable' ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start">
                    <div><div class="fw-semibold"><?= e($v['reg_no']) ?></div><div class="small text-muted"><?= e($v['brand'] . ' ' . $v['model']) ?></div></div>
                    <span class="badge text-bg-light border"><?= e($v['vehicle_type']) ?></span>
                </div>
                <div class="small my-2"><?= (int)$v['capacity'] ?> seat(s) &middot; licence <?= e($v['required_licence']) ?></div>
                <div class="mt-auto">
                <?php if ($ok): ?>
                    <a class="btn btn-dark btn-sm w-100" href="<?= e(url('requests/details', $query + ['vehicle_id' => $v['id']])) ?>">Select this vehicle</a>
                <?php else: ?>
                    <div class="small text-muted">Not available: <?= e($v['note']) ?></div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<p class="small text-muted mt-3">Vehicles are booked by whole days. Vehicles that are inactive are not listed.</p>
