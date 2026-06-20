<?php
require_once 'config/database.php';

$error   = '';
$success = '';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: driver/dashboard.php');
    }
    exit();
}

// Handle logged-out message
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been successfully logged out.';
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $authenticated = false;

            if ($user) {
                // Try password_verify (bcrypt) first
                if (password_verify($password, $user['password'])) {
                    $authenticated = true;
                }
                // Fallback: md5 comparison (legacy passwords)
                elseif (md5($password) === $user['password']) {
                    $authenticated = true;
                }
                // Fallback: SHA2-256 comparison
                elseif (hash('sha256', $password) === $user['password']) {
                    $authenticated = true;
                }
                // Fallback: plain-text comparison (development only)
                elseif ($password === $user['password']) {
                    $authenticated = true;
                }
            }

            if ($authenticated) {
                // Fetch associated driver_id if role is driver
                $driver_id = null;
                if ($user['role'] === 'driver') {
                    $dstmt = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ? LIMIT 1");
                    $dstmt->execute([$user['id']]);
                    $driver = $dstmt->fetch(PDO::FETCH_ASSOC);
                    $driver_id = $driver['id'] ?? null;
                }

                // Handle "Remember Me"
                if (!empty($_POST['remember_me'])) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (86400 * 30), '/', '', false, true);
                    // Optionally store token in DB here
                }

                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                $_SESSION['driver_id'] = $driver_id;

                if ($user['role'] === 'admin' || $user['role'] === 'superadmin') {
                    header('Location: admin/dashboard.php');
                } else {
                    header('Location: driver/dashboard.php');
                }
                exit();
            } else {
                $error = 'Invalid username or password. Please try again.';
            }
        } catch (PDOException $e) {
            $error = 'A database error occurred. Please contact the system administrator.';
            // Log actual error server-side: error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; Driver Scheduling &amp; Management System | UIS</title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ============================================================
           CSS CUSTOM PROPERTIES
        ============================================================ */
        :root {
            --uis-primary:    #003580;
            --uis-secondary:  #0056b3;
            --uis-accent:     #ffd700;
            --uis-dark:       #001f4d;
            --uis-light:      #e8f0fe;
            --shadow-sm:      0 2px 8px rgba(0,53,128,.12);
            --shadow-md:      0 8px 32px rgba(0,53,128,.18);
            --shadow-lg:      0 20px 60px rgba(0,53,128,.25);
            --radius-lg:      16px;
            --radius-xl:      24px;
            --transition:     all .3s cubic-bezier(.4,0,.2,1);
        }

        /* ============================================================
           GLOBAL RESET & BASE
        ============================================================ */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(135deg, var(--uis-dark) 0%, var(--uis-primary) 45%, var(--uis-secondary) 100%);
            position: relative;
            overflow-x: hidden;
        }

        /* ============================================================
           ANIMATED BACKGROUND PARTICLES
        ============================================================ */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(255,215,0,.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,.06) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(0,86,179,.15) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        /* Floating geometric shapes */
        .bg-shapes {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            opacity: .07;
            animation: floatShape 8s ease-in-out infinite;
        }

        .shape-1 {
            width: 320px; height: 320px;
            background: var(--uis-accent);
            top: -80px; left: -80px;
            animation-delay: 0s;
        }
        .shape-2 {
            width: 200px; height: 200px;
            background: #ffffff;
            top: 60%; right: -60px;
            animation-delay: 2s;
            animation-duration: 10s;
        }
        .shape-3 {
            width: 140px; height: 140px;
            background: var(--uis-accent);
            bottom: 10%; left: 15%;
            animation-delay: 4s;
            animation-duration: 7s;
        }
        .shape-4 {
            width: 80px; height: 80px;
            background: #ffffff;
            top: 30%; left: 5%;
            animation-delay: 1s;
            animation-duration: 9s;
            border-radius: 20%;
            opacity: .05;
        }
        .shape-5 {
            width: 60px; height: 60px;
            background: var(--uis-accent);
            top: 15%; right: 20%;
            animation-delay: 3s;
            animation-duration: 11s;
            border-radius: 12px;
            opacity: .06;
        }

        @keyframes floatShape {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            33%       { transform: translateY(-20px) rotate(5deg); }
            66%       { transform: translateY(10px) rotate(-3deg); }
        }

        /* ============================================================
           MAIN LAYOUT
        ============================================================ */
        .page-wrapper {
            position: relative;
            z-index: 1;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        /* ============================================================
           LOGIN CARD
        ============================================================ */
        .login-card {
            width: 100%;
            max-width: 460px;
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: slideUp .6s cubic-bezier(.4,0,.2,1) both;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ============================================================
           CARD HEADER / BRANDING
        ============================================================ */
        .card-header-brand {
            background: linear-gradient(135deg, var(--uis-dark) 0%, var(--uis-primary) 60%, var(--uis-secondary) 100%);
            padding: 2.5rem 2rem 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .card-header-brand::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }

        /* University crest / logo icon */
        .university-crest {
            width: 80px; height: 80px;
            background: rgba(255,255,255,.12);
            border: 3px solid rgba(255,215,0,.6);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            position: relative;
            transition: var(--transition);
            box-shadow: 0 0 0 6px rgba(255,215,0,.1), 0 0 30px rgba(255,215,0,.15);
        }

        .university-crest:hover {
            transform: scale(1.05);
            box-shadow: 0 0 0 10px rgba(255,215,0,.12), 0 0 40px rgba(255,215,0,.2);
        }

        .university-crest i {
            font-size: 2.2rem;
            color: var(--uis-accent);
            text-shadow: 0 2px 8px rgba(0,0,0,.3);
        }

        .brand-title {
            color: #ffffff;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: .25rem;
            letter-spacing: .02em;
            text-shadow: 0 1px 3px rgba(0,0,0,.2);
        }

        .brand-subtitle {
            color: rgba(255,255,255,.75);
            font-size: .82rem;
            font-weight: 400;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .divider-gold {
            width: 50px; height: 3px;
            background: linear-gradient(90deg, transparent, var(--uis-accent), transparent);
            border-radius: 2px;
            margin: .85rem auto .5rem;
        }

        /* ============================================================
           CARD BODY / FORM
        ============================================================ */
        .card-body-form {
            padding: 2rem 2.25rem 1.5rem;
        }

        .form-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--uis-primary);
            margin-bottom: .25rem;
        }

        .form-subtitle {
            font-size: .82rem;
            color: #6c757d;
            margin-bottom: 1.5rem;
        }

        /* Input group styling */
        .input-group-custom {
            position: relative;
            margin-bottom: 1.1rem;
        }

        .input-group-custom label {
            display: block;
            font-size: .8rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .35rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--uis-primary);
            font-size: .95rem;
            z-index: 2;
            pointer-events: none;
            transition: var(--transition);
        }

        .form-control-custom {
            width: 100%;
            padding: .7rem 2.8rem .7rem 2.8rem;
            font-size: .9rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            background: #f9fafb;
            color: #111827;
            transition: var(--transition);
            outline: none;
            font-family: 'Inter', sans-serif;
        }

        .form-control-custom:focus {
            border-color: var(--uis-secondary);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(0,86,179,.1);
        }

        .form-control-custom::placeholder { color: #9ca3af; }

        /* Show/hide password toggle */
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            font-size: .9rem;
            cursor: pointer;
            padding: 4px;
            z-index: 2;
            transition: color .2s;
        }

        .toggle-password:hover { color: var(--uis-primary); }

        /* Remember me */
        .remember-row {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-bottom: 1.4rem;
        }

        .remember-row input[type="checkbox"] {
            width: 16px; height: 16px;
            accent-color: var(--uis-primary);
            cursor: pointer;
        }

        .remember-row label {
            font-size: .85rem;
            color: #6b7280;
            cursor: pointer;
            user-select: none;
        }

        /* Login button */
        .btn-login {
            width: 100%;
            padding: .8rem;
            background: linear-gradient(135deg, var(--uis-primary) 0%, var(--uis-secondary) 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: .95rem;
            font-weight: 600;
            letter-spacing: .03em;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            font-family: 'Inter', sans-serif;
        }

        .btn-login::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0);
            transition: background .2s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,53,128,.35);
        }

        .btn-login:hover::after { background: rgba(255,255,255,.07); }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: none;
        }

        .btn-login i { margin-right: .5rem; }

        /* Spinner on submit */
        .btn-login .spinner-border {
            width: 1rem; height: 1rem;
            border-width: 2px;
            display: none;
            margin-right: .5rem;
        }

        /* Alert overrides */
        .alert-custom {
            border-radius: 10px;
            font-size: .875rem;
            padding: .75rem 1rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
            animation: shakeX .4s ease both;
        }

        @keyframes shakeX {
            0%, 100% { transform: translateX(0); }
            20%       { transform: translateX(-6px); }
            40%       { transform: translateX(6px); }
            60%       { transform: translateX(-4px); }
            80%       { transform: translateX(4px); }
        }

        .alert-danger-custom {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }

        .alert-success-custom {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #15803d;
        }

        /* ============================================================
           CARD FOOTER
        ============================================================ */
        .card-footer-info {
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
            padding: 1rem 2.25rem;
            text-align: center;
        }

        .card-footer-info p {
            font-size: .78rem;
            color: #9ca3af;
            margin: 0;
        }

        .card-footer-info strong { color: var(--uis-primary); }

        /* ============================================================
           PAGE FOOTER
        ============================================================ */
        .page-footer {
            position: relative;
            z-index: 1;
            text-align: center;
            padding: 1rem;
            color: rgba(255,255,255,.5);
            font-size: .78rem;
        }

        /* ============================================================
           RESPONSIVE TWEAKS
        ============================================================ */
        @media (max-width: 480px) {
            .card-body-form { padding: 1.5rem 1.25rem 1rem; }
            .card-header-brand { padding: 2rem 1.25rem 1.5rem; }
            .card-footer-info { padding: .85rem 1.25rem; }
            .brand-title { font-size: 1.1rem; }
        }
    </style>
</head>
<body>

    <!-- Animated background shapes -->
    <div class="bg-shapes" aria-hidden="true">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>

    <!-- Main content -->
    <div class="page-wrapper">
        <div class="login-card">

            <!-- ── Branding header ── -->
            <div class="card-header-brand">
                <div class="university-crest">
                    <i class="fas fa-graduation-cap" aria-hidden="true"></i>
                </div>
                <div class="brand-title">Universiti Islam Selangor</div>
                <div class="divider-gold"></div>
                <div class="brand-subtitle">Driver Scheduling &amp; Management System</div>
            </div>

            <!-- ── Form body ── -->
            <div class="card-body-form">

                <div class="form-title">Welcome Back</div>
                <div class="form-subtitle">Sign in to your account to continue</div>

                <!-- Error alert -->
                <?php if (!empty($error)): ?>
                <div class="alert-custom alert-danger-custom" role="alert">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <!-- Success / logged-out alert -->
                <?php if (!empty($success)): ?>
                <div class="alert-custom alert-success-custom" role="alert">
                    <i class="fas fa-circle-check" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="login.php" id="loginForm" novalidate autocomplete="off">

                    <!-- CSRF token -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                    <!-- Username -->
                    <div class="input-group-custom">
                        <label for="username">Username</label>
                        <div class="input-wrap">
                            <span class="input-icon"><i class="fas fa-user" aria-hidden="true"></i></span>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                class="form-control-custom"
                                placeholder="Enter your username"
                                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                required
                                autofocus
                                autocomplete="username"
                                maxlength="100"
                            >
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="input-group-custom">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <span class="input-icon"><i class="fas fa-lock" aria-hidden="true"></i></span>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control-custom"
                                placeholder="Enter your password"
                                required
                                autocomplete="current-password"
                                maxlength="255"
                            >
                            <button
                                type="button"
                                class="toggle-password"
                                id="togglePassword"
                                aria-label="Toggle password visibility"
                                title="Show / hide password"
                            >
                                <i class="fas fa-eye" id="toggleIcon" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember me -->
                    <div class="remember-row">
                        <input type="checkbox" id="remember_me" name="remember_me" value="1">
                        <label for="remember_me">Remember me for 30 days</label>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn-login" id="loginBtn">
                        <span class="spinner-border spinner-border-sm" id="loginSpinner" role="status" aria-hidden="true"></span>
                        <i class="fas fa-right-to-bracket" aria-hidden="true"></i>
                        <span id="loginBtnText">Sign In</span>
                    </button>

                </form>
            </div>

            <!-- ── Card footer info ── -->
            <div class="card-footer-info">
                <p>
                    <i class="fas fa-shield-halved" aria-hidden="true" style="color: var(--uis-primary);"></i>
                    &nbsp;Authorised personnel only &mdash; <strong>UIS</strong> Internal System
                </p>
            </div>

        </div><!-- /.login-card -->
    </div><!-- /.page-wrapper -->

    <!-- Page footer -->
    <footer class="page-footer">
        &copy; <?= date('Y') ?> Universiti Islam Selangor. All rights reserved.
    </footer>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    (function () {
        'use strict';

        /* ── Password show/hide toggle ── */
        const toggleBtn  = document.getElementById('togglePassword');
        const pwdInput   = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');

        if (toggleBtn && pwdInput) {
            toggleBtn.addEventListener('click', function () {
                const isHidden = pwdInput.type === 'password';
                pwdInput.type      = isHidden ? 'text' : 'password';
                toggleIcon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
                toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        }

        /* ── Form submit: loading state ── */
        const loginForm    = document.getElementById('loginForm');
        const loginBtn     = document.getElementById('loginBtn');
        const loginSpinner = document.getElementById('loginSpinner');
        const loginBtnText = document.getElementById('loginBtnText');

        if (loginForm) {
            loginForm.addEventListener('submit', function (e) {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value.trim();

                if (!username || !password) {
                    e.preventDefault();
                    if (!username) document.getElementById('username').focus();
                    else           document.getElementById('password').focus();
                    return;
                }

                // Show spinner
                if (loginBtn && loginSpinner && loginBtnText) {
                    loginBtn.disabled         = true;
                    loginSpinner.style.display = 'inline-block';
                    loginBtnText.textContent   = 'Signing in…';
                }
            });
        }

        /* ── Input focus glow on icon ── */
        document.querySelectorAll('.form-control-custom').forEach(function (input) {
            const icon = input.parentElement.querySelector('.input-icon i');
            input.addEventListener('focus', function () {
                if (icon) icon.style.color = 'var(--uis-secondary)';
            });
            input.addEventListener('blur', function () {
                if (icon) icon.style.color = 'var(--uis-primary)';
            });
        });
    })();
    </script>
</body>
</html>
