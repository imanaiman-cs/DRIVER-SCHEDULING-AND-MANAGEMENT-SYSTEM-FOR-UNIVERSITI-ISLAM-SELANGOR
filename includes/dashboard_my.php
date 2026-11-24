<?php
// ============================================================
// includes/dashboard_my.php  -  "My requests" block shared by the Staff, Head of Department and Driver dashboards
// ============================================================
require_once __DIR__ . '/dashboard_ui.php';

function dashMyRequestsSection(mysqli $conn, int $uid): void
{
    $active = dbAll($conn, requestSelectSql() . " WHERE r.requester_id = ? AND r.status IN ('pending','hod_approved','driver_assigned','approved') ORDER BY r.depart_at LIMIT 6", [$uid]);
    $counts = array_column(dbAll($conn, 'SELECT status, COUNT(*) AS n FROM requests WHERE requester_id = ? GROUP BY status', [$uid]), 'n', 'status');
    $inProgress = (int)($counts['pending'] ?? 0) + (int)($counts['hod_approved'] ?? 0) + (int)($counts['driver_assigned'] ?? 0);
    echo '<div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">'
       . dashKpi('In progress', $inProgress, 'hourglass-half', 'warning', SITE_URL . '/staff/my_requests.php')
       . dashKpi('Approved', (int)($counts['approved'] ?? 0), 'circle-check', 'success', SITE_URL . '/staff/my_requests.php?status=approved')
       . dashKpi('Completed', (int)($counts['completed'] ?? 0), 'flag-checkered', 'secondary', SITE_URL . '/staff/my_requests.php?status=completed')
       . dashKpi('Rejected / Lapsed', (int)($counts['rejected'] ?? 0) + (int)($counts['lapsed'] ?? 0), 'circle-xmark', 'danger', SITE_URL . '/staff/my_requests.php?status=rejected')
       . '</div>';
    echo '<section class="dash-card"><h2><span><i class="fas fa-list-check"></i>My active requests</span><a href="' . SITE_URL . '/staff/my_requests.php">See all</a></h2>';
    if (!$active) { echo dashEmpty('You have no active request.', 'car-side'); }
    foreach ($active as $r) {
        echo '<a class="dash-row" href="' . SITE_URL . '/staff/request.php?id=' . (int)$r['request_id'] . '"><span><strong>#' . (int)$r['request_id'] . '</strong> ' . h($r['destination'])
           . urgentBadge($r) . '<span class="block text-xs text-base-content/65">' . h(fmtPeriod($r['depart_at'], $r['return_at'])) . ' &middot; ' . h(requestVehicleSummary($r)) . '</span></span>'
           . '<span class="text-end">' . requestStatusBadge($r['status']) . (($w = requestWaitingText($r)) ? '<span class="block text-xs text-base-content/60">' . h($w) . '</span>' : '') . '</span></a>';
    }
    echo '</section>';
}
