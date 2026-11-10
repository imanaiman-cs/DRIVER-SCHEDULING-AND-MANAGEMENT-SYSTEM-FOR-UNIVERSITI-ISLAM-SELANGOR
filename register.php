<?php
// ============================================================
// register.php  -  UC013 Register Account (first-time user: Staff or Head of Department)
//   1. staff ID + UIS e-mail -> Request Code (UC014)     exceptions: already registered (2.1), not a UIS e-mail (2.2)
//   2. the verification code (Resend Code, A1)           exception: wrong code (3.1)
//   3. name, phone, department, role (Staff or Head of Department), password + confirm   exceptions: passwords differ (4.1),
//      the department already has a Head of Department (4.2)
// Driver accounts are registered by the Administrative Assistant (admin/users.php). The Administrative Assistant and Head of Admin
// accounts are created during the initial setup. The staff ID is the login name.
// ============================================================
require_once 'config/database.php';

if (isLoggedIn()) { header('Location: ' . roleHomeUrl()); exit(); }

$departments = allDepartments($conn);
$state = $_SESSION['reg'] ?? null;                 // ['staff_id', 'email', 'step' => 'code'|'details']
$step = $state['step'] ?? 'start';
$errors = [];
$info = '';
$v = ['username' => $state['staff_id'] ?? '', 'full_name' => '', 'email' => $state['email'] ?? '', 'phone' => '', 'department' => '', 'role' => 'staff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // nobody is signed in here, so the global CSRF check does not apply: check the token of the session ourselves
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        securityStop(419, 'Your page expired. Please go back, reload it and try again.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'restart') {
        unset($_SESSION['reg']);
        header('Location: register.php');
        exit();
    }
    if ($action === 'request_code' || $action === 'resend') {                                  // steps 1-2 and A1
        $staffId = $action === 'resend' ? (string)($state['staff_id'] ?? '') : trim((string)($_POST['username'] ?? ''));
        $email   = $action === 'resend' ? (string)($state['email'] ?? '') : strtolower(trim((string)($_POST['email'] ?? '')));
        $v['username'] = $staffId; $v['email'] = $email;
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $staffId) || $email === '') { $errors['username'] = 'Please fill in the staff ID and the UIS e-mail.'; }
        elseif (!isUisEmail($conn, $email)) { $errors['email'] = 'Only a UIS e-mail address (@' . getSetting($conn, 'UIS_EMAIL_DOMAIN', 'uis.edu.my') . ') is accepted.'; }   // 2.2
        else {
            $q = $conn->prepare('SELECT 1 FROM users WHERE username = ? OR email = ? LIMIT 1');
            $q->bind_param('ss', $staffId, $email);
            $q->execute();
            $taken = (bool)$q->get_result()->fetch_row();
            $q->close();
            if ($taken) { $errors['username'] = 'This staff ID or e-mail is already registered. The account already exists.'; }          // 2.1
        }
        if (!$errors) {
            $res = sendVerificationCode($conn, $email, $staffId, 'register');
            if ($res['ok']) {
                $_SESSION['reg'] = ['staff_id' => $staffId, 'email' => $email, 'step' => 'code'];
                $state = $_SESSION['reg']; $step = 'code'; $info = $res['message'];
            } else { $errors['email'] = $res['message']; }
        }
    } elseif ($action === 'verify' && $step === 'code') {                                      // step 3
        $res = checkVerificationCode($conn, $state['email'], 'register', (string)($_POST['code'] ?? ''));
        if ($res['ok']) { $_SESSION['reg']['step'] = 'details'; $step = 'details'; $state = $_SESSION['reg']; }
        else { $errors['code'] = $res['message']; }
    } elseif ($action === 'register' && $step === 'details' && isVerified($conn, $state['email'], 'register')) {   // step 4
        $_POST['username'] = $state['staff_id']; $_POST['email'] = $state['email'];
        [$v, $errors] = accountInput($conn, $_POST, true);
        $role = (string)($_POST['role'] ?? 'staff');
        $role = $role === 'supervisor' ? 'supervisor' : 'staff';                              // the options are Staff and Head of Department
        $v['role'] = $role;
        if ($v['department'] !== '' && !in_array($v['department'], $departments, true)) { $errors['department'] = 'Choose your PTJ from the list.'; }
        $password = (string)($_POST['password'] ?? '');
        if (($problem = passwordProblem($password)) !== null) { $errors['password'] = $problem; }
        elseif ($password !== (string)($_POST['confirm_password'] ?? '')) { $errors['confirm_password'] = 'The two passwords do not match.'; }   // 4.1
        if (!$errors && $role === 'supervisor' && ($existing = headOfDepartmentOf($conn, $v['department']))) {
            $errors['role'] = 'The department already has a Head of Department (' . $existing['full_name'] . ').';                               // 4.2
        }
        if (!$errors) {
            try {
                $id = createAccount($conn, $v, $role, $password);
                if ($role === 'supervisor') { refreshApprovers($conn, $v['department']); }
                auditLog($conn, 'user_register', null, ($role === 'supervisor' ? 'Head of Department ' : 'Staff ') . $v['username'] . ' (' . $v['department'] . ') registered', $id, $role);
                consumeVerification($conn, $v['email'], 'register');
                unset($_SESSION['reg']);
                $_SESSION['login_success'] = 'Your account was created. You can sign in with your staff ID and password.';
                header('Location: login.php');
                exit();
            } catch (mysqli_sql_exception $ex) {
                $errors['username'] = 'This staff ID or e-mail address already has an account.';   // two people registering at the same moment
            }
        }
    } else {
        $errors['code'] = 'Please start again.';
        unset($_SESSION['reg']); $step = 'start';
    }
}
$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$k]) . '</div>' : '';
$val = fn($k) => htmlspecialchars((string)$v[$k]);
$tok = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body{background:linear-gradient(160deg,#06291b,#0b5d3b 55%,#15804f);min-height:100vh}
        .reg-card{max-width:34rem;margin:5vh auto;border:0;border-radius:18px;box-shadow:0 20px 50px rgba(0,0,0,.3);overflow:hidden}
        .reg-head{background:#fff;text-align:center;padding:1.5rem 1.5rem .5rem}
        .reg-head img{height:64px}
        .btn-uis{background:linear-gradient(135deg,#0b5d3b,#15804f);color:#fff;border:0;font-weight:600;padding:.65rem}
        .btn-uis:hover{color:#fff;filter:brightness(1.08)}
        .form-label{font-weight:600;font-size:.82rem}
        .lang-switch{position:fixed;top:12px;right:12px;z-index:50;display:inline-flex;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;font-size:.8rem;font-weight:600;background:#fff}
        .lang-switch .lang-link{padding:.25rem .65rem;color:#475569;text-decoration:none}.lang-switch .lang-link.active{background:#0b5d3b;color:#fff}
    </style>
</head>
<body>
<?= languageSwitcher() ?>
<div class="card reg-card">
    <div class="reg-head">
        <img src="assets/images/uis-favicon.png" alt="UIS">
        <h5 class="fw-bold mt-2 mb-0" style="color:#0b5d3b">Register an account</h5>
        <p class="text-muted small mb-2">For Staff and Heads of Department. Driver accounts are registered by the Vehicle Section.</p>
    </div>
    <div class="card-body p-4 pt-2">
    <?php if ($info): ?><div class="alert alert-success py-2"><i class="fas fa-envelope-circle-check me-1"></i><?= htmlspecialchars($info) ?></div><?php endif; ?>
    <?php if ($step === 'start'): ?>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="request_code">
            <p class="small text-muted">Enter your staff ID and your UIS e-mail. We send a verification code to that e-mail.</p>
            <div class="mb-3"><label class="form-label" for="username">Staff ID *</label>
                <input class="form-control" id="username" name="username" maxlength="50" required autocomplete="username" value="<?= $val('username') ?>"><?= $err('username') ?></div>
            <div class="mb-3"><label class="form-label" for="email">UIS e-mail *</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="100" required autocomplete="email" value="<?= $val('email') ?>"><?= $err('email') ?></div>
            <button class="btn btn-uis w-100" type="submit"><i class="fas fa-paper-plane me-1"></i>Request code</button>
            <p class="text-center small mt-3 mb-0">Already have an account? <a href="login.php">Sign in</a></p>
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
            <form method="post" class="d-inline"><?= $tok ?><input type="hidden" name="action" value="restart"><button class="btn btn-link p-0 small" type="submit">Use another e-mail</button></form>
        </div>
    <?php else: ?>
        <p class="small text-muted"><i class="fas fa-circle-check text-success me-1"></i>Verified: <strong><?= htmlspecialchars($state['staff_id']) ?></strong> (<?= htmlspecialchars($state['email']) ?>)</p>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="register">
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="full_name">Full name *</label>
                    <input class="form-control" id="full_name" name="full_name" maxlength="100" required autocomplete="name" value="<?= $val('full_name') ?>"><?= $err('full_name') ?></div>
                <div class="col-sm-6"><label class="form-label" for="phone">Phone number</label>
                    <input class="form-control" id="phone" name="phone" maxlength="20" autocomplete="tel" value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
                <div class="col-sm-6"><label class="form-label" for="role">Role *</label>
                    <select class="form-select" id="role" name="role"><option value="staff" <?= $v['role'] === 'staff' ? 'selected' : '' ?>>Staff</option><option value="supervisor" <?= $v['role'] === 'supervisor' ? 'selected' : '' ?>>Head of Department</option></select><?= $err('role') ?></div>
                <div class="col-12"><label class="form-label" for="department">PTJ (department) *</label>
                    <select class="form-select" id="department" name="department" required><option value="">Choose...</option>
                        <?php foreach ($departments as $d): ?><option <?= $v['department'] === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option><?php endforeach; ?></select><?= $err('department') ?></div>
                <div class="col-sm-6"><label class="form-label" for="password">Password *</label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" maxlength="72"><?= $err('password') ?>
                    <div class="form-text">At least 8 characters, with letters and digits.</div></div>
                <div class="col-sm-6"><label class="form-label" for="confirm_password">Confirm password *</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72"><?= $err('confirm_password') ?></div>
            </div>
            <button class="btn btn-uis w-100 mt-4" type="submit"><i class="fas fa-user-plus me-1"></i>Register</button>
        </form>
    <?php endif; ?>
    </div>
</div>
</body>
</html>
