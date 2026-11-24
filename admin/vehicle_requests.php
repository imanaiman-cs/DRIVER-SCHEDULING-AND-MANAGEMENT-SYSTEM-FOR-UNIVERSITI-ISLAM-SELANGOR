<?php
// ============================================================
// admin/vehicle_requests.php  -  UC017 A2 All Request (Administrative Assistant, Head of Admin)
// Requests from all departments; filter by status, departure date, or search by ID / destination / name.
// ============================================================
$current_page = 'vehicle_requests.php';
require_once '../config/database.php';
require_once '../includes/ui.php';
requireAdmin();

[$w, $p, $vals] = requestListFilter();
$total = (int)dbValue($conn, "SELECT COUNT(*) FROM requests r JOIN users u ON u.user_id = r.requester_id WHERE 1=1$w", $p);
$pg = paginate($total, max(1, (int)($_GET['page'] ?? 1)), 20);
$rows = dbAll($conn, requestSelectSql() . " WHERE 1=1$w ORDER BY r.created_at DESC, r.request_id DESC LIMIT ? OFFSET ?", array_merge($p, [$pg['rows_per_page'], $pg['offset']]));
adminPageStart('All Request');
pageHeader('All Request', 'file-signature', 'Vehicle requests from all departments.', '<a class="btn btn-primary" href="assign_driver.php"><i class="fas fa-user-plus"></i>Assign Request Task</a>');
echo requestFilterForm($vals, [], true);
echo requestListTable($rows, true);
echo pagerHtml($pg, fn($i) => '?' . http_build_query(array_filter($vals + ['page' => $i])));
adminPageEnd();
