<?php /** @var array $v @var array $errors @var int $minDays @var string $earliest */
$step = 1;
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<h1 class="h4 mb-3">New vehicle request</h1>
<?php include __DIR__ . '/_stepper.php'; ?>
<div class="alert alert-light border small">
    <strong>Two-day rule:</strong> a request must be made at least <?= (int)$minDays ?> days before the trip.
    For an urgent trip, please submit the paper form <strong><?= e(RequestRules::URGENT_FORM) ?></strong> to the Vehicle Section.
    One request is for one trip and one vehicle.
</div>
<form method="get" action="<?= e(base_url() . '/index.php') ?>" class="card"><div class="card-body">
    <input type="hidden" name="page" value="requests/vehicles">
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label small" for="dep_date">Departure date</label>
            <input type="date" class="form-control" id="dep_date" name="dep_date" min="<?= e($earliest) ?>" value="<?= old($v, 'dep_date') ?>" required><?= $err('dep_date') ?></div>
        <div class="col-md-3"><label class="form-label small" for="dep_time">Departure time</label>
            <input type="time" class="form-control" id="dep_time" name="dep_time" value="<?= old($v, 'dep_time', '08:00') ?>" required></div>
        <div class="col-md-3"><label class="form-label small" for="ret_date">Return date</label>
            <input type="date" class="form-control" id="ret_date" name="ret_date" min="<?= e($earliest) ?>" value="<?= old($v, 'ret_date') ?>" required><?= $err('ret_date') ?></div>
        <div class="col-md-3"><label class="form-label small" for="ret_time">Return time</label>
            <input type="time" class="form-control" id="ret_time" name="ret_time" value="<?= old($v, 'ret_time', '17:00') ?>" required></div>
        <div class="col-md-3"><label class="form-label small" for="passengers">Number of passengers</label>
            <input type="number" class="form-control" id="passengers" name="passengers" min="1" max="100" value="<?= old($v, 'passengers') ?>" required><?= $err('passengers') ?></div>
    </div>
</div>
<div class="card-footer bg-white d-flex justify-content-between">
    <a class="btn btn-link" href="<?= e(url('home/index')) ?>">Cancel</a>
    <button class="btn btn-dark" type="submit">Check available vehicles &rarr;</button>
</div></form>
