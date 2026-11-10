<?php
// ============================================================
// admin/add_user.php  -  Add a user account (Administrative Assistant, Head of Admin).
// Typical use: create the account of a new driver, then register it as a driver in Add Driver.
// ============================================================
$current_page = 'add_user.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
requireAdmin();

$roles = assignableRoles((string)$_SESSION['role']);
$departments = departmentChoices($conn);
$v = ['username' => '', 'full_name' => '', 'email' => '', 'phone' => '', 'department' => '', 'role' => 'driver'];
$errors = [];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$v, $errors] = accountInput($conn, $_POST, true);
    if (!isset($roles[$v['role']])) { $errors['role'] = 'You cannot create an account with this role.'; }
    $password = (string)($_POST['password'] ?? '');
    if (($problem = passwordProblem($password)) !== null) { $errors['password'] = $problem; }
    elseif ($password !== (string)($_POST['confirm_password'] ?? '')) { $errors['confirm_password'] = 'The two passwords do not match.'; }
    if (!$errors) {
        try {
            $id = createAccount($conn, $v, $v['role'], $password);
            if ($v['role'] === 'supervisor') { refreshApprovers($conn, $v['department']); }
            auditLog($conn, 'user_add', null, $v['full_name'] . ' (' . $v['username'] . ', ' . (ACCOUNT_ROLES[$v['role']]) . ', ' . $v['department'] . ') account created');
            notifyUsers($conn, [$id], 'An account was created for you in the UIS Vehicle Section system. Sign in with your staff ID and the password you were given.', null);
            setFlash('success', 'The account of ' . $v['full_name'] . ' was created.' . ($v['role'] === 'driver' ? ' Now register this person as a driver in Add Driver.' : ''));
            header('Location: ' . ($v['role'] === 'driver' ? 'add_driver.php?user_id=' . $id : 'users.php'));
            exit();
        } catch (mysqli_sql_exception $ex) {
            $errors['username'] = 'This staff ID or e-mail address already has an account.';
        }
    }
}
$creating = true; $lockRole = '';
adminPageStart('Add account');
?>
    <h4 class="fw-bold mb-3"><i class="fas fa-user-plus text-success me-2"></i>Add account</h4>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <form method="post" novalidate>
            <?php require __DIR__ . '/user_form.php'; ?>
            <div class="row g-3 mt-0">
                <div class="col-12 col-md-6"><label class="form-label" for="password">Password *</label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" maxlength="72">
                    <?= isset($errors['password']) ? '<div class="invalid-feedback d-block">' . $h($errors['password']) . '</div>' : '<div class="form-text">At least 8 characters, with letters and digits. Give it to the person yourself.</div>' ?></div>
                <div class="col-12 col-md-6"><label class="form-label" for="confirm_password">Confirm password *</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72">
                    <?= isset($errors['confirm_password']) ? '<div class="invalid-feedback d-block">' . $h($errors['confirm_password']) . '</div>' : '' ?></div>
            </div>
            <div class="mt-4 d-flex gap-2"><button class="btn btn-success" type="submit"><i class="fas fa-save me-1"></i>Create account</button><a class="btn btn-outline-secondary" href="users.php">Cancel</a></div>
        </form>
    </div></div>
<?php adminPageEnd(); ?>
