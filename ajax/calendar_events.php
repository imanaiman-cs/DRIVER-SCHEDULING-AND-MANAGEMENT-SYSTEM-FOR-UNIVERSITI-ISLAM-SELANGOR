<?php
// ajax/calendar_events.php?start=YYYY-MM-DD&end=YYYY-MM-DD[&driver_id=][&vehicle_id=]
// JSON for the schedule calendar (UC028): request tasks, internal tasks and welfare duty. Admin roles only.
// `end` is exclusive (the first day NOT shown). At most 62 days per call.
require_once '../config/database.php';
require_once '../includes/schedule_view.php';
header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
    exit();
}
$start = (string)($_GET['start'] ?? '');
$end   = (string)($_GET['end'] ?? '');
if (!isValidDate($start) || !isValidDate($end) || $end <= $start) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Give start and end as YYYY-MM-DD (end after start).']);
    exit();
}
if (strtotime($end) - strtotime($start) > 62 * 86400) { $end = date('Y-m-d', strtotime($start . ' +62 days')); }
$events = calendarEvents($conn, $start . ' 00:00:00', $end . ' 00:00:00', (int)($_GET['driver_id'] ?? 0), (int)($_GET['vehicle_id'] ?? 0));
echo json_encode(['success' => true, 'start' => $start, 'end' => $end, 'events' => $events]);
