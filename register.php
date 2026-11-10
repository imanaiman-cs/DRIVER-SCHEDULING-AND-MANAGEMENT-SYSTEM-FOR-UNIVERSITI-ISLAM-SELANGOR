<?php
// ============================================================
// register.php  -  Staff register their own account (SRS summary of 10 Nov 2026)
// Anyone can register as Staff. Drivers, Heads of Department and administrators are created by the
// Administrative Assistant / Head of Admin (admin/users.php). The staff ID is the login name.
// Requests of the new account are approved by the Head of Department of the chosen PTJ.
// ============================================================
require_once 'config/database.php';

if (isLoggedIn()) { header('Location: ' . roleHomeUrl()); exit(); }

$departments = departmentChoices($conn);
$v = ['username' => '', 'full_name' => '', 'email' => '', 'phone' => '', 'department' => '', 'role' => 'staff'];
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // nobody is signed in here, so the global CSRF check does not apply: check the token of the session ourselves
    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        securityStop(419, 'Your page expired. Please go back, reload it and try again.');
    }
    [$v, $errors] = accountInput($conn, $_POST, true);
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');
    if ($v['department'] !== '' && !in_array($v['department'], $departments, true)) { $errors['department'] = 'Choose your PTJ from the list.'; }
    if (($problem = passwordProblem($password)) !== null) { $errors['password'] = $problem; }
    elseif ($password !== $confirm) { $errors['confirm_password'] = 'The two passwords do not match.'; }
    if (!$errors) {
        try {
            $id = createAccount($conn, $v, 'staff', $password);   // the role is always Staff here, whatever is posted
            auditLog($conn, 'user_register', null, 'Staff ' . $v['username'] . ' (' . $v['department'] . ') registered', $id, 'staff');
            $done = true;
        } catch (mysqli_sql_exception $ex) {
            $errors['username'] = 'This staff ID or e-mail address already has an account.';   // two people registering at the same moment
        }
    }
}
$err = fn($k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . htmlspecialchars($errors[$k]) . '</div>' : '';
$val = fn($k) => htmlspecialchars((string)$v[$k]);
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
        <h5 class="fw-bold mt-2 mb-0" style="color:#0b5d3b">Register as Staff</h5>
        <p class="text-muted small mb-2">Create your account to request a vehicle from the UIS Vehicle Section.</p>
    </div>
    <div class="card-body p-4 pt-2">
    <?php if ($done): ?>
        <div class="alert alert-success mb-3"><i class="fas fa-circle-check me-1"></i>Your account was created. You can sign in with your staff ID and password.</div>
        <a class="btn btn-uis w-100" href="login.php"><i class="fas fa-right-to-bracket me-1"></i>Go to sign in</a>
    <?php else: ?>
        <?php if (!$departments): ?><div class="alert alert-warning">No PTJ is open for registration yet. Please contact the Vehicle Section.</div><?php endif; ?>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <div class="row g-3">
                <div class="col-sm-6"><label class="form-label" for="username">Staff ID *</label>
                    <input class="form-control" id="username" name="username" maxlength="50" required autocomplete="username" value="<?= $val('username') ?>"><?= $err('username') ?></div>
                <div class="col-sm-6"><label class="form-label" for="phone">Phone number</label>
                    <input class="form-control" id="phone" name="phone" maxlength="20" autocomplete="tel" value="<?= $val('phone') ?>"><?= $err('phone') ?></div>
                <div class="col-12"><label class="form-label" for="full_name">Full name *</label>
                    <input class="form-control" id="full_name" name="full_name" maxlength="100" required autocomplete="name" value="<?= $val('full_name') ?>"><?= $err('full_name') ?></div>
                <div class="col-12"><label class="form-label" for="email">E-mail *</label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="100" required autocomplete="email" value="<?= $val('email') ?>"><?= $err('email') ?></div>
                <div class="col-12"><label class="form-label" for="department">PTJ (department) *</label>
                    <select class="form-select" id="department" name="department" required><option value="">Choose...</option>
                        <?php foreach ($departments as $d): ?><option <?= $v['department'] === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option><?php endforeach; ?></select>
                    <?= $err('department') ?><div class="form-text">Your requests are approved by the Head of Department of this PTJ.</div></div>
                <div class="col-sm-6"><label class="form-label" for="password">Password *</label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" maxlength="72"><?= $err('password') ?>
                    <div class="form-text">At least 8 characters, with letters and digits.</div></div>
                <div class="col-sm-6"><label class="form-label" for="confirm_password">Confirm password *</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" maxlength="72"><?= $err('confirm_password') ?></div>
            </div>
            <button class="btn btn-uis w-100 mt-4" type="submit" <?= $departments ? '' : 'disabled' ?>><i class="fas fa-user-plus me-1"></i>Create account</button>
            <p class="text-center small mt-3 mb-0">Already have an account? <a href="login.php">Sign in</a></p>
        </form>
    <?php endif; ?>
    </div>
</div>
</body>
</html>
