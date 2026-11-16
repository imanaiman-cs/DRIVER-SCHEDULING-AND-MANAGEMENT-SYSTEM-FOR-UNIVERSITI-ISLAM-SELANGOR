<?php
// ============================================================
// includes/schedule_view.php  -  shared by the schedule pages (SRS E: internal tasks, welfare duty,
// calendar UC028, driver View Task UC029): labels, "where to wait" text and the calendar query.
// Pure reading code, no output.
// ============================================================

/** Internal task types (SRS 14 Nov UC004): Workshop, Refuelling, Test Drive. Older rows may hold the retired values (see internalTaskTypeLabel). */
const INTERNAL_TASK_TYPES = [
    'workshop'  => 'Workshop',
    'refuel'    => 'Refuelling',
    'test_drive' => 'Test Drive',
];

/** The words people see for the status of an internal task (SRS 10 Nov: Waiting for Driver, Driver Assigned, Approved; a deleted task is kept). */
function internalTaskStatusLabel(string $status): string
{
    return ['waiting' => 'Waiting for Driver', 'assigned' => 'Driver Assigned', 'approved' => 'Approved', 'active' => 'Approved', 'cancelled' => 'Deleted'][$status] ?? ucfirst($status);
}

function welfareStatusLabel(string $status): string
{
    return ['waiting' => 'Waiting for Driver', 'assigned' => 'Driver Assigned', 'approved' => 'Approved', 'active' => 'Approved'][$status] ?? ucfirst($status);
}

function internalTaskTypeLabel(string $type): string
{
    return INTERNAL_TASK_TYPES[$type] ?? ['workshop_send' => 'Workshop', 'workshop_collect' => 'Workshop', 'document_delivery' => 'Document delivery', 'road_tax' => 'Road tax / inspection', 'other' => 'Other'][$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/** "Outbound trip" / "Return trip" from tasks.trip_type ('outbound' / 'return'; the old leg numbers 1 / 2 are still understood). */
function legLabel(int|string|null $trip_type): string
{
    if ($trip_type === 'outbound' || $trip_type === 1) { return 'Outbound trip'; }
    if ($trip_type === 'return' || $trip_type === 2) { return 'Return trip'; }
    return 'Trip';
}

/** "BHW 8595 (Toyota Hiace)" - plate plus model. */
function vehicleText(?string $plate, ?string $brand = null, ?string $model = null): string
{
    if ($plate === null || $plate === '') { return ''; }
    $m = trim(trim((string)$brand) . ' ' . trim((string)$model));
    return $m !== '' ? "$plate ($m)" : $plate;
}

/** The full destination: name + address. */
function destinationText(?string $destination, ?string $address): string
{
    $d = trim((string)$destination); $a = trim((string)$address);
    if ($a === '' || $a === $d) { return $d; }
    return $d === '' ? $a : "$d, $a";
}

/** Where the driver waits: the place, or the typed address when the place is "Other". */
function waitingText(?string $place, ?string $address): string
{
    $p = trim((string)$place); $a = trim((string)$address);
    if (strcasecmp($p, 'Other') === 0) { return $a !== '' ? $a : 'Other'; }
    return $p;
}

/** Binds a dynamic list of parameters (types are derived from the PHP types). */
function bindDynamic(mysqli_stmt $stmt, array $params): void
{
    if (!$params) { return; }
    $types = '';
    foreach ($params as $p) { $types .= is_int($p) ? 'i' : 's'; }
    $stmt->bind_param($types, ...$params);
}

/**
 * Everything on the schedule between two moments, for the calendar (UC028).
 *   trips    : request tasks (tasks.task_source = 'request', not cancelled), with the request status
 *   internal : internal tasks that are waiting, assigned or approved
 *   welfare  : welfare duty days (24 hours)
 * Overlap rule: start < range_end AND end > range_start.
 *
 * @return array<int, array<string, mixed>> sorted by start
 */
function calendarEvents(mysqli $conn, string $from_dt, string $to_dt, int $driver_id = 0, int $vehicle_id = 0): array
{
    $out = [];
    $iso = fn(string $dt) => date('Y-m-d\TH:i:s', strtotime($dt));

    // request tasks
    $sql = "SELECT s.task_id, s.request_id, s.trip_type, s.start_dt, s.end_dt, s.driver_id, s.vehicle_id, s.description AS s_purpose,
                   s.location AS s_dest, vr.status AS req_status, vr.destination, vr.destination_address, vr.purpose, vr.officer_name, vr.officer_phone,
                   vr.waiting_place, vr.waiting_address, d.name AS driver_name, v.plate_number, v.brand, v.model, s.vendor_name, s.vendor_bus_plate, s.vendor_driver_name
              FROM tasks s
              LEFT JOIN vehicle_requests vr ON vr.request_id = s.request_id
              LEFT JOIN drivers d ON d.driver_id = s.driver_id
              LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
             WHERE s.task_source = 'request' AND s.status <> 'cancelled' AND s.start_dt < ? AND s.end_dt > ?";
    $p = [$to_dt, $from_dt];
    if ($driver_id)  { $sql .= ' AND s.driver_id = ?';  $p[] = $driver_id; }
    if ($vehicle_id) { $sql .= ' AND s.vehicle_id = ? AND s.vendor_name IS NULL'; $p[] = $vehicle_id; }
    $sql .= ' ORDER BY s.start_dt LIMIT 1500';
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $p);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $leg = $r['trip_type'];
        $out[] = [
            'id' => 't' . $r['task_id'], 'kind' => 'trip', 'title' => legLabel($leg) . ($r['request_id'] ? ' - request #' . $r['request_id'] : ''),
            'start' => $iso($r['start_dt']), 'end' => $iso($r['end_dt']),
            'driver_id' => (int)$r['driver_id'], 'driver' => $r['vendor_name'] !== null ? 'Vendor bus: ' . $r['vendor_name'] : ($r['driver_name'] ?? ''), 'vehicle_id' => $r['vendor_name'] !== null ? 0 : (int)$r['vehicle_id'],
            'vehicle' => $r['vendor_name'] !== null ? 'Vendor bus ' . $r['vendor_bus_plate'] : vehicleText($r['plate_number'], $r['brand'], $r['model']),
            'request_id' => $r['request_id'] ? (int)$r['request_id'] : null,
            'request_status' => $r['req_status'] ? requestStatusLabel($r['req_status']) : '',
            'leg' => legLabel($leg),
            'destination' => destinationText($r['destination'] ?? $r['s_dest'], $r['destination_address']),
            'purpose' => (string)($r['purpose'] ?? $r['s_purpose'] ?? ''),
            'officer' => trim((string)($r['officer_name'] ?? '') . ' ' . ($r['officer_phone'] ? '(' . $r['officer_phone'] . ')' : '')),
            'waiting' => waitingText($r['waiting_place'], $r['waiting_address']),
            'schedule_id' => (int)$r['task_id'], 'task_id' => (int)$r['task_id'],
        ];
    }
    $stmt->close();

    // internal tasks
    $sql = "SELECT t.*, d.name AS driver_name, v.plate_number, v.brand, v.model
              FROM tasks t
              LEFT JOIN drivers d ON d.driver_id = t.driver_id
              LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
             WHERE t.task_source = 'internal' AND t.status IN ('waiting', 'assigned', 'approved') AND t.start_dt < ? AND t.end_dt > ?";
    $p = [$to_dt, $from_dt];
    if ($driver_id)  { $sql .= ' AND t.driver_id = ?';  $p[] = $driver_id; }
    if ($vehicle_id) { $sql .= ' AND t.vehicle_id = ?'; $p[] = $vehicle_id; }
    $sql .= ' ORDER BY t.start_dt LIMIT 1500';
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $p);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[] = [
            'id' => 'i' . $r['task_id'], 'kind' => 'internal', 'title' => internalTaskTypeLabel($r['task_type']),
            'start' => $iso($r['start_dt']), 'end' => $iso($r['end_dt']),
            'driver_id' => (int)$r['driver_id'], 'driver' => $r['driver_name'] ?? '(not assigned yet)', 'vehicle_id' => (int)$r['vehicle_id'],
            'vehicle' => vehicleText($r['plate_number'], $r['brand'], $r['model']),
            'request_id' => null, 'request_status' => internalTaskStatusLabel($r['status']), 'leg' => 'Internal task',
            'destination' => (string)$r['location'], 'purpose' => (string)$r['description'], 'officer' => '', 'waiting' => '', 'task_id' => (int)$r['task_id'],
        ];
    }
    $stmt->close();

    // welfare duty: one 24-hour day with a vehicle (reserved) and, once assigned, a driver
    $f = substr($from_dt, 0, 10); $t = substr($to_dt, 0, 10);
    // the reason of the last driver replacement is shown as the details of the day (it was welfare_roster.replacement_reason)
    $sql = "SELECT w.*, d.name AS driver_name, v.plate_number, v.brand, v.model,
                   (SELECT dr.reason FROM driver_replacements dr WHERE dr.task_id = w.task_id AND dr.status <> 'rejected' ORDER BY dr.replacement_id DESC LIMIT 1) AS replacement_reason
              FROM tasks w LEFT JOIN drivers d ON d.driver_id = w.driver_id LEFT JOIN vehicles v ON v.vehicle_id = w.vehicle_id
             WHERE w.task_source = 'welfare' AND w.status <> 'cancelled' AND w.task_date >= ? AND w.task_date <= ?";
    $p = [$f, $t];
    if ($driver_id) { $sql .= ' AND w.driver_id = ?'; $p[] = $driver_id; }
    if ($vehicle_id) { $sql .= ' AND w.vehicle_id = ?'; $p[] = $vehicle_id; }
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $p);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $day = $r['task_date'];
        if ($day . ' 00:00:00' >= $to_dt || date('Y-m-d H:i:s', strtotime("$day +1 day")) <= $from_dt) { continue; }
        $out[] = [
            'id' => 'w' . $r['task_id'], 'kind' => 'welfare', 'title' => 'Welfare duty',
            'start' => $day . 'T00:00:00', 'end' => date('Y-m-d', strtotime("$day +1 day")) . 'T00:00:00',
            'driver_id' => (int)$r['driver_id'], 'driver' => $r['driver_name'] ?? '(not assigned yet)', 'vehicle_id' => (int)$r['vehicle_id'], 'vehicle' => $r['vehicle_id'] ? vehicleText($r['plate_number'], $r['brand'], $r['model']) : '',
            'request_id' => null, 'request_status' => welfareStatusLabel($r['status']), 'leg' => 'Welfare duty (24 hours)',
            'destination' => '', 'purpose' => (string)($r['replacement_reason'] ?? ''), 'officer' => '', 'waiting' => '', 'task_id' => (int)$r['task_id'],
        ];
    }
    $stmt->close();
    usort($out, fn($a, $b) => [$a['start'], $a['kind']] <=> [$b['start'], $b['kind']]);
    return $out;
}

/**
 * One task row for the printable task sheet, with the full destination and waiting place.
 * $driver_id > 0: only a task of that driver, and only when its request is Confirmed/Completed.
 */
function taskSheetRow(mysqli $conn, int $task_id, int $driver_id = 0): ?array
{
    $sql = "SELECT s.task_id, s.task_id AS schedule_id, s.task_date AS trip_date, TIME(s.start_dt) AS start_time, TIME(s.end_dt) AS end_time,
                   COALESCE(vr.destination, s.location) AS destination, vr.destination_address,
                   COALESCE(vr.purpose, s.description) AS purpose, vr.officer_name, vr.officer_phone,
                   vr.waiting_place, vr.waiting_address,
                   d.name AS driver_name, v.plate_number AS vehicle_plate, v.brand AS vehicle_brand,
                   v.model AS vehicle_model, v.vehicle_type AS vehicle_type
              FROM tasks s
              LEFT JOIN vehicle_requests vr ON vr.request_id = s.request_id
              LEFT JOIN drivers d ON d.driver_id = s.driver_id
              LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
             WHERE s.task_id = ? AND s.task_source = 'request'";
    $p = [$task_id];
    if ($driver_id > 0) { $sql .= ' AND s.driver_id = ? AND s.status <> \'cancelled\' AND ' . SCHEDULE_CONFIRMED_ONLY_SQL; $p[] = $driver_id; }
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $p);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { return null; }
    $row['destination']   = destinationText($row['destination'], $row['destination_address']);
    $row['waiting_place'] = waitingText($row['waiting_place'], $row['waiting_address']);
    return $row;
}

/**
 * Dispatch board: one lane per vehicle for ONE day (06:00 - 22:00), with the request tasks, internal tasks and welfare duty of that day drawn
 * as bars on a time line (UC010 View Driver Schedule, shown on the dashboard). Read only; built from calendarEvents().
 */
function dispatchBoardHtml(mysqli $conn, string $date): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $from = 6; $to = 22; $span = ($to - $from) * 3600;
    $dayStart = strtotime($date . ' 00:00:00');
    $events = calendarEvents($conn, $date . ' 00:00:00', date('Y-m-d H:i:s', $dayStart + 86400));
    $lanes = [];
    foreach ($events as $e) {
        $key = $e['vehicle'] !== '' ? $e['vehicle'] : ($e['kind'] === 'welfare' ? 'Welfare duty' : 'No vehicle');
        $lanes[$key][] = $e;
    }
    ksort($lanes);
    $prev = date('Y-m-d', $dayStart - 86400); $next = date('Y-m-d', $dayStart + 86400);
    $isToday = $date === date('Y-m-d');
    $out = '<div class="dash-card board"><h6><span><i class="fas fa-tower-broadcast text-success me-1"></i>Dispatch board</span>'
         . '<span class="board-nav"><a href="?board=' . $h($prev) . '#board" aria-label="Previous day"><i class="fas fa-chevron-left"></i></a>'
         . '<strong>' . $h($isToday ? 'Today, ' . date('d M', $dayStart) : date('D, d M Y', $dayStart)) . '</strong>'
         . '<a href="?board=' . $h($next) . '#board" aria-label="Next day"><i class="fas fa-chevron-right"></i></a></span></h6>';
    if (!$lanes) {
        return $out . '<div class="dash-empty"><i class="fas fa-road"></i>No trip, internal task or welfare duty on this day.</div></div>';
    }
    $out .= '<div class="board-scroll"><div class="board-grid" id="board"><div class="board-hours"><span></span><div class="board-track">';
    for ($t = $from; $t <= $to; $t += 2) { $out .= '<i style="left:' . round(($t - $from) * 100 / ($to - $from), 2) . '%">' . sprintf('%02d:00', $t) . '</i>'; }
    $out .= '</div></div>';
    $nowPct = null;
    if ($isToday) { $n = time() - ($dayStart + $from * 3600); if ($n > 0 && $n < $span) { $nowPct = round($n * 100 / $span, 2); } }
    foreach ($lanes as $vehicle => $list) {
        $out .= '<div class="board-lane"><span class="board-veh" title="' . $h($vehicle) . '">' . $h($vehicle) . '</span><div class="board-track">';
        if ($nowPct !== null) { $out .= '<b class="board-now" style="left:' . $nowPct . '%" title="Now"></b>'; }
        foreach ($list as $e) {
            $s = max(strtotime($e['start']), $dayStart + $from * 3600); $en = min(strtotime($e['end']), $dayStart + $to * 3600);
            if ($e['kind'] === 'welfare') { $s = $dayStart + $from * 3600; $en = $dayStart + $to * 3600; }
            if ($en <= $s) { continue; }
            $left = round(($s - ($dayStart + $from * 3600)) * 100 / $span, 2); $width = max(1.5, round(($en - $s) * 100 / $span, 2));
            $time = $e['kind'] === 'welfare' ? '24 hours' : date('H:i', strtotime($e['start'])) . '-' . date('H:i', strtotime($e['end']));
            $tip = $e['title'] . ' | ' . $time . ($e['driver'] !== '' ? ' | ' . $e['driver'] : '') . ($e['destination'] !== '' ? ' | ' . $e['destination'] : '') . ($e['request_status'] !== '' ? ' | ' . $e['request_status'] : '');
            $out .= '<a class="board-bar is-' . $h($e['kind']) . '" href="calendar.php" style="left:' . $left . '%;width:' . $width . '%" title="' . $h($tip) . '">'
                  . '<span>' . $h($e['kind'] === 'trip' ? ($e['request_id'] ? '#' . $e['request_id'] . ' ' : '') . ($e['driver'] ?: 'No driver') : ($e['kind'] === 'welfare' ? 'Welfare: ' . $e['driver'] : $e['title'] . ($e['driver'] ? ' - ' . $e['driver'] : ''))) . '</span></a>';
        }
        $out .= '</div></div>';
    }
    return $out . '</div></div><div class="board-legend"><span><i class="is-trip"></i>Request task</span><span><i class="is-internal"></i>Internal task</span><span><i class="is-welfare"></i>Welfare duty</span>'
         . ($nowPct !== null ? '<span><i class="is-now"></i>Now</span>' : '') . '</div></div>';
}
