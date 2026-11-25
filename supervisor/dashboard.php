<?php
// ============================================================
// supervisor/dashboard.php  -  View Layer: Head of Department start page (requests waiting for me, my own requests)
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
require_once '../includes/dashboard_my.php';
requireRoles([User::HOD]);

$me = currentUser();
$rc = new RequestController();
$pending = $rc->listWaitingForHead($me);
$lapse = (int)setting('LAPSE_HOURS');
adminPageStart('Dashboard');
echo dashHeader([['New Request', '../staff/request_vehicle.php', 'car-side', false], ['Department Request', 'approvals.php', 'stamp', true]], 'Head of Department · ' . $me['department_name']);
echo '<section class="dash-card mb-6"><h2><span><i class="fas fa-stamp"></i>Waiting for my approval (' . count($pending) . ')</span><a href="approvals.php">Open</a></h2>';
if (!$pending) { echo dashEmpty('No request is waiting for you.', 'circle-check'); }
foreach (array_slice($pending, 0, 8) as $r) {
    $left = max(0, (int)floor((min(strtotime($r['date_submitted']) + $lapse * 3600, strtotime($r['depart_datetime'])) - time()) / 3600));
    echo '<a class="dash-row" href="../staff/request.php?id=' . (int)$r['request_id'] . '"><span><strong>#' . (int)$r['request_id'] . '</strong> ' . h($r['requester_name']) . ' &rarr; ' . h($r['destination']) . urgentBadge($r)
       . '<span class="block text-xs text-base-content/65">' . h(fmtPeriod($r['depart_datetime'], $r['return_datetime'])) . ' &middot; ' . h($r['vehicle_type'] . ' x ' . $r['quantity'] . ', ' . $r['driver_option']) . '</span></span>'
       . '<span class="text-xs text-warning">Lapses in ' . $left . ' h</span></a>';
}
echo '</section><h2 class="mb-4 text-xl font-bold tracking-tight">My own requests</h2>';
dashMyRequestsSection($me);
adminPageEnd();
