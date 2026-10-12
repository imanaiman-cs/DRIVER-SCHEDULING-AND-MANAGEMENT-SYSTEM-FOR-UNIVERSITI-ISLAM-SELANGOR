<?php /** @var ?array $vehicle @var array $v @var array $errors @var array $maintenance */
$f = fn(string $k, $d = '') => e($v[$k] ?? $d);
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<a class="small" href="<?= e(url('vehicles/index')) ?>">&larr; All vehicles</a>
<h1 class="h4 my-2"><?= $vehicle ? e($vehicle['reg_no']) : 'Add vehicle' ?></h1>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card"><div class="card-body">
            <form method="post" action="<?= e(url('vehicles/save')) ?>" class="row g-2">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)($vehicle['id'] ?? 0) ?>">
                <div class="col-sm-6"><label class="form-label small" for="reg_no">Registration no.</label>
                    <input class="form-control" id="reg_no" name="reg_no" maxlength="20" value="<?= $f('reg_no') ?>" required><?= $err('reg_no') ?></div>
                <div class="col-sm-6"><label class="form-label small" for="vehicle_type">Type</label>
                    <select class="form-select" id="vehicle_type" name="vehicle_type">
                        <?php foreach (Vehicle::TYPES as $t): ?><option <?= ($v['vehicle_type'] ?? 'Sedan') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                    </select><?= $err('vehicle_type') ?>
                    <div class="form-text">Licence needed: Bus = PSV, Lorry = GDL, others = D (set automatically).</div></div>
                <div class="col-sm-6"><label class="form-label small" for="brand">Brand</label>
                    <input class="form-control" id="brand" name="brand" maxlength="60" value="<?= $f('brand') ?>" required><?= $err('brand') ?></div>
                <div class="col-sm-6"><label class="form-label small" for="model">Model</label>
                    <input class="form-control" id="model" name="model" maxlength="60" value="<?= $f('model') ?>" required><?= $err('model') ?></div>
                <div class="col-sm-4"><label class="form-label small" for="capacity">Seats</label>
                    <input type="number" class="form-control" id="capacity" name="capacity" min="1" max="100" value="<?= $f('capacity') ?>" required><?= $err('capacity') ?></div>
                <div class="col-sm-4"><label class="form-label small" for="road_tax_expiry">Road tax expiry</label>
                    <input type="date" class="form-control" id="road_tax_expiry" name="road_tax_expiry" value="<?= $f('road_tax_expiry') ?>"><?= $err('road_tax_expiry') ?></div>
                <div class="col-sm-4"><label class="form-label small" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option <?= ($v['status'] ?? 'Available') === 'Available' ? 'selected' : '' ?>>Available</option>
                        <option <?= ($v['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select></div>
                <div class="col-12"><button class="btn btn-dark btn-sm" type="submit">Save</button></div>
            </form>
        </div></div>
    </div>
    <?php if ($vehicle): ?>
    <div class="col-lg-6">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Add maintenance period</h2>
            <form method="post" action="<?= e(url('vehicles/add_maintenance')) ?>" class="row g-2">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
                <div class="col-sm-6"><label class="form-label small" for="m_start">From</label><input type="date" class="form-control" id="m_start" name="start_date" required></div>
                <div class="col-sm-6"><label class="form-label small" for="m_end">To</label><input type="date" class="form-control" id="m_end" name="end_date" required></div>
                <div class="col-sm-6"><label class="form-label small" for="workshop">Workshop</label><input class="form-control" id="workshop" name="workshop" maxlength="120"></div>
                <div class="col-sm-6"><label class="form-label small" for="m_note">Note</label><input class="form-control" id="m_note" name="note" maxlength="255"></div>
                <div class="col-12"><button class="btn btn-dark btn-sm" type="submit">Add</button></div>
            </form>
        </div></div>
        <div class="card"><div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>From</th><th>To</th><th>Workshop</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($maintenance as $m): ?>
                    <tr>
                        <td><?= e(fmt_date($m['start_date'])) ?></td><td><?= e(fmt_date($m['end_date'])) ?></td><td><?= e($m['workshop'] ?? '') ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= e(url('vehicles/delete_maintenance')) ?>" class="d-inline">
                                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>"><input type="hidden" name="maintenance_id" value="<?= (int)$m['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" data-confirm="Remove this maintenance period?">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$maintenance): ?><tr><td colspan="4" class="text-center text-muted py-3">No maintenance recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <?php endif; ?>
</div>
