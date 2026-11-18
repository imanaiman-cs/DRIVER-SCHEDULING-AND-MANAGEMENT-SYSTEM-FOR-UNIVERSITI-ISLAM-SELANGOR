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

// Resolve helpers
$role         = $_SESSION['role']      ?? 'driver';
$full_name    = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');
$current_page = $current_page          ?? basename($_SERVER['PHP_SELF']);

// Build initials from the user's full name (up to 2 characters)
$name_parts = array_filter(explode(' ', trim($full_name)));
$initials   = '';
foreach (array_slice($name_parts, 0, 2) as $part) {
    $initials .= strtoupper($part[0]);
}
$initials = $initials ?: 'U';

// Role label displayed under the avatar
$role_label = roleLabel($role);

// Admin Assistant and Head of Admin share the admin menu (the Head of Admin has one extra item)
$_is_admin_role = in_array($role, ['admin', 'superadmin', 'head_admin'], true);

// Home URL for the current role (used by header breadcrumb)
$_role_home = match ($role) {
    'admin', 'superadmin', 'head_admin' => 'admin/dashboard.php',
    'staff'               => 'staff/dashboard.php',
    'supervisor'          => 'supervisor/dashboard.php',
    default               => 'driver/dashboard.php',
};

// Helper: returns 'active' CSS class when $pages matches the current page
function sidebarActive(string|array $pages, string $current): string
{
    $pages = (array) $pages;
    return in_array($current, $pages, true) ? 'active' : '';
}

// ── Counts shown as small badges ─────────────────────────────────
$_sidebar_uid     = (int)($_SESSION['user_id'] ?? 0);
$_badge           = ['requests' => 0, 'assignments' => 0, 'reminders' => 0, 'approvals' => 0];
$_app_unread      = 0;   // unread notifications (the bell)
$_app_notifs      = [];  // the latest few
if ($_sidebar_uid > 0 && isset($conn)) {
    try {
        if ($_is_admin_role) {
            $_badge['requests']    = (int)$conn->query("SELECT COUNT(*) FROM vehicle_requests WHERE status = 'approved'")->fetch_row()[0];
            $_badge['assignments'] = (int)$conn->query("SELECT COUNT(*) FROM vehicle_requests WHERE status = 'assigned'")->fetch_row()[0];
            $_badge['reminders']   = (int)$conn->query("SELECT COUNT(*) FROM vehicle_requests WHERE status = 'pending'")->fetch_row()[0];
        }
        if ($role === 'supervisor') {
            $st = $conn->prepare("SELECT COUNT(*) FROM vehicle_requests WHERE supervisor_id = ? AND status = 'pending'");
            $st->bind_param('i', $_sidebar_uid);
            $st->execute();
            $_badge['approvals'] = (int)$st->get_result()->fetch_row()[0];
            $st->close();
        }
        $st = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->bind_param('i', $_sidebar_uid);
        $st->execute();
        $_app_unread = (int)$st->get_result()->fetch_row()[0];
        $st->close();
        $st = $conn->prepare('SELECT notification_id, message, link, is_read, created_at FROM notifications
                               WHERE user_id = ? ORDER BY is_read ASC, created_at DESC, notification_id DESC LIMIT 6');
        $st->bind_param('i', $_sidebar_uid);
        $st->execute();
        $_app_notifs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    } catch (Throwable $e) {
        // tables not ready yet
    }
}

// ── The menu of each role: section => [ [pages that highlight it, url, icon, label, badge key], ... ] ──
$_my_requests = ['My Vehicle Requests' => [
    [['request_vehicle.php'], 'staff/request_vehicle.php', 'car-side', 'Request a Vehicle', null],
    [['my_requests.php'],     'staff/my_requests.php',     'list-check', 'My Requests', null],
]];
if ($_is_admin_role) {
    $_menu = [
        'Main' => [[['dashboard.php'], 'admin/dashboard.php', 'gauge-high', 'Dashboard', null]],
        'Requests' => array_merge(
            [[['vehicle_requests.php', 'assign_driver.php', 'replace_driver.php'], 'admin/vehicle_requests.php', 'file-signature', 'Vehicle Requests', 'requests']],
            $role === 'head_admin' ? [[['approve_assignments.php'], 'admin/approve_assignments.php', 'user-check', 'Approve Assignments', 'assignments']] : [],
            [[['reminders.php'], 'admin/reminders.php', 'bell-concierge', 'Approval Reminders', 'reminders']]
        ),
        'Schedule' => [
            [['calendar.php'],       'admin/calendar.php',       'calendar-days', 'Schedule Calendar', null],
            [['internal_tasks.php'], 'admin/internal_tasks.php', 'wrench',        'Internal Tasks', null],
            [['welfare.php'],        'admin/welfare.php',        'moon',          'Welfare Duty', null],
        ],
        'Fleet' => [
            [['drivers.php', 'add_driver.php', 'edit_driver.php', 'view_driver.php'],          'admin/drivers.php',  'id-card', 'Drivers', null],
            [['vehicles.php', 'add_vehicle.php', 'edit_vehicle.php', 'view_vehicle.php', 'vehicle_maintenance.php'], 'admin/vehicles.php', 'bus', 'Vehicles', null],
        ],
        'Reports' => [[['reports.php'], 'admin/reports.php', 'chart-column', 'Reports', null]],
        'System'  => [[['settings.php'], 'admin/settings.php', 'sliders', 'Settings', null]],
    ] + $_my_requests;
} elseif ($role === 'supervisor') {
    $_menu = ['Main' => [[['dashboard.php'], 'supervisor/dashboard.php', 'gauge-high', 'Dashboard', null]],
              'Approvals' => [[['approvals.php'], 'supervisor/approvals.php', 'stamp', 'Vehicle Approvals', 'approvals']]] + $_my_requests;
} elseif ($role === 'staff') {
    $_menu = ['Main' => [[['dashboard.php'], 'staff/dashboard.php', 'house', 'Dashboard', null]]] + $_my_requests;
} else {
    $_menu = ['Main' => [[['dashboard.php'], 'driver/dashboard.php', 'gauge-high', 'Dashboard', null]],
              'My Tasks' => [[['schedules.php', 'task_sheet.php'], 'driver/schedules.php', 'route', 'My Tasks', null]]] + $_my_requests;
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
foreach ($_menu as $_items) { foreach ($_items as [$_pages, $_url, $_icon]) { if (sidebarActive($_pages, $current_page)) { $_page_icon = $_icon; } } }
if ($current_page === 'help.php') { $_page_icon = 'circle-question'; } elseif ($current_page === 'notifications.php') { $_page_icon = 'bell'; }
?>
<aside class="drawer-side z-40" aria-label="Main navigation">
    <label for="app-drawer" class="drawer-overlay" aria-label="Close navigation menu"></label>

    <!-- Floating icon rail on large screens; a labelled panel when it slides in on phones -->
    <nav id="appRail" class="ember-rail m-3 flex h-[calc(100dvh-1.5rem)] w-64 flex-col rounded-[28px] py-4">
        <a href="<?php echo SITE_URL . '/' . $_role_home; ?>" class="mx-3 mb-3 flex shrink-0 items-center gap-3 lg:mx-0" title="UIS Driver Management">
            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-white p-1.5 shadow-[0_0_0_4px_rgb(21_128_79/.35)]"><img src="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png" alt="UIS" class="size-full object-contain"></span>
            <span class="rail-label leading-tight text-white"><span class="block font-semibold">UIS</span><span class="block text-xs text-white/60">Driver Management</span></span>
        </a>

        <ul class="flex w-full grow flex-col gap-1 overflow-y-auto px-3 [scrollbar-width:none] lg:items-center">
            <?php $_first = true; foreach ($_menu as $_section => $_items): ?>
            <?php if (!$_first): ?><li class="my-1.5 h-px w-full bg-white/10 lg:w-6" aria-hidden="true"></li><?php endif; $_first = false; ?>
            <li class="rail-label rail-section px-2 pt-1 text-[0.68rem] font-medium text-white/60"><?php echo htmlspecialchars($_section); ?></li>
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

        <ul class="mt-2 flex w-full shrink-0 flex-col gap-1 border-t border-white/10 px-3 pt-3 lg:items-center">
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

<div class="drawer-content min-w-0 lg:py-3 lg:pe-3">
<div class="ember-panel flex min-h-dvh flex-col lg:min-h-[calc(100dvh-1.5rem)] lg:rounded-[28px]">

<header class="sticky top-0 z-30 flex items-center gap-3 px-4 pt-4 pb-2 sm:px-6 lg:static lg:px-8 lg:pt-6 no-print">
    <button type="button" class="btn btn-circle btn-sm hidden lg:inline-flex" id="railToggle" aria-controls="appRail" aria-expanded="false" aria-label="Expand sidebar" title="Expand / collapse sidebar">
        <i class="fas fa-angles-right transition-transform" aria-hidden="true"></i>
    </button>
    <label for="app-drawer" class="btn btn-circle btn-sm lg:hidden" aria-label="Open navigation menu">
        <i class="fas fa-bars" aria-hidden="true"></i>
    </label>

    <div class="min-w-0 flex-1">
        <div class="text-xs text-base-content/65"><?php echo date('l, j F'); ?></div>
        <div class="flex items-center gap-2 truncate text-[0.95rem] font-medium">
            <i class="fas fa-<?php echo htmlspecialchars($_page_icon); ?> text-[0.8rem] text-base-content/60" aria-hidden="true"></i>
            <span class="truncate"><?php echo htmlspecialchars($page_title ?? ''); ?></span>
        </div>
    </div>

    <?php echo uisLangSwitch('hidden sm:flex'); ?>
    <?php echo uisThemeSwitch(); ?>

    <span id="dhClock" class="hidden rounded-full bg-base-100 px-3.5 py-2 text-sm font-medium tabular-nums shadow-[var(--ember-shadow)] xl:inline"></span>

    <!-- Notifications -->
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="btn btn-circle" aria-label="Notifications<?php echo $_app_unread ? ' (' . $_app_unread . ' unread)' : ''; ?>">
            <div class="indicator">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <?php if ($_app_unread > 0): ?>
                <span class="indicator-item size-2.5 rounded-full bg-error ring-2 ring-base-100" aria-hidden="true"></span>
                <?php endif; ?>
            </div>
        </div>
        <div tabindex="0" class="dropdown-content z-50 mt-2 w-80 rounded-[22px] bg-base-100 p-2 shadow-xl">
            <div class="px-3 pt-2 pb-1 text-sm font-semibold">Notifications<?php if ($_app_unread): ?> <span class="badge badge-sm badge-primary ms-1"><?php echo $_app_unread; ?> new</span><?php endif; ?></div>
            <ul class="menu w-full p-0">
                <?php foreach ($_app_notifs as $_n): ?>
                <li>
                    <a href="<?php echo SITE_URL; ?>/notifications.php?open=<?php echo (int)$_n['notification_id']; ?>" class="flex-col items-start gap-0.5 rounded-2xl">
                        <span class="text-sm <?php echo $_n['is_read'] ? 'text-base-content/70' : 'font-medium'; ?>">
                            <?php if (!$_n['is_read']): ?><span class="me-1.5 inline-block size-2 rounded-full bg-primary align-middle"></span><?php endif; ?>
                            <?php echo htmlspecialchars($_n['message']); ?>
                        </span>
                        <span class="text-xs text-base-content/65"><?php echo date('d M Y, H:i', strtotime($_n['created_at'])); ?></span>
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
        <div tabindex="0" role="button" class="flex cursor-pointer items-center gap-2 rounded-full" aria-label="Account menu">
            <span class="grid size-10 place-items-center rounded-full bg-gradient-to-br from-[#15804f] to-primary text-sm font-semibold text-white ring-2 ring-base-100 shadow-[var(--ember-shadow)]"><?php echo htmlspecialchars($initials); ?></span>
        </div>
        <ul tabindex="0" class="menu dropdown-content z-50 mt-2 w-64 rounded-[22px] bg-base-100 p-2 shadow-xl">
            <li class="menu-title normal-case">
                <span class="block truncate font-semibold text-base-content"><?php echo htmlspecialchars($full_name); ?></span>
                <span class="block text-xs font-normal"><?php echo htmlspecialchars($role_label); ?></span>
            </li>
            <li class="sm:hidden"><a class="rounded-2xl" href="<?php echo htmlspecialchars(uisLangUrl(uisLang() === 'ms' ? 'en' : 'ms')); ?>" lang="<?php echo uisLang() === 'ms' ? 'en' : 'ms'; ?>" data-no-translate>
                <i class="fas fa-language w-4" aria-hidden="true"></i><?php echo uisLang() === 'ms' ? 'English' : 'Bahasa Melayu'; ?></a></li>
            <li><a class="rounded-2xl" href="<?php echo SITE_URL; ?>/help.php"><i class="fas fa-circle-question w-4" aria-hidden="true"></i>Help</a></li>
            <li><a class="rounded-2xl text-error" href="<?php echo SITE_URL; ?>/logout.php"
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
/* Sidebar: expanded (labels) or collapsed (icons) on large screens; remembered in this browser */
(function () {
    var btn = document.getElementById('railToggle'), root = document.documentElement;
    if (!btn) { return; }
    function sync() {
        var open = root.classList.contains('rail-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.setAttribute('aria-label', open ? 'Collapse sidebar' : 'Expand sidebar');
    }
    btn.addEventListener('click', function () {
        root.classList.toggle('rail-open');
        try { localStorage.setItem('uis-rail', root.classList.contains('rail-open') ? 'open' : 'closed'); } catch (e) {}
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
