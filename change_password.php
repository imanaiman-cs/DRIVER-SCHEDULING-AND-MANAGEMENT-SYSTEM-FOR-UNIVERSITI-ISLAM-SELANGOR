<?php
// ============================================================
// UIS Driver Scheduling and Management System
// change_password.php  –  a signed-in user changes their own password.
// Users with a temporary password (must_change_password) are sent here first.
// ============================================================
require_once 'config/database.php';
require_once 'includes/admin_page.php';
requireLogin();

$forced = false;   // there is no temporary password any more; this page is only kept for the demo and is not in the menu
$error  = '';
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $uid     = (int)$_SESSION['user_id'];

    $q = $conn->prepare('SELECT password FROM users WHERE user_id = ?');
    $q->bind_param('i', $uid);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if (!$row || !verifyPassword($current, $row['password'])) {
        $error = 'Your current password is not correct.';
    } elseif ($new !== $confirm) {
        $error = 'The new password and its confirmation do not match.';
    } elseif ($new === $current) {
        $error = 'The new password must be different from the current one.';
    } elseif (($problem = passwordProblem($new)) !== null) {
        $error = $problem;
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ?');
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, 'password_changed', null, 'Own password changed');
        $done = true;
    }
}

$home = match ($_SESSION['role'] ?? '') {
    'admin', 'superadmin', 'head_admin' => 'admin/dashboard.php',
    'staff'               => 'staff/dashboard.php',
    'supervisor'          => 'supervisor/dashboard.php',
    default               => 'driver/dashboard.php',
};
?>
<!DOCTYPE html>
<html lang="en" data-theme="uis-light">
<head>
<?php appHead('Change password'); ?>
</head>
<body class="min-h-screen bg-base-200">
<main class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="card w-full max-w-sm border border-base-300 bg-base-100">
        <div class="card-body gap-4">
            <h1 class="text-xl font-medium">Change password</h1>
            <?php if ($done): ?>
                <div class="alert alert-soft alert-success text-sm" role="alert"><i class="fas fa-circle-check" aria-hidden="true"></i><span>Your password has been changed</span></div>
                <a class="btn btn-primary btn-block" href="<?= htmlspecialchars($home) ?>">Continue</a>
            <?php else: ?>
                <p class="text-sm text-base-content/60"><?= $forced
                    ? 'You are using a temporary password. Choose your own password before you continue.'
                    : 'At least 8 characters, with a letter and a number.' ?></p>
                <?php if ($error): ?><div class="alert alert-soft alert-error text-sm" role="alert"><i class="fas fa-circle-exclamation" aria-hidden="true"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
                <form method="post" autocomplete="off" class="flex flex-col gap-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <div><label class="mb-1 block text-sm font-medium" for="current_password">Current password</label>
                        <input type="password" class="input w-full" id="current_password" name="current_password" required autocomplete="current-password"></div>
                    <div><label class="mb-1 block text-sm font-medium" for="new_password">New password</label>
                        <input type="password" class="input w-full" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"></div>
                    <div><label class="mb-1 block text-sm font-medium" for="confirm_password">Confirm new password</label>
                        <input type="password" class="input w-full" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
                    <button class="btn btn-primary btn-block" type="submit">Save new password</button>
                    <?php if (!$forced): ?><a class="btn btn-ghost btn-block" href="<?= htmlspecialchars($home) ?>">Cancel</a>
                    <?php else: ?><a class="btn btn-ghost btn-block text-error" href="logout.php">Log out</a><?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
