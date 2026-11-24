<?php
// ============================================================
// forgot_password.php  -  UC015 Reset Password (includes UC014 Send Verification Email)
//   1. staff ID + UIS email of an active account -> Request Code
//   2. verification code -> Submit (Resend Code)
//   3. new password + confirm -> Save
// ============================================================
require_once 'config/database.php';
require_once 'includes/auth_layout.php';

if (isLoggedIn()) { header('Location: ' . roleHomeUrl()); exit(); }

$rs = $_SESSION['reset'] ?? null;
$error = null; $info = null; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your page expired. Please try again.';
    } else {
        $act = $_POST['action'] ?? '';
        if ($act === 'restart') { unset($_SESSION['reset']); header('Location: forgot_password.php'); exit(); }
        if ($act === 'request_code' || ($act === 'resend' && $rs)) {
            $staffNo = $act === 'resend' ? (string)$rs['staff_no'] : trim((string)($_POST['staff_no'] ?? ''));
            $email   = $act === 'resend' ? (string)$rs['email'] : strtolower(trim((string)($_POST['email'] ?? '')));
            $u = ($staffNo !== '' && $email !== '') ? accountByStaffAndEmail($conn, $staffNo, $email) : null;
            if (!$u) {
                $error = 'The account is not found. Check the staff ID and email.';
            } else {
                $res = issueVerificationCode($conn, 'reset', $u['staff_no'], $u['email'], $u['full_name']);
                if ($res['ok']) {
                    $_SESSION['reset'] = ['staff_no' => $u['staff_no'], 'email' => $u['email'], 'user_id' => (int)$u['user_id'], 'verified' => false];
                    $_SESSION['reset_info'] = $res['message'] . (isset($res['dev_code']) ? ' (Test mode, email is switched off: your code is ' . $res['dev_code'] . '.)' : '');
                    header('Location: forgot_password.php');
                    exit();
                }
                $error = $res['message'];
            }
        } elseif ($act === 'verify' && $rs) {
            $res = checkVerificationCode($conn, 'reset', $rs['email'], (string)($_POST['code'] ?? ''));
            if ($res['ok']) { $_SESSION['reset']['verified'] = true; header('Location: forgot_password.php'); exit(); }
            $error = $res['message'];
        } elseif ($act === 'save' && $rs && !empty($rs['verified'])) {
            $p1 = (string)($_POST['password'] ?? ''); $p2 = (string)($_POST['password_confirm'] ?? '');
            if ($pp = passwordProblem($p1)) { $errors['password'] = $pp; $error = $pp; }
            elseif ($p1 !== $p2) { $errors['password_confirm'] = 'The passwords do not match.'; $error = 'The passwords do not match.'; }
            else {
                setPassword($conn, (int)$rs['user_id'], $p1);
                consumeVerificationCode($conn, 'reset', $rs['email']);
                unset($_SESSION['reset']);
                $_SESSION['login_success'] = 'Your password was changed. You can now sign in.';
                header('Location: login.php');
                exit();
            }
        }
    }
}
if (!empty($_SESSION['reset_info'])) { $info = $_SESSION['reset_info']; unset($_SESSION['reset_info']); }
$rs = $_SESSION['reset'] ?? null;
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
