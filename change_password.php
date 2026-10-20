<?php
// ============================================================
// UIS Driver Scheduling and Management System
// change_password.php  –  a signed-in user changes their own password.
// Users with a temporary password (must_change_password) are sent here first.
// ============================================================
require_once 'config/database.php';
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
    'supervisor'          => 'supervisor/approvals.php',
    default               => 'driver/schedules.php',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>body{background:#f3f6f4}.card{max-width:28rem;margin:6vh auto;border:0;box-shadow:0 4px 18px rgba(11,93,59,.12)}.btn-uis{background:#0b5d3b;color:#fff}.btn-uis:hover{background:#15804f;color:#fff}</style>
</head>
<body>
<div class="container px-3">
    <div class="card"><div class="card-body p-4">
        <h4 class="fw-bold mb-1"><i class="fas fa-key text-success me-2"></i>Change password</h4>
        <?php if ($done): ?>
            <div class="alert alert-success mt-3">Your password has been changed.</div>
            <a class="btn btn-uis w-100" href="<?= htmlspecialchars($home) ?>">Continue</a>
        <?php else: ?>
            <p class="text-muted small"><?= $forced
                ? 'You are using a temporary password. Please choose your own password before you continue.'
                : 'Use at least 8 characters with a letter and a number.' ?></p>
            <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <div class="mb-3"><label class="form-label small" for="current_password">Current password</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password"></div>
                <div class="mb-3"><label class="form-label small" for="new_password">New password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autocomplete="new-password"></div>
                <div class="mb-3"><label class="form-label small" for="confirm_password">Confirm new password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
                <button class="btn btn-uis w-100" type="submit">Save new password</button>
                <?php if (!$forced): ?><a class="btn btn-link w-100 mt-1" href="<?= htmlspecialchars($home) ?>">Cancel</a>
                <?php else: ?><a class="btn btn-link w-100 mt-1 text-danger" href="logout.php">Log out</a><?php endif; ?>
            </form>
        <?php endif; ?>
    </div></div>
</div>
</body>
</html>
