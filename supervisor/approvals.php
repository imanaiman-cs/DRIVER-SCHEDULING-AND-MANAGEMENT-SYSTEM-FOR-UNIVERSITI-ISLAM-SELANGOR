<?php
// ============================================================
// supervisor/approvals.php  -  View Layer: ApprovalUI (SDD_CLASS_105) displayPendingList() and RequestUI
// displayRequestList() - Department Request (UC017 A1, UC025)
//   Waiting for my approval: requests the head must approve, Urgent first (decision on the request page)
//   All department requests: the department's requests, and the own requests of the heads that report to it
// ============================================================
$current_page = 'approvals.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireRoles([User::HOD]);

$me = currentUser();
$rc = new RequestController();
$tab = ($_GET['tab'] ?? 'pending') === 'all' ? 'all' : 'pending';
$waiting = $rc->listWaitingForHead($me);

adminPageStart('Department Request');
pageHeader('Department Request', 'stamp', 'Requests from your department. Approve or reject the requests waiting for you within ' . (int)setting('LAPSE_HOURS') . ' hours, or they lapse.');
echo tabsNav(['pending' => 'Waiting for my approval', 'all' => 'All department requests'], $tab, fn($k) => '?tab=' . $k, ['pending' => count($waiting)]);
if ($tab === 'pending') {
    echo requestListTable($waiting, true, $rc, 'No request is waiting for your approval.');
} else {
    $vals = requestListFilter();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $list = $rc->listRequests($me, 'department', $vals + ['limit' => 15, 'offset' => ($page - 1) * 15]);
    $pg = paginate($list['total'], $page, 15);
    echo requestFilterForm($vals, ['tab' => 'all'], true);
    echo requestListTable($list['rows'], true, $rc);
    echo pagerHtml($pg, fn($i) => '?' . http_build_query(array_filter($vals + ['tab' => 'all', 'page' => $i])));
}
adminPageEnd();
