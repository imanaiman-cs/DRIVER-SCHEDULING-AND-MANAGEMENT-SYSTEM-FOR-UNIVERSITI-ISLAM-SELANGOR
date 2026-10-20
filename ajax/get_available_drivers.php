<?php
// ============================================================
// ajax/get_available_drivers.php  -  UC018 View Driver Recommendation (JSON)
//
// GET start, end (Y-m-d H:i:s) [, licence = D|GDL|PSV] [, except_schedule = id]
// Returns the ranked list (rank, driver, tasks this month, tasks outside working hours, welfare-duty flag, score)
// and the drivers that were left out with the reason. The system only recommends: it never assigns by itself.
// ============================================================
require_once '../config/database.php';
header('Content-Type: application/json');
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
$start = trim((string)($_GET['start'] ?? ''));
$end   = trim((string)($_GET['end'] ?? ''));
$licence = strtoupper(trim((string)($_GET['licence'] ?? '')));
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $end) || strtotime($end) <= strtotime($start)) {
    echo json_encode(['success' => false, 'message' => 'A valid start and end are needed.']);
    exit();
}
$rec = recommendDrivers($conn, [
    'start' => date('Y-m-d H:i:s', strtotime($start)), 'end' => date('Y-m-d H:i:s', strtotime($end)),
    'licence' => isset(LICENCE_CLASSES[$licence]) ? $licence : null,
    'except_schedule' => (int)($_GET['except_schedule'] ?? 0),
]);
echo json_encode(['success' => true, 'drivers' => $rec['ranked'], 'excluded' => $rec['excluded'], 'weights' => $rec['weights']]);
