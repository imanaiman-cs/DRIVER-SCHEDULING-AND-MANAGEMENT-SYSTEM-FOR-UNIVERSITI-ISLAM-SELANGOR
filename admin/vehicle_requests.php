<?php
// ============================================================
// admin/vehicle_requests.php  -  View Layer: RequestUI (SDD_CLASS_104) displayRequestList() - UC017 A2 All Request
// Requests from all departments; filter by status, departure date, or search by ID / destination / name.
// ============================================================
$current_page = 'vehicle_requests.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

$rc = new RequestController();
$vals = requestListFilter();
$page = max(1, (int)($_GET['page'] ?? 1));
$list = $rc->listRequests(currentUser(), 'all', $vals + ['limit' => 20, 'offset' => ($page - 1) * 20]);
$pg = paginate($list['total'], $page, 20);
adminPageStart('All Request');
pageHeader('All Request', 'file-signature', 'Vehicle requests from all departments.', '<a class="btn btn-primary" href="assign_driver.php"><i class="fas fa-user-plus"></i>Assign Request Task</a>');
echo requestFilterForm($vals, [], true);
echo requestListTable($list['rows'], true, $rc);
echo pagerHtml($pg, fn($i) => '?' . http_build_query(array_filter($vals + ['page' => $i])));
adminPageEnd();
