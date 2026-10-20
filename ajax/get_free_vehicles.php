<?php
// ============================================================
// ajax/get_free_vehicles.php  -  UC010 step 2: the vehicles a requester may choose
//
// GET depart_date, depart_time, return_date, return_time, passengers [, request_id]
// Returns ONLY vehicles that are
//   - not locked (or used) in that TIME PERIOD  (overlap rule: start < other_end AND end > other_start),
//   - not Under Maintenance or Inactive,
//   - big enough for the passengers.
// Also says how the timing rule applies: 'ok', 'warn' (less than 2 days: confirm) or 'reject' (less than 1 day).
// ============================================================
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

$dd = trim((string)($_GET['depart_date'] ?? ''));
$dt = trim((string)($_GET['depart_time'] ?? ''));
$rd = trim((string)($_GET['return_date'] ?? ''));
$rt = trim((string)($_GET['return_time'] ?? ''));
$pax = (int)($_GET['passengers'] ?? 0);

if (!isValidDate($dd) || !isValidTime($dt) || !isValidDate($rd) || !isValidTime($rt)) {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'Please enter the departure and return date and time.']);
    exit();
}
if ($pax < 1) {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'Please enter the number of passengers.']);
    exit();
}
$start = $dd . ' ' . timeWithSeconds($dt);
$end   = $rd . ' ' . timeWithSeconds($rt);
if (strtotime($end) <= strtotime($start)) {
    echo json_encode(['success' => false, 'vehicles' => [], 'message' => 'The return must be after the departure.']);
    exit();
}

// the timing rule is for requesters; the Vehicle Section choosing a RETURN vehicle (for_assignment=1) is not bound by it
$timing = (isAdmin() && !empty($_GET['for_assignment'])) ? ['level' => 'ok', 'message' => null] : requestTimingRule($start);
$vehicles = $timing['level'] === 'reject' ? [] : availableVehicles($conn, $start, $end, $pax, (int)($_GET['request_id'] ?? 0));

echo json_encode([
    'success'  => true,
    'timing'   => $timing['level'],
    'notice'   => $timing['message'],
    'vehicles' => array_map(fn($v) => [
        'vehicle_id' => (int)$v['vehicle_id'], 'plate_number' => $v['plate_number'], 'vehicle_type' => $v['vehicle_type'],
        'model' => $v['model'], 'capacity' => (int)$v['capacity'], 'license_required' => $v['license_required'],
    ], $vehicles),
]);
