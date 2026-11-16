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
/** The same as vehicleText(), as HTML: the plate set as a number plate, then the model in muted text. */
function vehicleHtml(?string $plate, ?string $brand = null, ?string $model = null): string
{
    if ($plate === null || $plate === '') { return ''; }
    $m = trim(trim((string)$brand) . ' ' . trim((string)$model));
    return plate($plate) . ($m !== '' ? ' <span class="text-base-content/60">' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</span>' : '');
}

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
 * Dispatch board (UC010 View Driver Schedule, shown on the admin dashboard): one row per active vehicle across ONE day, with the
 * request tasks, internal tasks and welfare duty of that day (from calendarEvents()) as blocks. Busy vehicles first; a row with no
 * block is a free vehicle. Filled = Approved (Confirmed), outlined = waiting for the Head of Admin, grey = internal task or finished,
 * thin line = welfare duty. Arrows move a day back or forward (?board=Y-m-d). Read only.
 */
function dispatchBoardHtml(mysqli $conn, string $date): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $dayStart = strtotime($date . ' 00:00:00');
    $dayEnd = $dayStart + 86400;
    $isToday = $date === date('Y-m-d');
    $nowTs = time();
    $events = calendarEvents($conn, $date . ' 00:00:00', date('Y-m-d H:i:s', $dayEnd));

    $vehicles = [];
    $vs = $conn->prepare("SELECT vehicle_id, plate_number, vehicle_type, brand, model, status FROM vehicles WHERE status <> 'inactive' ORDER BY plate_number");
    $vs->execute();
    foreach ($vs->get_result()->fetch_all(MYSQLI_ASSOC) as $v) { $vehicles[(int)$v['vehicle_id']] = $v; }
    $vs->close();

    // The window: 07:00 to 19:00, widened to whole hours when a task starts earlier or ends later (welfare duty does not widen it)
    $from = 7; $to = 19;
    foreach ($events as $e) {
        if ($e['kind'] === 'welfare') { continue; }
        $s = strtotime($e['start']); $en = strtotime($e['end']);
        $from = min($from, $s < $dayStart ? 0 : (int)date('G', $s));
        $to = max($to, $en > $dayEnd ? 24 : (int)ceil(((int)date('G', $en) * 60 + (int)date('i', $en)) / 60));
    }
    $t0 = $dayStart + $from * 3600; $span = ($to - $from) * 3600;
    $pos = fn(int $ts) => max(0, min(100, ($ts - $t0) / $span * 100));

    $rows = [];
    foreach ($vehicles as $id => $v) { $rows['v' . $id] = ['v' => $v, 'label' => '', 'items' => []]; }
    $welfare = [];
    foreach ($events as $e) {
        if ($e['kind'] === 'welfare') { $welfare[] = $e['driver']; }
        if ($e['vehicle_id'] && isset($rows['v' . $e['vehicle_id']])) { $key = 'v' . $e['vehicle_id']; }
        else {
            $key = 'x' . ($e['vehicle'] !== '' ? $e['vehicle'] : ($e['kind'] === 'welfare' ? 'Welfare duty' : 'No vehicle'));
            $rows[$key] ??= ['v' => null, 'label' => substr($key, 1), 'items' => []];
        }
        $rows[$key]['items'][] = $e;
    }
    uasort($rows, fn($a, $b) => (count($b['items']) > 0) <=> (count($a['items']) > 0));

    $hours = '';
    for ($hr = $from; $hr <= $to; $hr++) {
        $x = ($hr - $from) / ($to - $from) * 100;
        $shift = $hr === $from ? '' : ($hr === $to ? '-translate-x-full' : '-translate-x-1/2');
        $hours .= '<span class="absolute ' . $shift . ' font-mono text-[0.6875rem] text-base-content/50" style="left:' . $x . '%">' . sprintf('%02d', $hr % 24) . '</span>';
    }
    $lines = '';
    for ($hr = $from + 1; $hr < $to; $hr++) {
        $lines .= '<span class="absolute inset-y-0 w-px bg-base-200" style="left:' . (($hr - $from) / ($to - $from) * 100) . '%"></span>';
    }
    $now = '';
    if ($isToday && $nowTs > $t0 && $nowTs < $t0 + $span) {
        $now = '<span class="absolute inset-y-0 w-px bg-base-content" style="left:' . $pos($nowTs) . '%"></span>'
             . '<span class="absolute -top-5 -translate-x-1/2 rounded-sm bg-base-content px-1 font-mono text-[0.6875rem] text-base-100" style="left:' . $pos($nowTs) . '%">' . date('H:i') . '</span>';
    }

    $prev = date('Y-m-d', $dayStart - 86400); $next = date('Y-m-d', $dayEnd);
    $title = $isToday ? 'Vehicles today' : 'Vehicles on ' . date('D, j M Y', $dayStart);
    $out = '<section class="dash-card mb-6" id="board" aria-labelledby="boardTitle">'
         . '<h6><span id="boardTitle">' . $h($title) . '</span><span class="flex items-center gap-1">'
         . '<a class="btn btn-ghost btn-xs" href="?board=' . $h($prev) . '#board" aria-label="Previous day"><i class="fas fa-chevron-left"></i></a>'
         . ($isToday ? '' : '<a class="btn btn-ghost btn-xs" href="?#board">Today</a>')
         . '<a class="btn btn-ghost btn-xs" href="?board=' . $h($next) . '#board" aria-label="Next day"><i class="fas fa-chevron-right"></i></a>'
         . '<a class="ms-2" href="calendar.php">Open calendar</a></span></h6>';

    $out .= '<div class="overflow-x-auto"><div class="min-w-[44rem]">'
          . '<div class="grid grid-cols-[11rem_1fr]"><div></div><div class="relative mb-2 h-4">' . $hours . '</div></div>'
          . '<div class="relative grid grid-cols-[11rem_1fr]">'
          . '<div class="pointer-events-none absolute inset-y-0 right-0 left-[11rem]">' . $lines . $now . '</div>';
    $assigned = requestStatusLabel('assigned');
    foreach ($rows as $r) {
        $v = $r['v'];
        $label = $v ? plate($v['plate_number']) . '<span class="mt-0.5 block truncate text-xs text-base-content/60">'
                    . $h($v['status'] === 'maintenance' ? 'In maintenance' : trim($v['vehicle_type'] . ' · ' . trim(($v['brand'] ?? '') . ' ' . ($v['model'] ?? '')), ' ·')) . '</span>'
                    : '<span class="truncate text-sm text-base-content/60">' . $h($r['label']) . '</span>';
        $blocks = '';
        foreach ($r['items'] as $e) {
            $s = strtotime($e['start']); $en = strtotime($e['end']);
            $left = $pos($s); $width = max(1.5, $pos($en) - $left);
            $time = $e['kind'] === 'welfare' ? '24 hours' : date('H:i', strtotime($e['start'])) . '–' . date('H:i', strtotime($e['end']));
            $tip = $time . ' ' . $e['title'] . ($e['driver'] !== '' ? ', ' . $e['driver'] : '') . ($e['destination'] !== '' ? ', ' . $e['destination'] : '') . ($e['request_status'] !== '' ? ' (' . $e['request_status'] . ')' : '');
            if ($e['kind'] === 'welfare') {          // a thin strip under the row: the vehicle is reserved for the whole day
                $blocks .= '<a class="absolute bottom-0 h-1 rounded-full bg-secondary/60" href="calendar.php" style="left:0;width:100%" title="' . $h($tip) . '" aria-label="' . $h($tip) . '"></a>';
                continue;
            }
            if ($e['kind'] === 'internal') {
                $style = 'bg-base-200 text-base-content/60'; $name = $e['title'];
            } else {
                $style = $en < $nowTs ? 'bg-base-200 text-base-content/60' : ($e['request_status'] === $assigned ? 'border border-primary/50 bg-base-100' : 'bg-primary/10');
                $name = ($e['request_id'] ? '#' . $e['request_id'] . ' ' : '') . $e['destination'];
            }
            $blocks .= '<a class="absolute inset-y-1 overflow-hidden rounded-field px-2 py-1 text-xs leading-tight ' . $style . '" href="calendar.php" style="left:' . $left . '%;width:' . $width . '%" title="' . $h($tip) . '">'
                     . '<span class="block truncate"><span class="font-mono">' . date('H:i', strtotime($e['start'])) . '</span> <span class="font-medium">' . $h($name) . '</span></span>'
                     . '<span class="block truncate text-base-content/60">' . $h($e['driver'] ?: 'No driver') . '</span></a>';
        }
        $track = $v && $v['status'] === 'maintenance' ? 'bg-base-200' : '';
        $out .= '<div class="flex h-14 flex-col items-start justify-center border-t border-base-200 pe-3">' . $label . '</div>'
              . '<div class="relative h-14 border-t border-base-200 ' . $track . '">' . $blocks . '</div>';
    }
    $out .= '</div></div></div>';
    if (!$events) { $out .= '<p class="dash-empty">No trip, internal task or welfare duty on this day.</p>'; }
    $out .= '<p class="mt-3 text-xs text-base-content/60">Green: Approved (Confirmed). Outlined: waiting for the Head of Admin. Grey: internal task or finished. Thin line: welfare duty. An empty row is a free vehicle.</p>';
    $out .= '<p class="mt-1 text-xs text-base-content/60">Welfare duty ' . ($isToday ? 'today' : 'on this day') . ': <span class="font-medium text-base-content">' . $h($welfare ? implode(', ', $welfare) : 'not set') . '</span></p>';
    return $out . '</section>';
}
