<?php
/**
 * Top navigation. The menu depends on the role, but hiding a link is only
 * for convenience: every controller ALSO checks the role on the server.
 */
$user = Auth::user();
$role = $user['role'] ?? null;
$unread = $user ? (new Notification())->unreadCount((int)$user['id']) : 0;

$menu = [];
if ($user) {
    $menu[] = ['Dashboard', 'home/index'];
    if (in_array($role, ['admin_assistant', 'head_admin'], true)) {
        // The Vehicle Section has many menu items, so the request links share one dropdown.
        $menu[] = ['Requests', [['New request', 'requests/create'], ['My requests', 'requests/index'], ['All requests', 'requests/all']]];
    } else {
        $menu[] = ['New request', 'requests/create'];
        $menu[] = ['My requests', 'requests/index'];
    }
    $menu[] = ['Calendar', 'calendar/index'];
    if ($role === 'driver') {
        $menu[] = ['My tasks', 'tasks/index'];
    }
    if (in_array($role, ['hod', 'head_admin'], true)) {
        $menu[] = ['Approvals', 'approvals/index'];
    }
    if (in_array($role, ['admin_assistant', 'head_admin'], true)) {
        $drivers = [['Assign driver', 'assignments/index'], ['Change driver', 'assignments/change']];
        if ($role === 'head_admin') {
            array_splice($drivers, 1, 0, [['Approve assignment', 'assignments/approve']]);
        }
        $menu[] = ['Drivers', $drivers];
        $menu[] = ['Operations', [
            ['Reminders', 'reminders/index'], ['Internal tasks', 'internal_tasks/index'], ['Welfare duty', 'welfare/index'],
        ]];
        $menu[] = ['Reports', 'reports/index'];
        $menu[] = ['Manage', [
            ['Users', 'users/index'], ['Drivers', 'drivers/index'], ['Vehicles', 'vehicles/index'],
            ['Departments (PTJ)', 'ptj/index'], ['Pickup points', 'pickup_points/index'],
            ['Settings', 'settings/index'], ['Audit log', 'audit/index'],
            ['System check', 'system/check'],
        ]];
    }
}
$current = $_GET['page'] ?? 'home/index';
?>
<nav class="navbar navbar-expand-xxl navbar-dark app-navbar">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= e(url('home/index')) ?>">
            <?= e($config['app_name']) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($menu as [$label, $target]): ?>
                    <?php if (is_array($target)): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link text-nowrap px-2 dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><?= e($label) ?></a>
                            <ul class="dropdown-menu">
                                <?php foreach ($target as [$l, $p]): ?>
                                    <li><a class="dropdown-item" href="<?= e(url($p)) ?>"><?= e($l) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link text-nowrap px-2 <?= $current === $target ? 'active' : '' ?>" href="<?= e(url($target)) ?>"><?= e($label) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex align-items-center gap-3 text-white small text-nowrap">
                <?php if ($user): ?>
                    <a class="text-white text-decoration-none position-relative" href="<?= e(url('notifications/index')) ?>" title="Notifications">
                        Notifications
                        <?php if ($unread > 0): ?><span class="badge rounded-pill bg-light text-dark ms-1"><?= (int)$unread ?></span><?php endif; ?>
                    </a>
                    <div class="dropdown">
                        <a class="text-white text-decoration-none dropdown-toggle nav-user" href="#" data-bs-toggle="dropdown" title="<?= e($user['name']) ?>"><?= e($user['name']) ?></a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-muted"><?= e(role_label($role)) ?></span></li>
                            <li><a class="dropdown-item" href="<?= e(url('auth/change_password')) ?>">Change password</a></li>
                            <li>
                                <form method="post" action="<?= e(url('auth/logout')) ?>" class="m-0">
                                    <?= Csrf::field() ?>
                                    <button class="dropdown-item" type="submit">Logout</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a class="btn btn-sm btn-outline-light" href="<?= e(url('auth/login')) ?>">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
