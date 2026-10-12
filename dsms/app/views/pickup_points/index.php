<?php /** @var array $rows @var ?array $edit */ ?>
<h1 class="h4 mb-3">Pickup points</h1>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card"><div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Name</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['name']) ?></td>
                        <td><?= $r['is_active'] ? 'Active' : '<span class="text-muted">Inactive</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-dark" href="<?= e(url('pickup_points/index', ['edit' => $r['id']])) ?>">Rename</a>
                            <form class="d-inline" method="post" action="<?= e(url('pickup_points/toggle')) ?>">
                                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <h2 class="h6"><?= $edit ? 'Rename pickup point' : 'Add pickup point' ?></h2>
            <form method="post" action="<?= e(url('pickup_points/save')) ?>">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div class="mb-3"><input class="form-control" name="name" maxlength="120" value="<?= e($edit['name'] ?? '') ?>" required></div>
                <button class="btn btn-dark btn-sm" type="submit">Save</button>
                <?php if ($edit): ?><a class="btn btn-link btn-sm" href="<?= e(url('pickup_points/index')) ?>">Cancel</a><?php endif; ?>
            </form>
        </div></div>
    </div>
</div>
