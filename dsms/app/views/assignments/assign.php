<?php /** @var array $r @var array $legs @var array $recs @var array $errors @var array $warnings @var array $choices */ ?>
<a class="small" href="<?= e(url('assignments/index')) ?>">&larr; Requests needing a driver</a>
<h1 class="h4 my-2">Assign driver</h1>
<?php include __DIR__ . '/_summary.php'; ?>
<?php foreach ($errors as $m): ?><div class="alert alert-danger py-2"><?= e($m) ?></div><?php endforeach; ?>
<?php if ($warnings): ?>
    <div class="alert alert-warning"><strong>Please check:</strong>
        <ul class="mb-2"><?php foreach ($warnings as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_clash" value="1" id="confirm_clash" form="assign-form">
            <label class="form-check-label" for="confirm_clash">Assign anyway (I know about the clash)</label></div>
    </div>
<?php endif; ?>
<form method="post" action="<?= e(url('assignments/store')) ?>" id="assign-form">
    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <?php foreach ($legs as $leg): ?>
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6"><?= e(ucfirst($leg['leg_type'])) ?> leg <span class="text-muted fw-normal">&middot; <?= e(fmt_datetime($leg['start_datetime'])) ?> - <?= e(fmt_time($leg['end_datetime'])) ?></span></h2>
            <?php
            $rec = $recs[(int)$leg['id']]; $inputName = 'driver[' . (int)$leg['id'] . ']';
            $selected = (int)($choices[$leg['id']] ?? 0); $currentDriverId = 0;
            include __DIR__ . '/_candidates.php';
            ?>
        </div></div>
    <?php endforeach; ?>
    <div class="d-flex gap-2">
        <button class="btn btn-dark" type="submit">Assign drivers</button>
        <a class="btn btn-link" href="<?= e(url('assignments/index')) ?>">Back</a>
    </div>
</form>
