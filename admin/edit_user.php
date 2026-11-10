<?php
// ============================================================
// admin/edit_user.php  -  Change an account (details, role, PTJ) or reset its password.
// Administrative Assistant and Head of Admin. Only the Head of Admin changes Administrative Assistant and Head of Admin accounts.
// ============================================================
$current_page = 'edit_user.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$me = (int)$_SESSION['user_id'];
$actorRole = (string)$_SESSION['role'];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$u) { setFlash('danger', 'That account was not found.'); header('Location: users.php'); exit(); }
if ($actorRole !== 'head_admin' && in_array($u['role'], ['admin', 'head_admin'], true)) {
    setFlash('danger', 'Only the Head of Admin can change an Administrative Assistant or Head of Admin account.');
    header('Location: users.php');
    exit();
}

$roles = assignableRoles($actorRole);
if (!isset($roles[$u['role']])) { $roles[$u['role']] = ACCOUNT_ROLES[$u['role']]; }
$lockRole = '';
if ($id === $me) { $lockRole = 'You cannot change your own role.'; }
elseif ($u['driver_id'] !== null) { $lockRole = 'This account is linked to a driver record, so the role stays Driver.'; }
$departments = departmentChoices($conn);
$v = ['username' => $u['username'], 'full_name' => $u['full_name'], 'email' => $u['email'], 'phone' => (string)$u['phone'], 'department' => (string)$u['department'], 'role' => $u['role']];
$errors = [];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? 'save');
    if ($act === 'password') {
        $password = (string)($_POST['password'] ?? '');
        if (($problem = passwordProblem($password)) !== null) { $errors['password'] = $problem; }
        elseif ($password !== (string)($_POST['confirm_password'] ?? '')) { $errors['confirm_password'] = 'The two passwords do not match.'; }
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE user_id = ?');
            $stmt->bind_param('si', $hash, $id); $stmt->execute(); $stmt->close();
            auditLog($conn, 'user_password_reset', null, 'Password of ' . $u['full_name'] . ' (' . $u['username'] . ') reset');
            notifyUsers($conn, [$id], 'Your password was reset by the Vehicle Section. Use the new password you were given to sign in.', null);
            setFlash('success', 'The password of ' . $u['full_name'] . ' was reset.');
            header('Location: edit_user.php?id=' . $id);
            exit();
        }
    } else {
        [$v, $errors] = accountInput($conn, $_POST, false, $id);
        $v['username'] = $u['username'];
        if ($lockRole !== '') { $v['role'] = $u['role']; }
        elseif (!isset($roles[$v['role']])) { $errors['role'] = 'You cannot give this role.'; }
        if (empty($errors['role']) && $v['role'] === 'supervisor' && $v['department'] !== '' && ($hod = headOfDepartmentOf($conn, $v['department'], $id))) {
            $errors['role'] = 'The department already has a Head of Department (' . $hod['full_name'] . ').';   // UC001 A2.1
        }
        $oldDept = (string)$u['department'];
        $movesAway = $u['role'] === 'supervisor' && ($v['role'] !== 'supervisor' || $v['department'] !== $oldDept) && (int)$u['is_active'] === 1;
        if (!$errors && $movesAway && pendingRequestsOfApprover($conn, $id) > 0) {
            $errors['role'] = 'This Head of Department has pending requests. Handle the pending requests first.';
        }
        if (!$errors) {
            $phone = $v['phone'] !== '' ? $v['phone'] : null;
            $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, department = ?, role = ? WHERE user_id = ?');
            $stmt->bind_param('sssssi', $v['full_name'], $v['email'], $phone, $v['department'], $v['role'], $id);
            $stmt->execute(); $stmt->close();
            if ($v['role'] === 'supervisor') {
                $stmt = $conn->prepare('UPDATE users SET supervisor_id = NULL WHERE user_id = ?'); $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
            } else {
                $ap = approverIdForDepartment($conn, $v['department'], $v['role']);
                $stmt = $conn->prepare('UPDATE users SET supervisor_id = ? WHERE user_id = ?'); $stmt->bind_param('ii', $ap, $id); $stmt->execute(); $stmt->close();
            }
            refreshApprovers($conn, $oldDept);
            refreshApprovers($conn, $v['department']);
            $changes = [];
            foreach (['full_name' => 'name', 'email' => 'e-mail', 'phone' => 'phone', 'department' => 'PTJ', 'role' => 'role'] as $k => $lbl) {
                if ((string)$u[$k] !== (string)$v[$k]) { $changes[] = $lbl; }
            }
            auditLog($conn, 'user_update', null, $u['full_name'] . ' (' . $u['username'] . ') changed: ' . ($changes ? implode(', ', $changes) : 'nothing'));
            setFlash('success', 'The account of ' . $v['full_name'] . ' was updated.');
            header('Location: users.php');
            exit();
        }
    }
}
$creating = false;
adminPageStart('Edit account');
?>
    <h4 class="fw-bold mb-3"><i class="fas fa-user-pen text-success me-2"></i>Edit account <span class="badge <?= (int)$u['is_active'] ? 'bg-success' : 'bg-secondary' ?> ms-2"><?= (int)$u['is_active'] ? 'Active' : 'Inactive' ?></span></h4>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <form method="post" novalidate>
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save">
            <?php require __DIR__ . '/user_form.php'; ?>
            <div class="mt-4 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="fas fa-save me-1"></i>Save</button><a class="btn btn-outline-secondary" href="users.php">Cancel</a></div>
        </form>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <h6 class="fw-bold">Reset password</h6>
        <p class="text-muted small">Use this when the person forgot the password. Give them the new password yourself; they are also told in the system and by e-mail.</p>
        <form method="post" novalidate class="row g-3">
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="password">
            <div class="col-12 col-md-5"><label class="form-label" for="password">New password</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" maxlength="72">
                <?= isset($errors['password']) ? '<div class="invalid-feedback d-block">' . $h($errors['password']) . '</div>' : '' ?></div>
            <div class="col-12 col-md-5"><label class="form-label" for="confirm_password">Confirm</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72">
                <?= isset($errors['confirm_password']) ? '<div class="invalid-feedback d-block">' . $h($errors['confirm_password']) . '</div>' : '' ?></div>
            <div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-outline-danger w-100" type="submit"><i class="fas fa-key me-1"></i>Reset</button></div>
        </form>
    </div></div>
<?php adminPageEnd(); ?>
