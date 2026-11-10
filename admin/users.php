<?php
// ============================================================
// admin/users.php  -  User accounts (SRS summary of 10 Nov 2026): list, search, make Inactive / Active again.
// Administrative Assistant and Head of Admin. Accounts are never deleted. Only the Head of Admin changes
// Administrative Assistant and Head of Admin accounts.
// ============================================================
$current_page = 'users.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$me = (int)$_SESSION['user_id'];
$actorRole = (string)$_SESSION['role'];

/** May the signed-in person change this account? */
function canManageUser(array $u, string $actorRole, int $me): bool
{
    return $actorRole === 'head_admin' || !in_array($u['role'], ['admin', 'head_admin'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int)($_POST['user_id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $stmt = $conn->prepare('SELECT u.*, (SELECT COUNT(*) FROM drivers d WHERE d.driver_id = u.driver_id AND d.status = \'active\') AS active_driver FROM users u WHERE u.user_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u || !in_array($act, ['deactivate', 'activate'], true)) {
        setFlash('danger', 'That account was not found.');
    } elseif (!canManageUser($u, $actorRole, $me)) {
        setFlash('danger', 'Only the Head of Admin can change an Administrative Assistant or Head of Admin account.');
    } elseif ($id === $me) {
        setFlash('danger', 'You cannot make your own account Inactive.');
    } elseif ($act === 'deactivate') {
        if ((int)$u['is_active'] === 0) {
            setFlash('warning', $u['full_name'] . ' is already Inactive.');
        } elseif ((int)$u['active_driver'] > 0) {
            setFlash('danger', $u['full_name'] . ' is an active driver. Make the driver Inactive first (Drivers page).');
        } else {
            $other = $u['role'] === 'supervisor' ? approverIdForDepartment($conn, $u['department'], 'staff', $id) : 1;
            if ($u['role'] === 'supervisor' && $other === null && pendingRequestsOfApprover($conn, $id) > 0) {
                setFlash('danger', $u['full_name'] . ' has Pending requests to approve and no other Head of Department is active in ' . $u['department'] . '. Add another Head of Department first.');
            } else {
                $stmt = $conn->prepare('UPDATE users SET is_active = 0 WHERE user_id = ?');
                $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
                if ($u['role'] === 'supervisor') { refreshApprovers($conn, $u['department']); }
                auditLog($conn, 'user_deactivate', null, $u['full_name'] . ' (' . $u['username'] . ') made Inactive');
                setFlash('success', $u['full_name'] . ' is now Inactive and cannot sign in. The history is kept.');
            }
        }
    } else {
        $stmt = $conn->prepare('UPDATE users SET is_active = 1, failed_attempts = 0, locked_until = NULL WHERE user_id = ?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        if ($u['role'] === 'supervisor') { refreshApprovers($conn, $u['department']); }
        auditLog($conn, 'user_activate', null, $u['full_name'] . ' (' . $u['username'] . ') made Active again');
        setFlash('success', $u['full_name'] . ' is Active again.');
    }
    header('Location: users.php' . (!empty($_POST['back']) && preg_match('/^[a-z0-9=&_%.\-+ ]*$/i', $_POST['back']) ? '?' . $_POST['back'] : ''));
    exit();
}

$q      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$role   = isset(ACCOUNT_ROLES[$_GET['role'] ?? '']) ? $_GET['role'] : '';
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$sql = 'SELECT u.*, a.full_name AS approver_name FROM users u LEFT JOIN users a ON a.user_id = u.supervisor_id WHERE 1=1';
$types = ''; $args = [];
if ($q !== '') { $sql .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.department LIKE ?)'; $like = '%' . $q . '%'; $types .= 'ssss'; array_push($args, $like, $like, $like, $like); }
if ($role !== '')   { $sql .= ' AND u.role = ?'; $types .= 's'; $args[] = $role; }
if ($status !== '') { $sql .= ' AND u.is_active = ?'; $types .= 'i'; $args[] = $status === 'active' ? 1 : 0; }
$sql .= ' ORDER BY u.is_active DESC, u.full_name';
$stmt = $conn->prepare($sql);
if ($args) { $stmt->bind_param($types, ...$args); }
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$back = http_build_query(array_filter(['q' => $q, 'role' => $role, 'status' => $status]));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

adminPageStart('Users');
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <h4 class="fw-bold mb-0"><i class="fas fa-users text-success me-2"></i>User accounts</h4>
        <a href="add_user.php" class="btn btn-success btn-sm"><i class="fas fa-user-plus me-1"></i>Add account</a>
    </div>
    <p class="text-muted small">Staff register their own account. Here you create driver and other accounts, change details, reset a password and make an account Inactive. Accounts are never deleted. After you create a driver account, register it as a driver in <a href="add_driver.php">Add Driver</a>.</p>
    <form method="get" class="row g-2 mb-3">
        <div class="col-12 col-md-4"><input class="form-control form-control-sm" name="q" value="<?= $h($q) ?>" placeholder="Name, staff ID, e-mail or PTJ" aria-label="Search"></div>
        <div class="col-6 col-md-3"><select class="form-select form-select-sm" name="role" aria-label="Role"><option value="">All roles</option>
            <?php foreach (ACCOUNT_ROLES as $k => $lbl): ?><option value="<?= $k ?>" <?= $role === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">All</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
        <div class="col-12 col-md-2"><button class="btn btn-sm btn-success w-100" type="submit"><i class="fas fa-search me-1"></i>Search</button></div>
    </form>
    <div class="card border-0 shadow-sm"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Name</th><th>Staff ID</th><th>Role</th><th>PTJ</th><th>Approver</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $manage = canManageUser($u, $actorRole, $me); ?>
                <tr>
                    <td><strong><?= $h($u['full_name']) ?></strong><div class="small text-muted"><?= $h($u['email']) ?><?= $u['phone'] ? ' &middot; ' . $h($u['phone']) : '' ?></div></td>
                    <td class="text-nowrap"><?= $h($u['username']) ?></td>
                    <td><?= $h(ACCOUNT_ROLES[$u['role']] ?? $u['role']) ?></td>
                    <td><?= $h($u['department'] ?? '-') ?></td>
                    <td class="small"><?= $h($u['approver_name'] ?? '-') ?></td>
                    <td><span class="badge <?= (int)$u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= (int)$u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-end text-nowrap">
                        <?php if ($manage): ?>
                            <a class="btn btn-sm btn-outline-success" href="edit_user.php?id=<?= (int)$u['user_id'] ?>"><i class="fas fa-pen"></i><span class="d-none d-md-inline ms-1">Edit</span></a>
                            <?php if ((int)$u['user_id'] !== $me): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"><input type="hidden" name="back" value="<?= $h($back) ?>">
                                <?php if ((int)$u['is_active']): ?>
                                    <button class="btn btn-sm btn-outline-danger" name="action" value="deactivate" data-confirm="<?= $h($u['full_name']) ?> will not be able to sign in. The history is kept." data-confirm-title="Make this account Inactive?" data-confirm-button="Make Inactive" data-confirm-tone="danger"><i class="fas fa-user-slash"></i><span class="d-none d-md-inline ms-1">Deactivate</span></button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-success" name="action" value="activate"><i class="fas fa-user-check"></i><span class="d-none d-md-inline ms-1">Activate</span></button>
                                <?php endif; ?>
                            </form>
                            <?php endif; ?>
                        <?php else: ?><span class="small text-muted">Head of Admin only</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?><tr><td colspan="7" class="text-center text-muted py-4">No account found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
    <p class="text-muted small mt-2"><?= count($users) ?> account(s)</p>
<?php adminPageEnd(); ?>
