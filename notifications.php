<?php
// ============================================================
// notifications.php  -  View Layer: NotificationUI (SDD_CLASS_111) displayNotificationList(), openNotification()
// Unread items first, then read ones (newest first), paginated. Opening one marks it read and goes to
// the request / task it is about (only relative links of this system are followed).
// ============================================================
$current_page = 'notifications_all.php';
require_once 'config/database.php';
require_once 'includes/admin_page.php';
requireLogin();

$uid = (int)$_SESSION['user_id'];
$ns = new NotificationService();
if (isset($_GET['open'])) {                       // openNotification(id): mark it read, then go where it points
    header('Location: ' . $ns->openNotification((int)$_GET['open'], $uid));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read_all') {
    $ns->markAllRead($uid);
    setFlash('success', 'All notifications are marked as read.');
    header('Location: notifications.php');
    exit();
}
$page = max(1, (int)($_GET['page'] ?? 1));
$list = $ns->listForUser($uid, 20, ($page - 1) * 20);
$total = $list['total'];
$pg = paginate($total, $page, 20);
$rows = $list['rows'];
$unread = $list['unread'];
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
                <div class="font-mono text-xs text-base-content/60"><?= $h(date('d M Y, H:i', strtotime($n['date_sent']))) ?></div>
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
