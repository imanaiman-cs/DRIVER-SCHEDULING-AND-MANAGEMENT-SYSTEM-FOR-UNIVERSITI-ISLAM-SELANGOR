<?php /** @var array $v @var array $errors @var array $warnings @var array $drivers @var array $vehicles */
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<a class="small" href="<?= e(url('internal_tasks/index')) ?>">&larr; Internal tasks</a>
<h1 class="h4 my-2">New internal task</h1>
<?php if ($warnings): ?>
    <div class="alert alert-warning"><strong>Please check:</strong>
        <ul class="mb-2"><?php foreach ($warnings as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_clash" value="1" id="confirm_clash" form="task-form">
            <label class="form-check-label" for="confirm_clash">Save anyway</label></div>
    </div>
<?php endif; ?>
<form method="post" action="<?= e(url('internal_tasks/store')) ?>" id="task-form" class="card"><div class="card-body">
    <?= Csrf::field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label small" for="task_type">Task</label>
            <select class="form-select" id="task_type" name="task_type" required>
                <?php foreach (DriverTask::TYPES as $k => $label): ?><option value="<?= e($k) ?>" <?= ($v['task_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select><?= $err('task_type') ?></div>
        <div class="col-md-6"><label class="form-label small" for="driver_id">Driver</label>
            <select class="form-select" id="driver_id" name="driver_id" required><option value="">Choose...</option>
                <?php foreach ($drivers as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)($v['driver_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select><?= $err('driver_id') ?></div>
        <div class="col-md-4"><label class="form-label small" for="task_date">Date</label>
            <input type="date" class="form-control" id="task_date" name="task_date" value="<?= old($v, 'task_date') ?>" required><?= $err('task_date') ?></div>
        <div class="col-md-4"><label class="form-label small" for="start_time">From</label>
            <input type="time" class="form-control" id="start_time" name="start_time" value="<?= old($v, 'start_time') ?>" required><?= $err('start_time') ?></div>
        <div class="col-md-4"><label class="form-label small" for="end_time">To</label>
            <input type="time" class="form-control" id="end_time" name="end_time" value="<?= old($v, 'end_time') ?>" required><?= $err('end_time') ?></div>
        <div class="col-md-6"><label class="form-label small" for="vehicle_id">Vehicle (optional)</label>
            <select class="form-select" id="vehicle_id" name="vehicle_id"><option value="">None</option>
                <?php foreach ($vehicles as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)($v['vehicle_id'] ?? 0) === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['reg_no'] . ' - ' . $x['brand'] . ' ' . $x['model']) ?></option><?php endforeach; ?>
            </select><?= $err('vehicle_id') ?></div>
        <div class="col-md-6"><label class="form-label small" for="location">Location (optional)</label>
            <input class="form-control" id="location" name="location" maxlength="150" value="<?= old($v, 'location') ?>"></div>
        <div class="col-12"><label class="form-label small" for="description">Note (optional)</label>
            <input class="form-control" id="description" name="description" maxlength="255" value="<?= old($v, 'description') ?>"></div>
    </div>
</div>
<div class="card-footer bg-white"><button class="btn btn-dark" type="submit">Save task</button> <a class="btn btn-link" href="<?= e(url('internal_tasks/index')) ?>">Cancel</a></div></form>
