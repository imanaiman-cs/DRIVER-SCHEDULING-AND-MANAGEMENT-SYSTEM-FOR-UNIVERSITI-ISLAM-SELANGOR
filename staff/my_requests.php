<?php
// ============================================================
// staff/my_requests.php  -  UC017 View Request: My Request (the user's own requests)
// Filter by status or departure date; select a request to see the details, history and actions.
// ============================================================
$current_page = 'my_requests.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireLogin();

$uid = (int)$_SESSION['user_id'];
[$w, $p, $vals] = requestListFilter();
$total = (int)dbValue($conn, "SELECT COUNT(*) FROM requests r JOIN users u ON u.user_id = r.requester_id WHERE r.requester_id = ?$w", array_merge([$uid], $p));
$pg = paginate($total, max(1, (int)($_GET['page'] ?? 1)), 15);
$rows = dbAll($conn, requestSelectSql() . " WHERE r.requester_id = ?$w ORDER BY r.created_at DESC, r.request_id DESC LIMIT ? OFFSET ?",
    array_merge([$uid], $p, [$pg['rows_per_page'], $pg['offset']]));

adminPageStart('My Request');
$canRequest = in_array($_SESSION['role'], ['staff', 'supervisor', 'driver'], true);
pageHeader('My Request', 'list-check', 'Your vehicle requests and their status. A Lapsed request was not approved in time; it was not cancelled by the Vehicle Section.',
    $canRequest ? '<a class="btn btn-primary" href="request_vehicle.php"><i class="fas fa-plus"></i>New Request</a>' : '');
echo requestFilterForm($vals);
echo requestListTable($rows, false, $vals['status'] || $vals['from'] || $vals['to'] ? 'No record is found.' : 'You have not made any request yet.');
echo pagerHtml($pg, fn($i) => '?' . http_build_query(array_filter($vals + ['page' => $i])));
adminPageEnd();
