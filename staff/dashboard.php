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
    <?= dashHeader('Today, ' . date('l j F'), '<a class="btn btn-primary btn-sm" href="request_vehicle.php">Request a vehicle</a>') ?>
    <?php dashMyRequestsSection($conn, $uid); ?>
<?php adminPageEnd(); ?>
