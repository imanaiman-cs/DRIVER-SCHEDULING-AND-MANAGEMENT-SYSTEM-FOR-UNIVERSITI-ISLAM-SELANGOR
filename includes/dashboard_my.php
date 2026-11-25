<?php
// ============================================================
// includes/dashboard_my.php  -  View Layer: the "My requests" block of the dashboards (through RequestController)
// ============================================================
require_once __DIR__ . '/dashboard_ui.php';

function dashMyRequestsSection(array $me): void
{
    $rc = new RequestController();
    $all = $rc->listRequests($me, 'mine')['rows'];
    $counts = array_count_values(array_column($all, 'status'));
    $active = array_values(array_filter($all, fn($r) => in_array($r['status'], Request::ACTIVE_STATUSES, true)));
    usort($active, fn($a, $b) => strcmp($a['depart_datetime'], $b['depart_datetime']));
    $inProgress = ($counts['Pending'] ?? 0) + ($counts['Approved by HoD'] ?? 0) + ($counts['Driver Assigned'] ?? 0);
    echo '<div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">'
       . dashKpi('In progress', $inProgress, 'hourglass-half', 'warning', SITE_URL . '/staff/my_requests.php')
       . dashKpi('Approved', $counts['Approved'] ?? 0, 'circle-check', 'success', SITE_URL . '/staff/my_requests.php?status=Approved')
       . dashKpi('Completed', $counts['Completed'] ?? 0, 'flag-checkered', 'secondary', SITE_URL . '/staff/my_requests.php?status=Completed')
       . dashKpi('Rejected / Lapsed', ($counts['Rejected'] ?? 0) + ($counts['Lapsed'] ?? 0), 'circle-xmark', 'danger', SITE_URL . '/staff/my_requests.php?status=Rejected')
       . '</div>';
    echo '<section class="dash-card"><h2><span><i class="fas fa-list-check"></i>My active requests</span><a href="' . SITE_URL . '/staff/my_requests.php">See all</a></h2>';
    if (!$active) { echo dashEmpty('You have no active request.', 'car-side'); }
    foreach (array_slice($active, 0, 6) as $r) {
        echo '<a class="dash-row" href="' . SITE_URL . '/staff/request.php?id=' . (int)$r['request_id'] . '"><span><strong>#' . (int)$r['request_id'] . '</strong> ' . h($r['destination']) . urgentBadge($r)
           . '<span class="block text-xs text-base-content/65">' . h(fmtPeriod($r['depart_datetime'], $r['return_datetime'])) . ' &middot; ' . h($r['vehicle_type'] . ' x ' . $r['quantity'] . ', ' . $r['driver_option']) . '</span></span>'
           . '<span class="text-end">' . requestStatusBadge($r['status']) . (($w = $rc->waitingText($r)) ? '<span class="block text-xs text-base-content/60">' . h($w) . '</span>' : '') . '</span></a>';
    }
    echo '</section>';
}
