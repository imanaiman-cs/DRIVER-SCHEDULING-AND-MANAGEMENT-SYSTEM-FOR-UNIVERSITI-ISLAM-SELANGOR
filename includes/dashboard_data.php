<?php
// ============================================================
// includes/dashboard_data.php  -  read-only numbers for the dashboards (every query is a prepared statement)
// Used by admin/dashboard.php, staff/dashboard.php, supervisor/dashboard.php and driver/dashboard.php.
// ============================================================

require_once __DIR__ . '/recommender.php';

/** Runs a prepared SELECT and returns all rows. */
function dashRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function dashValue(mysqli $conn, string $sql, string $types = '', array $params = [])
{
    $row = dashRows($conn, $sql, $types, $params)[0] ?? null;
    return $row ? array_values($row)[0] : null;
}

/** Number of requests created in a month (Y-m), optionally limited to some statuses. */
function dashRequestsInMonth(mysqli $conn, string $month, array $statuses = []): int
{
    $from = $month . '-01 00:00:00';
    $to = date('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'));
    $sql = 'SELECT COUNT(*) FROM vehicle_requests WHERE created_at >= ? AND created_at < ?';
    $types = 'ss'; $params = [$from, $to];
    if ($statuses) {
        $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $types .= str_repeat('s', count($statuses)); $params = array_merge($params, $statuses);
    }
    return (int)dashValue($conn, $sql, $types, $params);
}

/** Percentage change this month against last month ("+12%"), or null when there is nothing to compare. */
function dashTrend(int $now, int $before): ?array
{
    if ($before === 0) { return $now === 0 ? null : ['pct' => null, 'dir' => 'up']; }
    $pct = (int)round(($now - $before) / $before * 100);
    return ['pct' => $pct, 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
}

/** Requests created per month for the last $months months: [label => count]. */
function dashMonthlyRequests(mysqli $conn, int $months = 6): array
{
    $first = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $rows = dashRows($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS m, COUNT(*) AS n FROM vehicle_requests WHERE created_at >= ? GROUP BY m", 's', [$first . ' 00:00:00']);
    $by = array_column($rows, 'n', 'm');
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $out[date('M Y', strtotime($m . '-01'))] = (int)($by[$m] ?? 0);
    }
    return $out;
}

/** Requests of the last $months months per status. */
function dashStatusCounts(mysqli $conn, int $months = 6): array
{
    $first = date('Y-m-01', strtotime('-' . ($months - 1) . ' months')) . ' 00:00:00';
    $out = [];
    foreach (dashRows($conn, 'SELECT status, COUNT(*) AS n FROM vehicle_requests WHERE created_at >= ? GROUP BY status', 's', [$first]) as $r) { $out[$r['status']] = (int)$r['n']; }
    return $out;
}

/** Hours each active vehicle is booked (locked) this month, upcoming bookings included, busiest first. */
function dashVehicleUtilisation(mysqli $conn): array
{
    $from = date('Y-m-01 00:00:00');
    $to = date('Y-m-01 00:00:00', strtotime('first day of next month'));
    return dashRows(
        $conn,
        "SELECT v.plate_number, v.vehicle_type,
                ROUND(COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(MINUTE, GREATEST(l.start_dt, ?), LEAST(l.end_dt, ?)))), 0) / 60, 1) AS hours
           FROM vehicles v LEFT JOIN vehicle_locks l ON l.vehicle_id = v.vehicle_id AND l.start_dt < ? AND l.end_dt > ?
          WHERE v.status <> 'inactive' GROUP BY v.vehicle_id, v.plate_number, v.vehicle_type ORDER BY hours DESC, v.plate_number LIMIT 10",
        'ssss', [$from, $to, $to, $from]
    );
}

/** Task counts of the active drivers this month (same numbers the recommender uses), busiest first. */
function dashDriverWorkload(mysqli $conn, ?string $month = null): array
{
    $counts = driverMonthCounts($conn, $month ?? date('Y-m'));
    $out = [];
    foreach (dashRows($conn, "SELECT driver_id, name FROM drivers WHERE status = 'active' ORDER BY name") as $d) {
        $c = $counts[(int)$d['driver_id']] ?? ['tasks' => 0, 'outside' => 0, 'welfare' => 0];
        $out[] = ['name' => $d['name']] + $c;
    }
    usort($out, fn($a, $b) => $b['tasks'] <=> $a['tasks'] ?: strcmp($a['name'], $b['name']));
    return $out;
}

/** Vehicles by what they are doing right now: available / in_use / maintenance / inactive. */
function dashFleet(mysqli $conn): array
{
    $now = date('Y-m-d H:i:s');
    $inUse = array_column(dashRows(
        $conn,
        "SELECT DISTINCT l.vehicle_id FROM vehicle_locks l JOIN vehicle_requests vr ON vr.request_id = l.request_id
          WHERE vr.status = 'processed' AND l.start_dt <= ? AND l.end_dt > ?", 'ss', [$now, $now]
    ), 'vehicle_id', 'vehicle_id');
    $out = ['available' => 0, 'in_use' => 0, 'maintenance' => 0, 'inactive' => 0];
    foreach (dashRows($conn, 'SELECT vehicle_id, status FROM vehicles') as $v) {
        if ($v['status'] === 'available') { $out[isset($inUse[$v['vehicle_id']]) ? 'in_use' : 'available']++; }
        elseif (isset($out[$v['status']])) { $out[$v['status']]++; }
    }
    return $out;
}

/** Requests that need the Vehicle Section: by status, oldest first, with hours waiting. */
function dashQueue(mysqli $conn, string $status, int $limit = 5, int $olderThanHours = 0): array
{
    $cutoff = date('Y-m-d H:i:s', time() - $olderThanHours * 3600);
    return dashRows(
        $conn,
        "SELECT vr.request_id, vr.destination, vr.trip_date, vr.start_time, vr.return_date, vr.end_time, vr.status, u.full_name AS staff_name,
                TIMESTAMPDIFF(HOUR, COALESCE(vr.reviewed_at, vr.created_at), NOW()) AS waiting_h
           FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id
          WHERE vr.status = ? AND COALESCE(vr.reviewed_at, vr.created_at) <= ?
          ORDER BY COALESCE(vr.reviewed_at, vr.created_at) LIMIT $limit",
        'ss', [$status, $cutoff]
    );
}

/** Things the Vehicle Section should look at soon. Each: ['tone' => warning|danger|info, 'icon', 'text', 'href']. */
function dashAlerts(mysqli $conn): array
{
    $a = [];
    $soon = dashRows(
        $conn,
        "SELECT vr.request_id, vr.destination, vr.status FROM vehicle_requests vr
          WHERE vr.status IN ('pending','approved','assigned') AND vr.depart_at > NOW() AND vr.depart_at <= DATE_ADD(NOW(), INTERVAL 24 HOUR) ORDER BY vr.depart_at LIMIT 5"
    );
    foreach ($soon as $r) {
        $a[] = ['tone' => 'danger', 'icon' => 'clock', 'href' => 'vehicle_requests.php',
                'text' => "Request #{$r['request_id']} ({$r['destination']}) leaves within 24 hours but is still " . requestStatusLabel($r['status'])];
    }
    foreach (dashRows($conn, "SELECT v.plate_number, m.end_date FROM vehicle_maintenance m JOIN vehicles v ON v.vehicle_id = m.vehicle_id
                               WHERE m.closed_at IS NULL AND m.end_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY m.end_date LIMIT 5") as $r) {
        $a[] = ['tone' => 'info', 'icon' => 'screwdriver-wrench', 'href' => 'vehicle_maintenance.php',
                'text' => "{$r['plate_number']} is due back from maintenance on " . date('d M Y', strtotime($r['end_date']))];
    }
    foreach (dashRows($conn, "SELECT name, license_expiry FROM drivers WHERE status = 'active' AND license_expiry IS NOT NULL
                               AND license_expiry <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY license_expiry LIMIT 5") as $r) {
        $past = $r['license_expiry'] < date('Y-m-d');
        $a[] = ['tone' => $past ? 'danger' : 'warning', 'icon' => 'id-card', 'href' => 'drivers.php',
                'text' => "{$r['name']}'s licence " . ($past ? 'expired on ' : 'expires on ') . date('d M Y', strtotime($r['license_expiry']))];
    }
    $mail = (int)dashValue($conn, "SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $wa = (int)dashValue($conn, "SELECT COUNT(*) FROM whatsapp_log WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($mail > 0) { $a[] = ['tone' => 'warning', 'icon' => 'envelope', 'href' => '../notifications.php', 'text' => "$mail e-mail(s) failed to send in the last 7 days (table email_log)"]; }
    if ($wa > 0) { $a[] = ['tone' => 'warning', 'icon' => 'comment-dots', 'href' => '../notifications.php', 'text' => "$wa WhatsApp message(s) failed in the last 7 days"]; }
    return $a;
}

/** Today's request tasks (outbound / return) and internal tasks, by time. */
function dashToday(mysqli $conn): array
{
    $today = date('Y-m-d');
    $rows = dashRows(
        $conn,
        "SELECT s.start_dt, s.end_dt, s.leg_no, s.destination, d.name AS driver, v.plate_number, vr.status AS req_status
           FROM schedules s LEFT JOIN drivers d ON d.driver_id = s.driver_id LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
           LEFT JOIN vehicle_requests vr ON vr.request_id = s.request_id
          WHERE s.status <> 'cancelled' AND s.trip_date = ?", 's', [$today]
    );
    $items = [];
    foreach ($rows as $t) {
        $items[] = ['start' => $t['start_dt'], 'end' => $t['end_dt'], 'kind' => (int)$t['leg_no'] === 2 ? 'Return' : ((int)$t['leg_no'] === 1 ? 'Outbound' : 'Trip'),
                    'title' => $t['destination'], 'who' => (string)$t['driver'], 'vehicle' => (string)$t['plate_number'], 'status' => $t['req_status']];
    }
    foreach (dashRows($conn, "SELECT t.task_type, t.start_dt, t.end_dt, d.name AS driver, v.plate_number FROM internal_tasks t JOIN drivers d ON d.driver_id = t.driver_id
                               LEFT JOIN vehicles v ON v.vehicle_id = t.vehicle_id WHERE t.status = 'active' AND t.task_date = ?", 's', [$today]) as $t) {
        $items[] = ['start' => $t['start_dt'], 'end' => $t['end_dt'], 'kind' => 'Internal', 'title' => ucfirst(str_replace('_', ' ', $t['task_type'])),
                    'who' => $t['driver'], 'vehicle' => (string)$t['plate_number'], 'status' => null];
    }
    usort($items, fn($a, $b) => strcmp($a['start'], $b['start']));
    return $items;
}

function dashWelfareToday(mysqli $conn): ?string
{
    $n = dashValue($conn, 'SELECT d.name FROM welfare_roster w JOIN drivers d ON d.driver_id = w.driver_id WHERE w.duty_date = ?', 's', [date('Y-m-d')]);
    return $n !== null ? (string)$n : null;
}

/** Latest steps of the requests of one person (their own requests, or those they approve), without the Vehicle Section's internal steps. */
function dashActivity(mysqli $conn, int $userId, bool $asApprover = false, int $limit = 8): array
{
    $col = $asApprover ? 'vr.supervisor_id' : 'vr.staff_id';
    return dashRows(
        $conn,
        "SELECT a.action, a.created_at, a.request_id, vr.destination, COALESCE(u.full_name, 'System') AS who
           FROM audit_log a JOIN vehicle_requests vr ON vr.request_id = a.request_id LEFT JOIN users u ON u.user_id = a.user_id
          WHERE $col = ? AND a.action NOT IN ('assign','reject_assignment','replace_driver')
          ORDER BY a.log_id DESC LIMIT $limit",
        'i', [$userId]
    );
}

/** Requests the signed-in Head of Department can decide on (their own PTJ), oldest first. */
function dashHodPending(mysqli $conn, int $hodId, ?string $department, int $limit = 5): array
{
    return dashRows(
        $conn,
        "SELECT vr.request_id, vr.destination, vr.trip_date, vr.start_time, vr.return_date, vr.end_time, vr.status, u.full_name AS staff_name,
                TIMESTAMPDIFF(HOUR, vr.created_at, NOW()) AS waiting_h
           FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id
          WHERE vr.status = 'pending' AND vr.supervisor_id = ? AND (u.role = 'supervisor' OR u.department IS NULL OR u.department <=> ?)
          ORDER BY vr.created_at LIMIT $limit",
        'is', [$hodId, $department]
    );
}

/** My own requests that are still going somewhere, nearest departure first. */
function dashMyActive(mysqli $conn, int $userId): array
{
    return dashRows(
        $conn,
        requestSelectSql() . " WHERE vr.staff_id = ? AND vr.status IN ('pending','approved','assigned','processed') AND vr.return_at >= NOW() ORDER BY vr.depart_at LIMIT 6",
        'i', [$userId]
    );
}
