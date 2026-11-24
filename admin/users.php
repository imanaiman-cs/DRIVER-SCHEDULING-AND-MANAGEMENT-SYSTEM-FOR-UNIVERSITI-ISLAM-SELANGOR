<?php
// ============================================================
// admin/users.php  -  UC001 Manage Staff and Driver Account
//   UC001B list (staff ID, name, department, role, status), search by staff ID or name, filter by role
//   UC001A Create Driver (add_driver.php)       UC001C Edit (edit_user.php)
//   UC001C A1 Pending Role: confirm or reject a Head of Department role
//   UC001D Deactivate (confirmation; a driver with upcoming tasks or a Head of Department with pending requests
//          is refused until they are handled). An account is never deleted.
// ============================================================
$current_page = 'users.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$tab = ($_GET['tab'] ?? '') === 'pending' ? 'pending' : 'all';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $act = $_POST['action'] ?? '';
    $res = match ($act) {
        'deactivate' => setAccountActive($conn, $uid, false),
        'activate'   => setAccountActive($conn, $uid, true),
        'confirm_role' => decidePendingRole($conn, $uid, true),
        'reject_role'  => decidePendingRole($conn, $uid, false),
        default => wfFail('Unknown action.'),
    };
    redirectWithResult($res, $act === 'deactivate' && !$res['ok'] ? 'view_user.php?id=' . $uid : 'users.php' . ($tab === 'pending' ? '?tab=pending' : ''));
}

$q = trim((string)($_GET['q'] ?? ''));
$role = in_array($_GET['role'] ?? '', ['staff', 'supervisor', 'driver', 'admin', 'head_admin'], true) ? $_GET['role'] : '';
$w = []; $p = [];
if ($tab === 'pending') { $w[] = "u.pending_role IS NOT NULL AND u.status = 'active'"; }
if ($q !== '') { $w[] = '(u.staff_no LIKE ? OR u.full_name LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
if ($role) { $w[] = 'u.role = ?'; $p[] = $role; }
$W = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$total = (int)dbValue($conn, "SELECT COUNT(*) FROM users u $W", $p);
$pg = paginate($total, max(1, (int)($_GET['page'] ?? 1)), 20);
$rows = dbAll($conn, "SELECT u.*, d.department_name FROM users u LEFT JOIN departments d ON d.department_id = u.department_id $W ORDER BY u.status, u.full_name LIMIT ? OFFSET ?", array_merge($p, [$pg['rows_per_page'], $pg['offset']]));
$pendingCount = (int)dbValue($conn, "SELECT COUNT(*) FROM users WHERE pending_role IS NOT NULL AND status = 'active'");

adminPageStart('User Account');
pageHeader('User Account', 'users', 'Staff and Heads of Department register themselves; drivers are created here. Accounts are never deleted: deactivate them.', '<a class="btn btn-primary" href="add_driver.php"><i class="fas fa-user-plus"></i>Create Driver</a>');
echo tabsNav(['all' => 'All users', 'pending' => 'Pending Role'], $tab, fn($k) => '?tab=' . $k, ['pending' => $pendingCount]);
?>
<form method="get" class="mb-4 flex flex-wrap items-end gap-2">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div><label class="mb-1 block text-xs font-medium" for="q">Search</label><input type="search" class="input input-sm w-56" id="q" name="q" value="<?= h($q) ?>" placeholder="Staff ID or name"></div>
    <div><label class="mb-1 block text-xs font-medium" for="role">Role</label><select class="select select-sm" id="role" name="role"><option value="">All roles</option>
        <?php foreach (['staff', 'supervisor', 'driver', 'admin', 'head_admin'] as $r): ?><option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= h(roleLabel($r)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-magnifying-glass"></i>Search</button><a class="btn btn-sm btn-ghost" href="?tab=<?= h($tab) ?>">Reset</a>
</form>
<div class="card border border-base-300 bg-base-100"><div class="overflow-x-auto"><table class="table [&_tbody_tr:hover]:bg-base-200/60">
<thead><tr><th>Staff ID</th><th>Name</th><th>Department</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($rows as $u): $uid = (int)$u['user_id']; ?>
<tr>
    <td class="font-mono text-sm"><?= h($u['staff_no']) ?></td>
    <td><a class="link-hover font-medium" href="view_user.php?id=<?= $uid ?>"><?= h($u['full_name']) ?></a><div class="text-xs text-base-content/60"><?= h($u['email']) ?></div></td>
    <td><?= h($u['department_name'] ?? '-') ?></td>
    <td><?= h(roleLabel($u['role'])) ?><?php if ($u['pending_role']): ?><div><span class="badge badge-warning badge-sm">Asks: Head of Department</span></div><?php endif; ?></td>
    <td><?= userStatusBadge($u['status']) ?></td>
    <td class="text-end whitespace-nowrap">
        <?php if ($tab === 'pending'): ?>
            <form method="post" class="inline-flex gap-1"><input type="hidden" name="user_id" value="<?= $uid ?>">
                <button class="btn btn-sm btn-success" name="action" value="confirm_role" type="submit" data-confirm="<?= h($u['full_name']) ?> becomes the Head of Department of <?= h($u['department_name']) ?> and can approve its requests." data-confirm-title="Confirm the role?" data-confirm-button="Confirm"><i class="fas fa-check"></i>Confirm</button>
                <button class="btn btn-sm btn-outline btn-error" name="action" value="reject_role" type="submit" data-confirm="The user stays as Staff." data-confirm-title="Reject the role?" data-confirm-button="Reject" data-confirm-tone="danger"><i class="fas fa-xmark"></i>Reject</button></form>
        <?php else: ?>
            <a class="btn btn-sm btn-outline" href="view_user.php?id=<?= $uid ?>">View</a>
            <a class="btn btn-sm btn-outline" href="edit_user.php?id=<?= $uid ?>"><i class="fas fa-pen"></i><span class="hidden md:inline">Edit</span></a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><?= emptyRow(6, $tab === 'pending' ? 'No user is waiting for a Head of Department confirmation.' : 'No record is found.') ?><?php endif; ?>
</tbody></table></div></div>
<?= pagerHtml($pg, fn($i) => '?' . http_build_query(array_filter(['tab' => $tab, 'q' => $q, 'role' => $role, 'page' => $i]))) ?>
<?php adminPageEnd(); ?>
