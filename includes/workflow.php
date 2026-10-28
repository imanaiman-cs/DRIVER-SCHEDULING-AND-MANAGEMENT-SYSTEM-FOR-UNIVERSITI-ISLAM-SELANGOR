<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/workflow.php  -  the business rules of a vehicle request (SRS)
//
//   * Settings         editable values (weights, lapse hours, office hours ...)
//   * Audit / history  insert-only record of every approval, rejection, cancellation,
//                      assignment and replacement (user, date, time, reason). It is shown in the
//                      request details; there is no function that edits or deletes a row.
//   * Notifications    every notification is saved in the system AND e-mailed
//   * Vehicle lock     per TIME PERIOD (departure -> return), not per day
//   * Status engine    ONE place that says which status changes are allowed
//   * Assignment       outbound + return tasks, approve / reject assignment, replace driver
//   * Background jobs  lapse after 2 days, cancel what nobody handled, complete finished trips
//
// Status flow (stored value -> label shown):
//   pending -> approved -> assigned ("Driver Assigned") -> processed ("Confirmed") -> completed
//   pending -> rejected | lapsed | cancelled      approved/assigned/processed -> cancelled
//   assigned -> approved   (Head of Admin rejects the assignment)
//   processed -> assigned  (a driver is replaced: the Head of Admin approves again)
//
// Loaded by config/database.php, so every page can use these functions.
// ============================================================

// ------------------------------------------------------------
// Settings (table `settings`)
// ------------------------------------------------------------

/** Defaults used when a setting row does not exist yet. */
const SETTING_DEFAULTS = [
    'W_TASKS'             => '0.5',
    'W_WEEKEND'           => '0.3',
    'W_EXPERIENCE'        => '0.2',
    'LAPSE_HOURS'         => '48',
    'WORK_START'          => '08:00',
    'WORK_END'            => '17:30',
    'SESSION_TIMEOUT_MIN' => '15',
    'MAX_FAILED_ATTEMPTS' => '5',
    'LOCKOUT_MINUTES'     => '15',
    'TASK_DURATION_HOURS' => '2',
];

/** Reads one setting. The whole table is loaded once per request. */
function getSetting(mysqli $conn, string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null || isset($GLOBALS['__settings_dirty'])) {
        $cache = [];
        try {
            $r = $conn->query('SELECT setting_key, setting_value FROM settings');
            while ($r && ($row = $r->fetch_assoc())) { $cache[$row['setting_key']] = $row['setting_value']; }
        } catch (Throwable $e) {
            // table not there yet: defaults are used
        }
        unset($GLOBALS['__settings_dirty']);
    }
    return $cache[$key] ?? ($default ?? (SETTING_DEFAULTS[$key] ?? null));
}

function getSettingInt(mysqli $conn, string $key): int
{
    return (int)getSetting($conn, $key);
}

function saveSetting(mysqli $conn, string $key, string $value): void
{
    $stmt = $conn->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
    $GLOBALS['__settings_dirty'] = true;
}

/** Fixed list of waiting locations; 'Other' asks for a free-text address. */
const WAITING_LOCATIONS = ['Main entrance', 'Library', 'Administration building', 'Faculty entrance', 'Student Affairs lobby', 'Mosque', 'Other'];

/** Supporting documents: PDF, JPG or PNG, at most 5 MB each (checked on the server in includes/upload.php). */
const MAX_DOCUMENT_BYTES       = 5242880;
const MAX_SUPPORTING_DOCUMENTS = 5;

// ------------------------------------------------------------
// Strict date / time checks (2026-02-31 and 25:61 are NOT valid)
// ------------------------------------------------------------
function isValidDate(string $d): bool
{
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t !== false && $t->format('Y-m-d') === $d;
}

function isValidTime(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);
}

/** 'HH:MM' -> 'HH:MM:SS' (leaves a time that already has seconds alone). */
function timeWithSeconds(string $t): string
{
    return strlen($t) === 5 ? $t . ':00' : $t;
}

// ------------------------------------------------------------
// History (the request's audit trail). INSERT ONLY: database triggers refuse update/delete too.
// ------------------------------------------------------------

/**
 * @param int|null    $user_id null = done by the system (lapse job ...)
 * @param string|null $role    role of that user, or 'system'
 */
function auditLog(mysqli $conn, string $action, ?int $request_id = null, ?string $reason = null,
                  ?int $user_id = null, ?string $role = null): void
{
    try {
        if ($user_id === null && $role === null && isLoggedIn()) {
            $user_id = (int)$_SESSION['user_id'];
            $role    = (string)($_SESSION['role'] ?? '');
        }
        if ($reason !== null) { $reason = mb_substr($reason, 0, 500); }
        $stmt = $conn->prepare('INSERT INTO audit_log (user_id, user_role, action, request_id, reason) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issis', $user_id, $role, $action, $request_id, $reason);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('auditLog: ' . $e->getMessage());   // never block the real action
    }
}

/** Words for the history list. */
function historyActionLabel(string $action): string
{
    return [
        'submit' => 'Request submitted', 'paper_form' => 'Recorded from the approved paper form', 'approve' => 'Approved by Head of Department', 'reject' => 'Rejected',
        'cancel' => 'Cancelled', 'lapse' => 'Lapsed', 'assign' => 'Driver assigned', 'confirm' => 'Assignment approved (Confirmed)',
        'reject_assignment' => 'Assignment rejected', 'replace_driver' => 'Driver replaced', 'complete' => 'Completed',
        'comment' => 'Comment added', 'reminder' => 'Approval reminder sent',
    ][$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/** The status history of one request, oldest first: action, label, who, role, reason, date/time. */
function requestHistory(mysqli $conn, int $request_id): array
{
    $stmt = $conn->prepare(
        "SELECT a.action, a.reason, a.user_role, a.created_at, COALESCE(u.full_name, 'System') AS who
           FROM audit_log a LEFT JOIN users u ON u.user_id = a.user_id
          WHERE a.request_id = ? ORDER BY a.log_id"
    );
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) { $r['label'] = historyActionLabel($r['action']); }
    return $rows;
}

// ------------------------------------------------------------
// People
// ------------------------------------------------------------

/** Ids of every Administrative Assistant and Head of Admin (the Vehicle Section). */
function adminUserIds(mysqli $conn): array
{
    $r = $conn->query("SELECT user_id FROM users WHERE role IN ('admin','head_admin') AND is_active = 1");
    return array_map('intval', array_column($r->fetch_all(MYSQLI_ASSOC), 'user_id'));
}

/** Ids of the Administrative Assistant(s) only. */
function assistantUserIds(mysqli $conn): array
{
    $r = $conn->query("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1");
    return array_map('intval', array_column($r->fetch_all(MYSQLI_ASSOC), 'user_id'));
}

/** Ids of the Head of Admin account(s). */
function headAdminUserIds(mysqli $conn): array
{
    $r = $conn->query("SELECT user_id FROM users WHERE role = 'head_admin' AND is_active = 1");
    return array_map('intval', array_column($r->fetch_all(MYSQLI_ASSOC), 'user_id'));
}

/** user_id of the login account of a driver (to notify them), or null. */
function userIdOfDriver(mysqli $conn, int $driver_id): ?int
{
    $stmt = $conn->prepare('SELECT user_id FROM users WHERE driver_id = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['user_id'] : null;
}

/** Login accounts of the drivers on some schedule rows. */
function driverUserIdsOfSchedules(mysqli $conn, array $schedule_ids): array
{
    $ids = array_values(array_filter(array_map('intval', $schedule_ids)));
    if (!$ids) { return []; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT DISTINCT u.user_id FROM schedules s JOIN users u ON u.driver_id = s.driver_id WHERE s.schedule_id IN ($in)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $out = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id'));
    $stmt->close();
    return $out;
}

/** The page a role opens to see its requests. */
function requestPageForRole(string $role): string
{
    return match ($role) {
        'supervisor'          => 'supervisor/approvals.php',
        'admin', 'head_admin' => 'admin/vehicle_requests.php',
        'driver'              => 'staff/my_requests.php',
        default               => 'staff/my_requests.php',
    };
}

// ------------------------------------------------------------
// Notifications (UC030): ALWAYS in the system and by e-mail.
// If the e-mail fails the in-system notification is still saved and the failure is in email_log.
// ------------------------------------------------------------

/**
 * Tells one or more users. $link is a page below SITE_URL, e.g. 'staff/my_requests.php'.
 *
 * @param int|int[] $user_ids
 * @return array{sent:int, failed:int, skipped:int, errors:string[]}  e-mail results, for pages that show them
 */
function notifyUsers(mysqli $conn, $user_ids, string $message, ?string $link = null, ?string $kind = 'notice'): array
{
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$user_ids))));
    foreach ($ids as $uid) {
        $msg = mb_substr($message, 0, 500);
        try {
            $stmt = $conn->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $uid, $msg, $link);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('notifyUsers (in-system): ' . $e->getMessage());
        }
        try {
            $q = $conn->prepare('SELECT email, full_name, language FROM users WHERE user_id = ?');
            $q->bind_param('i', $uid);
            $q->execute();
            $u = $q->get_result()->fetch_assoc();
            $q->close();
            if ($u && function_exists('sendMail')) {
                $url  = $link ? rtrim(SITE_URL, '/') . '/' . ltrim($link, '/') : SITE_URL;
                $lang = validLanguage($u['language'] ?? 'en');                        // the e-mail is in the language of the person who receives it
                $body = translateText($msg, $lang);
                $open = translateText('Open in the system', $lang);
                $html = '<p>Salam ' . htmlspecialchars((string)$u['full_name']) . ',</p><p>' . htmlspecialchars($body)
                      . '</p><p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($open) . '</a></p><p>UIS Vehicle Section</p>';
                $text = "Salam {$u['full_name']},\n\n$body\n\nOpen: $url\n\nUIS Vehicle Section";
                $res = sendMail($conn, $u['email'], '[UIS Vehicle Section] ' . mb_substr($body, 0, 70), $html, $text, ['kind' => $kind ?: 'notice', 'user_id' => $uid]);
                $out[$res['status']] = ($out[$res['status']] ?? 0) + 1;
                if ($res['status'] === 'failed') { $out['errors'][] = $res['message']; }
            }
        } catch (Throwable $e) {
            $out['failed']++;
            $out['errors'][] = $e->getMessage();
            error_log('notifyUsers (e-mail): ' . $e->getMessage());
        }
        // WhatsApp (OpenWA): drivers only; a failure is logged and never stops the other channels
        try {
            $q = $conn->prepare("SELECT COALESCE(NULLIF(u.phone, ''), d.phone) AS phone FROM users u LEFT JOIN drivers d ON d.driver_id = u.driver_id WHERE u.user_id = ? AND u.role = 'driver'");
            $q->bind_param('i', $uid);
            $q->execute();
            $w = $q->get_result()->fetch_assoc();
            $q->close();
            if ($w) {
                $url = $link ? rtrim(SITE_URL, '/') . '/' . ltrim($link, '/') : SITE_URL;
                sendWhatsApp($conn, $uid, $w['phone'], "[UIS Vehicle Section] $msg\n$url");
            }
        } catch (Throwable $e) {
            error_log('notifyUsers (WhatsApp): ' . $e->getMessage());
        }
    }
    return $out;
}

/** Turns a stored link into a full address. */
function notificationUrl(?string $link): string
{
    return $link ? rtrim(SITE_URL, '/') . '/' . ltrim($link, '/') : SITE_URL . '/notifications.php';
}

// ------------------------------------------------------------
// Times of a request
//   departure = trip_date + start_time, return = return_date + end_time
//   outbound task = departure .. departure + TASK_DURATION_HOURS (never later than the return)
//   return task   = return    .. return    + TASK_DURATION_HOURS (never past midnight)
// ------------------------------------------------------------

function requestDepartDT(array $req): string { return $req['trip_date'] . ' ' . timeWithSeconds(substr($req['start_time'], 0, 8)); }
function requestReturnDT(array $req): string { return ($req['return_date'] ?? $req['trip_date']) . ' ' . timeWithSeconds(substr($req['end_time'], 0, 8)); }

/**
 * The two task windows of a request.
 * @return array{out: array, ret: array}  each: date, start (H:i:s), end (H:i:s), start_dt, end_dt
 */
function requestTaskWindows(mysqli $conn, array $req): array
{
    $dur = max(1, getSettingInt($conn, 'TASK_DURATION_HOURS')) * 3600;
    $dep = strtotime(requestDepartDT($req));
    $ret = strtotime(requestReturnDT($req));

    $outEnd = min($dep + $dur, $ret, strtotime(date('Y-m-d', $dep) . ' 23:59:59'));
    $retEnd = min($ret + $dur, strtotime(date('Y-m-d', $ret) . ' 23:59:59'));
    $win = fn(int $s, int $e) => ['date' => date('Y-m-d', $s), 'start' => date('H:i:s', $s), 'end' => date('H:i:s', $e),
                                  'start_dt' => date('Y-m-d H:i:s', $s), 'end_dt' => date('Y-m-d H:i:s', $e)];
    return ['out' => $win($dep, $outEnd), 'ret' => $win($ret, $retEnd)];
}

/**
 * The timing rule (SRS B.4) for a departure 'Y-m-d H:i:s':
 *   less than 1 day away -> 'reject' (use the paper form, nothing is saved)
 *   less than 2 days away -> 'warn'   (the requester must confirm)
 *   otherwise             -> 'ok'
 * @return array{level:string, message:?string}
 */
function requestTimingRule(string $departure): array
{
    $hours = (strtotime($departure) - time()) / 3600;
    if ($hours < 24) {
        return ['level' => 'reject', 'message' => 'Please use the paper form BPTK/BPI/2025/PIN.5'];
    }
    if ($hours < 48) {
        return ['level' => 'warn', 'message' => 'This trip is less than 2 days away. The request may not be approved in time. Please confirm that you want to continue.'];
    }
    return ['level' => 'ok', 'message' => null];
}

/** Hours until the trip departs (negative = already started). */
function hoursUntilDeparture(array $req): float
{
    return (strtotime(requestDepartDT($req)) - time()) / 3600;
}

// ------------------------------------------------------------
// Vehicle availability and the vehicle lock (per TIME PERIOD)
//   overlap rule everywhere:  start < other_end AND end > other_start
// ------------------------------------------------------------

/**
 * Why a vehicle cannot be used in [start, end] (Y-m-d H:i:s), or null when free:
 *   'inactive' | 'maintenance' | 'locked' (held by another request) | 'trip' | 'internal'
 */
function vehicleUnavailableReason(mysqli $conn, int $vehicle_id, string $start, string $end, int $except_request = 0): ?string
{
    $stmt = $conn->prepare('SELECT status FROM vehicles WHERE vehicle_id = ?');
    $stmt->bind_param('i', $vehicle_id);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$v || $v['status'] === 'inactive') { return 'inactive'; }
    if ($v['status'] === 'maintenance') {
        $stmt = $conn->prepare('SELECT MAX(end_date) AS back FROM vehicle_maintenance WHERE vehicle_id = ? AND closed_at IS NULL');
        $stmt->bind_param('i', $vehicle_id);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $back = $m['back'] ?? null;
        if ($back === null || substr($start, 0, 10) <= $back) { return 'maintenance'; }
    }
    $checks = [
        'locked'   => "SELECT 1 FROM vehicle_locks WHERE vehicle_id = ? AND request_id <> ? AND start_dt < ? AND end_dt > ? LIMIT 1",
        'trip'     => "SELECT 1 FROM schedules WHERE vehicle_id = ? AND COALESCE(request_id, 0) <> ? AND status <> 'cancelled' AND start_dt < ? AND end_dt > ? LIMIT 1",
        'internal' => "SELECT 1 FROM internal_tasks WHERE vehicle_id = ? AND task_id <> ? AND status = 'active' AND start_dt < ? AND end_dt > ? LIMIT 1",
    ];
    foreach ($checks as $why => $sql) {
        $except = $why === 'internal' ? 0 : $except_request;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iiss', $vehicle_id, $except, $end, $start);
        $stmt->execute();
        $hit = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        if ($hit) { return $why; }
    }
    return null;
}

function vehicleUnavailableText(string $reason): string
{
    return match ($reason) {
        'inactive'    => 'is inactive',
        'maintenance' => 'is under maintenance',
        'locked'      => 'is already booked by another request for that time',
        'trip'        => 'already has a trip at that time',
        'internal'    => 'is used for an internal task at that time',
        default       => 'is not available',
    };
}

/**
 * UC010: the vehicles a requester may choose: NOT locked in that time period, NOT under maintenance or
 * inactive, and with at least $passengers seats. One query, indexed on the period columns.
 * @return array<int, array> vehicle rows (vehicle_id, plate_number, vehicle_type, model, capacity, license_required, status)
 */
function availableVehicles(mysqli $conn, string $start, string $end, int $passengers, int $except_request = 0): array
{
    $day = substr($start, 0, 10);
    $stmt = $conn->prepare(
        "SELECT v.vehicle_id, v.plate_number, v.vehicle_type, v.model, v.capacity, v.license_required, v.status, v.photo
           FROM vehicles v
          WHERE v.status IN ('available', 'in_use', 'maintenance') AND v.capacity >= ?
            AND (v.status <> 'maintenance'
                 OR COALESCE((SELECT MAX(m.end_date) FROM vehicle_maintenance m WHERE m.vehicle_id = v.vehicle_id AND m.closed_at IS NULL), '9999-12-31') < ?)
            AND NOT EXISTS (SELECT 1 FROM vehicle_locks l WHERE l.vehicle_id = v.vehicle_id AND l.request_id <> ? AND l.start_dt < ? AND l.end_dt > ?)
            AND NOT EXISTS (SELECT 1 FROM schedules s WHERE s.vehicle_id = v.vehicle_id AND COALESCE(s.request_id, 0) <> ? AND s.status <> 'cancelled' AND s.start_dt < ? AND s.end_dt > ?)
            AND NOT EXISTS (SELECT 1 FROM internal_tasks t WHERE t.vehicle_id = v.vehicle_id AND t.status = 'active' AND t.start_dt < ? AND t.end_dt > ?)
          ORDER BY v.capacity, v.plate_number"
    );
    $stmt->bind_param('isississss', $passengers, $day, $except_request, $end, $start, $except_request, $end, $start, $end, $start);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Locks a vehicle for [start, end] for a request. Call it INSIDE a transaction: the vehicle row is
 * locked first (SELECT ... FOR UPDATE), so two submissions at the same moment cannot both succeed.
 * @throws RuntimeException 'Sorry, ...' (safe to show) when the vehicle is not free any more
 */
function lockVehiclePeriod(mysqli $conn, int $request_id, int $vehicle_id, string $start, string $end): void
{
    $stmt = $conn->prepare('SELECT vehicle_id FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
    $stmt->bind_param('i', $vehicle_id);
    $stmt->execute();
    $stmt->get_result();
    $stmt->close();

    $why = vehicleUnavailableReason($conn, $vehicle_id, $start, $end, $request_id);
    if ($why !== null) {
        $p = $conn->prepare('SELECT plate_number FROM vehicles WHERE vehicle_id = ?');
        $p->bind_param('i', $vehicle_id);
        $p->execute();
        $plate = (string)($p->get_result()->fetch_row()[0] ?? 'The vehicle');
        $p->close();
        throw new RuntimeException('Sorry, ' . $plate . ' ' . vehicleUnavailableText($why) . '. Please choose another vehicle or another time.');
    }
    $stmt = $conn->prepare('INSERT INTO vehicle_locks (request_id, vehicle_id, start_dt, end_dt) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $request_id, $vehicle_id, $start, $end);
    $stmt->execute();
    $stmt->close();
}

/** Gives the vehicle(s) of a request back (reject, cancel, lapse, completed). */
function releaseVehicleLocks(mysqli $conn, int $request_id): void
{
    $stmt = $conn->prepare('DELETE FROM vehicle_locks WHERE request_id = ?');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $stmt->close();
}

// ------------------------------------------------------------
// Request status engine
// ------------------------------------------------------------

const REQUEST_TRANSITIONS = [
    'pending'   => ['approved', 'rejected', 'lapsed', 'cancelled'],
    'approved'  => ['assigned', 'cancelled'],
    'assigned'  => ['processed', 'approved', 'cancelled'],      // processed = Head of Admin approves; approved = rejects
    'processed' => ['completed', 'cancelled', 'assigned'],      // assigned = a driver was replaced
    'rejected'  => [], 'lapsed' => [], 'completed' => [], 'cancelled' => [],
];

/** Statuses in which the request still needs its vehicle (so it keeps the lock). */
const REQUEST_ACTIVE_STATUSES = ['pending', 'approved', 'assigned', 'processed'];

function canChangeRequestStatus(string $from, string $to): bool
{
    return in_array($to, REQUEST_TRANSITIONS[$from] ?? [], true);
}

/** Ids of the schedule rows (tasks) of a request: outbound first, then return. */
function requestScheduleIds(mysqli $conn, int $request_id): array
{
    $stmt = $conn->prepare('SELECT schedule_id FROM schedules WHERE request_id = ? ORDER BY leg_no, schedule_id');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'schedule_id'));
    $stmt->close();
    return $ids;
}

/** The request a schedule row was made from (0 if none). */
function requestIdOfSchedule(mysqli $conn, int $schedule_id): int
{
    $stmt = $conn->prepare('SELECT COALESCE(request_id, 0) FROM schedules WHERE schedule_id = ?');
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $id;
}

/**
 * SQL condition (table alias `s` = schedules) that hides a task from the driver until the request is
 * Confirmed. UC011/UC029: driver and vehicle are shown only AFTER the assignment is Confirmed.
 */
const SCHEDULE_CONFIRMED_ONLY_SQL = "EXISTS (SELECT 1 FROM vehicle_requests vr_c WHERE vr_c.request_id = s.request_id AND vr_c.status IN ('processed', 'completed'))";

/** Cancels the tasks of a request and removes their driver (the old driver ids are returned for the notice). */
function cancelRequestTasks(mysqli $conn, int $request_id): array
{
    $stmt = $conn->prepare("SELECT schedule_id, driver_id FROM schedules WHERE request_id = ? AND status <> 'cancelled'");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $upd = $conn->prepare("UPDATE schedules SET status = 'cancelled', driver_id = NULL WHERE schedule_id = ?");
    $drivers = [];
    foreach ($rows as $r) {
        if ($r['driver_id'] !== null) { $drivers[] = (int)$r['driver_id']; }
        $sid = (int)$r['schedule_id'];
        $upd->bind_param('i', $sid);
        $upd->execute();
    }
    $upd->close();
    return array_values(array_unique($drivers));
}

/** One line per task: "Outbound: Name (plate)  /  Return: Name (plate)". */
function taskSummaryText(mysqli $conn, int $request_id): string
{
    $stmt = $conn->prepare(
        "SELECT s.leg_no, d.name AS driver, v.plate_number, v.model, s.vendor_name, s.vendor_bus_plate, s.vendor_driver_name FROM schedules s
           LEFT JOIN drivers d ON d.driver_id = s.driver_id LEFT JOIN vehicles v ON v.vehicle_id = s.vehicle_id
          WHERE s.request_id = ? AND s.status <> 'cancelled' ORDER BY s.leg_no"
    );
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $parts = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $parts[] = ((int)$r['leg_no'] === 2 ? 'Return' : 'Outbound') . ': ' . ($r['vendor_name'] !== null
            ? 'vendor bus ' . $r['vendor_bus_plate'] . ' (' . $r['vendor_name'] . ', driver ' . $r['vendor_driver_name'] . ')'
            : ($r['driver'] ?? 'no driver') . ' with ' . ($r['plate_number'] ?? 'no vehicle') . ($r['model'] ? ' (' . $r['model'] . ')' : ''));
    }
    $stmt->close();
    return implode('; ', $parts);
}

/**
 * Moves a request to a new status. EVERY status change goes through here.
 *   - checks the move is allowed and nobody else changed it meanwhile (row lock)
 *   - gives the vehicle back when the request is no longer active; cancelling removes the drivers
 *   - writes the history entry and tells the right people (in the system AND by e-mail)
 *
 * $o: reason, actor_id, actor_role, system (bool), only_if_supervisor (user id that must be the approver)
 *
 * @return array{ok:bool, message:string}
 */
function changeRequestStatus(mysqli $conn, int $request_id, string $to, array $o = []): array
{
    $reason = isset($o['reason']) ? trim((string)$o['reason']) : null;
    $reason = ($reason === '' ? null : ($reason !== null ? mb_substr($reason, 0, 500) : null));
    $system = !empty($o['system']);
    $actor  = $system ? null : (int)($o['actor_id'] ?? ($_SESSION['user_id'] ?? 0));
    $role   = $system ? 'system' : (string)($o['actor_role'] ?? ($_SESSION['role'] ?? ''));

    $oldDrivers = [];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM vehicle_requests WHERE request_id = ? FOR UPDATE');
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$req) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Request not found.'];
        }
        if (!empty($o['only_if_supervisor']) && (int)$req['supervisor_id'] !== (int)$o['only_if_supervisor']) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This request is not assigned to you.'];
        }
        if (!canChangeRequestStatus($req['status'], $to)) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'This request is already ' . requestStatusLabel($req['status']) . ' and cannot become ' . requestStatusLabel($to) . '.'];
        }

        $sets = ['status = ?', 'status_reason = ?'];
        $args = [$to, $reason];
        $types = 'ss';
        if ($to === 'approved' && $req['status'] === 'pending') { $sets[] = 'reviewed_at = NOW()'; }
        if ($to === 'rejected') {
            $sets[] = 'reviewed_at = NOW()';
            $sets[] = 'supervisor_notes = ?'; $args[] = (string)$reason; $types .= 's';
        }
        if ($to === 'cancelled') {
            $sets[] = 'cancelled_at = NOW()';
            $sets[] = 'cancelled_by = ?';       $args[] = $actor;  $types .= 'i';
            $sets[] = 'cancelled_by_role = ?';  $args[] = $role;   $types .= 's';
        }
        $args[] = $request_id; $types .= 'i';
        $stmt = $conn->prepare('UPDATE vehicle_requests SET ' . implode(', ', $sets) . ' WHERE request_id = ?');
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $stmt->close();

        if (!in_array($to, REQUEST_ACTIVE_STATUSES, true)) {
            releaseVehicleLocks($conn, $request_id);
        }
        if ($to === 'cancelled') {
            $oldDrivers = cancelRequestTasks($conn, $request_id);
        }
        if ($to === 'processed' && requestAllTasksVendor($conn, $request_id)) {
            releaseVehicleLocks($conn, $request_id);          // UC017 A2: the vendor bus replaces the UIS bus, which is free for others again
        }
        if ($to === 'approved' && $req['status'] === 'assigned') {
            // The Head of Admin rejected the assignment: the tasks and the driver go, the vehicle stays locked
            $oldDrivers = cancelRequestTasks($conn, $request_id);
            $del = $conn->prepare('DELETE FROM vehicle_locks WHERE request_id = ? AND NOT (vehicle_id <=> ?)');
            $mainVehicle = $req['vehicle_id'] !== null ? (int)$req['vehicle_id'] : null;
            $del->bind_param('ii', $request_id, $mainVehicle);
            $del->execute();
            $del->close();
            $clr = $conn->prepare('UPDATE vehicle_requests SET schedule_id = NULL WHERE request_id = ?');
            $clr->bind_param('i', $request_id);
            $clr->execute();
            $clr->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('changeRequestStatus: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Database error. Please try again.'];
    }

    afterRequestStatusChange($conn, $req, $to, $reason, $system, $actor, $role, $oldDrivers);
    return ['ok' => true, 'message' => 'Request ' . requestStatusLabel($to) . '.'];
}

/** History entry + notifications after a status change (never undoes the change). */
/** True when the request has tasks and every one of them is given to a vendor bus (no UIS driver and no UIS bus is needed). */
function requestAllTasksVendor(mysqli $conn, int $request_id): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS n, SUM(vendor_name IS NULL) AS uis FROM schedules WHERE request_id = ? AND status <> 'cancelled'");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$r['n'] > 0 && (int)$r['uis'] === 0;
}

function afterRequestStatusChange(mysqli $conn, array $req, string $to, ?string $reason, bool $system, ?int $actor, string $role, array $oldDrivers = []): void
{
    $request_id = (int)$req['request_id'];
    $from = $req['status'];
    $action = ['approved' => $from === 'assigned' ? 'reject_assignment' : 'approve', 'rejected' => 'reject', 'cancelled' => 'cancel', 'lapsed' => 'lapse',
               'assigned' => $from === 'processed' ? 'replace_driver' : 'assign', 'processed' => 'confirm', 'completed' => 'complete'][$to];
    $system ? auditLog($conn, $action, $request_id, $reason, null, 'system')
            : auditLog($conn, $action, $request_id, $reason, $actor, $role);

    $what = "request #{$request_id} (" . $req['destination'] . ', ' . date('d M Y H:i', strtotime(requestDepartDT($req))) . ')';
    $why  = $reason ? ' Reason: ' . $reason : '';
    $staff = (int)$req['staff_id'];
    $hod   = !empty($req['supervisor_id']) ? (int)$req['supervisor_id'] : 0;

    switch ($to) {
        case 'approved':
            if ($from === 'assigned' && isRequestWithoutDriver($req)) {      // UC019 A2: the use of the vehicle was rejected
                notifyUsers($conn, assistantUserIds($conn), "The use of the vehicle for $what (without driver) was rejected by the Head of Admin.$why", 'admin/vehicle_requests.php');
            } elseif ($from === 'assigned') {                // UC020: Head of Admin rejected the assignment
                notifyUsers($conn, assistantUserIds($conn), "The driver assignment for $what was rejected by the Head of Admin. Please assign again.$why", 'admin/vehicle_requests.php');
                notifyUsers($conn, driverUserIdsFromDriverIds($conn, $oldDrivers), "You are no longer assigned to $what.", 'driver/schedules.php');
            } else {                                         // UC013
                notifyUsers($conn, [$staff], "Your $what was approved by your Head of Department.", 'staff/my_requests.php');
                notifyUsers($conn, adminUserIds($conn), ucfirst($what) . (isRequestWithoutDriver($req) ? ' is approved. It is without driver: send it to the Head of Admin.' : ' is approved and needs a driver.'), 'admin/vehicle_requests.php');
            }
            break;
        case 'rejected':                                     // UC014
            notifyUsers($conn, [$staff], "Your $what was rejected by your Head of Department.$why", 'staff/my_requests.php');
            break;
        case 'lapsed':
            $msg = "The $what lapsed because it was not approved within " . lapseWindowText($conn) . '.';
            notifyUsers($conn, array_filter([$staff, $hod]), $msg, $hod ? 'supervisor/approvals.php' : 'staff/my_requests.php');
            break;
        case 'cancelled':                                    // UC012
            $by = $system ? 'the system' : requestActorLabel($role);
            $msg = "The $what was cancelled by $by." . $why;
            // the staff member, the Head of Department, the Administrative Assistant(s) and any assigned driver
            $people = array_merge([$staff], $hod ? [$hod] : [], adminUserIds($conn), driverUserIdsFromDriverIds($conn, $oldDrivers));
            notifyUsers($conn, $people, $msg, 'staff/my_requests.php');
            break;
        case 'assigned':
            if ($from === 'processed') { break; }            // a replacement tells people itself (replaceTaskDriver)
            notifyUsers($conn, headAdminUserIds($conn), ucfirst($what) . (isRequestWithoutDriver($req) ? ' is without driver and needs your approval for the use of the vehicle.' : ' has a driver assigned and needs your approval.'), 'admin/approve_assignments.php');
            break;
        case 'processed':                                    // UC019: the driver first, then the staff member
            $ids = requestScheduleIds($conn, $request_id);
            notifyUsers($conn, driverUserIdsOfSchedules($conn, $ids), "You have a new trip: $what.", 'driver/schedules.php');
            notifyUsers($conn, [$staff], "Your $what is confirmed. " . (isRequestWithoutDriver($req) ? 'You may use the vehicle without a driver.' : taskSummaryText($conn, $request_id) . '.'), 'staff/my_requests.php');
            break;
        case 'completed':
            break;
    }
}

function driverUserIdsFromDriverIds(mysqli $conn, array $driver_ids): array
{
    $out = [];
    foreach ($driver_ids as $d) {
        $u = userIdOfDriver($conn, (int)$d);
        if ($u) { $out[] = $u; }
    }
    return $out;
}

function requestActorLabel(string $role): string
{
    return match ($role) {
        'staff', 'driver'  => 'the requester',
        'supervisor'       => 'the Head of Department',
        'admin'            => 'the Administrative Assistant',
        'head_admin'       => 'the Head of Admin',
        'system'           => 'the system',
        default            => $role,
    };
}

/** "two days" / "48 hours" for messages. */
function lapseWindowText(mysqli $conn): string
{
    $h = max(1, getSettingInt($conn, 'LAPSE_HOURS'));
    return $h % 24 === 0 ? (($h / 24) === 1 ? 'one day' : ($h / 24 === 2 ? 'two days' : ($h / 24) . ' days')) : "$h hours";
}

// ------------------------------------------------------------
// Cancel (UC012): who may cancel, and the rules
// ------------------------------------------------------------

/**
 * May the CURRENT user cancel this request, and must they give a reason?
 * Staff: own request, reason optional. Head of Department: requests they approve, reason required.
 * Administrative Assistant / Head of Admin: any request, reason required. Never after the trip started.
 * @return array{allowed:bool, reason_required:bool, message:string}
 */
function cancelRulesFor(array $req): array
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!in_array($req['status'], REQUEST_ACTIVE_STATUSES, true)) {
        return ['allowed' => false, 'reason_required' => false, 'message' => 'This request is already ' . requestStatusLabel($req['status']) . ' and cannot be cancelled.'];
    }
    if (strtotime(requestDepartDT($req)) <= time()) {
        return ['allowed' => false, 'reason_required' => false, 'message' => 'The trip has already started, so the request can no longer be cancelled.'];
    }
    if (isAdmin()) { return ['allowed' => true, 'reason_required' => true, 'message' => '']; }
    if (isSupervisor() && (int)($req['supervisor_id'] ?? 0) === $uid) { return ['allowed' => true, 'reason_required' => true, 'message' => '']; }
    if ((int)$req['staff_id'] === $uid) { return ['allowed' => true, 'reason_required' => false, 'message' => '']; }
    return ['allowed' => false, 'reason_required' => false, 'message' => 'You are not allowed to cancel this request.'];
}

// ------------------------------------------------------------
// Assignment (UC017): outbound task + return task
// ------------------------------------------------------------

/** UC010 A3: was this request made "Without driver"? (accepts a vehicle_requests row) */
function isRequestWithoutDriver(array $req): bool
{
    return isset($req['need_driver']) && (int)$req['need_driver'] === 0;
}

/**
 * UC010 A3 / UC019 A2: an Approved request WITHOUT driver has no driver step. The Administrative Assistant sends it to
 * the Head of Admin, who approves the use of the vehicle. The request becomes "Driver Assigned" (no tasks are created).
 * @return array{ok:bool, message:string}
 */
function sendRequestWithoutDriverForApproval(mysqli $conn, int $request_id): array
{
    $stmt = $conn->prepare('SELECT * FROM vehicle_requests WHERE request_id = ?');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$req || $req['status'] !== 'approved') { return ['ok' => false, 'message' => 'This request is not waiting for the Vehicle Section any more.']; }
    if (!isRequestWithoutDriver($req)) { return ['ok' => false, 'message' => 'This request needs a driver: assign a driver.']; }
    if (empty($req['vehicle_id'])) { return ['ok' => false, 'message' => 'The request has no vehicle.']; }
    if (strtotime(requestDepartDT($req)) <= time()) { return ['ok' => false, 'message' => 'The trip time has already passed.']; }
    $res = changeRequestStatus($conn, $request_id, 'assigned');
    return $res['ok'] ? ['ok' => true, 'message' => 'Sent to the Head of Admin to approve the use of the vehicle.'] : $res;
}

/**
 * The Administrative Assistant (or Head of Admin) assigns the drivers of an Approved request.
 *   $out_driver  driver of the outbound task
 *   $ret_driver  driver of the return task (null = the same driver as outbound)
 *   $ret_vehicle vehicle of the return task (null = the same vehicle as outbound)
 *   $vendorOut / $vendorRet  UC017 A2 vendor bus ['name', 'plate', 'driver'] instead of a UIS driver for that task. Only for a bus that needs
 *                a PSV licence when no UIS driver is available for the task. With a vendor outbound and nothing chosen for the return,
 *                the return uses the same vendor bus.
 * The request becomes "Driver Assigned" and the Head of Admin is told. The system never assigns by itself,
 * and a driver with a clashing task cannot be chosen.
 * @return array{ok:bool, message:string}
 */
function assignRequestDrivers(mysqli $conn, int $request_id, int $out_driver, ?int $ret_driver = null, ?int $ret_vehicle = null,
                              ?array $vendorOut = null, ?array $vendorRet = null): array
{
    $fail = fn(string $m) => ['ok' => false, 'message' => $m];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM vehicle_requests WHERE request_id = ? FOR UPDATE');
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$req || $req['status'] !== 'approved') { $conn->rollback(); return $fail('This request is not waiting for a driver any more.'); }
        if (isRequestWithoutDriver($req)) { $conn->rollback(); return $fail('This request is without driver: send it to the Head of Admin instead of assigning a driver.'); }
        if (empty($req['vehicle_id'])) { $conn->rollback(); return $fail('The request has no vehicle.'); }
        if (strtotime(requestDepartDT($req)) <= time()) { $conn->rollback(); return $fail('The trip time has already passed.'); }

        $win = requestTaskWindows($conn, $req);
        $mainVehicle = (int)$req['vehicle_id'];
        $sameReturn  = ($ret_driver === null && $ret_vehicle === null && $vendorRet === null);
        $retVehicle  = $ret_vehicle ?? $mainVehicle;
        $retDriver   = $ret_driver ?? $out_driver;
        if (!$sameReturn && $vendorRet === null && ($ret_driver === null || $ret_vehicle === null)) {
            $conn->rollback();
            return $fail('Choose both a driver and a vehicle for the return trip, or use the same driver and vehicle.');
        }

        $checkDriver = function (int $driver_id, int $vehicle_id, array $w, string $label) use ($conn, $request_id) {
            $s = $conn->prepare("SELECT d.name, d.license_class, d.status, v.vehicle_type, v.license_required, v.plate_number
                                   FROM drivers d JOIN vehicles v ON v.vehicle_id = ? WHERE d.driver_id = ? FOR UPDATE");
            $s->bind_param('ii', $vehicle_id, $driver_id);
            $s->execute();
            $d = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$d || $d['status'] !== 'active') { return "The $label driver is not an active driver."; }
            if (!driverHoldsLicence($d['license_class'], $d['license_required'])) {
                return $d['name'] . ' does not hold licence class ' . $d['license_required'] . ', which ' . $d['plate_number'] . ' needs.';
            }
            if (($why = driverUnavailableReason($conn, $driver_id, $w['start_dt'], $w['end_dt']) ?? driverClashReason($conn, $driver_id, $w['start_dt'], $w['end_dt'])) !== null) {
                return $d['name'] . ' cannot be chosen for the ' . $label . ' trip: ' . $why . '.';
            }
            return null;
        };

        // UC017 A2: a vendor bus is allowed only when no UIS driver with the PSV licence is available for that task
        $vendorOk = function (array $v, array $w, string $label) use ($conn, $mainVehicle): ?string {
            if (trim((string)($v['name'] ?? '')) === '' || trim((string)($v['plate'] ?? '')) === '' || trim((string)($v['driver'] ?? '')) === '') {
                return 'Enter the vendor name, the bus registration number and the vendor driver name.';
            }
            if (mb_strlen((string)$v['name']) > 150 || mb_strlen((string)$v['plate']) > 20 || mb_strlen((string)$v['driver']) > 100) { return 'The vendor details are too long.'; }
            $q = $conn->prepare('SELECT license_required FROM vehicles WHERE vehicle_id = ?');
            $q->bind_param('i', $mainVehicle);
            $q->execute();
            $need = (string)($q->get_result()->fetch_row()[0] ?? 'D');
            $q->close();
            if ($need !== 'PSV') { return 'A vendor bus can be used only for a bus that needs a PSV licence.'; }
            if (recommendDrivers($conn, ['start' => $w['start_dt'], 'end' => $w['end_dt'], 'licence' => 'PSV'])['ranked']) {
                return 'A UIS driver with a PSV licence is available for the ' . $label . ' trip, so a vendor bus cannot be used.';
            }
            return null;
        };
        $vend = fn(?array $v) => $v === null ? null : ['name' => trim((string)($v['name'] ?? '')), 'plate' => trim((string)($v['plate'] ?? '')), 'driver' => trim((string)($v['driver'] ?? ''))];
        $vendorOut = $vend($vendorOut); $vendorRet = $vend($vendorRet);
        if ($vendorOut !== null) {
            if (($e = $vendorOk($vendorOut, $win['out'], 'outbound')) !== null) { $conn->rollback(); return $fail($e); }
        } elseif (($e = $checkDriver($out_driver, $mainVehicle, $win['out'], 'outbound')) !== null) { $conn->rollback(); return $fail($e); }
        if ($vendorRet !== null && ($e = $vendorOk($vendorRet, $win['ret'], 'return')) !== null) { $conn->rollback(); return $fail($e); }

        $ins = $conn->prepare(
            "INSERT INTO schedules (driver_id, vehicle_id, trip_date, start_time, end_time, destination, purpose, passenger_count,
                                    status, created_by, notes, officer_name, officer_phone, waiting_place, request_id, leg_no,
                                    vendor_name, vendor_bus_plate, vendor_driver_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $note = 'e-Kenderaan request #' . $request_id;
        $insertTask = function (?int $driver, ?int $vehicle, array $w, int $leg, ?array $vendor = null) use ($ins, $req, $by, $note, $request_id) {
            $dest = $req['destination'];
            $purpose = mb_substr((string)$req['purpose'], 0, 255);
            $pax = (int)$req['passenger_count'];
            $vn = $vendor['name'] ?? null; $vp = $vendor['plate'] ?? null; $vd = $vendor['driver'] ?? null;
            $ins->bind_param('iisssssiissssiisss', $driver, $vehicle, $w['date'], $w['start'], $w['end'], $dest, $purpose, $pax,
                $by, $note, $req['officer_name'], $req['officer_phone'], $req['waiting_place'], $request_id, $leg, $vn, $vp, $vd);
            $ins->execute();
            return (int)$ins->insert_id;
        };
        $outId = $insertTask($vendorOut !== null ? null : $out_driver, $vendorOut !== null ? null : $mainVehicle, $win['out'], 1, $vendorOut);   // a vendor task uses no UIS vehicle

        // return trip
        $retIsVendor = $vendorRet !== null || ($sameReturn && $vendorOut !== null);
        if ($retVehicle !== $mainVehicle && !$retIsVendor) {
            $why = vehicleUnavailableReason($conn, $retVehicle, $win['ret']['start_dt'], $win['ret']['end_dt'], $request_id);
            if ($why !== null) { $conn->rollback(); return $fail('The return vehicle ' . vehicleUnavailableText($why) . '.'); }
            $cap = $conn->prepare('SELECT capacity FROM vehicles WHERE vehicle_id = ?');
            $cap->bind_param('i', $retVehicle);
            $cap->execute();
            $c = (int)($cap->get_result()->fetch_row()[0] ?? 0);
            $cap->close();
            if ($c < (int)$req['passenger_count']) { $conn->rollback(); return $fail('The return vehicle has too few seats for ' . (int)$req['passenger_count'] . ' passengers.'); }
        }
        if ($retIsVendor) {
            $insertTask(null, null, $win['ret'], 2, $vendorRet ?? $vendorOut);
        } else {
            if (($e = $checkDriver($retDriver, $retVehicle, $win['ret'], 'return')) !== null) { $conn->rollback(); return $fail($e); }
            $insertTask($retDriver, $retVehicle, $win['ret'], 2);
        }
        if ($retVehicle !== $mainVehicle && !$retIsVendor) {
            lockVehiclePeriod($conn, $request_id, $retVehicle, $win['ret']['start_dt'], $win['ret']['end_dt']);
        }
        $ins->close();

        $upd = $conn->prepare("UPDATE vehicle_requests SET status = 'assigned', schedule_id = ?, status_reason = NULL WHERE request_id = ? AND status = 'approved'");
        $upd->bind_param('ii', $outId, $request_id);
        $upd->execute();
        $upd->close();
        $conn->commit();
    } catch (RuntimeException $e) {
        $conn->rollback();
        return $fail($e->getMessage());
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('assignRequestDrivers: ' . $e->getMessage());
        return $fail('Database error. Please try again.');
    }
    afterRequestStatusChange($conn, $req, 'assigned', null, false, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['role'] ?? ''));
    return ['ok' => true, 'message' => ($vendorOut !== null || $vendorRet !== null ? 'Vendor bus recorded.' : 'Driver assigned.') . ' The Head of Admin was asked to approve it.'];
}

/** UC019: the Head of Admin approves the assignment -> Confirmed. */
function approveAssignment(mysqli $conn, int $request_id): array
{
    $q = $conn->prepare('SELECT need_driver FROM vehicle_requests WHERE request_id = ?');
    $q->bind_param('i', $request_id);
    $q->execute();
    $withoutDriver = ($row = $q->get_result()->fetch_assoc()) && isRequestWithoutDriver($row);
    $q->close();
    if ($withoutDriver) { return changeRequestStatus($conn, $request_id, 'processed'); }       // UC019 A2: only the vehicle is approved
    $stmt = $conn->prepare("SELECT s.driver_id FROM schedules s WHERE s.request_id = ? AND s.status <> 'cancelled' AND s.driver_id IS NULL AND s.vendor_name IS NULL");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $noDriver = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($noDriver || !requestScheduleIds($conn, $request_id)) {
        return ['ok' => false, 'message' => 'A task of this request has no driver, so it cannot be confirmed.'];
    }
    return changeRequestStatus($conn, $request_id, 'processed');
}

/** UC020: the Head of Admin rejects the assignment (reason required) -> back to Approved, driver removed. */
function rejectAssignment(mysqli $conn, int $request_id, string $reason): array
{
    if (mb_strlen(trim($reason)) < 3) { return ['ok' => false, 'message' => 'Please give a reason for rejecting the assignment.']; }
    return changeRequestStatus($conn, $request_id, 'approved', ['reason' => $reason]);
}

/**
 * UC021: replaces the driver of a task of a CONFIRMED request that has not started.
 * The new driver must have no clash and a reason is required. The change is recorded
 * (old driver, new driver, reason, date), the request goes back to "Driver Assigned" so the
 * Head of Admin approves again, and the old driver, the new driver and the Head of Admin are told.
 */
function replaceTaskDriver(mysqli $conn, int $schedule_id, int $new_driver, string $reason, bool $driverRequest = false): array
{
    $reason = trim($reason);
    if ($driverRequest && $reason === '') { $reason = 'Driver Request'; }      // UC020 A1: the Head of Admin replaces after a schedule change request
    $fail = fn(string $m) => ['ok' => false, 'message' => $m];
    if (mb_strlen($reason) < 3) { return $fail('Please give a reason for replacing the driver.'); }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT s.*, vr.status AS req_status, vr.destination AS req_dest, vr.trip_date AS r_date, vr.start_time AS r_start, vr.return_date, vr.end_time AS r_end,
                    vr.staff_id, vr.supervisor_id
               FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id WHERE s.schedule_id = ? FOR UPDATE"
        );
        $stmt->bind_param('i', $schedule_id);
        $stmt->execute();
        $t = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$t || $t['status'] === 'cancelled') { $conn->rollback(); return $fail('Task not found.'); }
        if ($t['req_status'] !== 'processed') { $conn->rollback(); return $fail('Only a Confirmed task can have its driver replaced.'); }
        if ($t['vendor_name'] !== null) { $conn->rollback(); return $fail('This task is given to a vendor bus, so there is no UIS driver to replace.'); }
        if (strtotime($t['start_dt']) <= time()) { $conn->rollback(); return $fail('The task has already started, so its driver cannot be replaced.'); }
        $old = (int)$t['driver_id'];
        if ($old === $new_driver) { $conn->rollback(); return $fail('The new driver is the same as the current driver.'); }

        $d = $conn->prepare('SELECT name, license_class, status FROM drivers WHERE driver_id = ? FOR UPDATE');
        $d->bind_param('i', $new_driver);
        $d->execute();
        $nd = $d->get_result()->fetch_assoc();
        $d->close();
        if (!$nd || $nd['status'] !== 'active') { $conn->rollback(); return $fail('The new driver is not an active driver.'); }
        $v = $conn->prepare('SELECT license_required FROM vehicles WHERE vehicle_id = ?');
        $vid = (int)$t['vehicle_id'];
        $v->bind_param('i', $vid);
        $v->execute();
        $need = (string)($v->get_result()->fetch_row()[0] ?? 'D');
        $v->close();
        if (!driverHoldsLicence($nd['license_class'], $need)) { $conn->rollback(); return $fail($nd['name'] . ' does not hold licence class ' . $need . '.'); }
        if (($why = driverUnavailableReason($conn, $new_driver, $t['start_dt'], $t['end_dt']) ?? driverClashReason($conn, $new_driver, $t['start_dt'], $t['end_dt'], $schedule_id)) !== null) {
            $conn->rollback();
            return $fail($nd['name'] . ' cannot be chosen: ' . $why . '.');
        }

        $u = $conn->prepare('UPDATE schedules SET driver_id = ? WHERE schedule_id = ?');
        $u->bind_param('ii', $new_driver, $schedule_id);
        $u->execute();
        $u->close();
        $by = (int)($_SESSION['user_id'] ?? 0);
        $log = $conn->prepare('INSERT INTO driver_replacements (schedule_id, previous_driver_id, new_driver_id, reason, changed_by) VALUES (?, ?, ?, ?, ?)');
        $log->bind_param('iiisi', $schedule_id, $old, $new_driver, $reason, $by);
        $log->execute();
        $log->close();
        $rid = (int)$t['request_id'];
        if (!$driverRequest) {                       // normal replacement: the Head of Admin approves the new assignment (UC019)
            $back = $conn->prepare("UPDATE vehicle_requests SET status = 'assigned' WHERE request_id = ? AND status = 'processed'");
            $back->bind_param('i', $rid);
            $back->execute();
            $back->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('replaceTaskDriver: ' . $e->getMessage());
        return $fail('Database error. Please try again.');
    }

    $rid  = (int)$t['request_id'];
    $on = $conn->prepare('SELECT name FROM drivers WHERE driver_id = ?');
    $on->bind_param('i', $old);
    $on->execute();
    $oldN = (string)($on->get_result()->fetch_row()[0] ?? '');
    $on->close();
    auditLog($conn, 'replace_driver', $rid, "Task #$schedule_id: $oldN -> {$nd['name']}. Reason: $reason");
    $what = "request #$rid ({$t['req_dest']}, " . date('d M Y H:i', strtotime($t['start_dt'])) . ')';
    notifyUsers($conn, driverUserIdsFromDriverIds($conn, [$old]), "You were replaced on $what. Reason: $reason", 'driver/schedules.php');
    if ($driverRequest) {                            // UC020 A1: done by the Head of Admin, so the task is Confirmed directly
        notifyUsers($conn, driverUserIdsFromDriverIds($conn, [$new_driver]), "You have a new trip: $what.", 'driver/schedules.php');
        notifyUsers($conn, [(int)$t['staff_id']], "The driver of your $what changed to {$nd['name']}.", 'staff/my_requests.php');
        return ['ok' => true, 'message' => "Driver replaced by {$nd['name']}; the task is Confirmed."];
    }
    notifyUsers($conn, driverUserIdsFromDriverIds($conn, [$new_driver]), "You are assigned to $what, waiting for the Head of Admin to confirm.", 'driver/schedules.php');
    notifyUsers($conn, headAdminUserIds($conn), "The driver of $what was replaced ($oldN -> {$nd['name']}). Reason: $reason. Please approve the new assignment.", 'admin/approve_assignments.php');
    return ['ok' => true, 'message' => 'Driver replaced. The Head of Admin must approve the change.'];
}

// ------------------------------------------------------------
// Comments (UC015): optional, saved with the commenter's name and date, the staff member is told
// ------------------------------------------------------------

function addRequestComment(mysqli $conn, array $req, string $text): array
{
    $text = trim($text);
    if ($text === '') { return ['ok' => false, 'message' => 'The comment is empty.']; }
    $text = mb_substr($text, 0, 1000);
    $uid = (int)$_SESSION['user_id'];
    $role = (string)$_SESSION['role'];
    $rid = (int)$req['request_id'];
    $stmt = $conn->prepare('INSERT INTO request_comments (request_id, user_id, user_role, comment) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $rid, $uid, $role, $text);
    $stmt->execute();
    $stmt->close();
    auditLog($conn, 'comment', $rid, $text);
    if ((int)$req['staff_id'] !== $uid) {
        notifyUsers($conn, [(int)$req['staff_id']], ($_SESSION['full_name'] ?? 'Someone') . " commented on your request #$rid: " . mb_substr($text, 0, 200), 'staff/my_requests.php');
    }
    return ['ok' => true, 'message' => 'Comment saved.'];
}

/** The comments of a request, oldest first, with the commenter's name and role label. */
function requestComments(mysqli $conn, int $request_id): array
{
    $stmt = $conn->prepare(
        'SELECT c.comment, c.created_at, c.user_role, u.full_name FROM request_comments c JOIN users u ON u.user_id = c.user_id
          WHERE c.request_id = ? ORDER BY c.comment_id'
    );
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) { $r['role_label'] = roleLabel($r['user_role']); }
    return $rows;
}

// ------------------------------------------------------------
// Who may see a request
// ------------------------------------------------------------

/**
 * @return array|null the request row when the current user may see it, else null.
 *   staff / driver / any role: their own request.  Head of Department: requests of their own PTJ (they are the approver).
 *   Administrative Assistant / Head of Admin: any request.
 */
function requestForCurrentUser(mysqli $conn, int $request_id): ?array
{
    $stmt = $conn->prepare('SELECT vr.*, s.department AS staff_department, s.full_name AS staff_name, s.role AS staff_role
                              FROM vehicle_requests vr JOIN users s ON s.user_id = vr.staff_id WHERE vr.request_id = ? LIMIT 1');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$req || !isLoggedIn()) { return null; }
    $uid = (int)$_SESSION['user_id'];
    if (isAdmin()) { return $req; }
    if ((int)$req['staff_id'] === $uid) { return $req; }
    if (isSupervisor() && hodOwnsRequest($req)) { return $req; }
    return null;
}

/** Does the signed-in Head of Department own this request (same PTJ, or a Head of Department they head)? */
function hodOwnsRequest(array $req): bool
{
    if ((int)($req['supervisor_id'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) { return false; }
    $dept = $req['staff_department'] ?? $req['department'] ?? null;
    return ($req['staff_role'] ?? '') === 'supervisor' || $dept === null || $dept === ($_SESSION['department'] ?? null);
}

// ------------------------------------------------------------
// Approval reminders.
//   Automatic (UC012): the hourly job reminds the Head of Department ONCE when a request is still Pending after
//   APPROVAL_REMINDER_HOURS (one day). Manual: the Administrative Assistant can also remind by hand (admin/reminders.php).
// ------------------------------------------------------------

const APPROVAL_REMINDER_HOURS = 24;

/**
 * Reminds about a Pending request. Saved in the system and e-mailed; the reminder date is recorded.
 * If the e-mail fails the reminder is still saved and the failure is recorded.
 * $automatic = true (the hourly job): only the Head of Department is reminded and the reminder has no sender.
 * $automatic = false (the Administrative Assistant): the Head of Department AND the staff member are reminded.
 * @return array{ok:bool, message:string, email_error:?string}
 */
function sendApprovalReminder(mysqli $conn, int $request_id, bool $automatic = false): array
{
    $stmt = $conn->prepare("SELECT vr.*, u.full_name AS staff_name FROM vehicle_requests vr JOIN users u ON u.user_id = vr.staff_id WHERE vr.request_id = ?");
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$req || $req['status'] !== 'pending') { return ['ok' => false, 'message' => 'This request is not waiting for approval any more.', 'email_error' => null]; }
    $days = max(0, (int)floor((time() - strtotime($req['created_at'])) / 86400));
    $by = $automatic ? null : (int)$_SESSION['user_id'];
    $hod = (int)($req['supervisor_id'] ?? 0);
    if ($automatic && $hod === 0) { return ['ok' => false, 'message' => 'The request has no Head of Department.', 'email_error' => null]; }
    $err = null;
    $res = notifyUsers($conn, $automatic ? [$hod] : array_filter([(int)$req['staff_id'], $hod]),
        "Reminder: request #$request_id from {$req['staff_name']} to {$req['destination']} has been waiting for approval for $days day(s) and will lapse after " . lapseWindowText($conn) . '.',
        'supervisor/approvals.php', 'reminder');
    if ($res['failed'] > 0) { $err = 'The e-mail could not be sent (' . ($res['errors'][0] ?? 'unknown error') . '). The reminder is saved in the system.'; }
    $stmt = $conn->prepare('INSERT INTO reminders (request_id, sent_by, sent_at, email_error) VALUES (?, ?, NOW(), ?)');
    $stmt->bind_param('iis', $request_id, $by, $err);
    $stmt->execute();
    $stmt->close();
    if ($automatic) { auditLog($conn, 'reminder', $request_id, "Automatic reminder: pending for $days day(s)", null, 'system'); }
    else { auditLog($conn, 'reminder', $request_id, "Pending for $days day(s)"); }
    return ['ok' => true, 'message' => $automatic ? 'Reminder sent to the Head of Department.' : 'Reminder sent to the Head of Department and the staff member.' . ($err ? ' ' . $err : ''), 'email_error' => $err];
}

// ------------------------------------------------------------
// Background jobs: lapse, remind (UC012), cancel what nobody handled, complete finished trips.
// Run hourly: php cron/run_jobs.php
// ------------------------------------------------------------

/** @return array{lapsed:int, reminded:int, cancelled:int, completed:int} */
function runRequestJobs(mysqli $conn): array
{
    $out = ['lapsed' => 0, 'reminded' => 0, 'cancelled' => 0, 'completed' => 0];
    $hours = max(1, getSettingInt($conn, 'LAPSE_HOURS'));

    // 1. Pending for 2 days (or the trip time has passed): Lapsed. The vehicle lock is released.
    $stmt = $conn->prepare("SELECT request_id FROM vehicle_requests WHERE status = 'pending'
                              AND (created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR) OR depart_at <= NOW())");
    $stmt->bind_param('i', $hours);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (changeRequestStatus($conn, (int)$row['request_id'], 'lapsed', ['system' => true])['ok']) { $out['lapsed']++; }
    }
    $stmt->close();

    // 1b. UC012: still Pending after one day and no reminder was sent yet: ONE reminder to the Head of Department
    $remindAfter = date('Y-m-d H:i:s', time() - APPROVAL_REMINDER_HOURS * 3600);
    $stmt = $conn->prepare("SELECT request_id FROM vehicle_requests vr WHERE status = 'pending' AND created_at <= ?
                              AND NOT EXISTS (SELECT 1 FROM reminders r WHERE r.request_id = vr.request_id)");
    $stmt->bind_param('s', $remindAfter);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (sendApprovalReminder($conn, (int)$row['request_id'], true)['ok']) { $out['reminded']++; }
    }
    $stmt->close();

    // 2. Approved / Driver Assigned but the trip time came and nobody confirmed it: cancelled by the system
    $r = $conn->query("SELECT request_id FROM vehicle_requests WHERE status IN ('approved', 'assigned') AND depart_at <= NOW()");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) {
        $res = changeRequestStatus($conn, (int)$row['request_id'], 'cancelled', [
            'system' => true, 'reason' => 'The request was not confirmed before the trip time.',
        ]);
        if ($res['ok']) { $out['cancelled']++; }
    }

    // 3. Confirmed and the trip (and its return task) is over: Completed. The lock is released.
    $r = $conn->query("SELECT vr.request_id FROM vehicle_requests vr WHERE vr.status = 'processed'
                          AND GREATEST(vr.return_at, IFNULL((SELECT MAX(s.end_dt) FROM schedules s WHERE s.request_id = vr.request_id AND s.status <> 'cancelled'), vr.return_at)) < NOW()");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) {
        if (changeRequestStatus($conn, (int)$row['request_id'], 'completed', ['system' => true])['ok']) { $out['completed']++; }
    }
    $conn->query("UPDATE schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id SET s.status = 'completed'
                   WHERE vr.status = 'completed' AND s.status = 'approved'");
    return $out;
}

/** Runs the jobs at most once every 5 minutes, whoever opens a page (works on XAMPP without cron). */
function runRequestJobsThrottled(mysqli $conn): void
{
    $file = sys_get_temp_dir() . '/uis_jobs_last_run_' . md5(__DIR__);   // outside the web folder
    $fh = @fopen($file, 'c+');
    if (!$fh) { return; }
    if (flock($fh, LOCK_EX | LOCK_NB)) {
        $last = (int)trim((string)stream_get_contents($fh));
        if (time() - $last >= 300) {
            try {
                runRequestJobs($conn);
            } finally {
                ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)time());
            }
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

// ------------------------------------------------------------
// UC007 A1: the driver updates the vehicle used for their task (mileage, fuel level, condition)
// ------------------------------------------------------------

const VEHICLE_FUEL_LEVELS = ['empty' => 'Empty', 'quarter' => '1/4', 'half' => '1/2', 'three_quarters' => '3/4', 'full' => 'Full'];

/**
 * The vehicle of one of THIS driver's tasks: a trip task of a Confirmed / Completed request, or an active internal task.
 * $kind is 'trip' (schedule_id) or 'internal' (internal_tasks.task_id). Returns the vehicle row, or null when the driver may not update it.
 */
function driverTaskVehicle(mysqli $conn, int $driver_id, string $kind, int $id): ?array
{
    if ($kind === 'trip') {
        $sql = "SELECT v.* FROM schedules s JOIN vehicles v ON v.vehicle_id = s.vehicle_id
                 WHERE s.schedule_id = ? AND s.driver_id = ? AND s.status <> 'cancelled' AND " . SCHEDULE_CONFIRMED_ONLY_SQL;
    } elseif ($kind === 'internal') {
        $sql = "SELECT v.* FROM internal_tasks t JOIN vehicles v ON v.vehicle_id = t.vehicle_id
                 WHERE t.task_id = ? AND t.driver_id = ? AND t.status = 'active'";
    } else {
        return null;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $id, $driver_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Saves the driver's update (name, date and time are kept) and the new mileage becomes the vehicle's mileage.
 * The new mileage must not be lower than the last recorded one. If a condition issue is entered the Administrative Assistant is told.
 * @return array{ok:bool, message:string}
 */
function recordVehicleUsage(mysqli $conn, int $driver_id, string $kind, int $task_id, $mileage, string $fuel, ?string $issue): array
{
    $veh = driverTaskVehicle($conn, $driver_id, $kind, $task_id);
    if (!$veh) { return ['ok' => false, 'message' => 'You can only update a vehicle that is used for one of your tasks.']; }
    if (!is_numeric($mileage) || (string)(int)$mileage !== trim((string)$mileage) || (int)$mileage < 0 || (int)$mileage > 9999999) {
        return ['ok' => false, 'message' => 'Enter the current mileage as a whole number (km).'];
    }
    $mileage = (int)$mileage;
    if (!isset(VEHICLE_FUEL_LEVELS[$fuel])) { return ['ok' => false, 'message' => 'Choose the fuel level.']; }
    $issue = $issue === null ? null : trim($issue);
    if ($issue !== null && mb_strlen($issue) > 500) { return ['ok' => false, 'message' => 'Describe the condition issue in at most 500 characters.']; }
    $issue = ($issue === '' ? null : $issue);
    $vehicle_id = (int)$veh['vehicle_id'];
    $sched = $kind === 'trip' ? $task_id : null;
    $task  = $kind === 'internal' ? $task_id : null;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT mileage FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
        $stmt->bind_param('i', $vehicle_id);
        $stmt->execute();
        $last = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        if ($mileage < $last) {
            $conn->rollback();
            return ['ok' => false, 'message' => "The mileage is lower than the last recorded mileage ($last km)."];
        }
        $stmt = $conn->prepare('INSERT INTO vehicle_usage_log (vehicle_id, driver_id, schedule_id, internal_task_id, mileage, fuel_level, condition_issue) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiiiiss', $vehicle_id, $driver_id, $sched, $task, $mileage, $fuel, $issue);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('UPDATE vehicles SET mileage = ? WHERE vehicle_id = ?');
        $stmt->bind_param('ii', $mileage, $vehicle_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('recordVehicleUsage: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Database error. Please try again.'];
    }
    if ($issue !== null) {
        $q = $conn->prepare('SELECT name FROM drivers WHERE driver_id = ?');
        $q->bind_param('i', $driver_id);
        $q->execute();
        $name = $q->get_result()->fetch_row()[0] ?? 'A driver';
        $q->close();
        notifyUsers($conn, assistantUserIds($conn), "$name reported a condition issue on {$veh['plate_number']}: $issue", 'admin/view_vehicle.php?id=' . $vehicle_id);
    }
    return ['ok' => true, 'message' => 'The vehicle was updated.' . ($issue !== null ? ' The Administrative Assistant was told about the condition issue.' : '')];
}

// ------------------------------------------------------------
// UC029 Request Schedule Change (driver) and UC030 Approve/Reject Schedule Change (Head of Admin)
//   type "unavailable": the driver cannot work some dates; once approved the driver is not recommended or assigned on them
//   type "withdraw":    the driver asks to be withdrawn from ONE confirmed task; the task stays with the driver until the Head of Admin
//                       approves and replaces the driver (UC020)
// ------------------------------------------------------------

const SCHEDULE_CHANGE_MAX_DAYS = 31;

/** Is the driver marked unavailable on a day of this period (an approved Unavailable Date request)? Returns the reason text, or null. */
function driverUnavailableReason(mysqli $conn, int $driver_id, string $start, string $end): ?string
{
    $from = substr($start, 0, 10);
    $to = substr($end, 0, 10);
    $stmt = $conn->prepare(
        "SELECT d.change_date FROM schedule_change_dates d JOIN schedule_change_requests r ON r.change_id = d.change_id
          WHERE r.driver_id = ? AND r.change_type = 'unavailable' AND r.status = 'approved' AND d.change_date BETWEEN ? AND ?
          ORDER BY d.change_date LIMIT 1"
    );
    $stmt->bind_param('iss', $driver_id, $from, $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? 'Marked unavailable on ' . date('d M Y', strtotime($row['change_date'])) : null;
}

/** Every day from $from to $to (Y-m-d, inclusive). Empty when a date is invalid or the range is backwards. */
function scheduleChangeDates(string $from, string $to): array
{
    if (!isValidDate($from) || !isValidDate($to) || $to < $from) { return []; }
    $out = [];
    for ($d = strtotime($from); $d <= strtotime($to) && count($out) <= SCHEDULE_CHANGE_MAX_DAYS; $d += 86400) { $out[] = date('Y-m-d', $d); }
    return $out;
}

/**
 * UC029: saves the request with the status Pending and tells the Head of Admin (system + e-mail).
 * $dates: the days of an "unavailable" request. $schedule_id: the task of a "withdraw" request.
 * @return array{ok:bool, message:string, change_id?:int}
 */
function createScheduleChangeRequest(mysqli $conn, int $driver_id, string $type, array $dates, ?int $schedule_id, string $reason): array
{
    $fail = fn(string $m) => ['ok' => false, 'message' => $m];
    $reason = trim($reason);
    if (!in_array($type, ['unavailable', 'withdraw'], true)) { return $fail('Choose the type of request.'); }
    if ($reason === '') { return $fail('Please enter the reason.'); }
    $reason = mb_substr($reason, 0, 500);
    $today = date('Y-m-d');

    $task = null;
    if ($type === 'withdraw') {
        $stmt = $conn->prepare(
            "SELECT s.schedule_id, s.start_dt, s.end_dt, vr.destination FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id
              WHERE s.schedule_id = ? AND s.driver_id = ? AND s.status <> 'cancelled' AND vr.status = 'processed'"
        );
        $sid = (int)$schedule_id;
        $stmt->bind_param('ii', $sid, $driver_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$task) { return $fail('Choose one of your confirmed tasks.'); }
        if (strtotime($task['start_dt']) <= time()) { return $fail('The task has already started or passed.'); }
        $dates = [substr($task['start_dt'], 0, 10)];
    } else {
        $dates = array_values(array_unique(array_filter(array_map('strval', $dates), 'isValidDate')));
        sort($dates);
        if (!$dates) { return $fail('Choose the date or dates.'); }
        if (count($dates) > SCHEDULE_CHANGE_MAX_DAYS) { return $fail('Choose at most ' . SCHEDULE_CHANGE_MAX_DAYS . ' days in one request.'); }
        if ($dates[0] < $today) { return $fail('The selected date has already passed.'); }
    }

    $conn->begin_transaction();
    try {
        // no second Pending request for the same date or task
        if ($type === 'withdraw') {
            $stmt = $conn->prepare("SELECT 1 FROM schedule_change_requests WHERE driver_id = ? AND change_type = 'withdraw' AND status = 'pending' AND schedule_id = ? FOR UPDATE");
            $sid = (int)$schedule_id;
            $stmt->bind_param('ii', $driver_id, $sid);
            $stmt->execute();
            $dup = (bool)$stmt->get_result()->fetch_row();
            $stmt->close();
            if ($dup) { $conn->rollback(); return $fail('A pending request already exists for this task.'); }
        } else {
            $in = implode(',', array_fill(0, count($dates), '?'));
            $stmt = $conn->prepare("SELECT d.change_date FROM schedule_change_dates d JOIN schedule_change_requests r ON r.change_id = d.change_id
                                     WHERE r.driver_id = ? AND r.change_type = 'unavailable' AND r.status = 'pending' AND d.change_date IN ($in) ORDER BY d.change_date LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i' . str_repeat('s', count($dates)), $driver_id, ...$dates);
            $stmt->execute();
            $dupRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dupRow) { $conn->rollback(); return $fail('A pending request already exists for ' . date('d M Y', strtotime($dupRow['change_date'])) . '.'); }
        }
        $stmt = $conn->prepare('INSERT INTO schedule_change_requests (driver_id, change_type, schedule_id, reason) VALUES (?, ?, ?, ?)');
        $sid = $type === 'withdraw' ? (int)$schedule_id : null;
        $stmt->bind_param('isis', $driver_id, $type, $sid, $reason);
        $stmt->execute();
        $cid = (int)$stmt->insert_id;
        $stmt->close();
        $stmt = $conn->prepare('INSERT INTO schedule_change_dates (change_id, change_date) VALUES (?, ?)');
        foreach ($dates as $d) {
            $stmt->bind_param('is', $cid, $d);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('createScheduleChangeRequest: ' . $e->getMessage());
        return $fail('Database error. Please try again.');
    }

    $q = $conn->prepare('SELECT name FROM drivers WHERE driver_id = ?');
    $q->bind_param('i', $driver_id);
    $q->execute();
    $name = (string)($q->get_result()->fetch_row()[0] ?? 'A driver');
    $q->close();
    $what = $type === 'withdraw' ? 'to be withdrawn from the task on ' . date('d M Y H:i', strtotime($task['start_dt'])) . ' (' . $task['destination'] . ')'
                                 : 'to be unavailable on ' . scheduleChangeDatesText($dates);
    auditLog($conn, 'schedule_change_request', null, "Request #$cid by $name $what. Reason: $reason");
    notifyUsers($conn, headAdminUserIds($conn), "$name asks $what. Reason: $reason", 'admin/schedule_changes.php');
    return ['ok' => true, 'message' => 'Your request was sent to the Head of Admin.', 'change_id' => $cid];
}

/** "25 Oct 2026" or "25, 26 and 28 Oct 2026" style text for a list of days. */
function scheduleChangeDatesText(array $dates): string
{
    $dates = array_values($dates);
    if (count($dates) === 1) { return date('d M Y', strtotime($dates[0])); }
    return date('d M Y', strtotime($dates[0])) . ' to ' . date('d M Y', strtotime($dates[count($dates) - 1])) . ' (' . count($dates) . ' days)';
}

/** The days of a request. */
function scheduleChangeDatesOf(mysqli $conn, int $change_id): array
{
    $stmt = $conn->prepare('SELECT change_date FROM schedule_change_dates WHERE change_id = ? ORDER BY change_date');
    $stmt->bind_param('i', $change_id);
    $stmt->execute();
    $out = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'change_date');
    $stmt->close();
    return $out;
}

/** The driver's tasks that a request touches (UC029 2.1 and UC030 2.1): trips of this driver on the days, or the withdraw task. */
function scheduleChangeAffectedTasks(mysqli $conn, array $change): array
{
    if ($change['change_type'] === 'withdraw') {
        $stmt = $conn->prepare(
            "SELECT s.schedule_id, s.request_id, s.leg_no, s.start_dt, s.end_dt, vr.destination, vr.status AS req_status
               FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id WHERE s.schedule_id = ? AND s.status <> 'cancelled'"
        );
        $sid = (int)$change['schedule_id'];
        $stmt->bind_param('i', $sid);
    } else {
        $stmt = $conn->prepare(
            "SELECT DISTINCT s.schedule_id, s.request_id, s.leg_no, s.start_dt, s.end_dt, vr.destination, vr.status AS req_status
               FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id
               JOIN schedule_change_dates d ON d.change_id = ? AND d.change_date BETWEEN DATE(s.start_dt) AND DATE(s.end_dt)
              WHERE s.driver_id = ? AND s.status <> 'cancelled' AND vr.status IN ('assigned', 'processed') AND s.end_dt > NOW() ORDER BY s.start_dt"
        );
        $cid = (int)$change['change_id']; $did = (int)$change['driver_id'];
        $stmt->bind_param('ii', $cid, $did);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * UC030: the Head of Admin approves or rejects a Pending request (the comment is UC016: required when rejecting).
 * Approve: the driver is marked unavailable on the days (unavailable type) and the driver and the Administrative Assistant are told.
 * Reject: the driver is told the reason and the task stays with the driver.
 * @return array{ok:bool, message:string, tasks?:array}  tasks = the Confirmed tasks whose driver must be replaced now (UC020)
 */
function decideScheduleChange(mysqli $conn, int $change_id, string $decision, string $comment): array
{
    $fail = fn(string $m) => ['ok' => false, 'message' => $m];
    $comment = trim($comment);
    if (!in_array($decision, ['approve', 'reject'], true)) { return $fail('Choose Approve or Reject.'); }
    if ($decision === 'reject' && mb_strlen($comment) < 3) { return $fail('Please enter the reason for rejecting.'); }
    $comment = mb_substr($comment, 0, 500);
    $actor = (int)($_SESSION['user_id'] ?? 0);
    $status = $decision === 'approve' ? 'approved' : 'rejected';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT r.*, d.name AS driver_name FROM schedule_change_requests r JOIN drivers d ON d.driver_id = r.driver_id WHERE r.change_id = ? FOR UPDATE');
        $stmt->bind_param('i', $change_id);
        $stmt->execute();
        $ch = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ch) { $conn->rollback(); return $fail('Request not found.'); }
        if ($ch['status'] !== 'pending') { $conn->rollback(); return $fail('This request was already ' . $ch['status'] . '.'); }
        $com = $comment === '' ? null : $comment;
        $stmt = $conn->prepare("UPDATE schedule_change_requests SET status = ?, decision_comment = ?, decided_by = ?, decided_at = NOW() WHERE change_id = ? AND status = 'pending'");
        $stmt->bind_param('ssii', $status, $com, $actor, $change_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('decideScheduleChange: ' . $e->getMessage());
        return $fail('Database error. Please try again.');
    }

    $dates = scheduleChangeDatesOf($conn, $change_id);
    $what = $ch['change_type'] === 'withdraw' ? 'to be withdrawn from the task on ' . scheduleChangeDatesText($dates) : 'to be unavailable on ' . scheduleChangeDatesText($dates);
    $du = userIdOfDriver($conn, (int)$ch['driver_id']);
    $why = $com !== null ? ' Comment: ' . $com : '';
    $tasks = [];
    if ($decision === 'approve') {
        auditLog($conn, 'schedule_change_approve', null, "Request #$change_id of {$ch['driver_name']} approved.$why");
        if ($du) { notifyUsers($conn, [$du], "Your request $what was approved.$why", 'driver/schedule_change.php'); }
        notifyUsers($conn, assistantUserIds($conn), "{$ch['driver_name']}'s request $what was approved." . ($ch['change_type'] === 'withdraw' ? ' The driver is replaced on the task.' : ' The driver is not offered on those days.'), 'admin/vehicle_requests.php');
        foreach (scheduleChangeAffectedTasks($conn, $ch) as $t) { if ($t['req_status'] === 'processed') { $tasks[] = $t; } }
    } else {
        auditLog($conn, 'schedule_change_reject', null, "Request #$change_id of {$ch['driver_name']} rejected.$why");
        if ($du) { notifyUsers($conn, [$du], "Your request $what was rejected.$why The task stays with you.", 'driver/schedule_change.php'); }
    }
    return ['ok' => true, 'message' => 'Request ' . $status . '.', 'tasks' => $tasks];
}
