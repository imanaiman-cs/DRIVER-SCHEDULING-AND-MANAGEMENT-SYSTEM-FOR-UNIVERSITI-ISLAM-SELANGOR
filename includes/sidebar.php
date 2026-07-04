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
$role_label = match ($role) {
    'admin',
    'superadmin' => 'Administrator',
    'driver'     => 'Driver',
    default      => ucfirst($role),
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

// ── Admin page groups ────────────────────────────────────────────
$driver_pages   = ['drivers.php', 'add_driver.php', 'edit_driver.php', 'view_driver.php'];
$vehicle_pages  = ['vehicles.php', 'add_vehicle.php', 'edit_vehicle.php', 'view_vehicle.php'];
$schedule_pages = ['schedules.php', 'add_schedule.php', 'edit_schedule.php', 'view_schedule.php', 'auto_assign.php', 'calendar.php'];
$leave_pages    = ['leave_requests.php'];
$report_pages   = ['report_driver.php', 'report_workload.php', 'report_vehicle.php', 'report_monthly.php'];

// ── Unread message count + pending alerts ────────────────────────
$_sidebar_uid        = (int)($_SESSION['user_id'] ?? 0);
$_unread_msgs        = 0;
$_pending_leaves     = 0;
$_pending_assignments = 0;
if ($_sidebar_uid > 0 && isset($conn)) {
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=$_sidebar_uid AND is_read=0");
    if ($r) $_unread_msgs = (int)$r->fetch_assoc()['cnt'];
    if ($role === 'admin' || $role === 'superadmin') {
        $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM leave_requests WHERE status='pending'");
        if ($r2) $_pending_leaves = (int)$r2->fetch_assoc()['cnt'];
        $r3 = $conn->query("SELECT COUNT(*) AS cnt FROM schedules WHERE driver_id IS NULL AND status NOT IN ('cancelled','completed')");
        if ($r3) $_pending_assignments = (int)$r3->fetch_assoc()['cnt'];
    }
}
?>

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
                <i class="fas fa-graduation-cap" aria-hidden="true"></i>
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
                <?php if ($role === 'admin' || $role === 'superadmin'): ?>
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

            <?php if ($role === 'admin' || $role === 'superadmin'): ?>
            <!-- ====================================================
                 ADMIN MENU
                 ==================================================== -->

            <li class="sidebar-section-label">Main</li>

            <!-- Dashboard -->
            <li class="sidebar-item <?php echo sidebarActive('dashboard.php', $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/admin/dashboard.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-gauge-high" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Dashboard</span>
                </a>
            </li>

            <li class="sidebar-section-label">Management</li>

            <!-- Drivers ── with submenu -->
            <li class="sidebar-item sidebar-item-has-children <?php echo sidebarActive($driver_pages, $current_page); ?>">
                <a href="#driversSubmenu"
                   class="sidebar-link sidebar-link-toggle <?php echo sidebarActive($driver_pages, $current_page); ?>"
                   data-bs-toggle="collapse"
                   aria-expanded="<?php echo in_array($current_page, $driver_pages) ? 'true' : 'false'; ?>"
                   aria-controls="driversSubmenu">
                    <span class="sidebar-icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Drivers</span>
                    <span class="sidebar-arrow"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
                </a>
                <ul class="sidebar-submenu collapse <?php echo submenuShow($driver_pages, $current_page); ?>"
                    id="driversSubmenu">
                    <li class="sidebar-subitem <?php echo sidebarActive('drivers.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/drivers.php" class="sidebar-sublink">
                            <i class="fas fa-list fa-xs" aria-hidden="true"></i> All Drivers
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('add_driver.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/add_driver.php" class="sidebar-sublink">
                            <i class="fas fa-user-plus fa-xs" aria-hidden="true"></i> Add Driver
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Vehicles ── with submenu -->
            <li class="sidebar-item sidebar-item-has-children <?php echo sidebarActive($vehicle_pages, $current_page); ?>">
                <a href="#vehiclesSubmenu"
                   class="sidebar-link sidebar-link-toggle <?php echo sidebarActive($vehicle_pages, $current_page); ?>"
                   data-bs-toggle="collapse"
                   aria-expanded="<?php echo in_array($current_page, $vehicle_pages) ? 'true' : 'false'; ?>"
                   aria-controls="vehiclesSubmenu">
                    <span class="sidebar-icon"><i class="fas fa-car" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Vehicles</span>
                    <span class="sidebar-arrow"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
                </a>
                <ul class="sidebar-submenu collapse <?php echo submenuShow($vehicle_pages, $current_page); ?>"
                    id="vehiclesSubmenu">
                    <li class="sidebar-subitem <?php echo sidebarActive('vehicles.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/vehicles.php" class="sidebar-sublink">
                            <i class="fas fa-list fa-xs" aria-hidden="true"></i> All Vehicles
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('add_vehicle.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/add_vehicle.php" class="sidebar-sublink">
                            <i class="fas fa-circle-plus fa-xs" aria-hidden="true"></i> Add Vehicle
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Schedules ── with submenu -->
            <li class="sidebar-item sidebar-item-has-children <?php echo sidebarActive($schedule_pages, $current_page); ?>">
                <a href="#schedulesSubmenu"
                   class="sidebar-link sidebar-link-toggle <?php echo sidebarActive($schedule_pages, $current_page); ?>"
                   data-bs-toggle="collapse"
                   aria-expanded="<?php echo in_array($current_page, $schedule_pages) ? 'true' : 'false'; ?>"
                   aria-controls="schedulesSubmenu">
                    <span class="sidebar-icon"><i class="fas fa-calendar-days" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Schedules</span>
                    <span class="sidebar-arrow"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
                </a>
                <ul class="sidebar-submenu collapse <?php echo submenuShow($schedule_pages, $current_page); ?>"
                    id="schedulesSubmenu">
                    <li class="sidebar-subitem <?php echo sidebarActive('schedules.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/schedules.php" class="sidebar-sublink">
                            <i class="fas fa-list fa-xs" aria-hidden="true"></i> All Schedules
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('add_schedule.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/add_schedule.php" class="sidebar-sublink">
                            <i class="fas fa-calendar-plus fa-xs" aria-hidden="true"></i> Create Schedule
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('auto_assign.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/auto_assign.php" class="sidebar-sublink">
                            <i class="fas fa-wand-magic-sparkles fa-xs" aria-hidden="true"></i> Auto Assign
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('calendar.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/calendar.php" class="sidebar-sublink">
                            <i class="fas fa-calendar-week fa-xs" aria-hidden="true"></i> Calendar View
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Leave Requests -->
            <li class="sidebar-item <?php echo sidebarActive($leave_pages, $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/admin/leave_requests.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-calendar-xmark" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Leave Requests</span>
                    <?php if ($_pending_leaves > 0): ?>
                    <span class="ms-auto badge rounded-pill"
                          style="background:#f59e0b;font-size:0.65rem;min-width:18px;">
                        <?php echo $_pending_leaves; ?>
                    </span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="sidebar-section-label">Communication</li>

            <!-- Messages -->
            <li class="sidebar-item <?php echo sidebarActive('messages.php', $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/admin/messages.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-comments" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Messages</span>
                    <?php if ($_unread_msgs > 0): ?>
                    <span class="ms-auto badge rounded-pill"
                          style="background:#dc2626;font-size:0.65rem;min-width:18px;">
                        <?php echo $_unread_msgs; ?>
                    </span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="sidebar-section-label">Analytics</li>

            <!-- Reports ── with submenu -->
            <li class="sidebar-item sidebar-item-has-children <?php echo sidebarActive($report_pages, $current_page); ?>">
                <a href="#reportsSubmenu"
                   class="sidebar-link sidebar-link-toggle <?php echo sidebarActive($report_pages, $current_page); ?>"
                   data-bs-toggle="collapse"
                   aria-expanded="<?php echo in_array($current_page, $report_pages) ? 'true' : 'false'; ?>"
                   aria-controls="reportsSubmenu">
                    <span class="sidebar-icon"><i class="fas fa-chart-bar" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Reports</span>
                    <span class="sidebar-arrow"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
                </a>
                <ul class="sidebar-submenu collapse <?php echo submenuShow($report_pages, $current_page); ?>"
                    id="reportsSubmenu">
                    <li class="sidebar-subitem <?php echo sidebarActive('report_driver.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/report_driver.php" class="sidebar-sublink">
                            <i class="fas fa-trophy fa-xs" aria-hidden="true"></i> Driver Performance
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('report_workload.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/report_workload.php" class="sidebar-sublink">
                            <i class="fas fa-chart-pie fa-xs" aria-hidden="true"></i> Workload
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('report_vehicle.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/report_vehicle.php" class="sidebar-sublink">
                            <i class="fas fa-car-side fa-xs" aria-hidden="true"></i> Vehicle Usage
                        </a>
                    </li>
                    <li class="sidebar-subitem <?php echo sidebarActive('report_monthly.php', $current_page); ?>">
                        <a href="<?php echo SITE_URL; ?>/admin/report_monthly.php" class="sidebar-sublink">
                            <i class="fas fa-calendar-check fa-xs" aria-hidden="true"></i> Monthly
                        </a>
                    </li>
                </ul>
            </li>

            <?php else: ?>
            <!-- ====================================================
                 DRIVER MENU
                 ==================================================== -->

            <li class="sidebar-section-label">Main</li>

            <!-- Dashboard -->
            <li class="sidebar-item <?php echo sidebarActive('dashboard.php', $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/driver/dashboard.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-gauge-high" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Dashboard</span>
                </a>
            </li>

            <li class="sidebar-section-label">My Work</li>

            <!-- My Schedules -->
            <li class="sidebar-item <?php echo sidebarActive(['schedules.php', 'view_schedule.php'], $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/driver/schedules.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-calendar-days" aria-hidden="true"></i></span>
                    <span class="sidebar-label">My Schedules</span>
                </a>
            </li>

            <!-- Calendar View -->
            <li class="sidebar-item <?php echo sidebarActive('calendar.php', $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/driver/calendar.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-calendar-week" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Calendar</span>
                </a>
            </li>

            <!-- Leave Requests -->
            <li class="sidebar-item <?php echo sidebarActive(['leave_request.php', 'my_leaves.php'], $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/driver/leave_request.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-calendar-xmark" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Leave Request</span>
                </a>
            </li>

            <!-- My Leave History -->
            <li class="sidebar-item <?php echo sidebarActive('my_leaves.php', $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/driver/my_leaves.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                    <span class="sidebar-label">My Leave History</span>
                </a>
            </li>

            <li class="sidebar-section-label">Communication</li>

            <!-- Messages -->
            <li class="sidebar-item <?php echo sidebarActive('messages.php', $current_page); ?>">
                <a href="<?php echo SITE_URL; ?>/driver/messages.php" class="sidebar-link">
                    <span class="sidebar-icon"><i class="fas fa-comments" aria-hidden="true"></i></span>
                    <span class="sidebar-label">Messages</span>
                    <?php if ($_unread_msgs > 0): ?>
                    <span class="ms-auto badge rounded-pill"
                          style="background:#dc2626;font-size:0.65rem;min-width:18px;">
                        <?php echo $_unread_msgs; ?>
                    </span>
                    <?php endif; ?>
                </a>
            </li>

            <?php endif; ?>

        </ul><!-- /.sidebar-nav -->
    </div><!-- /.sidebar-menu-wrapper -->

    <!-- ── Sidebar Footer / Logout ───────────────────────────────── -->
    <div class="sidebar-footer">
        <a href="<?php echo SITE_URL; ?>/logout.php" class="sidebar-logout-btn"
           onclick="return confirm('Are you sure you want to log out?');">
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
        <i class="fas fa-graduation-cap" aria-hidden="true"></i>
        UIS Driver Management
    </span>
    <a href="<?php echo SITE_URL; ?>/logout.php" class="topbar-logout"
       onclick="return confirm('Are you sure you want to log out?');"
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
                    <a href="<?php echo SITE_URL; ?>/<?php echo ($role === 'admin' || $role === 'superadmin') ? 'admin' : 'driver'; ?>/dashboard.php"
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

        <?php if ($role === 'admin' || $role === 'superadmin'):
            $_dh_total = $_pending_leaves + $_pending_assignments; ?>
        <!-- Notification bell (admin) -->
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
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:260px;border:1px solid #e8edf5;border-radius:var(--radius-md);">
                <li>
                    <h6 class="dropdown-header"
                        style="font-size:.74rem;font-weight:700;color:var(--uis-primary);text-transform:uppercase;letter-spacing:.06em;">
                        Notifications
                    </h6>
                </li>
                <?php if ($_pending_assignments > 0): ?>
                <li>
                    <a class="dropdown-item py-2" href="<?php echo SITE_URL; ?>/admin/schedules.php">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle bg-danger d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:28px;height:28px;">
                                <i class="fas fa-calendar-xmark" style="color:#fff;font-size:.65rem;"></i>
                            </div>
                            <div style="font-size:.81rem;">
                                <div class="fw-semibold"><?php echo $_pending_assignments; ?> unassigned schedule<?php echo $_pending_assignments > 1 ? 's' : ''; ?></div>
                                <div class="text-muted" style="font-size:.72rem;">Drivers not yet assigned</div>
                            </div>
                        </div>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($_pending_leaves > 0): ?>
                <li>
                    <a class="dropdown-item py-2" href="<?php echo SITE_URL; ?>/admin/leave_requests.php">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                 style="width:28px;height:28px;background:#f59e0b;">
                                <i class="fas fa-calendar-minus" style="color:#fff;font-size:.65rem;"></i>
                            </div>
                            <div style="font-size:.81rem;">
                                <div class="fw-semibold"><?php echo $_pending_leaves; ?> pending leave<?php echo $_pending_leaves > 1 ? 's' : ''; ?></div>
                                <div class="text-muted" style="font-size:.72rem;">Awaiting review</div>
                            </div>
                        </div>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($_dh_total === 0): ?>
                <li>
                    <span class="dropdown-item-text text-center py-3" style="font-size:.82rem;color:#9ca3af;">
                        <i class="fas fa-check-circle text-success me-1"></i>All clear
                    </span>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item text-center py-2"
                       href="<?php echo SITE_URL; ?>/admin/schedules.php"
                       style="font-size:.79rem;color:var(--uis-primary);font-weight:600;">
                        View All Schedules
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

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
                <i class="fas fa-chevron-down" style="font-size:.6rem;color:#9ca3af;"></i>
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
                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/logout.php"
                       onclick="return confirm('Are you sure you want to log out?');"
                       style="font-size:.84rem;color:#dc2626;">
                        <i class="fas fa-right-from-bracket me-2"></i>Log Out
                    </a>
                </li>
            </ul>
        </div>

    </div>
</header>

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
