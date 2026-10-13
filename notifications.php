<?php
// ============================================================
// UIS Driver Scheduling and Management System
// notifications.php  –  the user's in-app notifications (the bell)
// Works for every role. ?open=ID marks one as read and goes to the page it is about.
// ============================================================
$page_title   = 'Notifications';
$current_page = 'notifications_all.php';
require_once 'config/database.php';
requireLogin();

$uid = (int)$_SESSION['user_id'];

// Open one notification: mark it read, then go where it points.
if (isset($_GET['open'])) {
    $id = (int)$_GET['open'];
    $stmt = $conn->prepare('SELECT link FROM notifications WHERE notification_id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $upd = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
        $upd->bind_param('ii', $id, $uid);
        $upd->execute();
        $upd->close();
        // Only pages of this site are followed (stored links are relative, never full addresses)
        $link = (string)($row['link'] ?? '');
        $safe = $link !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $link) && !str_contains($link, '..');
        header('Location: ' . ($safe ? SITE_URL . '/' . ltrim($link, '/') : SITE_URL . '/notifications.php'));
        exit();
    }
    header('Location: ' . SITE_URL . '/notifications.php');
    exit();
}

// Mark all as read (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_all') {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $uid");
    setFlash('success', 'All notifications are marked as read.');
    header('Location: notifications.php');
    exit();
}

$rows = $conn->query("SELECT * FROM notifications WHERE user_id = $uid ORDER BY created_at DESC, notification_id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | UIS Driver Management</title>
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/images/uis-favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require_once 'includes/sidebar.php'; ?>
<main class="main-content p-4" id="mainContent">
    <?php showFlash(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h4 class="fw-bold mb-0"><i class="fas fa-bell text-success me-2"></i>Notifications</h4>
            <div class="text-muted small">What happened to your requests and tasks.</div>
        </div>
        <form method="post"><input type="hidden" name="action" value="read_all">
            <button class="btn btn-outline-success btn-sm"><i class="fas fa-check-double me-1"></i>Mark all as read</button>
        </form>
    </div>
    <div class="list-group shadow-sm">
        <?php foreach ($rows as $n): ?>
        <a class="list-group-item list-group-item-action <?= $n['is_read'] ? '' : 'fw-semibold' ?>"
           href="notifications.php?open=<?= (int)$n['notification_id'] ?>">
            <div><?= $n['is_read'] ? '' : '<span class="badge bg-success me-1">New</span>' ?><?= htmlspecialchars($n['message']) ?></div>
            <div class="small text-muted fw-normal"><?= date('d M Y, H:i', strtotime($n['created_at'])) ?></div>
        </a>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
        <div class="list-group-item text-center text-muted py-4">No notifications yet.</div>
        <?php endif; ?>
    </div>
</main>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
</body>
</html>
