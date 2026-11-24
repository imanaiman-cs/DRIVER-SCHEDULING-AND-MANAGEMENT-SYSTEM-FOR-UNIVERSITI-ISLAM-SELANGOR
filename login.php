<?php
require_once 'config/database.php';

$error   = '';
$success = '';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . roleHomeUrl());
    exit();
}

// Handle logged-out message
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been successfully logged out.';
}
// Notice left by the idle timeout / deactivated account, and success messages of Register / Reset Password
if (!empty($_SESSION['login_notice'])) {
    $error = $_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
}
if (!empty($_SESSION['login_success'])) {
    $success = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}

// Login is not a use case (SRS UC015 note): staff ID or UIS email + password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $generic  = 'Invalid staff ID or password. Please try again.';

    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your page expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both your staff ID and password.';
    } else {
        $user = dbOne($conn, 'SELECT * FROM users WHERE staff_no = ? OR email = ? LIMIT 1', [$username, strtolower($username)]);
        $maxAttempts = max(1, getSettingInt($conn, 'MAX_FAILED_ATTEMPTS'));
        $lockMinutes = max(1, getSettingInt($conn, 'LOCKOUT_MINUTES'));

        if (!$user) {
            $error = $generic;
        } elseif (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $left = max(1, (int)ceil((strtotime($user['locked_until']) - time()) / 60));
            $error = "Too many failed attempts. Login is blocked for $lockMinutes minutes. Please try again in $left minute" . ($left > 1 ? 's' : '') . '. You can also use Forgot Password.';
        } elseif (!verifyPassword($password, $user['password'])) {
            $uid = (int)$user['user_id'];
            $n = (int)$user['failed_attempts'] + 1;
            if ($n >= $maxAttempts) {
                dbExec($conn, 'UPDATE users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE user_id = ?', [$lockMinutes, $uid]);
                $error = "Too many failed attempts. Login is blocked for $lockMinutes minutes.";
            } else {
                dbExec($conn, 'UPDATE users SET failed_attempts = ? WHERE user_id = ?', [$n, $uid]);
                $error = $generic;
            }
        } elseif ($user['status'] !== 'active') {
            $error = 'This account is not active. Please contact the Vehicle Section.';
        } else {
            $uid = (int)$user['user_id'];
            dbExec($conn, 'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE user_id = ?', [$uid]);
            upgradeLegacyPassword($conn, $uid, $password, (string)$user['password']);
            session_regenerate_id(true);                // new session id after authentication (prevents session fixation)
            $_SESSION['user_id']       = $uid;
            $_SESSION['username']      = $user['staff_no'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['full_name']     = $user['full_name'];
            $_SESSION['department_id'] = $user['department_id'];
            $_SESSION['driver_id']     = ($d = dbValue($conn, 'SELECT driver_id FROM drivers WHERE user_id = ?', [$uid])) ? (int)$d : null;
            $_SESSION['last_activity'] = time();
            header('Location: ' . roleHomeUrl($user['role']));
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="uis-light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="assets/images/uis-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Condensed:wght@600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
    <?php uisThemeHead(); ?>
</head>
<body class="uis-page flex min-h-screen flex-col">

    <div class="flex justify-end gap-2 px-4 pt-4">
        <?= uisLangSwitch() ?>
        <?= uisThemeSwitch() ?>
    </div>

    <main class="flex flex-1 items-center justify-center px-4 pt-4 pb-10">
        <div class="w-full max-w-md overflow-hidden rounded-lg bg-base-100 shadow-2xl">

            <div class="relative bg-gradient-to-br from-[#07301f] to-primary px-6 pt-9 pb-7 text-center text-white">
                <span class="mx-auto mb-4 grid size-20 place-items-center rounded-full bg-white shadow-lg ring-4 ring-secondary/60">
                    <img src="assets/images/uis-favicon.png" alt="UIS crest" class="size-14 object-contain">
                </span>
                <h1 class="text-xl font-bold">Universiti Islam Selangor</h1>
                <div class="mx-auto my-3 h-0.5 w-12 rounded-full bg-secondary"></div>
                <p class="text-xs tracking-[0.12em] text-white/75 uppercase">Driver Scheduling &amp; Management System</p>
            </div>

            <div class="px-8 pt-7 pb-6">
                <h2 class="text-lg font-bold text-primary">Welcome Back</h2>
                <p class="mb-5 text-sm text-base-content/60">Sign in to your account to continue</p>

                <?php if (!empty($error)): ?>
                <div class="alert alert-soft alert-error mb-4 text-sm" role="alert">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                <div class="alert alert-soft alert-success mb-4 text-sm" role="alert">
                    <i class="fas fa-circle-check" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="login.php" id="loginForm" novalidate autocomplete="off" class="flex flex-col gap-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

                    <div>
                        <label for="username" class="mb-1.5 block text-xs font-bold tracking-[0.08em] uppercase">Staff ID or UIS email</label>
                        <label class="input input-lg w-full">
                            <i class="fas fa-user text-primary" aria-hidden="true"></i>
                            <input type="text" id="username" name="username" class="grow text-base" placeholder="e.g. stf001"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus autocomplete="username" maxlength="100">
                        </label>
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-xs font-bold tracking-[0.08em] uppercase">Password</label>
                        <label class="input input-lg w-full">
                            <i class="fas fa-lock text-primary" aria-hidden="true"></i>
                            <input type="password" id="password" name="password" class="grow text-base" placeholder="Enter your password"
                                   required autocomplete="current-password" maxlength="255">
                            <button type="button" class="btn btn-ghost btn-xs btn-square" id="togglePassword" aria-label="Show password" title="Show / hide password">
                                <i class="fas fa-eye" id="toggleIcon" aria-hidden="true"></i>
                            </button>
                        </label>
                    </div>

                    <div class="-mt-1 text-end text-sm"><a class="link link-primary" href="forgot_password.php">Forgot Password?</a></div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-1 bg-gradient-to-r from-primary to-[#15804f]" id="loginBtn">
                        <span class="loading loading-spinner loading-sm hidden" id="loginSpinner" aria-hidden="true"></span>
                        <i class="fas fa-right-to-bracket" aria-hidden="true"></i>
                        <span id="loginBtnText">Sign In</span>
                    </button>
                </form>
                <p class="mt-5 text-center text-sm">First Time User? <a class="link link-primary font-semibold" href="register.php">Register Now</a></p>
            </div>

            <div class="border-t border-base-300 bg-base-200 px-6 py-3.5 text-center text-sm text-base-content/70">
                <i class="fas fa-shield-halved me-1 text-primary" aria-hidden="true"></i>Authorised personnel only &mdash; <strong class="text-primary">UIS</strong> Internal System
            </div>
        </div>
    </main>

    <footer class="pb-6 text-center text-xs text-base-content/65">
        &copy; <?= date('Y') ?> Universiti Islam Selangor. All rights reserved.
    </footer>

    <script>
    (function () {
        'use strict';

        /* Password show/hide */
        const toggleBtn  = document.getElementById('togglePassword');
        const pwdInput   = document.getElementById('password');
        const toggleIcon = document.getElementById('toggleIcon');
        if (toggleBtn && pwdInput) {
            toggleBtn.addEventListener('click', function () {
                const isHidden = pwdInput.type === 'password';
                pwdInput.type = isHidden ? 'text' : 'password';
                toggleIcon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
                toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        }

        /* Submit: check both fields, then show a spinner */
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
                    (username ? document.getElementById('password') : document.getElementById('username')).focus();
                    return;
                }
                loginBtn.disabled = true;
                loginSpinner.classList.remove('hidden');
                loginBtnText.textContent = 'Signing in…';
            });
        }
    })();
    </script>
</body>
</html>
