<?php
// ============================================================
// UIS Driver Scheduling and Management System
// staff/dashboard.php  -  the staff member's start page: my next trip, the progress of my requests, recent activity
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/admin_page.php';
require_once '../includes/dashboard_my.php';
requireRequester();   // every role is also staff

$uid = (int)$_SESSION['user_id'];
adminPageStart('Dashboard');
?>
    <div class="dash-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h4 class="fw-bold mb-2"><?= dashGreeting() ?>, <?= dh($_SESSION['full_name'] ?? '') ?></h4>
            <div><?= dashChips([['building-columns', (string)($_SESSION['department'] ?? 'Universiti Islam Selangor')], ['calendar-day', date('l, d F Y')]]) ?></div>
        </div>
        <a class="btn btn-light" href="request_vehicle.php"><i class="fas fa-car-side me-1"></i>Request a vehicle</a>
    </div>
    <?php dashMyRequestsSection($conn, $uid); ?>
<?php adminPageEnd(); ?>
