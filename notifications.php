<?php
// ============================================================
// notifications.php  -  Notifications (SRS UC030), the same page for every role.
// Unread items first, then read ones (newest first), paginated. Opening one marks it read and goes to
// the request / task it is about (only relative links of this system are followed).
// ============================================================
$current_page = 'notifications_all.php';
require_once 'config/database.php';
require_once 'includes/admin_page.php';
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
    $target = SITE_URL . '/notifications.php';
    if ($row) {
        $upd = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
        $upd->bind_param('ii', $id, $uid);
        $upd->execute();
        $upd->close();
        $link = trim((string)($row['link'] ?? ''));
        // relative links only: no scheme, no "//", no backslash, no "..", no control characters
        $safe = $link !== '' && !preg_match('#^[a-z][a-z0-9+.-]*:|^//|[\\\\\x00-\x1f]#i', $link) && !str_contains($link, '..');
        if ($safe) { $target = SITE_URL . '/' . ltrim($link, '/'); }
    }
    header('Location: ' . $target);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_all') {
    $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();
    setFlash('success', 'All notifications are marked as read.');
    header('Location: notifications.php');
    exit();
}

$stmt = $conn->prepare('SELECT COUNT(*), COALESCE(SUM(is_read = 0), 0) FROM notifications WHERE user_id = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
[$total, $unread] = array_map('intval', $stmt->get_result()->fetch_row());
$stmt->close();
$pg = paginate($total, max(1, (int)($_GET['page'] ?? 1)), 20);
$stmt = $conn->prepare('SELECT notification_id, message, link, is_read, created_at FROM notifications WHERE user_id = ?
                         ORDER BY is_read ASC, created_at DESC, notification_id DESC LIMIT ? OFFSET ?');
$stmt->bind_param('iii', $uid, $pg['rows_per_page'], $pg['offset']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

adminPageStart('Notifications');
?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h4 class="fw-bold mb-0"><i class="fas fa-bell text-success me-2"></i>Notifications <?php if ($unread): ?><span class="badge bg-danger fs-6"><?= $unread ?> new</span><?php endif; ?></h4>
        <?php if ($unread): ?>
        <form method="post"><input type="hidden" name="action" value="read_all"><button class="btn btn-sm btn-outline-success"><i class="fas fa-check-double me-1"></i>Mark all as read</button></form>
        <?php endif; ?>
    </div>
    <div class="card border-0 shadow-sm"><div class="list-group list-group-flush">
    <?php foreach ($rows as $n): ?>
        <a class="list-group-item list-group-item-action d-flex gap-3 align-items-start <?= $n['is_read'] ? '' : 'bg-success-subtle' ?>" href="notifications.php?open=<?= (int)$n['notification_id'] ?>">
            <i class="fas <?= $n['is_read'] ? 'fa-envelope-open text-muted' : 'fa-envelope text-success' ?> mt-1"></i>
            <div class="flex-grow-1">
                <div class="<?= $n['is_read'] ? '' : 'fw-semibold' ?>"><?= $h($n['message']) ?></div>
                <div class="small text-muted"><?= $h(date('d M Y, H:i', strtotime($n['created_at']))) ?></div>
            </div>
            <?php if (!$n['is_read']): ?><span class="badge bg-success align-self-center">New</span><?php endif; ?>
        </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="list-group-item text-center text-muted py-4">You have no notifications.</div><?php endif; ?>
    </div></div>
    <?php if ($pg['total_pages'] > 1): ?>
    <nav class="mt-3" aria-label="Pages"><ul class="pagination pagination-sm flex-wrap">
        <?php for ($i = 1; $i <= $pg['total_pages']; $i++): ?><li class="page-item <?= $i === $pg['current_page'] ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?>
    </ul></nav>
    <?php endif; ?>
<?php adminPageEnd(); ?>
