<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/sidebar.php
// Universiti Islam Selangor (UIS)
//
// Prerequisites (set before including):
//   $_SESSION['role']      – 'admin' | 'driver'
//   $_SESSION['full_name'] – user's display name
//   $_SESSION['username']  – fallback display name
//   $current_page          – basename of current file, e.g. 'dashboard.php'
// ============================================================

// View Layer (SDD_PKG_100): the menu of each role, the notification bell (NotificationUI, SDD_CLASS_111)
$role         = $_SESSION['role'] ?? '';
$full_name    = $_SESSION['full_name'] ?? 'User';
$current_page = $current_page ?? basename($_SERVER['PHP_SELF']);

$name_parts = array_values(array_filter(explode(' ', trim($full_name)), fn($w) => !in_array(strtolower($w), ['bin', 'binti'], true)));
$initials = strtoupper(substr($name_parts[0] ?? 'U', 0, 1) . substr($name_parts[1] ?? '', 0, 1));
$role_label = $role;
$_is_admin_role = in_array($role, [User::AA, User::HOA], true);
$_role_home = substr(roleHomeUrl($role), strlen(SITE_URL) + 1);

function sidebarActive(string|array $pages, string $current): string
{
    return in_array($current, (array)$pages, true) ? 'active' : '';
}

// ── Counts shown as small badges (through the Domain Layer) ─────────────────
$_badge = ['assign' => 0, 'approvals' => 0, 'head' => 0, 'changes' => 0, 'pending_role' => 0, 'replace' => 0, 'welfare' => 0, 'internal' => 0];
$_app_unread = 0;
$_app_notifs = [];
if (isLoggedIn()) {
    try {
        $_me = currentUser();
        $_pending = (new ApprovalController())->countPending($_me);
        $_badge['head'] = $_pending['head'];
        if ($_is_admin_role) {
            $_ac = new AssignmentController();
            $_badge['assign'] = count($_ac->listWaiting('Request'));
            $_badge['replace'] = count($_ac->listWaiting('Replacement'));
            $_badge['internal'] = count($_ac->listWaiting('Internal'));
            $_badge['pending_role'] = (new AccountController())->listAccounts(['pending' => true])['total'];
        }
        if ($role === User::HOA) {
            $_badge['approvals'] = $_pending['head'] + $_pending['request'] + $_pending['internal'] + $_pending['welfare'] + $_pending['replacement'];
            $_badge['changes'] = $_pending['schedule'];
        }
        $_ns = new NotificationService();
        $_app_unread = $_ns->countUnread((int)$_SESSION['user_id']);
        $_app_notifs = $_ns->listForUser((int)$_SESSION['user_id'], 6, 0)['rows'];
    } catch (Throwable $e) {
        appLog('error', 'sidebar: ' . $e->getMessage());
    }
}

// ── The menu of each role: section => [ [pages that highlight it, url, icon, label, badge key], ... ] ──
$_my_requests = ['Vehicle Requests' => [
    [['request_vehicle.php'], 'staff/request_vehicle.php', 'car-side', 'New Request', null],
    [['my_requests.php', 'request.php', 'approval_letter.php'], 'staff/my_requests.php', 'list-check', 'My Request', null],
]];
if ($_is_admin_role) {
    $_menu = ['Main' => [[['dashboard.php'], 'admin/dashboard.php', 'gauge-high', 'Dashboard', null]]];
    if ($role === User::HOA) {
        $_menu['Approvals'] = [
            [['approvals.php'], 'admin/approvals.php', 'stamp', 'Pending Approval', 'approvals'],
            [['schedule_changes.php'], 'admin/schedule_changes.php', 'calendar-xmark', 'Schedule Change', 'changes'],
        ];
    }
    $_menu['Requests'] = [
        [['vehicle_requests.php'], 'admin/vehicle_requests.php', 'file-signature', 'All Request', null],
        [['assign_driver.php'], 'admin/assign_driver.php', 'user-plus', 'Assign Request Task', 'assign'],
    ];
    $_menu['My Vehicle Requests'] = [
        [['request_vehicle.php'], 'staff/request_vehicle.php', 'car-side', 'New Request', null],
        [['my_requests.php', 'request.php', 'approval_letter.php'], 'staff/my_requests.php', 'list-check', 'My Request', null],
    ];
    $_menu['Schedule'] = [
        [['schedule.php', 'task.php', 'replace_driver.php'], 'admin/schedule.php', 'calendar-days', 'Driver Schedule', 'replace'],
        [['internal_tasks.php'], 'admin/internal_tasks.php', 'wrench', 'Internal Task', 'internal'],
        [['welfare.php'], 'admin/welfare.php', 'moon', 'Welfare Duty', null],
    ];
    $_menu['Manage'] = [
        [['users.php', 'add_driver.php', 'edit_user.php', 'view_user.php'], 'admin/users.php', 'users', 'User Account', 'pending_role'],
        [['vehicles.php', 'add_vehicle.php', 'edit_vehicle.php', 'view_vehicle.php'], 'admin/vehicles.php', 'bus', 'Vehicle', null],
    ];
    $_menu['Reports'] = [[['reports.php'], 'admin/reports.php', 'chart-column', 'Report', null]];
} elseif ($role === User::HOD) {
    $_menu = ['Main' => [[['dashboard.php'], 'supervisor/dashboard.php', 'gauge-high', 'Dashboard', null]],
              'Approvals' => [[['approvals.php'], 'supervisor/approvals.php', 'stamp', 'Department Request', 'head']]] + $_my_requests;
} elseif ($role === User::DRIVER) {
    $_menu = ['Main' => [[['dashboard.php'], 'driver/dashboard.php', 'gauge-high', 'Dashboard', null]],
              'My Work' => [[['tasks.php', 'task.php'], 'driver/tasks.php', 'route', 'My Task', null],
                            [['schedule_change.php'], 'driver/schedule_change.php', 'calendar-xmark', 'Schedule Change', null]]] + $_my_requests;
} else {
    $_menu = ['Main' => [[['dashboard.php'], 'staff/dashboard.php', 'house', 'Dashboard', null]]] + $_my_requests;
}
?>

<a class="skip-link" href="#mainContent">Skip to main content</a>

<!-- ================================================================
     DaisyUI drawer: the sidebar is always open on large screens and
     slides in from the left on phones (the checkbox below drives it).
     admin_page.php / appLayoutEnd() closes the two divs opened here.
     ================================================================ -->
<div class="drawer lg:drawer-open">
<input id="app-drawer" type="checkbox" class="drawer-toggle" tabindex="-1" aria-hidden="true">

<?php
// The icon of the page we are on (shown in the header next to the page title)
$_page_icon = 'house';
$_page_section = '';
foreach ($_menu as $_sec => $_items) { foreach ($_items as [$_pages, $_url, $_icon]) { if (sidebarActive($_pages, $current_page)) { $_page_icon = $_icon; $_page_section = $_sec; } } }
if ($current_page === 'profile.php') { $_page_icon = 'user'; } elseif ($current_page === 'help.php') { $_page_icon = 'circle-question'; } elseif ($current_page === 'notifications.php') { $_page_icon = 'bell'; }
?>
<aside class="drawer-side z-40" aria-label="Main navigation">
    <label for="app-drawer" class="drawer-overlay" aria-label="Close navigation menu"></label>

    <!-- A full white sidebar with labelled sections (collapsible to icons on large screens); slides in on phones -->
    <nav id="appRail" class="ember-rail flex h-dvh w-64 flex-col pt-4 pb-4">
        <a href="<?php echo SITE_URL . '/' . $_role_home; ?>" class="mx-3 mb-2 flex shrink-0 items-center gap-3 border-b border-base-200 px-1 pb-4" title="UIS Driver Management">
            <span class="grid size-10 shrink-0 place-items-center"><img src="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png" alt="UIS" class="size-full object-contain"></span>
            <span class="rail-label leading-tight"><span class="block text-[0.95rem] font-bold">DSMS</span><span class="block text-xs text-base-content/65">UIS Vehicle Section</span></span>
        </a>

        <ul class="flex w-full grow flex-col gap-0.5 overflow-y-auto px-3 [scrollbar-width:thin]">
            <?php $_first = true; foreach ($_menu as $_section => $_items): ?>
            <?php if (!$_first): ?><li class="rail-divider my-1.5 h-px w-6 bg-base-300" aria-hidden="true"></li><?php endif; $_first = false; ?>
            <li class="rail-label rail-section"><?php echo htmlspecialchars($_section); ?></li>
                <?php foreach ($_items as [$_pages, $_url, $_icon, $_label, $_bk]): $_on = sidebarActive($_pages, $current_page) !== ''; ?>
            <li class="lg:tooltip lg:tooltip-right" data-tip="<?php echo htmlspecialchars($_label); ?>">
                <a href="<?php echo SITE_URL . '/' . $_url; ?>" class="rail-item <?php echo $_on ? 'is-active' : ''; ?>" <?php echo $_on ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-<?php echo $_icon; ?>" aria-hidden="true"></i>
                    <span class="rail-label grow"><?php echo htmlspecialchars($_label); ?></span>
                    <?php if ($_bk !== null && $_badge[$_bk] > 0): ?>
                    <span class="rail-count"><?php echo (int)$_badge[$_bk]; ?></span>
                    <?php endif; ?>
                </a>
            </li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>

        <ul class="mt-2 flex w-full shrink-0 flex-col gap-0.5 border-t border-base-300 px-3 pt-3">
            <li class="lg:tooltip lg:tooltip-right" data-tip="Help &amp; Guide">
                <a href="<?php echo SITE_URL; ?>/help.php" class="rail-item <?php echo $current_page === 'help.php' ? 'is-active' : ''; ?>"><i class="fas fa-circle-question" aria-hidden="true"></i><span class="rail-label grow">Help &amp; Guide</span></a>
            </li>
            <li class="lg:tooltip lg:tooltip-right" data-tip="Log Out">
                <a href="<?php echo SITE_URL; ?>/logout.php" class="rail-item rail-logout"
                   data-confirm="You will need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log Out" data-confirm-tone="danger">
                    <i class="fas fa-right-from-bracket" aria-hidden="true"></i><span class="rail-label grow">Log Out</span></a>
            </li>
        </ul>
    </nav>
</aside>

<div class="drawer-content min-w-0">
<div class="ember-panel flex min-h-dvh flex-col">

<header class="uis-topbar sticky top-0 z-30 flex flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-8 no-print">
    <button type="button" class="btn btn-square btn-sm hidden lg:inline-flex" id="railToggle" aria-controls="appRail" aria-expanded="true" aria-label="Collapse sidebar" title="Expand / collapse sidebar">
        <i class="fas fa-angles-right transition-transform" aria-hidden="true"></i>
    </button>
    <label for="app-drawer" class="btn btn-square btn-sm lg:hidden" aria-label="Open navigation menu">
        <i class="fas fa-bars" aria-hidden="true"></i>
    </label>

    <nav class="min-w-0 flex-1" aria-label="Breadcrumb">
        <ol class="uis-crumbs">
            <li><a href="<?php echo SITE_URL . '/' . $_role_home; ?>">Home</a></li>
            <?php if ($_page_section !== '' && $_page_section !== 'Main' && $_page_section !== ($page_title ?? '')): ?>
            <li class="sep" aria-hidden="true">/</li><li><?php echo htmlspecialchars($_page_section); ?></li>
            <?php endif; ?>
            <li class="sep" aria-hidden="true">/</li><li class="truncate font-medium text-base-content" aria-current="page"><?php echo htmlspecialchars($page_title ?? ''); ?></li>
        </ol>
        <div class="hidden text-xs text-base-content/65 sm:block"><?php echo date('l, j F Y'); ?></div>
    </nav>

    <?php echo uisLangSwitch('hidden sm:flex'); ?>
    <?php echo uisThemeSwitch(); ?>

    <span id="dhClock" class="hidden rounded-md border border-base-300 px-3 py-1.5 text-sm font-medium tabular-nums 2xl:inline"></span>

    <!-- Notifications -->
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="btn btn-square" aria-label="Notifications<?php echo $_app_unread ? ' (' . $_app_unread . ' unread)' : ''; ?>">
            <div class="indicator">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <?php if ($_app_unread > 0): ?>
                <span class="indicator-item size-2.5 rounded-full bg-error ring-2 ring-base-100" aria-hidden="true"></span>
                <?php endif; ?>
            </div>
        </div>
        <div tabindex="0" class="dropdown-content z-50 mt-2 w-80 rounded-lg border border-base-300 bg-base-100 p-2 shadow-lg">
            <div class="px-3 pt-2 pb-1 text-sm font-semibold">Notifications<?php if ($_app_unread): ?> <span class="badge badge-sm badge-primary ms-1"><?php echo $_app_unread; ?> new</span><?php endif; ?></div>
            <ul class="menu w-full p-0">
                <?php foreach ($_app_notifs as $_n): ?>
                <li>
                    <a href="<?php echo SITE_URL; ?>/notifications.php?open=<?php echo (int)$_n['notification_id']; ?>" class="flex-col items-start gap-0.5 rounded-md">
                        <span class="text-sm <?php echo $_n['is_read'] ? 'text-base-content/70' : 'font-medium'; ?>">
                            <?php if (!$_n['is_read']): ?><span class="me-1.5 inline-block size-2 rounded-full bg-primary align-middle"></span><?php endif; ?>
                            <?php echo htmlspecialchars($_n['message']); ?>
                        </span>
                        <span class="text-xs text-base-content/65"><?php echo date('d M Y, H:i', strtotime($_n['date_sent'])); ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if (!$_app_notifs): ?>
                <li class="disabled"><span class="justify-center py-4 text-sm"><i class="fas fa-check-circle text-success" aria-hidden="true"></i>All clear</span></li>
                <?php endif; ?>
            </ul>
            <a class="btn btn-sm btn-block mt-1" href="<?php echo SITE_URL; ?>/notifications.php">See all notifications</a>
        </div>
    </div>

    <!-- Account -->
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="flex cursor-pointer items-center gap-2.5 rounded-md py-1 ps-1 pe-2 hover:bg-base-200" aria-label="Account menu">
            <span class="grid size-9 place-items-center rounded-full bg-[#fbf1d6] text-sm font-bold text-[#7a5800]"><?php echo htmlspecialchars($initials); ?></span>
            <span class="hidden flex-col md:flex"><span class="uis-user-name max-w-44 truncate"><?php echo htmlspecialchars($full_name); ?></span><span class="uis-user-role"><?php echo htmlspecialchars($role_label); ?></span></span>
            <i class="fas fa-chevron-down hidden text-[0.65rem] text-base-content/60 md:inline" aria-hidden="true"></i>
        </div>
        <ul tabindex="0" class="menu dropdown-content z-50 mt-2 w-64 rounded-lg border border-base-300 bg-base-100 p-2 shadow-lg">
            <li class="menu-title normal-case">
                <span class="block truncate font-semibold text-base-content"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="block text-xs font-normal"><?php echo htmlspecialchars($role_label); ?></span>
            </li>
            <li class="sm:hidden"><a class="rounded-md" href="<?php echo htmlspecialchars(uisLangUrl(uisLang() === 'ms' ? 'en' : 'ms')); ?>" lang="<?php echo uisLang() === 'ms' ? 'en' : 'ms'; ?>" data-no-translate>
                <i class="fas fa-language w-4" aria-hidden="true"></i><?php echo uisLang() === 'ms' ? 'English' : 'Bahasa Melayu'; ?></a></li>
            <li><a class="rounded-md<?php echo $current_page === 'profile.php' ? ' menu-active' : ''; ?>" href="<?php echo SITE_URL; ?>/profile.php"><i class="fas fa-user w-4" aria-hidden="true"></i>My Profile</a></li>
            <li><a class="rounded-md" href="<?php echo SITE_URL; ?>/profile.php#password"><i class="fas fa-key w-4" aria-hidden="true"></i>Change Password</a></li>
            <li><a class="rounded-md" href="<?php echo SITE_URL; ?>/help.php"><i class="fas fa-circle-question w-4" aria-hidden="true"></i>Help</a></li>
            <li><a class="rounded-md text-error" href="<?php echo SITE_URL; ?>/logout.php"
                   data-confirm="You will need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log Out" data-confirm-tone="danger">
                <i class="fas fa-right-from-bracket w-4" aria-hidden="true"></i>Log Out</a></li>
        </ul>
    </div>
</header>

<!-- Shared popup dialogs (SweetAlert2 + UIS theme) -->
<script>window.UIS_CSRF = <?php echo json_encode(csrfToken()); ?>;
(function () {
    'use strict';
    var token = window.UIS_CSRF || '';
    if (!token) { return; }

    function sameOrigin(url) {
        try { return new URL(url, window.location.href).origin === window.location.origin; }
        catch (e) { return true; }
    }

    function addToForm(form) {
        if (!form || (form.method || '').toLowerCase() !== 'post') { return; }
        if (form.querySelector('input[name="csrf_token"]')) { return; }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = token;
        form.appendChild(input);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form').forEach(addToForm);
    });
    document.addEventListener('submit', function (event) { addToForm(event.target); }, true);

    if (window.fetch) {
        var nativeFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            var method = (init.method || (input && input.method) || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD' && sameOrigin(url)) {
                var headers = new Headers(init.headers || (input && input.headers) || {});
                if (!headers.has('X-CSRF-Token')) { headers.set('X-CSRF-Token', token); }
                init.headers = headers;
            }
            return nativeFetch.call(this, input, init);
        };
    }

    var nativeOpen = XMLHttpRequest.prototype.open;
    var nativeSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__uisMethod = (method || 'GET').toUpperCase();
        this.__uisUrl = url;
        return nativeOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        if (this.__uisMethod && this.__uisMethod !== 'GET' && sameOrigin(this.__uisUrl)) {
            try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) {}
        }
        return nativeSend.apply(this, arguments);
    };
})();
</script>
<script src="<?php echo SITE_URL; ?>/assets/js/ui.js"></script>

<script>
/* Sidebar: open (labels, the default) or collapsed (icons) on large screens; remembered in this browser */
(function () {
    var btn = document.getElementById('railToggle'), root = document.documentElement;
    if (!btn) { return; }
    function sync() {
        var open = !root.classList.contains('rail-closed');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.setAttribute('aria-label', open ? 'Collapse sidebar' : 'Expand sidebar');
    }
    btn.addEventListener('click', function () {
        root.classList.toggle('rail-closed');
        try { localStorage.setItem('uis-rail', root.classList.contains('rail-closed') ? 'closed' : 'open'); } catch (e) {}
        sync();
    });
    sync();
})();

/* Live clock in the top bar */
(function () {
    var el = document.getElementById('dhClock');
    if (!el) { return; }
    function tick() { el.textContent = new Date().toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' }).toUpperCase(); }
    tick();
    setInterval(tick, 15000);
})();
</script>
