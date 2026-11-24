<?php
// ============================================================
// register.php  -  UC013 Register Account (includes UC014 Send Verification Email)
//   1. staff ID + UIS email -> Request Code (not registered yet, UIS email only)
//   2. verification code -> Submit (Resend Code when it expired or was not received)
//   3. name, phone, department, role (Staff / Head of Department), password -> Register
// A Head of Department role is confirmed by the Administrative Assistant; until then the user is Staff.
// ============================================================
require_once 'config/database.php';
require_once 'includes/auth_layout.php';

if (isLoggedIn()) { header('Location: ' . roleHomeUrl()); exit(); }

$reg = $_SESSION['reg'] ?? null;
$error = null; $info = null; $errors = [];
$step = !$reg ? 0 : (empty($reg['verified']) ? 1 : 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your page expired. Please try again.';
    } else {
        $act = $_POST['action'] ?? '';
        if ($act === 'restart') {
            unset($_SESSION['reg']);
            header('Location: register.php');
            exit();
        }
        if ($act === 'request_code' || $act === 'resend') {
            $staffNo = $act === 'resend' ? (string)$reg['staff_no'] : strtoupper(trim((string)($_POST['staff_no'] ?? '')));
            $email   = $act === 'resend' ? (string)$reg['email'] : strtolower(trim((string)($_POST['email'] ?? '')));
            if ($p = registerStartProblem($conn, $staffNo, $email)) {
                $error = $p;
            } else {
                $res = issueVerificationCode($conn, 'register', $staffNo, $email);
                if ($res['ok']) {
                    $_SESSION['reg'] = ['staff_no' => $staffNo, 'email' => $email, 'verified' => false];
                    $_SESSION['reg_info'] = $res['message'] . (isset($res['dev_code']) ? ' (Test mode, email is switched off: your code is ' . $res['dev_code'] . '.)' : '');
                    header('Location: register.php');
                    exit();
                }
                $error = $res['message'];
            }
        } elseif ($act === 'verify' && $reg) {
            $res = checkVerificationCode($conn, 'register', $reg['email'], (string)($_POST['code'] ?? ''));
            if ($res['ok']) {
                $_SESSION['reg']['verified'] = true;
                header('Location: register.php');
                exit();
            }
            $error = $res['message'];
        } elseif ($act === 'register' && $reg && !empty($reg['verified'])) {
            $res = registerAccount($conn, $reg['staff_no'], $reg['email'], $_POST);
            if ($res['ok']) {
                unset($_SESSION['reg']);
                $_SESSION['login_success'] = $res['message'];
                header('Location: login.php');
                exit();
            }
            $error = $res['message'];
            $errors = $res['errors'] ?? [];
        }
    }
}
if (!empty($_SESSION['reg_info'])) { $info = $_SESSION['reg_info']; unset($_SESSION['reg_info']); }
$reg = $_SESSION['reg'] ?? null;
$step = !$reg ? 0 : (empty($reg['verified']) ? 1 : 2);
$departments = departmentsList($conn);
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
$v = fn($k, $d = '') => h($_POST[$k] ?? $d);

authPageStart('Register Account', 'First time using the system? Register with your UIS staff ID and UIS email. Drivers do not register: the Vehicle Section creates their accounts.');
echo authSteps(['Verify email', 'Enter code', 'Your details'], $step);
echo authAlert($error, $info);
?>
<?php if ($step === 0): ?>
    <form method="post" class="flex flex-col gap-4" novalidate>
        <?= $csrf ?><input type="hidden" name="action" value="request_code">
        <div>
            <label class="mb-1 block text-sm font-medium" for="staff_no">Staff ID</label>
            <input class="input w-full" id="staff_no" name="staff_no" maxlength="30" required value="<?= $v('staff_no') ?>" autocomplete="username" autofocus>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium" for="email">UIS email</label>
            <input type="email" class="input w-full" id="email" name="email" maxlength="150" required value="<?= $v('email') ?>" placeholder="name@uis.edu.my" autocomplete="email">
            <div class="mt-1 text-xs text-base-content/60">Only UIS email is accepted. The verification code is sent to this email.</div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i>Request Code</button>
    </form>
<?php elseif ($step === 1): ?>
    <p class="mb-3 text-sm">Staff ID <strong><?= h($reg['staff_no']) ?></strong>, email <strong><?= h($reg['email']) ?></strong>.</p>
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
        <div class="col-span-2 text-sm">Staff ID <strong><?= h($reg['staff_no']) ?></strong> &middot; <?= h($reg['email']) ?> <span class="badge badge-success badge-sm">verified</span></div>
        <div class="col-span-2">
            <label class="mb-1 block text-sm font-medium" for="full_name">Full name</label>
            <input class="input w-full<?= errClass($errors, 'full_name') ?>" id="full_name" name="full_name" maxlength="120" required value="<?= $v('full_name') ?>" autocomplete="name">
            <?= fieldError($errors, 'full_name') ?>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="mb-1 block text-sm font-medium" for="phone">Phone number</label>
            <input type="tel" class="input w-full<?= errClass($errors, 'phone') ?>" id="phone" name="phone" maxlength="20" required value="<?= $v('phone') ?>" placeholder="012-345 6789" autocomplete="tel">
            <?= fieldError($errors, 'phone') ?>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="mb-1 block text-sm font-medium" for="role">Role</label>
            <select class="select w-full<?= errClass($errors, 'role', 'select') ?>" id="role" name="role">
                <option value="staff">Staff</option>
                <option value="supervisor" <?= ($_POST['role'] ?? '') === 'supervisor' ? 'selected' : '' ?>>Head of Department</option>
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
