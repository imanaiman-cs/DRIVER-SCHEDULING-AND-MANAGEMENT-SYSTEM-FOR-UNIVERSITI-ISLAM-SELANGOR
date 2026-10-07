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

// ── Optional: licence requirement from the selected vehicle ──
$required_classes = null;
if ($vehicle_id > 0) {
    $vs = $conn->prepare("SELECT vehicle_type FROM vehicles WHERE vehicle_id = ? LIMIT 1");
    $vs->bind_param('i', $vehicle_id);
    $vs->execute();
    $vrow = $vs->get_result()->fetch_assoc();
    $vs->close();
    if ($vrow) {
        $required_classes = requiredLicenseClasses((string)$vrow['vehicle_type']);
    }
}
$filter_trip_type = in_array($trip_type, ['top_management', 'regular'], true) ? $trip_type : null;

// ── This month's workload (month of the requested trip date) ─
$month_counts = getMonthlyTaskCounts($conn, substr($trip_date, 0, 7));

$sql = "SELECT d.*,
        (SELECT COUNT(*) FROM schedules s2 WHERE s2.driver_id = d.driver_id AND s2.trip_date = ? AND s2.status NOT IN ('cancelled')) as daily_trips,
        (SELECT COALESCE(SUM(TIMESTAMPDIFF(HOUR, s2.start_time, s2.end_time)),0) FROM schedules s2 WHERE s2.driver_id = d.driver_id AND s2.trip_date = ? AND s2.status NOT IN ('cancelled')) as daily_hours
        FROM drivers d
        WHERE d.status = 'active'
          AND d.driver_id NOT IN (
                SELECT COALESCE(driver_id,0) FROM schedules
                WHERE trip_date = ? AND status NOT IN ('cancelled')
                AND driver_id IS NOT NULL
                AND schedule_id != ?
                AND ((start_time < ? AND end_time > ?)
                  OR (start_time < ? AND end_time > ?)
                  OR (start_time >= ? AND end_time <= ?))
          )
        HAVING daily_hours < 8
        ORDER BY daily_trips ASC, d.name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sssissssss",
    $trip_date, $trip_date,
    $trip_date, $exclude_id,
    $end_time, $start_time,
    $end_time, $start_time,
    $start_time, $end_time
);
$stmt->execute();
$result = $stmt->get_result();

$drivers = [];
while ($row = $result->fetch_assoc()) {
    if ($filter_trip_type !== null && ($row['driver_type'] ?? 'regular') !== $filter_trip_type) {
        continue;
    }
    if ($required_classes !== null && !driverHasLicense($row['license_class'] ?? null, $required_classes)) {
        continue;
    }

    $counts   = $month_counts[(int)$row['driver_id']] ?? ['tasks' => 0, 'weekend' => 0];
    $priority = calculateAllocationScore(
        $counts['tasks'],
        $counts['weekend'],
        (float)$row['experience_years']
    );
    $drivers[] = [
        'driver_id'        => (int)$row['driver_id'],
        'name'             => $row['name'],
        'employee_id'      => $row['employee_id'] ?? null,
        'experience_years' => $row['experience_years'],
        'license_class'    => $row['license_class'] ?? null,
        'daily_trips'      => (int)$row['daily_trips'],
        'daily_hours'      => round((float)$row['daily_hours'], 1),
        'month_tasks'      => $counts['tasks'],
        'month_weekend'    => $counts['weekend'],
        'priority'         => $priority,
        'priority_score'   => $priority,   // kept for existing JS consumers
    ];
}

// Highest allocation score first; ties → fewer trips that day, then name
usort($drivers, function ($a, $b) {
    if ($a['priority'] !== $b['priority']) return $b['priority'] <=> $a['priority'];
    if ($a['daily_trips'] !== $b['daily_trips']) return $a['daily_trips'] - $b['daily_trips'];
    return strcmp($a['name'], $b['name']);
});

echo json_encode(['success' => true, 'drivers' => $drivers, 'count' => count($drivers)]);
