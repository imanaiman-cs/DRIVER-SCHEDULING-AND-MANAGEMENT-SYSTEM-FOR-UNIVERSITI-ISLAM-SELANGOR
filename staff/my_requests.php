<?php
// ============================================================
// staff/my_requests.php  -  View Layer: RequestUI (SDD_CLASS_104) displayRequestList() - UC017 My Request
// The user's own requests; filter by status or departure date; select a request to see the details and history.
// ============================================================
$current_page = 'my_requests.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireLogin();

$rc = new RequestController();
$vals = requestListFilter();
$page = max(1, (int)($_GET['page'] ?? 1));
$list = $rc->listRequests(currentUser(), 'mine', $vals + ['limit' => 15, 'offset' => ($page - 1) * 15]);
$pg = paginate($list['total'], $page, 15);

adminPageStart('My Request');
pageHeader('My Request', 'list-check', 'Your vehicle requests and their status. A Lapsed request was not approved in time; it was not cancelled by the Vehicle Section.',
    '<a class="btn btn-primary" href="request_vehicle.php"><i class="fas fa-plus"></i>New Request</a>');
echo requestFilterForm($vals);
echo requestListTable($list['rows'], false, $rc, array_filter($vals) ? 'No record is found.' : 'You have not made any request yet.');
echo pagerHtml($pg, fn($i) => '?' . http_build_query(array_filter($vals + ['page' => $i])));
adminPageEnd();
