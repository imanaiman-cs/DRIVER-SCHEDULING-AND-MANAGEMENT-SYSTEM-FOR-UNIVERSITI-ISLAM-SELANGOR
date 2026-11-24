<?php
// ============================================================
// supervisor/approvals.php  -  Department Request (UC017 A1, UC025)
//   Waiting for approval: requests the Head of Department must approve, Urgent first (approve / reject on the request)
//   Department requests: every request of the department (and of the Heads of Department of child departments
//   that this Head of Department approves)
// ============================================================
$current_page = 'approvals.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireRoles(['supervisor']);

$me = currentUser();
$uid = (int)$me['user_id'];
$tab = ($_GET['tab'] ?? 'pending') === 'all' ? 'all' : 'pending';
$scope = '(r.approver_id = ? OR r.department_id = ?)';
$sp = [$uid, (int)$me['department_id']];
$pendingCount = (int)dbValue($conn, "SELECT COUNT(*) FROM requests r WHERE r.approver_id = ? AND r.status = 'pending' AND r.purpose_type <> 'personal'", [$uid]);

adminPageStart('Department Request');
pageHeader('Department Request', 'stamp', 'Requests from your department. Approve or reject the requests waiting for you within ' . getSettingInt($conn, 'LAPSE_HOURS') . ' hours, or they lapse.');
echo tabsNav(['pending' => 'Waiting for my approval', 'all' => 'All department requests'], $tab, fn($k) => '?tab=' . $k, ['pending' => $pendingCount]);
if ($tab === 'pending') {
    $rows = dbAll($conn, requestSelectSql() . " WHERE r.approver_id = ? AND r.status = 'pending' AND r.purpose_type <> 'personal' ORDER BY r.is_urgent DESC, r.created_at", [$uid]);
    echo requestListTable($rows, true, 'No request is waiting for your approval.');
} else {
    [$w, $p, $vals] = requestListFilter();
    $total = (int)dbValue($conn, "SELECT COUNT(*) FROM requests r JOIN users u ON u.user_id = r.requester_id WHERE $scope$w", array_merge($sp, $p));
    $pg = paginate($total, max(1, (int)($_GET['page'] ?? 1)), 15);
    $rows = dbAll($conn, requestSelectSql() . " WHERE $scope$w ORDER BY r.created_at DESC LIMIT ? OFFSET ?", array_merge($sp, $p, [$pg['rows_per_page'], $pg['offset']]));
    echo requestFilterForm($vals, ['tab' => 'all'], true);
    echo requestListTable($rows, true);
    echo pagerHtml($pg, fn($i) => '?' . http_build_query(array_filter($vals + ['tab' => 'all', 'page' => $i])));
}
adminPageEnd();
