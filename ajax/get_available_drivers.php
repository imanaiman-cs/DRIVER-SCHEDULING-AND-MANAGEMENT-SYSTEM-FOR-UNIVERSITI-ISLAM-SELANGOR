<?php
require_once '../config/database.php';
requireAdmin();
header('Content-Type: application/json');

// Parameters may arrive via POST (forms) or GET
$param = function (string $key) {
    return $_POST[$key] ?? $_GET[$key] ?? '';
};

$trip_date   = trim((string)$param('trip_date'));
$start_time  = trim((string)$param('start_time'));
$end_time    = trim((string)$param('end_time'));
$exclude_id  = (int)$param('schedule_id');
$vehicle_id  = (int)$param('vehicle_id');          // optional
$trip_type   = trim((string)$param('trip_type'));  // optional: top_management | regular

if (!$trip_date || !$start_time || !$end_time) {
    echo json_encode(['success' => false, 'drivers' => [], 'message' => 'Missing parameters.']);
    exit();
}

// ── Vehicle type (decides the licence class that is needed) ──
$vehicle_type = null;
if ($vehicle_id > 0) {
    $vs = $conn->prepare("SELECT vehicle_type FROM vehicles WHERE vehicle_id = ? LIMIT 1");
    $vs->bind_param('i', $vehicle_id);
    $vs->execute();
    $vrow = $vs->get_result()->fetch_assoc();
    $vs->close();
    $vehicle_type = $vrow['vehicle_type'] ?? null;
}

// ── DriverRecommender: hard filters, then the weighted score (includes/recommender.php) ──
// A normal trip never offers Top Management drivers (R8); a Top Management trip offers only them.
$rec = recommendDrivers($conn, [
    'date' => $trip_date, 'start' => $start_time, 'end' => $end_time,
    'vehicle_type' => $vehicle_type,
    'trip_type' => in_array($trip_type, ['top_management', 'regular'], true) ? $trip_type : 'regular',
    'exclude_schedule_id' => $exclude_id,
]);

$drivers = [];
foreach ($rec['ranked'] as $c) {
    $drivers[] = [
        'driver_id'        => (int)$c['driver_id'],
        'name'             => $c['name'],
        'employee_id'      => $c['employee_id'] ?? null,
        'experience_years' => $c['experience_years'],
        'license_class'    => $c['license_class'] ?? null,
        'daily_hours'      => $c['daily_hours'],
        'month_tasks'      => $c['tasks'],
        'month_weekend'    => $c['after_hours'],      // after-hours tasks (older JS calls it "weekend")
        'after_hours'      => $c['after_hours'],
        'welfare'          => $c['welfare'],
        'warnings'         => $c['warnings'],
        'priority'         => $c['score'],            // 0-100, higher = better
        'priority_score'   => $c['score'],            // kept for existing JS consumers
    ];
}

echo json_encode([
    'success' => true, 'drivers' => $drivers, 'count' => count($drivers),
    'excluded' => $rec['excluded'], 'weights' => $rec['weights'],
    // drivers an admin may still choose on purpose (clashing task) if a reason is given
    'clash' => array_values(array_filter($rec['excluded'], fn($x) => !empty($x['overridable']))),
]);
