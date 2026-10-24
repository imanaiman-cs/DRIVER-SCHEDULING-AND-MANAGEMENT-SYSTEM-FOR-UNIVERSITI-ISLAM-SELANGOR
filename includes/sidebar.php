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

// Helper: returns Bootstrap 'show' class when any page in $pages is current
function submenuShow(array $pages, string $current): string
{
    return in_array($current, $pages, true) ? 'show' : '';
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
     OFF-CANVAS SIDEBAR OVERLAY (mobile)
     ================================================================ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ================================================================
     SIDEBAR
     ================================================================ -->
<nav class="sidebar" id="mainSidebar" aria-label="Main navigation">

    <!-- ── Brand / Logo ──────────────────────────────────────────── -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-inner">
            <div class="sidebar-logo-ring">
                <img alt="" src="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png" alt="UIS crest">
            </div>
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title">UIS</span>
                <span class="sidebar-brand-sub">Driver Management</span>
            </div>
        </div>
        <!-- Mobile close button -->
        <button class="sidebar-close-btn d-lg-none" onclick="closeSidebar()" aria-label="Close sidebar">
            <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
    </div>

    <!-- ── User Profile Card ─────────────────────────────────────── -->
    <div class="sidebar-profile">
        <div class="sidebar-avatar" aria-hidden="true">
            <?php echo htmlspecialchars($initials); ?>
        </div>
        <div class="sidebar-profile-info">
            <div class="sidebar-profile-name" title="<?php echo htmlspecialchars($full_name); ?>">
                <?php echo htmlspecialchars($full_name); ?>
            </div>
            <span class="sidebar-role-badge">
                <?php if ($_is_admin_role): ?>
                    <i class="fas fa-shield-halved fa-xs" aria-hidden="true"></i>
                <?php else: ?>
                    <i class="fas fa-id-card fa-xs" aria-hidden="true"></i>
                <?php endif; ?>
                <?php echo htmlspecialchars($role_label); ?>
            </span>
        </div>
    </div>

    <!-- ── Navigation Menu ───────────────────────────────────────── -->
    <div class="sidebar-menu-wrapper">
        <ul class="sidebar-nav" id="sidebarNav">
            <?php foreach ($_menu as $_section => $_items): ?>
            <li class="sidebar-section-label"><?php echo htmlspecialchars($_section); ?></li>
                <?php foreach ($_items as [$_pages, $_url, $_icon, $_label, $_bk]): ?>
            <li class="sidebar-item <?php echo sidebarActive($_pages, $current_page); ?>">
                <a href="<?php echo SITE_URL . '/' . $_url; ?>" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-<?php echo $_icon; ?>" aria-hidden="true"></i></span>
                    <span class="sidebar-label"><?php echo htmlspecialchars($_label); ?></span>
                    <?php if ($_bk !== null && $_badge[$_bk] > 0): ?>
                    <span class="ms-auto badge rounded-pill" style="background:#15803d;font-size:0.65rem;min-width:18px;"><?php echo (int)$_badge[$_bk]; ?></span>
                    <?php endif; ?>
                </a>
            </li>
                <?php endforeach; ?>
            <?php endforeach; ?>

        </ul><!-- /.sidebar-nav -->
    </div><!-- /.sidebar-menu-wrapper -->

    <!-- ── Sidebar Footer / Logout ───────────────────────────────── -->
    <div class="sidebar-footer">
        <a href="<?php echo SITE_URL; ?>/help.php"
           class="sidebar-logout-btn mb-1 <?php echo $current_page === 'help.php' ? 'active' : ''; ?>"
           style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.85);">
            <span class="sidebar-icon"><i class="fas fa-circle-question" aria-hidden="true"></i></span>
            <span class="sidebar-label">Help &amp; Guide</span>
        </a>
        <a href="<?php echo SITE_URL; ?>/logout.php" class="sidebar-logout-btn"
           data-confirm="You will need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log Out" data-confirm-tone="danger" data-confirm-icon="fa-right-from-bracket">
            <span class="sidebar-icon"><i class="fas fa-right-from-bracket" aria-hidden="true"></i></span>
            <span class="sidebar-label">Log Out</span>
        </a>
    </div>

</nav><!-- /#mainSidebar -->

<!-- ================================================================
     TOP NAV BAR  (mobile hamburger + page title)
     ================================================================ -->
<nav class="topbar d-lg-none" aria-label="Mobile top bar">
    <button class="topbar-hamburger" onclick="openSidebar()" aria-label="Open navigation menu">
        <i class="fas fa-bars" aria-hidden="true"></i>
    </button>
    <span class="topbar-title">
        <img alt="" src="<?php echo SITE_URL; ?>/assets/images/uis-favicon.png" alt="UIS crest"
             style="height:26px;width:auto;">
        UIS Driver Management
    </span>
    <a href="<?php echo SITE_URL; ?>/logout.php" class="topbar-logout"
       data-confirm="You will need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log Out" data-confirm-tone="danger" data-confirm-icon="fa-right-from-bracket"
       aria-label="Log out">
        <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
    </a>
</nav>

<!-- ================================================================
     DESKTOP HEADER (sticky top bar, hidden on mobile)
     ================================================================ -->
<header class="desktop-header" id="desktopHeader">

    <!-- Sidebar mini-toggle -->
    <button class="btn-sidebar-toggle" onclick="toggleSidebarMini()" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Breadcrumb / page title -->
    <div class="flex-grow-1 min-width-0">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item">
                    <a href="<?php echo SITE_URL; ?>/<?php echo $_role_home; ?>"
                       style="color:var(--uis-secondary);">
                        <i class="fas fa-home me-1"></i>Home
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <?php echo htmlspecialchars($page_title ?? ''); ?>
                </li>
            </ol>
        </nav>
    </div>

    <!-- Right controls -->
    <div class="d-flex align-items-center gap-2 flex-shrink-0">

        <!-- Live clock (xl+ only) -->
        <div class="d-none d-xl-block text-end me-1">
            <div id="dhClock" style="font-size:.88rem;font-weight:700;color:var(--uis-primary);letter-spacing:.03em;white-space:nowrap;"></div>
        </div>

        <?php
            $_dh_total = $_app_unread;
        ?>
        <!-- Notification bell (all roles) -->
        <div class="dropdown">
            <button class="btn btn-light btn-sm position-relative rounded-circle"
                    style="width:38px;height:38px;border:1.5px solid #e5e9f0;"
                    data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="fas fa-bell" style="color:var(--uis-primary);"></i>
                <?php if ($_dh_total > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                      style="font-size:.58rem;"><?php echo $_dh_total; ?></span>
                <?php endif; ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:320px;max-width:360px;border:1px solid #e8edf5;border-radius:var(--radius-md);">
                <li>
                    <h6 class="dropdown-header"
                        style="font-size:.74rem;font-weight:700;color:var(--uis-primary);text-transform:uppercase;letter-spacing:.06em;">
                        Notifications
                    </h6>
                </li>
                <?php foreach ($_app_notifs as $_n): ?>
                <li>
                    <a class="dropdown-item py-2 <?php echo $_n['is_read'] ? '' : 'fw-semibold'; ?>"
                       href="<?php echo SITE_URL; ?>/notifications.php?open=<?php echo (int)$_n['notification_id']; ?>"
                       style="white-space:normal;">
                        <div style="font-size:.8rem;line-height:1.35;">
                            <?php if (!$_n['is_read']): ?><span class="badge bg-success me-1" style="font-size:.58rem;">New</span><?php endif; ?>
                            <?php echo htmlspecialchars($_n['message']); ?>
                        </div>
                        <div class="text-muted" style="font-size:.7rem;"><?php echo date('d M Y, H:i', strtotime($_n['created_at'])); ?></div>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if ($_dh_total === 0 && !$_app_notifs): ?>
                <li>
                    <span class="dropdown-item-text text-center py-3" style="font-size:.82rem;color:#6b7280;">
                        <i class="fas fa-check-circle text-success me-1"></i>All clear
                    </span>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item text-center py-2"
                       href="<?php echo SITE_URL; ?>/notifications.php"
                       style="font-size:.79rem;color:var(--uis-primary);font-weight:600;">
                        See all notifications
                    </a>
                </li>
            </ul>
        </div>

        <!-- User dropdown -->
        <div class="dropdown">
            <button class="btn btn-light btn-sm d-flex align-items-center gap-2"
                    style="border:1.5px solid #e5e9f0;border-radius:var(--radius-md);padding:5px 10px;"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:26px;height:26px;background:linear-gradient(135deg,var(--uis-primary),var(--uis-secondary));color:#fff;font-size:.65rem;font-weight:700;">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <span class="d-none d-sm-inline" style="font-size:.82rem;font-weight:600;color:#1a2035;">
                    <?php echo htmlspecialchars($full_name); ?>
                </span>
                <i class="fas fa-chevron-down" style="font-size:.6rem;color:#6b7280;"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm"
                style="border:1px solid #e8edf5;border-radius:var(--radius-md);">
                <li>
                    <span class="dropdown-item-text" style="font-size:.78rem;color:#6b7280;">
                        Signed in as <strong style="color:#1a2035;"><?php echo htmlspecialchars($full_name); ?></strong>
                        <span class="badge bg-secondary ms-1" style="font-size:.65rem;"><?php echo $role_label; ?></span>
                    </span>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/help.php" style="font-size:.84rem;">
                        <i class="fas fa-circle-question me-2"></i>Help
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/logout.php"
                       data-confirm="You will need to sign in again to continue." data-confirm-title="Log out?" data-confirm-button="Log Out" data-confirm-tone="danger" data-confirm-icon="fa-right-from-bracket"
                       style="font-size:.84rem;color:#dc2626;">
                        <i class="fas fa-right-from-bracket me-2"></i>Log Out
                    </a>
                </li>
            </ul>
        </div>

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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?php echo SITE_URL; ?>/assets/js/ui.js"></script>

<!-- ================================================================
     SIDEBAR JAVASCRIPT
     ================================================================ -->
<script>
(function () {
    'use strict';

    /* ── Open / close for mobile overlay ── */
    window.openSidebar = function () {
        document.getElementById('mainSidebar').classList.add('sidebar-open');
        document.getElementById('sidebarOverlay').classList.add('overlay-show');
        document.body.style.overflow = 'hidden';
    };

    window.closeSidebar = function () {
        document.getElementById('mainSidebar').classList.remove('sidebar-open');
        document.getElementById('sidebarOverlay').classList.remove('overlay-show');
        document.body.style.overflow = '';
    };

    /* ── Rotate chevron arrow on collapse toggle ── */
    document.querySelectorAll('.sidebar-link-toggle').forEach(function (link) {
        var target = document.querySelector(link.getAttribute('href'));
        if (!target) return;

        // Sync arrow on page load (Bootstrap already applies 'show' via PHP)
        syncArrow(link, target.classList.contains('show'));

        target.addEventListener('show.bs.collapse',  function () { syncArrow(link, true);  });
        target.addEventListener('hide.bs.collapse',  function () { syncArrow(link, false); });
    });

    function syncArrow(link, isOpen) {
        var arrow = link.querySelector('.sidebar-arrow i');
        if (!arrow) return;
        if (isOpen) {
            arrow.classList.add('rotated');
        } else {
            arrow.classList.remove('rotated');
        }
    }

    /* ── Close mobile sidebar when a non-toggle link is clicked ── */
    document.querySelectorAll('.sidebar-link:not(.sidebar-link-toggle), .sidebar-sublink, .sidebar-logout-btn')
        .forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });

    /* ── Desktop sidebar mini-toggle ── */
    window.toggleSidebarMini = function () {
        document.body.classList.toggle('sidebar-mini');
    };

    /* ── Desktop header live clock ── */
    (function () {
        var el = document.getElementById('dhClock');
        if (!el) return;
        function tick() {
            var now  = new Date();
            var h    = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12; if (!h) h = 12;
            el.textContent =
                (h < 10 ? '0' : '') + h + ':' +
                (m < 10 ? '0' : '') + m + ':' +
                (s < 10 ? '0' : '') + s + ' ' + ampm;
        }
        tick();
        setInterval(tick, 1000);
    })();

})();
</script>
