<?php
// ============================================================
// forgot_password.php  -  View Layer: AccountUI (SDD_CLASS_101) displayResetForm()
//   UC015 Reset Password (includes UC014 Send Verification Email) through AccountController
//   1. staff ID + UIS email of an active account -> Request Code
//   2. verification code -> Submit (Resend Code)
//   3. new password + confirm -> Save
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
        if ($act === 'restart') { $ac->clearVerification(); header('Location: forgot_password.php'); exit(); }
        if ($act === 'request_code' || ($act === 'resend' && $v)) {
            $res = $ac->requestCode($act === 'resend' ? $v['staff_id'] : (string)($_POST['staff_no'] ?? ''), $act === 'resend' ? $v['email'] : (string)($_POST['email'] ?? ''), 'Reset Password');
            if ($res['ok']) {
                $_SESSION['reset_info'] = $res['message'] . (isset($res['dev_code']) ? ' (Test mode, email is switched off: your code is ' . $res['dev_code'] . '.)' : '');
                header('Location: forgot_password.php'); exit();
            }
            $error = $res['message'];
        } elseif ($act === 'verify') {
            $res = $ac->verifyCode((string)($_POST['code'] ?? ''));
            if ($res['ok']) { header('Location: forgot_password.php'); exit(); }
            $error = $res['message'];
        } elseif ($act === 'save') {
            $res = $ac->resetPassword((string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''));
            if ($res['ok']) { $_SESSION['login_success'] = $res['message']; header('Location: login.php'); exit(); }
            $error = $res['message'];
            $errors = $res['errors'] ?? [];
        }
    }
}
if (!empty($_SESSION['reset_info'])) { $info = $_SESSION['reset_info']; unset($_SESSION['reset_info']); }
$rs = (($_SESSION['verify']['purpose'] ?? '') === 'Reset Password') ? $_SESSION['verify'] : null;
$step = !$rs ? 0 : (empty($rs['verified']) ? 1 : 2);
$csrf = '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';

authPageStart('Reset Password', 'Forgot your password? Enter your staff ID and UIS email. We will send a verification code to your email.');
echo authSteps(['Your account', 'Enter code', 'New password'], $step);
echo authAlert($error, $info);
?>
<?php if ($step === 0): ?>
    <form method="post" class="flex flex-col gap-4" novalidate>
        <?= $csrf ?><input type="hidden" name="action" value="request_code">
        <div><label class="mb-1 block text-sm font-medium" for="staff_no">Staff ID</label>
            <input class="input w-full" id="staff_no" name="staff_no" maxlength="30" required value="<?= h($_POST['staff_no'] ?? '') ?>" autofocus></div>
        <div><label class="mb-1 block text-sm font-medium" for="email">UIS email</label>
            <input type="email" class="input w-full" id="email" name="email" maxlength="150" required value="<?= h($_POST['email'] ?? '') ?>" placeholder="name@uis.edu.my"></div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i>Request Code</button>
    </form>
<?php elseif ($step === 1): ?>
    <p class="mb-3 text-sm">A code was sent to <strong><?= h($rs['email']) ?></strong>.</p>
    <form method="post" class="flex flex-col gap-4" novalidate>
        <?= $csrf ?><input type="hidden" name="action" value="verify">
        <div><label class="mb-1 block text-sm font-medium" for="code">Verification code</label>
            <input class="input input-lg w-full tracking-[0.4em]" id="code" name="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code" autofocus></div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i>Submit</button>
    </form>
    <div class="mt-3 flex flex-wrap justify-between gap-2">
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="resend"><button class="btn btn-sm btn-ghost" type="submit"><i class="fas fa-rotate"></i>Resend Code</button></form>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="restart"><button class="btn btn-sm btn-ghost" type="submit">Start again</button></form>
    </div>
<?php else: ?>
    <form method="post" class="flex flex-col gap-4" novalidate>
        <?= $csrf ?><input type="hidden" name="action" value="save">
        <div><label class="mb-1 block text-sm font-medium" for="password">New password</label>
            <input type="password" class="input w-full<?= errClass($errors, 'password') ?>" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
            <div class="mt-1 text-xs text-base-content/60">At least 8 characters with letters and numbers.</div></div>
        <div><label class="mb-1 block text-sm font-medium" for="password_confirm">Confirm password</label>
            <input type="password" class="input w-full<?= errClass($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm" required autocomplete="new-password"></div>
        <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i>Save</button>
    </form>
<?php endif; ?>
<?php authPageEnd(); ?>
