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
$stmt = $conn->prepare('SELECT notification_id, message, link, is_read, date_sent AS created_at FROM notifications WHERE user_id = ?
                         ORDER BY is_read ASC, date_sent DESC, notification_id DESC LIMIT ? OFFSET ?');
$stmt->bind_param('iii', $uid, $pg['rows_per_page'], $pg['offset']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

adminPageStart('Notifications');
?>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-medium">Notifications<?php if ($unread): ?> <span class="font-mono text-base font-normal text-base-content/60"><?= $unread ?> new</span><?php endif; ?></h1>
        <?php if ($unread): ?>
        <form method="post"><input type="hidden" name="action" value="read_all"><button class="btn btn-sm">Mark all as read</button></form>
        <?php endif; ?>
    </div>
    <div class="divide-y divide-base-300 rounded-box border border-base-300">
    <?php foreach ($rows as $n): ?>
        <a class="flex items-start gap-3 px-4 py-3 transition-colors hover:bg-base-200" href="notifications.php?open=<?= (int)$n['notification_id'] ?>">
            <span class="mt-2 size-2 shrink-0 rounded-full <?= $n['is_read'] ? '' : 'bg-primary' ?>" aria-hidden="true"></span>
            <div class="grow">
                <div class="<?= $n['is_read'] ? 'text-base-content/70' : 'font-medium' ?>"><?= $h($n['message']) ?><?= $n['is_read'] ? '' : '<span class="sr-only"> (new)</span>' ?></div>
                <div class="font-mono text-xs text-base-content/60"><?= $h(date('d M Y, H:i', strtotime($n['created_at']))) ?></div>
            </div>
        </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="dash-empty">No notifications</p><?php endif; ?>
    </div>
    <?php if ($pg['total_pages'] > 1): ?>
    <nav class="join mt-4" aria-label="Pages">
        <?php for ($i = 1; $i <= $pg['total_pages']; $i++): ?><a class="join-item btn btn-sm <?= $i === $pg['current_page'] ? 'btn-active' : '' ?>" href="?page=<?= $i ?>" <?= $i === $pg['current_page'] ? 'aria-current="page"' : '' ?>><?= $i ?></a><?php endfor; ?>
    </nav>
    <?php endif; ?>
<?php adminPageEnd(); ?>
