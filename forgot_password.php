<?php
// ============================================================
// forgot_password.php  -  UC015 Reset Password (Staff, Driver, Administrative Assistant, Head of Admin)
//   1. staff ID + UIS e-mail must match an active account (exception 2.1) -> a verification code is sent (UC014)
//   2. the code (Resend Code, A1; wrong code 3.1)
//   3. new password + confirm (4.1) -> the password is changed, the sign-in page shows a success message
// Nothing tells a visitor whether an account exists except 2.1, as the SRS asks.
// ============================================================
require_once 'config/database.php';

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
            else { $errors['email'] = $res['message']; }
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
$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$k]) . '</div>' : '';
$tok = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body{background:linear-gradient(160deg,#06291b,#0b5d3b 55%,#15804f);min-height:100vh}
        .reg-card{max-width:30rem;margin:8vh auto;border:0;border-radius:18px;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden}
        .reg-head{background:#fff;text-align:center;padding:1.5rem 1.5rem .5rem}.reg-head img{height:64px}
        .btn-uis{background:linear-gradient(135deg,#0b5d3b,#15804f);color:#fff;border:0;font-weight:600;padding:.65rem}.btn-uis:hover{color:#fff;filter:brightness(1.08)}
        .form-label{font-weight:600;font-size:.82rem}
        .lang-switch{position:fixed;top:12px;right:12px;z-index:50;display:inline-flex;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;font-size:.8rem;font-weight:600;background:#fff}
        .lang-switch .lang-link{padding:.25rem .65rem;color:#475569;text-decoration:none}.lang-switch .lang-link.active{background:#0b5d3b;color:#fff}
    </style>
</head>
<body>
<?= languageSwitcher() ?>
<div class="card reg-card">
    <div class="reg-head"><img src="assets/images/uis-favicon.png" alt="UIS"><h5 class="fw-bold mt-2 mb-0" style="color:#0b5d3b">Reset password</h5><p class="text-muted small mb-2">Use the verification code sent to your UIS e-mail.</p></div>
    <div class="card-body p-4 pt-2">
    <?php if ($info): ?><div class="alert alert-success py-2"><i class="fas fa-envelope-circle-check me-1"></i><?= htmlspecialchars($info) ?></div><?php endif; ?>
    <?php if ($step === 'start'): ?>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="request_code">
            <div class="mb-3"><label class="form-label" for="username">Staff ID *</label>
                <input class="form-control" id="username" name="username" maxlength="50" required autocomplete="username" value="<?= htmlspecialchars($v['username']) ?>"><?= $err('username') ?></div>
            <div class="mb-3"><label class="form-label" for="email">UIS e-mail *</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="100" required autocomplete="email" value="<?= htmlspecialchars($v['email']) ?>"><?= $err('email') ?></div>
            <button class="btn btn-uis w-100" type="submit"><i class="fas fa-paper-plane me-1"></i>Request code</button>
            <p class="text-center small mt-3 mb-0"><a href="login.php">Back to sign in</a></p>
        </form>
    <?php elseif ($step === 'code'): ?>
        <p class="small text-muted">A verification code was sent to <strong><?= htmlspecialchars($state['email']) ?></strong>. It is valid for <?= VERIFICATION_MINUTES ?> minutes.</p>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="verify">
            <div class="mb-3"><label class="form-label" for="code">Verification code *</label>
                <input class="form-control form-control-lg text-center" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required style="letter-spacing:.3em"><?= $err('code') ?></div>
            <button class="btn btn-uis w-100" type="submit"><i class="fas fa-check me-1"></i>Submit</button>
        </form>
        <div class="d-flex justify-content-between mt-3 small">
            <form method="post" class="d-inline"><?= $tok ?><input type="hidden" name="action" value="resend"><button class="btn btn-link p-0 small" type="submit">Resend code</button></form>
            <form method="post" class="d-inline"><?= $tok ?><input type="hidden" name="action" value="restart"><button class="btn btn-link p-0 small" type="submit">Start again</button></form>
        </div>
    <?php else: ?>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="save">
            <div class="mb-3"><label class="form-label" for="password">New password *</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" maxlength="72"><?= $err('password') ?>
                <div class="form-text">At least 8 characters, with letters and digits.</div></div>
            <div class="mb-3"><label class="form-label" for="confirm_password">Confirm password *</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72"><?= $err('confirm_password') ?></div>
            <button class="btn btn-uis w-100" type="submit"><i class="fas fa-key me-1"></i>Save</button>
        </form>
    <?php endif; ?>
    </div>
</div>
</body>
</html>
