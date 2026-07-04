<?php
require_once '../config/database.php';
requireAdmin();
header('Content-Type: application/json');

$trip_date   = $_POST['trip_date']   ?? '';
$start_time  = $_POST['start_time']  ?? '';
$end_time    = $_POST['end_time']    ?? '';
$exclude_id  = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;

if (!$trip_date || !$start_time || !$end_time) {
    echo json_encode(['success' => false, 'drivers' => [], 'message' => 'Missing parameters.']);
    exit();
}

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
    $priority = calculatePriorityScore(
        $row['experience_years'],
        $row['attendance_rate'],
        $row['performance_score'],
        $row['certification_score']
    );
    $drivers[] = [
        'driver_id'        => $row['driver_id'],
        'name'             => $row['name'],
        'experience_years' => $row['experience_years'],
        'daily_trips'      => (int)$row['daily_trips'],
        'daily_hours'      => round((float)$row['daily_hours'], 1),
        'priority_score'   => $priority,
    ];
}

// Sort by fewer trips first, then higher priority
usort($drivers, function($a, $b) {
    if ($a['daily_trips'] !== $b['daily_trips']) return $a['daily_trips'] - $b['daily_trips'];
    return $b['priority_score'] <=> $a['priority_score'];
});

echo json_encode(['success' => true, 'drivers' => $drivers, 'count' => count($drivers)]);
