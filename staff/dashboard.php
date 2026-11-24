<?php
// ============================================================
// staff/dashboard.php  -  Staff start page: the progress of my requests
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
require_once '../includes/dashboard_my.php';
requireLogin();
if (in_array($_SESSION['role'], ['admin', 'head_admin', 'supervisor', 'driver'], true)) { header('Location: ' . roleHomeUrl()); exit(); }

$me = currentUser();
adminPageStart('Dashboard');
echo dashHeader([['New Request', 'request_vehicle.php', 'car-side', true]], ($me['department_name'] ?? 'Universiti Islam Selangor') . ' · ' . date('l, j F Y'));
if ($me['pending_role']) {
    echo '<div class="alert alert-soft alert-info mb-5"><i class="fas fa-hourglass-half"></i><span>Your Head of Department role is waiting for confirmation by the Administrative Assistant. Until then you use the system as Staff.</span></div>';
}
dashMyRequestsSection($conn, (int)$me['user_id']);
adminPageEnd();
