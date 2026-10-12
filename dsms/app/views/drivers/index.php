<?php /** @var array $rows */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Drivers</h1>
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('users/create')) ?>">Add driver account</a>
</div>
<p class="text-muted small">Driver accounts are created under Users (role = driver). Here you set the group, licence classes and leave.
    Management drivers are never recommended for normal requests.</p>
<div class="card"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Staff ID</th><th>Name</th><th>Group</th><th>Licences</th><th>Phone</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
                <td><?= e($r['staff_id']) ?></td>
                <td><?= e($r['name']) ?></td>
                <td><span class="badge text-bg-<?= $r['driver_group'] === 'management' ? 'dark' : 'secondary' ?>"><?= e(Driver::GROUPS[$r['driver_group']]) ?></span></td>
                <td><?= $r['licences'] ? e($r['licences']) : '<span class="text-danger">none set</span>' ?></td>
                <td><?= e($r['phone'] ?? '-') ?></td>
                <td>
                    <?php if (!$r['is_active']): ?><span class="badge text-bg-light border">Inactive</span>
                    <?php elseif ($r['on_leave_today']): ?><span class="badge text-bg-warning">On leave today</span>
                    <?php else: ?><span class="badge text-bg-light border">Active</span><?php endif; ?>
                </td>
                <td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= e(url('drivers/edit', ['id' => $r['id']])) ?>">Manage</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">No drivers yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
