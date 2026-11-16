<?php
// ============================================================
// ajax/get_vehicle_types.php  -  UC018 step 2: how many vehicles of each TYPE can be reserved for the trip period
//
// GET depart_date, depart_time, return_date, return_time, passengers [, quantity]
// The period reserved is departure -> return + 1 hour. For each type (car, MPV, van, bus, lorry) it says how many vehicles are left
// and whether the requested quantity can seat the passengers. Also says how the timing rule applies: 'ok' or 'urgent'.
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
$qty = max(1, min(20, (int)($_GET['quantity'] ?? 1)));
if (!isValidDate($dd) || !isValidTime($dt) || !isValidDate($rd) || !isValidTime($rt)) {
    echo json_encode(['success' => false, 'types' => [], 'message' => 'Please enter the departure and return date and time.']);
    exit();
}
if ($pax < 1) {
    echo json_encode(['success' => false, 'types' => [], 'message' => 'Please enter the number of passengers.']);
    exit();
}
$start = $dd . ' ' . timeWithSeconds($dt);
$end   = $rd . ' ' . timeWithSeconds($rt);
if (strtotime($end) <= strtotime($start)) {
    echo json_encode(['success' => false, 'types' => [], 'message' => 'The return must be after the departure.']);
    exit();
}
$timing = requestTimingRule($start);
$types = [];
foreach (VEHICLE_TYPES as $type => $label) {
    $a = vehicleTypeAvailability($conn, $type, $start, reservationEnd($end), $pax, $qty);
    $types[] = ['type' => $type, 'label' => $label, 'free' => $a['free'], 'enough' => $a['free'] >= $qty && $a['can_seat'], 'can_seat' => $a['can_seat']];
}
echo json_encode(['success' => true, 'timing' => $timing['level'], 'notice' => $timing['message'], 'types' => $types]);
