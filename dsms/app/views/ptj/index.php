<?php /** @var array $rows @var ?array $edit */ ?>
<h1 class="h4 mb-3">Departments (PTJ)</h1>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card"><div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Code</th><th>Name</th><th class="text-end">Users</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code><?= e($r['code']) ?></code></td><td><?= e($r['name']) ?></td>
                        <td class="text-end"><?= (int)$r['user_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-dark" href="<?= e(url('ptj/index', ['edit' => $r['id']])) ?>">Edit</a>
                            <?php if ((int)$r['user_count'] === 0): ?>
                            <form class="d-inline" method="post" action="<?= e(url('ptj/delete')) ?>">
                                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" data-confirm="Delete this department?">Delete</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <h2 class="h6"><?= $edit ? 'Edit department' : 'Add department' ?></h2>
            <form method="post" action="<?= e(url('ptj/save')) ?>">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
                <div class="mb-2"><label class="form-label small" for="code">Code</label>
                    <input class="form-control" id="code" name="code" maxlength="20" value="<?= e($edit['code'] ?? '') ?>" required></div>
                <div class="mb-3"><label class="form-label small" for="name">Name</label>
                    <input class="form-control" id="name" name="name" maxlength="150" value="<?= e($edit['name'] ?? '') ?>" required></div>
                <button class="btn btn-dark btn-sm" type="submit">Save</button>
                <?php if ($edit): ?><a class="btn btn-link btn-sm" href="<?= e(url('ptj/index')) ?>">Cancel</a><?php endif; ?>
            </form>
        </div></div>
    </div>
</div>
