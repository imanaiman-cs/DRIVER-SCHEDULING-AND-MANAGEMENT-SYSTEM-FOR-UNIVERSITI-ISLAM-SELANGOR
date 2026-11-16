<?php
require_once 'config/database.php';

$error   = '';
$success = '';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } elseif (isStaff()) {
        header('Location: staff/dashboard.php');
    } elseif (isSupervisor()) {
        header('Location: supervisor/dashboard.php');
    } else {
        header('Location: driver/dashboard.php');
    }
    exit();
}

// Handle logged-out message
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been successfully logged out.';
}
// Success notice (account created, password reset)
if (!empty($_SESSION['login_success'])) {
    $success = $_SESSION['login_success'];
    unset($_SESSION['login_success']);
}
// Notice left by the idle timeout / deactivated account
if (!empty($_SESSION['login_notice'])) {
    $error = $_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $generic  = 'Invalid username or password. Please try again.';

    if (!hash_equals(csrfToken(), (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your page expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT u.*, (SELECT dn.department_name FROM departments dn WHERE dn.department_id = u.department_id) AS department FROM users u WHERE u.username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $maxAttempts = max(1, getSettingInt($conn, 'MAX_FAILED_ATTEMPTS'));
        $lockMinutes = max(1, getSettingInt($conn, 'LOCKOUT_MINUTES'));

        if (!$user) {
            $error = $generic;
        } elseif (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            // login is blocked for LOCKOUT_MINUTES (15) after MAX_FAILED_ATTEMPTS (5) wrong passwords; it opens by itself
            $left = max(1, (int)ceil((strtotime($user['locked_until']) - time()) / 60));
            $error = "Too many failed attempts. Login is blocked for $lockMinutes minutes. Please try again in $left minute" . ($left > 1 ? 's' : '') . '.';
        } elseif (!verifyPassword($password, $user['password'])) {
            $uid = (int)$user['user_id'];
            // an old block that has already expired starts the count again
            $n = (int)$user['failed_attempts'] + 1;
            if ($n >= $maxAttempts) {
                $st = $conn->prepare('UPDATE users SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE user_id = ?');
                $st->bind_param('ii', $lockMinutes, $uid);
                $st->execute();
                $st->close();
                $error = "Too many failed attempts. Login is blocked for $lockMinutes minutes.";
            } else {
                $st = $conn->prepare('UPDATE users SET failed_attempts = ? WHERE user_id = ?');
                $st->bind_param('ii', $n, $uid);
                $st->execute();
                $st->close();
                $error = $generic;
            }
        } elseif ((int)($user['is_active'] ?? 1) === 0) {
            $error = 'This account is not active. Please contact the Vehicle Section.';
        } else {
            $uid = (int)$user['user_id'];
            $st = $conn->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE user_id = ?');
            $st->bind_param('i', $uid);
            $st->execute();
            $st->close();
            upgradeLegacyPassword($conn, $uid, $password, (string)$user['password']);

            // new session id after authentication (prevents session fixation)
            session_regenerate_id(true);
            unset($_SESSION['lang_synced']);

            $driver_id = ($user['role'] === 'driver' && isset($user['driver_id'])) ? (int)$user['driver_id'] : null;
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
            $_SESSION['driver_id'] = $driver_id;
            $_SESSION['department']    = $user['department']    ?? null;
            $_SESSION['department_id'] = $user['department_id'] !== null ? (int)$user['department_id'] : null;
            $_SESSION['last_activity'] = time();
            if (isset($_COOKIE['dsms_lang'])) { setLanguage($conn, (string)$_COOKIE['dsms_lang']); } else { syncLanguageFromAccount($conn); }   // a language chosen on the login page is kept

            if (in_array($user['role'], ['admin', 'head_admin'], true)) {
                header('Location: admin/dashboard.php');
            } elseif ($user['role'] === 'staff') {
                header('Location: staff/dashboard.php');
            } elseif ($user['role'] === 'supervisor') {
                header('Location: supervisor/dashboard.php');
            } else {
                header('Location: driver/dashboard.php');
            }
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="uis">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="assets/images/uis-favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans+Condensed:wght@600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="flex min-h-screen flex-col bg-base-200">
    <div class="fixed end-3 top-3 z-50"><?= languageSwitcher() ?></div>

    <main class="flex flex-1 items-center justify-center px-4 py-10">
        <div class="w-full max-w-sm">

            <div class="mb-6 flex flex-col items-center text-center">
                <img src="assets/images/uis-favicon.png" alt="Universiti Islam Selangor" class="mb-4 size-12">
                <h1 class="text-xl font-medium">Sign in to UIS Driver Management</h1>
            </div>

            <div class="card border border-base-300 bg-base-100">
                <div class="card-body gap-4">

                    <?php if (!empty($error)): ?>
                    <div class="alert alert-soft alert-error text-sm" role="alert">
                        <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                    <div class="alert alert-soft alert-success text-sm" role="alert">
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($success) ?></span>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" id="loginForm" novalidate autocomplete="off" class="flex flex-col gap-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

                        <div>
                            <label for="username" class="mb-1 block text-sm font-medium">Username</label>
                            <label class="input w-full">
                                <i class="fas fa-user text-base-content/40" aria-hidden="true"></i>
                                <input type="text" id="username" name="username" class="grow" placeholder="Your username"
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus autocomplete="username" maxlength="100">
                            </label>
                        </div>

                        <div>
                            <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                            <label class="input w-full">
                                <i class="fas fa-lock text-base-content/40" aria-hidden="true"></i>
                                <input type="password" id="password" name="password" class="grow" placeholder="Your password"
                                       required autocomplete="current-password" maxlength="255">
                                <button type="button" class="btn btn-ghost btn-xs btn-square" id="togglePassword" aria-label="Show password" title="Show / hide password">
                                    <i class="fas fa-eye" id="toggleIcon" aria-hidden="true"></i>
                                </button>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-block" id="loginBtn">
                            <span class="loading loading-spinner loading-sm hidden" id="loginSpinner" aria-hidden="true"></span>
                            <span id="loginBtnText">Sign in</span>
                        </button>
                    </form>

                    <div class="mt-5 space-y-1 border-t border-base-300 pt-4 text-center text-sm">
                        <p><a class="link link-primary" href="forgot_password.php">Forgot password?</a></p>
                        <p>First time user? <a class="link link-primary" href="register.php">Register now</a></p>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <footer class="pb-6 text-center text-xs text-base-content/60">
        &copy; <?= date('Y') ?> Universiti Islam Selangor. For authorised staff only.
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
