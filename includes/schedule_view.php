<?php
// ============================================================
// includes/schedule_view.php  -  shared by the schedule pages (SRS E: internal tasks, welfare duty,
// calendar UC028, driver View Task UC029): labels, "where to wait" text and the calendar query.
// Pure reading code, no output.
// ============================================================

/** Internal task types a user can choose (UC022). Older rows may hold 'road_tax' / 'other'. */
const INTERNAL_TASK_TYPES = [
    'workshop_send'     => 'Workshop - send vehicle',
    'workshop_collect'  => 'Workshop - collect vehicle',
    'refuel'            => 'Refuelling',
    'test_drive'        => 'Test drive',
    'document_delivery' => 'Document delivery',
];

function internalTaskTypeLabel(string $type): string
{
    return INTERNAL_TASK_TYPES[$type] ?? ['road_tax' => 'Road tax / inspection', 'other' => 'Other'][$type] ?? ucfirst(str_replace('_', ' ', $type));
}

/** "Outbound trip" / "Return trip" from schedules.leg_no. */
function legLabel(?int $leg_no): string
{
    return $leg_no === 1 ? 'Outbound trip' : ($leg_no === 2 ? 'Return trip' : 'Trip');
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
 *   trips    : request tasks (schedules that are not cancelled), with the request status
 *   internal : active internal tasks
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
    $sql = "SELECT s.schedule_id, s.request_id, s.leg_no, s.start_dt, s.end_dt, s.driver_id, s.vehicle_id, s.purpose AS s_purpose,
                   s.destination AS s_dest, s.officer_name AS s_officer, s.officer_phone AS s_phone, s.waiting_place AS s_wait,
                   vr.status AS req_status, vr.destination, vr.destination_address, vr.purpose, vr.officer_name, vr.officer_phone,
                   vr.waiting_place, vr.waiting_address, d.name AS driver_name, v.plate_number, v.brand, v.model, s.vendor_name, s.vendor_bus_plate, s.vendor_driver_name
              FROM schedules s
              LEFT JOIN vehicle_requests vr ON vr.request_id = s.request_id
              LEFT JOIN drivers d ON d.driver_id = s.driver_id
              LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
             WHERE s.status <> 'cancelled' AND s.start_dt < ? AND s.end_dt > ?";
    $p = [$to_dt, $from_dt];
    if ($driver_id)  { $sql .= ' AND s.driver_id = ?';  $p[] = $driver_id; }
    if ($vehicle_id) { $sql .= ' AND s.vehicle_id = ? AND s.vendor_name IS NULL'; $p[] = $vehicle_id; }
    $sql .= ' ORDER BY s.start_dt LIMIT 1500';
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $p);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $leg = $r['leg_no'] !== null ? (int)$r['leg_no'] : null;
        $out[] = [
            'id' => 't' . $r['schedule_id'], 'kind' => 'trip', 'title' => legLabel($leg) . ($r['request_id'] ? ' - request #' . $r['request_id'] : ''),
            'start' => $iso($r['start_dt']), 'end' => $iso($r['end_dt']),
            'driver_id' => (int)$r['driver_id'], 'driver' => $r['vendor_name'] !== null ? 'Vendor bus: ' . $r['vendor_driver_name'] . ' (' . $r['vendor_name'] . ')' : ($r['driver_name'] ?? ''), 'vehicle_id' => $r['vendor_name'] !== null ? 0 : (int)$r['vehicle_id'],
            'vehicle' => $r['vendor_name'] !== null ? 'Vendor bus ' . $r['vendor_bus_plate'] : vehicleText($r['plate_number'], $r['brand'], $r['model']),
            'request_id' => $r['request_id'] ? (int)$r['request_id'] : null,
            'request_status' => $r['req_status'] ? requestStatusLabel($r['req_status']) : '',
            'leg' => legLabel($leg),
            'destination' => destinationText($r['destination'] ?? $r['s_dest'], $r['destination_address']),
            'purpose' => (string)($r['purpose'] ?? $r['s_purpose'] ?? ''),
            'officer' => trim((string)($r['officer_name'] ?? $r['s_officer'] ?? '') . ' ' . (($r['officer_phone'] ?? $r['s_phone']) ? '(' . ($r['officer_phone'] ?? $r['s_phone']) . ')' : '')),
            'waiting' => waitingText($r['waiting_place'] ?? $r['s_wait'], $r['waiting_address']),
            'schedule_id' => (int)$r['schedule_id'],
        ];
    }
    $stmt->close();

    // internal tasks
    $sql = "SELECT t.*, d.name AS driver_name, v.plate_number, v.brand, v.model
              FROM internal_tasks t
              JOIN drivers d ON d.driver_id = t.driver_id
              LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id
             WHERE t.status = 'active' AND t.start_dt < ? AND t.end_dt > ?";
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
            'driver_id' => (int)$r['driver_id'], 'driver' => $r['driver_name'], 'vehicle_id' => (int)$r['vehicle_id'],
            'vehicle' => vehicleText($r['plate_number'], $r['brand'], $r['model']),
            'request_id' => null, 'request_status' => '', 'leg' => 'Internal task',
            'destination' => (string)$r['location'], 'purpose' => (string)$r['description'], 'officer' => '', 'waiting' => '',
        ];
    }
    $stmt->close();

    // welfare duty (not shown when filtering by vehicle: duty is not tied to a vehicle)
    if (!$vehicle_id) {
        $f = substr($from_dt, 0, 10); $t = substr($to_dt, 0, 10);
        $sql = 'SELECT w.*, d.name AS driver_name FROM welfare_roster w JOIN drivers d ON d.driver_id = w.driver_id
                 WHERE w.duty_date >= ? AND w.duty_date <= ?';
        $p = [$f, $t];
        if ($driver_id) { $sql .= ' AND w.driver_id = ?'; $p[] = $driver_id; }
        $stmt = $conn->prepare($sql);
        bindDynamic($stmt, $p);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $day = $r['duty_date'];
            if ($day . ' 00:00:00' >= $to_dt || date('Y-m-d H:i:s', strtotime("$day +1 day")) <= $from_dt) { continue; }
            $out[] = [
                'id' => 'w' . $r['roster_id'], 'kind' => 'welfare', 'title' => 'Welfare duty',
                'start' => $day . 'T00:00:00', 'end' => date('Y-m-d', strtotime("$day +1 day")) . 'T00:00:00',
                'driver_id' => (int)$r['driver_id'], 'driver' => $r['driver_name'], 'vehicle_id' => 0, 'vehicle' => '',
                'request_id' => null, 'request_status' => '', 'leg' => 'Welfare duty (24 hours)',
                'destination' => '', 'purpose' => (string)($r['replacement_reason'] ?? ''), 'officer' => '', 'waiting' => '',
            ];
        }
        $stmt->close();
    }
    usort($out, fn($a, $b) => [$a['start'], $a['kind']] <=> [$b['start'], $b['kind']]);
    return $out;
}

/**
 * One task row for the printable task sheet, with the full destination and waiting place.
 * $driver_id > 0: only a task of that driver, and only when its request is Confirmed/Completed.
 */
function taskSheetRow(mysqli $conn, int $schedule_id, int $driver_id = 0): ?array
{
    $sql = "SELECT s.schedule_id, s.trip_date, s.start_time, s.end_time,
                   COALESCE(vr.destination, s.destination) AS destination, vr.destination_address,
                   COALESCE(vr.purpose, s.purpose) AS purpose, COALESCE(vr.officer_name, s.officer_name) AS officer_name,
                   COALESCE(vr.officer_phone, s.officer_phone) AS officer_phone,
                   COALESCE(vr.waiting_place, s.waiting_place) AS waiting_place, vr.waiting_address,
                   d.name AS driver_name, v.plate_number AS vehicle_plate, v.brand AS vehicle_brand,
                   v.model AS vehicle_model, v.vehicle_type AS vehicle_type
              FROM schedules s
              LEFT JOIN vehicle_requests vr ON vr.request_id = s.request_id
              LEFT JOIN drivers d ON d.driver_id = s.driver_id
              LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
             WHERE s.schedule_id = ?";
    $p = [$schedule_id];
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
