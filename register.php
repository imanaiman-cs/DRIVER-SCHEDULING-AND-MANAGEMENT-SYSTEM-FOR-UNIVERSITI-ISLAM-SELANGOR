<?php
// ============================================================
// register.php  -  View Layer: AccountUI (SDD_CLASS_101) displayRegisterForm()
//   UC013 Register Account (includes UC014 Send Verification Email) through AccountController
//   1. staff ID + UIS email -> Request Code (not registered yet, UIS email only)
//   2. verification code -> Submit (Resend Code when it expired or was not received)
//   3. name, phone, department, role (Staff / Head of Department), password -> Register
// A Head of Department role is confirmed by the Administrative Assistant; until then the user is Staff.
// ============================================================
require_once 'config/database.php';
require_once 'includes/auth_layout.php';

if (isLoggedIn()) { header('Location: ' . roleHomeUrl()); exit(); }

$ac = new AccountController();
$error = null; $info = null; $errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your page expired. Please try again.';
    } else {
        $act = $_POST['action'] ?? '';
        $v = $_SESSION['verify'] ?? null;
        if ($act === 'restart') { $ac->clearVerification(); header('Location: register.php'); exit(); }
        if ($act === 'request_code' || ($act === 'resend' && $v)) {
            $res = $ac->requestCode($act === 'resend' ? $v['staff_id'] : (string)($_POST['staff_id'] ?? ''), $act === 'resend' ? $v['email'] : (string)($_POST['email'] ?? ''), 'Register');
            if ($res['ok']) {
                $_SESSION['reg_info'] = $res['message'] . (isset($res['dev_code']) ? ' (Test mode, email is switched off: your code is ' . $res['dev_code'] . '.)' : '');
                header('Location: register.php'); exit();
            }
            $error = $res['message'];
        } elseif ($act === 'verify') {
            $res = $ac->verifyCode((string)($_POST['code'] ?? ''));
            if ($res['ok']) { header('Location: register.php'); exit(); }
            $error = $res['message'];
        } elseif ($act === 'register') {
            $res = $ac->register($_POST);
            if ($res['ok']) { $_SESSION['login_success'] = $res['message']; header('Location: login.php'); exit(); }
            $error = $res['message'];
            $errors = $res['errors'] ?? [];
        }
    }
}
if (!empty($_SESSION['reg_info'])) { $info = $_SESSION['reg_info']; unset($_SESSION['reg_info']); }
$v = (($_SESSION['verify']['purpose'] ?? '') === 'Register') ? $_SESSION['verify'] : null;
$step = !$v ? 0 : (empty($v['verified']) ? 1 : 2);
$reg = $v;
$departments = $ac->listDepartments();
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
$val = fn($k, $d = '') => h($_POST[$k] ?? $d);

authPageStart('Register Account', 'First time using the system? Register with your UIS staff ID and UIS email. Drivers do not register: the Vehicle Section creates their accounts.');
echo authSteps(['Verify email', 'Enter code', 'Your details'], $step);
echo authAlert($error, $info);
?>
<?php if ($step === 0): ?>
    <form method="post" class="flex flex-col gap-4" novalidate>
        <?= $csrf ?><input type="hidden" name="action" value="request_code">
        <div>
            <label class="mb-1 block text-sm font-medium" for="staff_no">Staff ID</label>
            <input class="input w-full" id="staff_no" name="staff_id" maxlength="20" required value="<?= $val('staff_id') ?>" autocomplete="username" autofocus>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium" for="email">UIS email</label>
            <input type="email" class="input w-full" id="email" name="email" maxlength="150" required value="<?= $val('email') ?>" placeholder="name@uis.edu.my" autocomplete="email">
            <div class="mt-1 text-xs text-base-content/60">Only UIS email is accepted. The verification code is sent to this email.</div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i>Request Code</button>
    </form>
<?php elseif ($step === 1): ?>
    <p class="mb-3 text-sm">Staff ID <strong><?= h($reg['staff_id']) ?></strong>, email <strong><?= h($reg['email']) ?></strong>.</p>
    <form method="post" class="flex flex-col gap-4" novalidate>
        <?= $csrf ?><input type="hidden" name="action" value="verify">
        <div>
            <label class="mb-1 block text-sm font-medium" for="code">Verification code</label>
            <input class="input input-lg w-full tracking-[0.4em]" id="code" name="code" inputmode="numeric" maxlength="6" pattern="\d{6}" required autocomplete="one-time-code" autofocus>
        </div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i>Submit</button>
    </form>
    <div class="mt-3 flex flex-wrap justify-between gap-2">
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="resend"><button class="btn btn-sm btn-ghost" type="submit"><i class="fas fa-rotate"></i>Resend Code</button></form>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="restart"><button class="btn btn-sm btn-ghost" type="submit">Use another staff ID or email</button></form>
    </div>
<?php else: ?>
    <form method="post" class="grid grid-cols-2 gap-4" novalidate>
        <?= $csrf ?><input type="hidden" name="action" value="register">
        <div class="col-span-2 text-sm">Staff ID <strong><?= h($reg['staff_id']) ?></strong> &middot; <?= h($reg['email']) ?> <span class="badge badge-success badge-sm">verified</span></div>
        <div class="col-span-2">
            <label class="mb-1 block text-sm font-medium" for="full_name">Full name</label>
            <input class="input w-full<?= errClass($errors, 'name') ?>" id="full_name" name="name" maxlength="150" required value="<?= $val('name') ?>" autocomplete="name">
            <?= fieldError($errors, 'name') ?>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="mb-1 block text-sm font-medium" for="phone">Phone number</label>
            <input type="tel" class="input w-full<?= errClass($errors, 'phone_no') ?>" id="phone" name="phone_no" maxlength="20" required value="<?= $val('phone_no') ?>" placeholder="012-345 6789" autocomplete="tel">
            <?= fieldError($errors, 'phone_no') ?>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="mb-1 block text-sm font-medium" for="role">Role</label>
            <select class="select w-full<?= errClass($errors, 'role', 'select') ?>" id="role" name="role">
                <option>Staff</option>
                <option <?= ($_POST['role'] ?? '') === 'Head of Department' ? 'selected' : '' ?>>Head of Department</option>
            </select>
            <?= fieldError($errors, 'role') ?>
        </div>
        <div class="col-span-2">
            <label class="mb-1 block text-sm font-medium" for="department_id">Department</label>
            <select class="select w-full<?= errClass($errors, 'department_id', 'select') ?>" id="department_id" name="department_id" required>
                <option value="">Select your department</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>" <?= (int)($_POST['department_id'] ?? 0) === (int)$d['department_id'] ? 'selected' : '' ?>><?= h($d['department_name']) ?></option><?php endforeach; ?>
            </select>
            <?= fieldError($errors, 'department_id') ?>
            <div class="mt-1 text-xs text-base-content/60">A Head of Department role is confirmed by the Administrative Assistant. Until then you use the system as Staff.</div>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="mb-1 block text-sm font-medium" for="password">Password</label>
            <input type="password" class="input w-full<?= errClass($errors, 'password') ?>" id="password" name="password" required minlength="8" autocomplete="new-password">
            <?= fieldError($errors, 'password') ?>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="mb-1 block text-sm font-medium" for="password_confirm">Confirm password</label>
            <input type="password" class="input w-full<?= errClass($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            <?= fieldError($errors, 'password_confirm') ?>
        </div>
        <div class="col-span-2 text-xs text-base-content/60">At least 8 characters with letters and numbers.</div>
        <button class="btn btn-primary col-span-2" type="submit"><i class="fas fa-user-plus"></i>Register</button>
    </form>
<?php endif; ?>
<?php authPageEnd(); ?>
