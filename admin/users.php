<?php
// ============================================================
// UIS Driver Scheduling and Management System
// admin/users.php  –  user accounts (create, edit, reset password, unlock, deactivate)
//
// New accounts and reset passwords get a random temporary password that is shown once;
// the user must change it at first sign-in. Nothing is ever deleted: an account that is no
// longer needed is deactivated, so old requests and the audit log keep their names.
// ============================================================
$current_page = 'users.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

// The five roles. Every role is also staff (can request a vehicle); the extra rights come with the role.
const USER_ROLES = ['staff' => 'Staff', 'driver' => 'Driver', 'supervisor' => 'Head of Section',
                    'admin' => 'Admin Assistant', 'head_admin' => 'Head of Admin'];

$me     = (int)$_SESSION['user_id'];
$errors = [];
$form   = ['username' => '', 'full_name' => '', 'email' => '', 'phone' => '', 'role' => 'staff', 'department' => '', 'supervisor_id' => '', 'driver_id' => ''];

/** Validate the account fields; returns [clean values, errors]. $id = 0 for a new account. */
function validateUserForm(mysqli $conn, array $in, int $id): array
{
    $v = [
        'username'      => trim((string)($in['username'] ?? '')),
        'full_name'     => trim((string)($in['full_name'] ?? '')),
        'email'         => trim((string)($in['email'] ?? '')),
        'phone'         => trim((string)($in['phone'] ?? '')),
        'role'          => (string)($in['role'] ?? ''),
        'department'    => trim((string)($in['department'] ?? '')),
        'supervisor_id' => (int)($in['supervisor_id'] ?? 0),
        'driver_id'     => (int)($in['driver_id'] ?? 0),
    ];
    $e = [];
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $v['username'])) { $e[] = 'Username: 3-50 characters, letters, numbers, dot, dash or underscore.'; }
    if ($v['full_name'] === '' || mb_strlen($v['full_name']) > 100) { $e[] = 'Full name is required (max 100 characters).'; }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 100) { $e[] = 'Please enter a valid email address.'; }
    if (!isset(USER_ROLES[$v['role']])) { $e[] = 'Please choose a role.'; }
    // Only the Head of Admin manages Head of Admin accounts (otherwise an Admin Assistant could confirm his own assignments)
    if ($v['role'] === 'head_admin' && !isHeadAdmin()) { $e[] = 'Only the Head of Admin can create or assign the Head of Admin role.'; }
    if ($id > 0 && !isHeadAdmin()) {
        $cur = $conn->query('SELECT role FROM users WHERE user_id = ' . $id)->fetch_row();
        if ($cur && $cur[0] === 'head_admin') { $e[] = 'Only the Head of Admin can change a Head of Admin account.'; }
    }
    if (mb_strlen($v['department']) > 100 || mb_strlen($v['phone']) > 30) { $e[] = 'Department or phone is too long.'; }
    if (!$e) {
        $q = $conn->prepare('SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id <> ? LIMIT 1');
        $q->bind_param('ssi', $v['username'], $v['email'], $id);
        $q->execute();
        if ($q->get_result()->fetch_assoc()) { $e[] = 'That username or email is already used by another account.'; }
        $q->close();
    }
    if ($v['role'] === 'driver') {
        if ($v['driver_id'] <= 0) {
            $e[] = 'Choose the driver record this account belongs to.';
        } else {
            $q = $conn->prepare('SELECT 1 FROM users WHERE driver_id = ? AND user_id <> ? LIMIT 1');
            $q->bind_param('ii', $v['driver_id'], $id);
            $q->execute();
            if ($q->get_result()->fetch_assoc()) { $e[] = 'That driver already has a login account.'; }
            $q->close();
            $q = $conn->prepare('SELECT 1 FROM drivers WHERE driver_id = ?');
            $q->bind_param('i', $v['driver_id']);
            $q->execute();
            if (!$q->get_result()->fetch_assoc()) { $e[] = 'Driver record not found.'; }
            $q->close();
        }
    } else {
        $v['driver_id'] = 0;
    }
    // Approver rule: a request goes to the approver of the requester (every role can request).
    // A Head of Section's approver is their own head, never themselves.
    if ($v['supervisor_id'] > 0 && $v['supervisor_id'] === $id) {
        $e[] = 'A person cannot be their own approver.';
    } elseif ($v['supervisor_id'] > 0) {
        $q = $conn->prepare("SELECT 1 FROM users WHERE user_id = ? AND role = 'supervisor' AND is_active = 1");
        $q->bind_param('i', $v['supervisor_id']);
        $q->execute();
        if (!$q->get_result()->fetch_assoc()) { $e[] = 'The chosen approver is not an active Head of Section.'; }
        $q->close();
    }
    return [$v, $e];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($act === 'create' || $act === 'update') {
        [$v, $errors] = validateUserForm($conn, $_POST, $act === 'update' ? $uid : 0);
        $form = $v;
        if ($act === 'update' && $uid === $me && $v['role'] !== $_SESSION['role']) {
            $errors[] = 'You cannot change your own role.';
        }
        if (!$errors) {
            $dept = $v['department'] !== '' ? $v['department'] : null;
            $phone = $v['phone'] !== '' ? $v['phone'] : null;
            $sup  = $v['supervisor_id'] ?: null;
            $drv  = $v['driver_id'] ?: null;
            if ($act === 'create') {
                $temp = generateTempPassword();
                $hash = password_hash($temp, PASSWORD_DEFAULT);
                $st = $conn->prepare('INSERT INTO users (username, password, email, full_name, role, driver_id, department, supervisor_id, phone, must_change_password, is_active)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)');
                $st->bind_param('sssssisis', $v['username'], $hash, $v['email'], $v['full_name'], $v['role'], $drv, $dept, $sup, $phone);
                $st->execute();
                $newId = $st->insert_id;
                $st->close();
                auditLog($conn, 'user_created', null, "{$v['username']} ({$v['role']}) created");
                $_SESSION['temp_password'] = ['user' => $v['username'], 'password' => $temp, 'what' => 'created'];
                header('Location: users.php');
                exit();
            }
            $st = $conn->prepare('UPDATE users SET username=?, email=?, full_name=?, role=?, driver_id=?, department=?, supervisor_id=?, phone=? WHERE user_id=?');
            $st->bind_param('ssssisisi', $v['username'], $v['email'], $v['full_name'], $v['role'], $drv, $dept, $sup, $phone, $uid);
            $st->execute();
            $st->close();
            auditLog($conn, 'user_updated', null, "{$v['username']} updated (role {$v['role']})");
            setFlash('success', 'Account updated.');
            header('Location: users.php');
            exit();
        }
    } else {
        $t = $conn->prepare('SELECT user_id, username, is_active, role FROM users WHERE user_id = ?');
        $t->bind_param('i', $uid);
        $t->execute();
        $target = $t->get_result()->fetch_assoc();
        $t->close();
        if (!$target) {
            setFlash('danger', 'Account not found.');
        } elseif ($target['role'] === 'head_admin' && !isHeadAdmin() && $uid !== $me) {
            setFlash('danger', 'Only the Head of Admin can manage a Head of Admin account.');
        } elseif ($act === 'reset_password') {
            $temp = generateTempPassword();
            $hash = password_hash($temp, PASSWORD_DEFAULT);
            $st = $conn->prepare('UPDATE users SET password = ?, must_change_password = 1, failed_attempts = 0, is_locked = 0 WHERE user_id = ?');
            $st->bind_param('si', $hash, $uid);
            $st->execute();
            $st->close();
            auditLog($conn, 'password_reset', null, "Password of {$target['username']} reset by admin");
            $_SESSION['temp_password'] = ['user' => $target['username'], 'password' => $temp, 'what' => 'reset'];
        } elseif ($act === 'unlock') {
            $conn->query("UPDATE users SET is_locked = 0, failed_attempts = 0 WHERE user_id = $uid");
            auditLog($conn, 'user_unlocked', null, "{$target['username']} unlocked");
            setFlash('success', "Account {$target['username']} unlocked.");
        } elseif ($act === 'toggle_active') {
            if ($uid === $me) {
                setFlash('danger', 'You cannot deactivate your own account.');
            } else {
                $new = (int)$target['is_active'] ? 0 : 1;
                $conn->query("UPDATE users SET is_active = $new WHERE user_id = $uid");
                auditLog($conn, $new ? 'user_activated' : 'user_deactivated', null, $target['username']);
                setFlash('success', "Account {$target['username']} " . ($new ? 'activated.' : 'deactivated.'));
            }
        }
        header('Location: users.php');
        exit();
    }
}

// ---- data for the page
$editId = (int)($_GET['edit'] ?? 0);
if ($editId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $q = $conn->prepare('SELECT * FROM users WHERE user_id = ?');
    $q->bind_param('i', $editId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if ($row) {
        $form = ['username' => $row['username'], 'full_name' => $row['full_name'], 'email' => $row['email'], 'phone' => $row['phone'] ?? '',
                 'role' => $row['role'], 'department' => $row['department'] ?? '', 'supervisor_id' => (int)$row['supervisor_id'], 'driver_id' => (int)$row['driver_id']];
    } else {
        $editId = 0;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = ($_POST['action'] ?? '') === 'update' ? (int)($_POST['user_id'] ?? 0) : 0;
}

$search = trim((string)($_GET['q'] ?? ''));
$roleF  = (string)($_GET['role'] ?? '');
$sql = 'SELECT u.*, s.full_name AS supervisor_name, d.name AS driver_name FROM users u
        LEFT JOIN users s ON s.user_id = u.supervisor_id LEFT JOIN drivers d ON d.driver_id = u.driver_id WHERE 1=1';
$types = ''; $args = [];
if ($search !== '') {
    $sql .= ' AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
    $like = "%$search%"; $types .= 'sss'; array_push($args, $like, $like, $like);
}
if (isset(USER_ROLES[$roleF])) { $sql .= ' AND u.role = ?'; $types .= 's'; $args[] = $roleF; }
$sql .= ' ORDER BY u.is_active DESC, u.role, u.full_name';
$st = $conn->prepare($sql);
if ($types) { $st->bind_param($types, ...$args); }
$st->execute();
$users = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$supervisors = $conn->query("SELECT user_id, full_name, department FROM users WHERE role = 'supervisor' AND is_active = 1 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$freeDrivers = $conn->query("SELECT d.driver_id, d.name, d.employee_id FROM drivers d
                              WHERE d.driver_id NOT IN (SELECT driver_id FROM users WHERE driver_id IS NOT NULL" . ($editId ? " AND user_id <> $editId" : '') . ")
                              ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);

$temp = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

$page_title = 'User Accounts';
adminPageStart('User Accounts');
?>
    <h4 class="fw-bold mb-3"><i class="fas fa-users-gear text-success me-2"></i>User Accounts</h4>

    <?php if ($temp): ?>
    <div class="alert alert-warning">
        <strong>Temporary password for <code><?= htmlspecialchars($temp['user']) ?></code>:</strong>
        <code class="fs-5 ms-2 user-select-all"><?= htmlspecialchars($temp['password']) ?></code>
        <div class="small mt-1">Give this to the user now. It is shown only once; the user must choose a new password at first sign-in.</div>
    </div>
    <?php endif; ?>
    <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold"><?= $editId ? 'Edit account' : 'New account' ?></h6>
                <form method="post" class="row g-2" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $editId ? 'update' : 'create' ?>">
                    <?php if ($editId): ?><input type="hidden" name="user_id" value="<?= $editId ?>"><?php endif; ?>
                    <div class="col-12"><label class="form-label small" for="username">Username</label>
                        <input class="form-control form-control-sm" id="username" name="username" value="<?= htmlspecialchars($form['username']) ?>" required maxlength="50"></div>
                    <div class="col-12"><label class="form-label small" for="full_name">Full name</label>
                        <input class="form-control form-control-sm" id="full_name" name="full_name" value="<?= htmlspecialchars($form['full_name']) ?>" required maxlength="100"></div>
                    <div class="col-12"><label class="form-label small" for="email">Email</label>
                        <input type="email" class="form-control form-control-sm" id="email" name="email" value="<?= htmlspecialchars($form['email']) ?>" required maxlength="100"></div>
                    <div class="col-12"><label class="form-label small" for="phone">Phone (optional)</label>
                        <input class="form-control form-control-sm" id="phone" name="phone" value="<?= htmlspecialchars($form['phone']) ?>" maxlength="30"></div>
                    <div class="col-12"><label class="form-label small" for="role">Role</label>
                        <select class="form-select form-select-sm" id="role" name="role">
                            <?php foreach (USER_ROLES as $k => $label): ?><option value="<?= $k ?>" <?= $form['role'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-12"><label class="form-label small" for="department">Department / unit</label>
                        <input class="form-control form-control-sm" id="department" name="department" value="<?= htmlspecialchars($form['department']) ?>" maxlength="100"></div>
                    <div class="col-12" id="rowSupervisor"><label class="form-label small" for="supervisor_id">Approver (Head of Section)</label>
                        <select class="form-select form-select-sm" id="supervisor_id" name="supervisor_id">
                            <option value="">&mdash; none &mdash;</option>
                            <?php foreach ($supervisors as $s): ?><option value="<?= (int)$s['user_id'] ?>" <?= (int)$form['supervisor_id'] === (int)$s['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name'] . ($s['department'] ? ' — ' . $s['department'] : '')) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-12" id="rowDriver"><label class="form-label small" for="driver_id">Driver record (for driver)</label>
                        <select class="form-select form-select-sm" id="driver_id" name="driver_id">
                            <option value="">&mdash; choose &mdash;</option>
                            <?php foreach ($freeDrivers as $d): ?><option value="<?= (int)$d['driver_id'] ?>" <?= (int)$form['driver_id'] === (int)$d['driver_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name'] . ' (' . $d['employee_id'] . ')') ?></option><?php endforeach; ?>
                        </select><div class="form-text">Only drivers without a login account are listed.</div></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-success btn-sm" type="submit"><?= $editId ? 'Save changes' : 'Create account' ?></button>
                        <?php if ($editId): ?><a class="btn btn-outline-secondary btn-sm" href="users.php">Cancel</a><?php endif; ?>
                    </div>
                    <?php if (!$editId): ?><div class="col-12 form-text">A random temporary password is generated and shown once.</div><?php endif; ?>
                </form>
            </div></div>
        </div>

        <div class="col-lg-8">
            <form method="get" class="d-flex gap-2 mb-2">
                <input class="form-control form-control-sm" name="q" placeholder="Search username, name or email" value="<?= htmlspecialchars($search) ?>">
                <select class="form-select form-select-sm w-auto" name="role">
                    <option value="">All roles</option>
                    <?php foreach (USER_ROLES as $k => $label): ?><option value="<?= $k ?>" <?= $roleF === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-outline-success btn-sm" type="submit">Filter</button>
            </form>
            <div class="card border-0 shadow-sm"><div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Account</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="<?= (int)$u['is_active'] ? '' : 'text-muted' ?>">
                            <td><strong><?= htmlspecialchars($u['username']) ?></strong><br><span class="small"><?= htmlspecialchars($u['full_name']) ?></span>
                                <?php if ($u['supervisor_name']): ?><br><span class="small text-muted">Approver: <?= htmlspecialchars($u['supervisor_name']) ?></span><?php endif; ?>
                                <?php if ($u['role'] === 'driver' && $u['driver_name']): ?><br><span class="small text-muted">Driver: <?= htmlspecialchars($u['driver_name']) ?></span><?php endif; ?></td>
                            <td class="small"><?= htmlspecialchars(USER_ROLES[$u['role']] ?? $u['role']) ?></td>
                            <td class="small">
                                <?php if ((int)$u['is_locked']): ?><span class="badge bg-danger">Locked</span>
                                <?php elseif (!(int)$u['is_active']): ?><span class="badge bg-secondary">Inactive</span>
                                <?php else: ?><span class="badge bg-success">Active</span><?php endif; ?>
                                <?php if ((int)$u['must_change_password']): ?><span class="badge bg-warning text-dark">Temp password</span><?php endif; ?>
                            </td>
                            <td class="small text-nowrap"><?= $u['last_login_at'] ? date('d M Y H:i', strtotime($u['last_login_at'])) : '&mdash;' ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="users.php?edit=<?= (int)$u['user_id'] ?>">Edit</a>
                                <form method="post" class="d-inline"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" data-confirm="A new temporary password will be generated and the old one stops working." data-confirm-title="Reset password?" data-confirm-button="Reset" data-confirm-tone="warning">Reset</button></form>
                                <?php if ((int)$u['is_locked']): ?>
                                <form method="post" class="d-inline"><input type="hidden" name="action" value="unlock"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-success">Unlock</button></form>
                                <?php endif; ?>
                                <?php if ((int)$u['user_id'] !== $me): ?>
                                <form method="post" class="d-inline"><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" data-confirm="<?= (int)$u['is_active'] ? 'This person will no longer be able to sign in. Their past records are kept.' : 'This person will be able to sign in again.' ?>" data-confirm-title="<?= (int)$u['is_active'] ? 'Deactivate account?' : 'Activate account?' ?>" data-confirm-button="Yes" data-confirm-tone="danger"><?= (int)$u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?><tr><td colspan="5" class="text-center text-muted py-4">No accounts found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div>
    </div>
    <script>
    (function () {
        var role = document.getElementById('role');
        function sync() {
            document.getElementById('rowDriver').style.display = role.value === 'driver' ? '' : 'none';
        }
        role.addEventListener('change', sync); sync();
    })();
    </script>
<?php adminPageEnd(); ?>
