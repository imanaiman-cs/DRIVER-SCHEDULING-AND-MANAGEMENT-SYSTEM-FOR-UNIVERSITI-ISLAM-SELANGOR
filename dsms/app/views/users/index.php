<?php /** @var array $rows @var string $q @var string $role @var string $status */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h4 mb-0">Users</h1>
    <a class="btn btn-dark btn-sm" href="<?= e(url('users/create')) ?>">+ New user</a>
</div>
<form class="row g-2 mb-3" method="get" action="<?= e(url('users/index')) ?>">
    <input type="hidden" name="page" value="users/index">
    <div class="col-md-4"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search name, staff ID or e-mail"></div>
    <div class="col-md-3"><select class="form-select form-select-sm" name="role">
        <option value="">All roles</option>
        <?php foreach (Auth::ROLES as $r): ?><option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-md-3"><select class="form-select form-select-sm" name="status">
        <option value="">Any status</option>
        <?php foreach (['active' => 'Active', 'locked' => 'Locked', 'inactive' => 'Inactive'] as $k => $l): ?>
            <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-dark w-100">Filter</button></div>
</form>
<div class="card"><div class="table-responsive">
<table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>Staff ID</th><th>Name</th><th>Role</th><th>PTJ</th><th>Approver</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
        <tr>
            <td><code><?= e($u['staff_id']) ?></code></td>
            <td><?= e($u['name']) ?><div class="small text-muted"><?= e($u['email']) ?></div></td>
            <td><?= e(role_label($u['role'])) ?></td>
            <td><?= e($u['ptj_code'] ?? '-') ?></td>
            <td><?= e($u['approver_name'] ?? '-') ?></td>
            <td>
                <?php if (!$u['is_active']): ?><span class="badge text-bg-light border">Inactive</span>
                <?php elseif ($u['is_locked']): ?><span class="badge text-bg-dark">Locked</span>
                <?php else: ?><span class="badge text-bg-secondary">Active</span><?php endif; ?>
                <?php if ($u['must_change_password']): ?><span class="badge text-bg-light border" title="Has not changed the temporary password yet">Temp password</span><?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-dark" href="<?= e(url('users/edit', ['id' => $u['id']])) ?>">Edit</a>
                <?php if ($u['is_locked']): ?>
                    <form class="d-inline" method="post" action="<?= e(url('users/unlock')) ?>"><?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn btn-sm btn-dark">Unlock</button></form>
                <?php endif; ?>
                <form class="d-inline" method="post" action="<?= e(url('users/reset_password')) ?>"><?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" data-confirm="Reset the password of <?= e($u['name']) ?>? A new temporary password will be shown once.">Reset password</button></form>
                <?php if ((int)$u['id'] !== Auth::id()): ?>
                <form class="d-inline" method="post" action="<?= e(url('users/toggle')) ?>"><?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" data-confirm="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this account?"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
