<?php
// ============================================================
// forgot_password.php  -  UC015 Reset Password (Staff, Driver, Administrative Assistant, Head of Admin)
//   1. staff ID + UIS e-mail must match an active account (exception 2.1) -> a verification code is sent (UC014)
//   2. the code (Resend Code, A1; wrong code 3.1)
//   3. new password + confirm (4.1) -> the password is changed, the sign-in page shows a success message
// Nothing tells a visitor whether an account exists except 2.1, as the SRS asks.
// ============================================================
require_once 'config/database.php';
require_once 'includes/admin_page.php';     // appHead()

if (isLoggedIn()) { header('Location: ' . roleHomeUrl()); exit(); }

$state = $_SESSION['pwreset'] ?? null;            // ['staff_id', 'email', 'step' => 'code'|'password']
$step = $state['step'] ?? 'start';
$errors = [];
$info = '';
$v = ['username' => $state['staff_id'] ?? '', 'email' => $state['email'] ?? ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        securityStop(419, 'Your page expired. Please go back, reload it and try again.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'restart') { unset($_SESSION['pwreset']); header('Location: forgot_password.php'); exit(); }
    if ($action === 'request_code' || $action === 'resend') {
        $staffId = $action === 'resend' ? (string)($state['staff_id'] ?? '') : trim((string)($_POST['username'] ?? ''));
        $email   = $action === 'resend' ? (string)($state['email'] ?? '') : strtolower(trim((string)($_POST['email'] ?? '')));
        $v = ['username' => $staffId, 'email' => $email];
        $q = $conn->prepare('SELECT user_id FROM users WHERE username = ? AND LOWER(email) = ? AND is_active = 1 LIMIT 1');
        $q->bind_param('ss', $staffId, $email);
        $q->execute();
        $found = (bool)$q->get_result()->fetch_row();
        $q->close();
        if ($staffId === '' || $email === '') { $errors['username'] = 'Please fill in the staff ID and the UIS e-mail.'; }
        elseif (!$found) { $errors['username'] = 'The account is not found: the staff ID and the e-mail do not match a registered account.'; }   // 2.1
        else {
            $res = sendVerificationCode($conn, $email, $staffId, 'reset');
            if ($res['ok']) { $_SESSION['pwreset'] = ['staff_id' => $staffId, 'email' => $email, 'step' => 'code']; $state = $_SESSION['pwreset']; $step = 'code'; $info = $res['message']; }
            else { $errors[$step === 'code' ? 'code' : 'email'] = $res['message']; }   // on the code step the message shows under the code
        }
    } elseif ($action === 'verify' && $step === 'code') {
        $res = checkVerificationCode($conn, $state['email'], 'reset', (string)($_POST['code'] ?? ''));
        if ($res['ok']) { $_SESSION['pwreset']['step'] = 'password'; $step = 'password'; $state = $_SESSION['pwreset']; }
        else { $errors['code'] = $res['message']; }
    } elseif ($action === 'save' && $step === 'password' && isVerified($conn, $state['email'], 'reset')) {
        $password = (string)($_POST['password'] ?? '');
        if (($problem = passwordProblem($password)) !== null) { $errors['password'] = $problem; }
        elseif ($password !== (string)($_POST['confirm_password'] ?? '')) { $errors['confirm_password'] = 'The two passwords do not match.'; }   // 4.1
        else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE username = ? AND LOWER(email) = ? AND is_active = 1');
            $stmt->bind_param('sss', $hash, $state['staff_id'], $state['email']);
            $stmt->execute();
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            if ($changed) {
                $q = $conn->prepare('SELECT user_id, role FROM users WHERE username = ?');
                $q->bind_param('s', $state['staff_id']);
                $q->execute();
                $u = $q->get_result()->fetch_assoc();
                $q->close();
                auditLog($conn, 'password_reset', null, 'Password reset with a verification code (' . $state['staff_id'] . ')', (int)$u['user_id'], (string)$u['role']);
                consumeVerification($conn, $state['email'], 'reset');
                unset($_SESSION['pwreset']);
                $_SESSION['login_success'] = 'Your password was changed. You can sign in with the new password.';
                header('Location: login.php');
                exit();
            }
            $errors['password'] = 'The password could not be changed. Please start again.';
        }
    } else {
        unset($_SESSION['pwreset']); $step = 'start'; $errors['username'] = 'Please start again.';
    }
}
$err = fn($k) => isset($errors[$k]) ? '<div class="mt-1 block text-xs text-error">' . htmlspecialchars($errors[$k]) . '</div>' : '';
$tok = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
?>
<!DOCTYPE html>
<html lang="en" data-theme="uis">
<head>
<?php appHead('Forgot password'); ?>
</head>
<body class="flex min-h-screen flex-col bg-base-200">
<div class="fixed end-3 top-3 z-50"><?= languageSwitcher() ?></div>
<main class="flex flex-1 items-center justify-center px-4 py-10">
<div class="w-full max-w-sm">
    <div class="mb-6 flex flex-col items-center text-center">
        <img src="assets/images/uis-favicon.png" alt="UIS" class="mb-4 size-12">
        <h1 class="text-xl font-medium">Reset password</h1>
        <p class="text-sm text-base-content/60">Use the verification code sent to your UIS e-mail.</p>
    </div>
    <div class="card border border-base-300 bg-base-100"><div class="card-body">
    <?php if ($info): ?><div class="alert alert-soft alert-success mb-4 text-sm"><i class="fas fa-envelope-circle-check me-1"></i><?= htmlspecialchars($info) ?></div><?php endif; ?>
    <?php if ($step === 'start'): ?>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="request_code">
            <div class="mb-3"><label class="mb-1 block text-sm font-medium" for="username">Staff ID *</label>
                <input class="input w-full" id="username" name="username" maxlength="50" required autocomplete="username" value="<?= htmlspecialchars($v['username']) ?>"><?= $err('username') ?></div>
            <div class="mb-3"><label class="mb-1 block text-sm font-medium" for="email">UIS e-mail *</label>
                <input type="email" class="input w-full" id="email" name="email" maxlength="100" required autocomplete="email" value="<?= htmlspecialchars($v['email']) ?>"><?= $err('email') ?></div>
            <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-paper-plane me-1"></i>Request code</button>
            <p class="text-center text-sm mt-3 mb-0"><a class="link link-primary" href="login.php">Back to sign in</a></p>
        </form>
    <?php elseif ($step === 'code'): ?>
        <p class="text-sm text-base-content/60">A verification code was sent to <strong><?= htmlspecialchars($state['email']) ?></strong>. It is valid for <?= VERIFICATION_MINUTES ?> minutes.</p>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="verify">
            <div class="mb-3"><label class="mb-1 block text-sm font-medium" for="code">Verification code *</label>
                <input class="input input-lg w-full text-center" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required style="letter-spacing:.3em"><?= $err('code') ?></div>
            <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-check me-1"></i>Submit</button>
        </form>
        <div class="flex justify-between mt-3 text-sm">
            <form method="post" class="inline"><?= $tok ?><input type="hidden" name="action" value="resend"><button class="link link-primary text-sm" type="submit">Resend code</button></form>
            <form method="post" class="inline"><?= $tok ?><input type="hidden" name="action" value="restart"><button class="link link-primary text-sm" type="submit">Start again</button></form>
        </div>
    <?php else: ?>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="save">
            <div class="mb-3"><label class="mb-1 block text-sm font-medium" for="password">New password *</label>
                <input type="password" class="input w-full" id="password" name="password" required autocomplete="new-password" maxlength="72"><?= $err('password') ?>
                <div class="mt-1 block text-xs text-base-content/60">At least 8 characters, with letters and digits.</div></div>
            <div class="mb-3"><label class="mb-1 block text-sm font-medium" for="confirm_password">Confirm password *</label>
                <input type="password" class="input w-full" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72"><?= $err('confirm_password') ?></div>
            <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-key me-1"></i>Save</button>
        </form>
    <?php endif; ?>
    </div></div>
</div>
</main>
</body>
</html>
