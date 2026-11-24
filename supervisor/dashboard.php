<?php
// ============================================================
// supervisor/dashboard.php  -  Head of Department: requests waiting for my approval, then my own requests
// ============================================================
$current_page = 'dashboard.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
require_once '../includes/dashboard_my.php';
requireRoles(['supervisor']);

$me = currentUser();
$uid = (int)$me['user_id'];
$pending = dbAll($conn, requestSelectSql() . " WHERE r.approver_id = ? AND r.status = 'pending' AND r.purpose_type <> 'personal' ORDER BY r.is_urgent DESC, r.created_at LIMIT 8", [$uid]);
$lapse = getSettingInt($conn, 'LAPSE_HOURS');
adminPageStart('Dashboard');
echo dashHeader([['New Request', '../staff/request_vehicle.php', 'car-side', false], ['Department Request', 'approvals.php', 'stamp', true]], 'Head of Department · ' . ($me['department_name'] ?? ''));
echo '<section class="dash-card mb-6"><h2><span><i class="fas fa-stamp"></i>Waiting for my approval (' . count($pending) . ')</span><a href="approvals.php">Open</a></h2>';
if (!$pending) { echo dashEmpty('No request is waiting for you.', 'circle-check'); }
foreach ($pending as $r) {
    $left = max(0, (int)floor((min(strtotime($r['created_at']) + $lapse * 3600, strtotime($r['depart_at'])) - time()) / 3600));
    echo '<a class="dash-row" href="../staff/request.php?id=' . (int)$r['request_id'] . '"><span><strong>#' . (int)$r['request_id'] . '</strong> ' . h($r['requester_name']) . ' &rarr; ' . h($r['destination']) . urgentBadge($r)
       . '<span class="block text-xs text-base-content/65">' . h(fmtPeriod($r['depart_at'], $r['return_at'])) . ' &middot; ' . h(requestVehicleSummary($r)) . '</span></span>'
       . '<span class="text-xs text-warning">Lapses in ' . $left . ' h</span></a>';
}
echo '</section><h2 class="mb-4 text-xl font-bold tracking-tight">My own requests</h2>';
dashMyRequestsSection($conn, $uid);
adminPageEnd();
