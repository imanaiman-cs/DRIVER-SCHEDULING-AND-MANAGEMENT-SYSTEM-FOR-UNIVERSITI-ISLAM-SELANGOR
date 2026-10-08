<?php
// ============================================================
// ajax/check_vehicles_free.php
// For the e-Kenderaan form: are the chosen vehicles free in the slot?
// GET: trip_date, start_time, end_time, ids (comma separated vehicle ids)
// Returns each vehicle with free = true/false, plus the seats in total.
// ============================================================
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isStaff() && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

$trip_date  = trim($_GET['trip_date']  ?? '');
$start_time = trim($_GET['start_time'] ?? '');
$end_time   = trim($_GET['end_time']   ?? '');
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))));
$ids = array_slice($ids, 0, 5);

if (!$ids
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trip_date)
    || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)
    || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)
    || $end_time <= $start_time) {
    echo json_encode(['success' => false, 'message' => 'Missing or invalid parameters.']);
    exit();
}

$in = implode(',', $ids);
$stmt = $conn->prepare(
    "SELECT v.vehicle_id, v.plate_number, v.capacity, v.status,
            EXISTS (
                SELECT 1 FROM schedules s
                WHERE s.vehicle_id = v.vehicle_id AND s.trip_date = ?
                  AND s.status NOT IN ('cancelled', 'completed')
                  AND s.start_time < ? AND s.end_time > ?
            ) AS booked
     FROM vehicles v WHERE v.vehicle_id IN ({$in})"
);
$stmt->bind_param('sss', $trip_date, $end_time, $start_time);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$out = [];
$seats = 0;
foreach ($rows as $r) {
    $ok = in_array($r['status'], ['available', 'in_use'], true) && (int)$r['booked'] === 0;
    $reason = $ok ? '' : ($r['status'] === 'maintenance' ? 'under maintenance'
                        : ($r['status'] === 'retired' ? 'not in service' : 'already booked at this time'));
    $out[] = [
        'vehicle_id' => (int)$r['vehicle_id'], 'plate_number' => $r['plate_number'],
        'capacity' => (int)$r['capacity'], 'free' => $ok, 'reason' => $reason,
    ];
    if ($ok) { $seats += (int)$r['capacity']; }
}
echo json_encode(['success' => true, 'vehicles' => $out, 'free_seats' => $seats]);
