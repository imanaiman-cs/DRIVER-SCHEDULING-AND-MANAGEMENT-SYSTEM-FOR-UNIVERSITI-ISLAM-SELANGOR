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
require_once 'includes/admin_page.php';     // appHead()

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
            } else { $errors[$step === 'code' ? 'code' : 'email'] = $res['message']; }   // on the code step the message shows under the code
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
$err = fn($k) => isset($errors[$k]) ? '<div class="mt-1 block text-xs text-error">' . htmlspecialchars($errors[$k]) . '</div>' : '';
$val = fn($k) => htmlspecialchars((string)$v[$k]);
$tok = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
?>
<!DOCTYPE html>
<html lang="en" data-theme="uis">
<head>
<?php appHead('Register'); ?>
</head>
<body class="flex min-h-screen flex-col bg-base-200">
<div class="fixed end-3 top-3 z-50"><?= languageSwitcher() ?></div>
<main class="flex flex-1 items-center justify-center px-4 py-10">
<div class="w-full max-w-lg">
    <div class="mb-6 flex flex-col items-center text-center">
        <img src="assets/images/uis-favicon.png" alt="UIS" class="mb-4 size-12">
        <h1 class="text-xl font-medium">Register an account</h1>
        <p class="text-sm text-base-content/60">For Staff and Heads of Department. Driver accounts are registered by the Vehicle Section.</p>
    </div>
    <div class="card border border-base-300 bg-base-100"><div class="card-body">
    <?php if ($info): ?><div class="alert alert-soft alert-success mb-4 text-sm"><i class="fas fa-envelope-circle-check me-1"></i><?= htmlspecialchars($info) ?></div><?php endif; ?>
    <?php if ($step === 'start'): ?>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="request_code">
            <p class="text-sm text-base-content/60">Enter your staff ID and your UIS e-mail. We send a verification code to that e-mail.</p>
            <div class="mb-3"><label class="mb-1 block text-sm font-medium" for="username">Staff ID *</label>
                <input class="input w-full" id="username" name="username" maxlength="50" required autocomplete="username" value="<?= $val('username') ?>"><?= $err('username') ?></div>
            <div class="mb-3"><label class="mb-1 block text-sm font-medium" for="email">UIS e-mail *</label>
                <input type="email" class="input w-full" id="email" name="email" maxlength="100" required autocomplete="email" value="<?= $val('email') ?>"><?= $err('email') ?></div>
            <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-paper-plane me-1"></i>Request code</button>
            <p class="text-center text-sm mt-3 mb-0">Already have an account? <a class="link link-primary" href="login.php">Sign in</a></p>
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
            <form method="post" class="inline"><?= $tok ?><input type="hidden" name="action" value="restart"><button class="link link-primary text-sm" type="submit">Use another e-mail</button></form>
        </div>
    <?php else: ?>
        <p class="text-sm text-base-content/60"><i class="fas fa-circle-check text-success me-1"></i>Verified: <strong><?= htmlspecialchars($state['staff_id']) ?></strong> (<?= htmlspecialchars($state['email']) ?>)</p>
        <form method="post" novalidate><?= $tok ?><input type="hidden" name="action" value="register">
            <div class="grid-12 grid grid-cols-12 gap-4">
                <div class="col-span-12"><label class="mb-1 block text-sm font-medium" for="full_name">Full name *</label>
                    <input class="input w-full" id="full_name" name="full_name" maxlength="100" required autocomplete="name" value="<?= $val('full_name') ?>"><?= $err('full_name') ?></div>
                <div class="col-span-12 sm:col-span-6"><label class="mb-1 block text-sm font-medium" for="phone">Phone number</label>
                    <input class="input w-full" id="phone" name="phone" maxlength="20" autocomplete="tel" value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
                <div class="col-span-12 sm:col-span-6"><label class="mb-1 block text-sm font-medium" for="role">Role *</label>
                    <select class="select w-full" id="role" name="role"><option value="staff" <?= $v['role'] === 'staff' ? 'selected' : '' ?>>Staff</option><option value="supervisor" <?= $v['role'] === 'supervisor' ? 'selected' : '' ?>>Head of Department</option></select><?= $err('role') ?></div>
                <div class="col-span-12"><label class="mb-1 block text-sm font-medium" for="department">PTJ (department) *</label>
                    <select class="select w-full" id="department" name="department" required><option value="">Choose...</option>
                        <?php foreach ($departments as $d): ?><option value="<?= htmlspecialchars($d) ?>" <?= $v['department'] === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option><?php endforeach; ?></select><?= $err('department') ?></div>
                <div class="col-span-12 sm:col-span-6"><label class="mb-1 block text-sm font-medium" for="password">Password *</label>
                    <input type="password" class="input w-full" id="password" name="password" required autocomplete="new-password" maxlength="72"><?= $err('password') ?>
                    <div class="mt-1 block text-xs text-base-content/60">At least 8 characters, with letters and digits.</div></div>
                <div class="col-span-12 sm:col-span-6"><label class="mb-1 block text-sm font-medium" for="confirm_password">Confirm password *</label>
                    <input type="password" class="input w-full" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72"><?= $err('confirm_password') ?></div>
            </div>
            <button class="btn btn-primary btn-block mt-4" type="submit"><i class="fas fa-user-plus me-1"></i>Register</button>
        </form>
    <?php endif; ?>
    </div></div>
</div>
</main>
</body>
</html>
