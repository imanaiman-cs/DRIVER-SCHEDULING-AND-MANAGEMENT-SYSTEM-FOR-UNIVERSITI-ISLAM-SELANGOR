<?php /** @var array $driver @var array $licences @var array $leaves */ ?>
<a class="small" href="<?= e(url('drivers/index')) ?>">&larr; All drivers</a>
<h1 class="h4 my-2"><?= e($driver['name']) ?> <small class="text-muted fs-6"><?= e($driver['staff_id']) ?> &middot; <?= e($driver['phone'] ?? 'no phone') ?></small></h1>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <h2 class="h6">Group and licences</h2>
            <form method="post" action="<?= e(url('drivers/save')) ?>">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$driver['id'] ?>">
                <div class="mb-3">
                    <label class="form-label small" for="driver_group">Group</label>
                    <select class="form-select" id="driver_group" name="driver_group">
                        <?php foreach (Driver::GROUPS as $k => $label): ?>
                            <option value="<?= e($k) ?>" <?= $driver['driver_group'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Management drivers are reserved for top management and excluded from recommendations (R8).</div>
                </div>
                <div class="mb-3">
                    <div class="form-label small">Licence classes</div>
                    <?php foreach (Driver::LICENCES as $c): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="licences[]" id="lic<?= e($c) ?>" value="<?= e($c) ?>" <?= in_array($c, $licences, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="lic<?= e($c) ?>"><?= e($c) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">D = cars/vans, GDL = lorries, PSV = buses. A driver without the right class is blocked from that vehicle (R5).</div>
                </div>
                <button class="btn btn-dark btn-sm" type="submit">Save</button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-body">
            <h2 class="h6">Add leave / MC</h2>
            <form method="post" action="<?= e(url('drivers/add_leave')) ?>" class="row g-2">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$driver['id'] ?>">
                <div class="col-sm-4"><label class="form-label small" for="start_date">From</label><input type="date" class="form-control" id="start_date" name="start_date" required></div>
                <div class="col-sm-4"><label class="form-label small" for="end_date">To</label><input type="date" class="form-control" id="end_date" name="end_date" required></div>
                <div class="col-sm-4"><label class="form-label small" for="type">Type</label>
                    <select class="form-select" id="type" name="type"><option value="leave">Leave</option><option value="mc">MC</option></select></div>
                <div class="col-12"><label class="form-label small" for="note">Note (optional)</label><input class="form-control" id="note" name="note" maxlength="255"></div>
                <div class="col-12"><button class="btn btn-dark btn-sm" type="submit">Add</button></div>
            </form>
        </div></div>
        <div class="card"><div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>From</th><th>To</th><th>Type</th><th>Note</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($leaves as $l): ?>
                    <tr>
                        <td><?= e(fmt_date($l['start_date'])) ?></td><td><?= e(fmt_date($l['end_date'])) ?></td>
                        <td><?= $l['type'] === 'mc' ? 'MC' : 'Leave' ?></td><td><?= e($l['note'] ?? '') ?></td>
                        <td class="text-end">
                            <form method="post" action="<?= e(url('drivers/delete_leave')) ?>" class="d-inline">
                                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$driver['id'] ?>"><input type="hidden" name="leave_id" value="<?= (int)$l['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" data-confirm="Remove this leave record?">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$leaves): ?><tr><td colspan="5" class="text-center text-muted py-3">No leave recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>
