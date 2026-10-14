<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/workflow.php  -  the business rules of a vehicle request
//
//   * Settings        editable values (weights, lapse hours, ...)
//   * Audit log       insert-only record of important actions (R10)
//   * Notifications   the bell (in-app) + e-mail to people
//   * Status engine   ONE place that says which status changes are allowed,
//                     and does everything a change needs (hold release,
//                     audit entry, notification with the reason)
//   * Background jobs lapse after 48 h, auto-complete, auto-reminder (R11)
//
// Loaded by config/database.php, so every page can use these functions.
// ============================================================

// ------------------------------------------------------------
// Settings (table `settings`)
// ------------------------------------------------------------

/** Defaults used when a setting row does not exist yet. */
const SETTING_DEFAULTS = [
    'W_TASKS'             => '0.6',
    'W_AFTER_HOURS'       => '0.4',
    'WELFARE_PENALTY'     => '20',
    'LAPSE_HOURS'         => '48',
    'REMINDER_AFTER_HOURS'=> '24',
    'MIN_DAYS_AHEAD'      => '2',
    'WORK_START'          => '08:00',
    'WORK_END'            => '17:30',
    'SESSION_TIMEOUT_MIN' => '15',
    'MAX_FAILED_ATTEMPTS' => '5',
];

/**
 * Reads one setting. The whole table is loaded once per request.
 */
function getSetting(mysqli $conn, string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null || isset($GLOBALS['__settings_dirty'])) {
        $cache = [];
        try {
            $r = $conn->query('SELECT setting_key, setting_value FROM settings');
            while ($r && ($row = $r->fetch_assoc())) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            // table not created yet: the defaults below are used
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

// ------------------------------------------------------------
// Audit log (R10): INSERT ONLY. There is deliberately no function
// that updates or deletes a row, and database triggers refuse it too.
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
        if ($reason !== null) {
            $reason = mb_substr($reason, 0, 500);
        }
        $stmt = $conn->prepare('INSERT INTO audit_log (user_id, user_role, action, request_id, reason) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('issis', $user_id, $role, $action, $request_id, $reason);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('auditLog: ' . $e->getMessage());   // never block the real action
    }
}

// ------------------------------------------------------------
// Notifications: the bell, plus an e-mail copy
// ------------------------------------------------------------

/**
 * Tells one or more users. $link is a page below SITE_URL, e.g. 'staff/my_requests.php'.
 *
 * @param int|int[] $user_ids
 */
function notifyUsers(mysqli $conn, $user_ids, string $message, ?string $link = null, bool $email = true): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$user_ids))));
    foreach ($ids as $uid) {
        try {
            $msg  = mb_substr($message, 0, 500);
            $stmt = $conn->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $uid, $msg, $link);
            $stmt->execute();
            $stmt->close();

            if ($email) {
                $u = $conn->query('SELECT email, full_name FROM users WHERE user_id = ' . $uid)->fetch_assoc();
                if ($u && !empty($u['email']) && function_exists('sendMail')) {
                    $url  = $link ? rtrim(SITE_URL, '/') . '/' . ltrim($link, '/') : SITE_URL;
                    $html = '<p>Salam ' . htmlspecialchars((string)$u['full_name']) . ',</p><p>' . htmlspecialchars($msg)
                          . '</p><p><a href="' . htmlspecialchars($url) . '">Open in the system</a></p><p>UIS Transport Unit</p>';
                    $text = "Salam {$u['full_name']},\n\n$msg\n\nOpen: $url\n\nUIS Transport Unit";
                    sendMail($conn, $u['email'], '[UIS Transport] ' . mb_substr($msg, 0, 70), $html, $text, ['kind' => 'notice']);
                }
            }
        } catch (Throwable $e) {
            error_log('notifyUsers: ' . $e->getMessage());
        }
    }
}

/** Ids of every admin (the Vehicle Section). */
function adminUserIds(mysqli $conn): array
{
    $ids = [];
    $r = $conn->query("SELECT user_id FROM users WHERE role IN ('admin','superadmin','head_admin') AND is_active = 1");
    while ($r && ($row = $r->fetch_assoc())) { $ids[] = (int)$row['user_id']; }
    return $ids;
}

/** Ids of the Head of Admin account(s). */
function headAdminUserIds(mysqli $conn): array
{
    $ids = [];
    $r = $conn->query("SELECT user_id FROM users WHERE role = 'head_admin' AND is_active = 1");
    while ($r && ($row = $r->fetch_assoc())) { $ids[] = (int)$row['user_id']; }
    return $ids;
}

/**
 * SQL condition (table alias `s` = schedules) that hides a schedule from the driver until the
 * request behind it is Confirmed. A trip that still waits for the Head of Admin is not final.
 */
const SCHEDULE_CONFIRMED_ONLY_SQL = "NOT EXISTS (SELECT 1 FROM vehicle_requests vr_c JOIN schedules f_c ON f_c.schedule_id = vr_c.schedule_id
        WHERE vr_c.status = 'assigned' AND (f_c.schedule_id = s.schedule_id OR (f_c.job_group IS NOT NULL AND f_c.job_group = s.job_group)))";

/** The page a role opens to see its requests. */
function requestPageForRole(string $role): string
{
    return match ($role) {
        'staff'      => 'staff/my_requests.php',
        'supervisor' => 'supervisor/approvals.php',
        'admin', 'superadmin', 'head_admin' => 'admin/vehicle_requests.php',
        default      => 'driver/dashboard.php',
    };
}

/** Turns a stored link into a full address. */
function notificationUrl(?string $link): string
{
    return $link ? rtrim(SITE_URL, '/') . '/' . ltrim($link, '/') : SITE_URL . '/notifications.php';
}

// ------------------------------------------------------------
// Request status engine
//
//   pending   -> approved | rejected | lapsed | cancelled
//   approved  -> assigned  (Admin Assistant assigned vehicle + driver; waits for the Head of Admin)
//             -> processed (Head of Admin assigned it himself: Confirmed at once)
//   assigned  -> processed (Head of Admin approves the assignment) | cancelled
//   processed -> completed (system, after the trip) | cancelled
//   (the stored value 'processed' is shown to people as "Confirmed", 'assigned' as "Driver Assigned")
//   rejected, lapsed, completed, cancelled: final
// ------------------------------------------------------------

const REQUEST_TRANSITIONS = [
    'pending'   => ['approved', 'rejected', 'lapsed', 'cancelled'],
    'approved'  => ['assigned', 'processed', 'cancelled'],   // assigned: Admin Assistant; processed (Confirmed): Head of Admin directly
    'assigned'  => ['processed', 'cancelled'],               // Head of Admin approves the assignment
    'processed' => ['completed', 'cancelled'],
    'rejected'  => [], 'lapsed' => [], 'completed' => [], 'cancelled' => [],
];

/** Statuses in which the request still needs its vehicle (so it keeps the day holds). */
const REQUEST_ACTIVE_STATUSES = ['pending', 'approved', 'assigned', 'processed'];

function canChangeRequestStatus(string $from, string $to): bool
{
    return in_array($to, REQUEST_TRANSITIONS[$from] ?? [], true);
}

/** Ids of the schedule rows that belong to a processed request (several when it is a multi-vehicle job). */
function requestScheduleIds(mysqli $conn, int $request_id): array
{
    $row = $conn->query('SELECT schedule_id FROM vehicle_requests WHERE request_id = ' . $request_id)->fetch_assoc();
    if (!$row || empty($row['schedule_id'])) { return []; }
    $first = (int)$row['schedule_id'];
    $g = $conn->query('SELECT job_group FROM schedules WHERE schedule_id = ' . $first)->fetch_assoc();
    if ($g && $g['job_group'] !== null) {
        $ids = [];
        $r = $conn->query('SELECT schedule_id FROM schedules WHERE job_group = ' . (int)$g['job_group']);
        while ($r && ($s = $r->fetch_assoc())) { $ids[] = (int)$s['schedule_id']; }
        return $ids;
    }
    return [$first];
}

/**
 * Moves a request to a new status. EVERY status change goes through here.
 *   - checks the move is allowed and nobody else changed it meanwhile (row lock)
 *   - gives the vehicle back (day holds) when the request is no longer active
 *   - cancelling a processed request also cancels its schedules (frees the drivers)
 *   - writes the audit log and tells the requester WITH the reason
 *
 * $o: reason, actor_id, actor_role, system (bool), only_if_supervisor (int user id that must be the approver)
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

    $conn->begin_transaction();
    try {
        $req = $conn->query('SELECT * FROM vehicle_requests WHERE request_id = ' . $request_id . ' FOR UPDATE')->fetch_assoc();
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
            return ['ok' => false, 'message' => 'This request is already ' . $req['status'] . ' and cannot become ' . $to . '.'];
        }

        $sets = ['status = ?', 'status_reason = ?'];
        $args = [$to, $reason];
        $types = 'ss';
        if ($to === 'approved' || $to === 'rejected') {
            $sets[] = 'reviewed_at = NOW()';
            if ($to === 'rejected') { $sets[] = 'supervisor_notes = ?'; $args[] = (string)$reason; $types .= 's'; }
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
            releaseVehicleHolds($conn, $request_id);
        }
        if ($to === 'cancelled' && in_array($req['status'], ['assigned', 'processed'], true)) {
            // The drivers' tasks disappear from their lists (cancelled schedules are not shown as work)
            $ids = requestScheduleIds($conn, $request_id);
            if ($ids) {
                $conn->query("UPDATE schedules SET status = 'cancelled' WHERE schedule_id IN (" . implode(',', array_map('intval', $ids)) . ")");
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('changeRequestStatus: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Database error. Please try again.'];
    }

    // Everything below happens after the change is saved, and may never undo it.
    $action = ['approved' => 'approve', 'rejected' => 'reject', 'cancelled' => 'cancel', 'lapsed' => 'lapse',
               'assigned' => 'assign', 'processed' => 'confirm', 'completed' => 'complete'][$to];
    $system ? auditLog($conn, $action, $request_id, $reason, null, 'system')
            : auditLog($conn, $action, $request_id, $reason, $actor, $role);

    $what = "request #{$request_id} ({$req['destination']}, " . date('d M Y', strtotime($req['trip_date'])) . ')';
    $why  = $reason ? ' Reason: ' . $reason : '';
    $msg  = match ($to) {
        'approved'  => "Your $what was approved by your Head of Section. The transport unit will arrange the vehicle and driver.",
        'rejected'  => "Your $what was rejected.$why",
        'lapsed'    => "Your $what lapsed because it was not approved within " . lapseWindowText($conn) . '.',
        'cancelled' => "Your $what was cancelled." . ($system ? '' : ' By: ' . requestActorLabel($role)) . $why,
        'assigned'  => "A vehicle and driver were assigned to your $what. It is waiting for the Head of Admin to confirm.",
        'processed' => "Your $what is confirmed. The vehicle and driver are ready.",
        'completed' => "Your $what is completed.",
        default     => null,
    };
    if ($msg !== null) {
        notifyUsers($conn, [(int)$req['staff_id']], $msg, 'staff/my_requests.php');
    }
    if ($to === 'cancelled' && $req['status'] === 'processed') {
        // tell the drivers who were on it (they only knew about the trip once it was Confirmed)
        $ids = requestScheduleIds($conn, $request_id);
        if ($ids) {
            $r = $conn->query("SELECT DISTINCT u.user_id FROM schedules s JOIN users u ON u.driver_id = s.driver_id WHERE s.schedule_id IN (" . implode(',', array_map('intval', $ids)) . ")");
            $drv = [];
            while ($r && ($row = $r->fetch_assoc())) { $drv[] = (int)$row['user_id']; }
            notifyUsers($conn, $drv, "The trip for $what was cancelled.$why", 'driver/schedules.php');
        }
    }
    if ($to === 'cancelled' && !empty($req['supervisor_id']) && (int)$req['supervisor_id'] !== (int)$actor) {
        notifyUsers($conn, [(int)$req['supervisor_id']], "$what was cancelled." . $why, 'supervisor/approvals.php');
    }
    if ($to === 'assigned') {
        notifyUsers($conn, headAdminUserIds($conn), ucfirst($what) . ' has a driver assigned and needs your approval.', 'admin/approve_assignments.php');
    }
    if ($to === 'processed') {
        // the drivers learn about the trip only now (before this it was not final)
        $ids = requestScheduleIds($conn, $request_id);
        if ($ids) {
            $r = $conn->query("SELECT DISTINCT u.user_id FROM schedules s JOIN users u ON u.driver_id = s.driver_id WHERE s.schedule_id IN (" . implode(',', array_map('intval', $ids)) . ")");
            $drv = [];
            while ($r && ($row = $r->fetch_assoc())) { $drv[] = (int)$row['user_id']; }
            notifyUsers($conn, $drv, "You have a new trip: $what.", 'driver/schedules.php');
        }
    }
    if ($to === 'approved') {
        notifyUsers($conn, adminUserIds($conn), ucfirst($what) . ' is approved and needs a vehicle and driver.', 'admin/vehicle_requests.php');
    }
    return ['ok' => true, 'message' => 'Request ' . $to . '.'];
}

function requestActorLabel(string $role): string
{
    return match ($role) {
        'staff' => 'the requester', 'supervisor' => 'the Head of Section',
        'admin', 'superadmin' => 'the Admin Assistant', 'head_admin' => 'the Head of Admin', 'driver' => 'the requester',
        'system' => 'the system', default => $role,
    };
}

/** "two days" / "48 hours" for messages. */
function lapseWindowText(mysqli $conn): string
{
    $h = max(1, getSettingInt($conn, 'LAPSE_HOURS'));
    return $h % 24 === 0 ? (($h / 24) === 1 ? 'one day' : ($h / 24 === 2 ? 'two days' : ($h / 24) . ' days')) : "$h hours";
}

// ------------------------------------------------------------
// Vehicle day holds (R3) - filled in when a request is submitted
// ------------------------------------------------------------

function releaseVehicleHolds(mysqli $conn, int $request_id): void
{
    $conn->query('DELETE FROM vehicle_day_holds WHERE request_id = ' . $request_id);
}

/**
 * Vehicles that cannot be used on a date (R4): in maintenance, or held by another request.
 * The UNIQUE(vehicle_id, hold_date) key of vehicle_day_holds is what makes a double booking impossible;
 * this function only lets the screens say so BEFORE the user presses Submit.
 *
 * @param int $except_request_id holds of this request do not count (it is the one being processed)
 * @return array<int,string> vehicle_id => 'maintenance' | 'held'
 */
function blockedVehicleIds(mysqli $conn, string $date, int $except_request_id = 0): array
{
    $out = [];
    try {
        $stmt = $conn->prepare('SELECT DISTINCT vehicle_id FROM vehicle_maintenance WHERE ? BETWEEN start_date AND end_date');
        $stmt->bind_param('s', $date);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $out[(int)$r['vehicle_id']] = 'maintenance'; }
        $stmt->close();

        $stmt = $conn->prepare('SELECT DISTINCT vehicle_id FROM vehicle_day_holds WHERE hold_date = ? AND request_id <> ?');
        $stmt->bind_param('si', $date, $except_request_id);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int)$r['vehicle_id']] = $out[(int)$r['vehicle_id']] ?? 'held';
        }
        $stmt->close();
    } catch (Throwable $e) {
        // tables not created yet: nothing is blocked
    }
    return $out;
}

/** Human text for a block reason. */
function vehicleBlockText(string $reason): string
{
    return $reason === 'maintenance' ? 'under maintenance on this date' : 'already booked for this day by another request';
}

/**
 * R3: hold the vehicle(s) for the day of the request. Run inside the same transaction as the
 * request. If someone else got the vehicle first the UNIQUE key refuses the row; that becomes a
 * clear message instead of a crash.
 *
 * @throws RuntimeException with a message that can be shown to the user
 */
function holdVehiclesForRequest(mysqli $conn, int $request_id, array $vehicle_ids, string $date): void
{
    $conn->query('DELETE FROM vehicle_day_holds WHERE request_id = ' . $request_id);
    $stmt = $conn->prepare('INSERT INTO vehicle_day_holds (vehicle_id, hold_date, request_id) VALUES (?, ?, ?)');
    foreach (array_unique(array_map('intval', $vehicle_ids)) as $vid) {
        if ($vid <= 0) { continue; }
        try {
            $stmt->bind_param('isi', $vid, $date, $request_id);
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            if ((int)$e->getCode() === 1062) {
                throw new RuntimeException('Sorry, a vehicle was just booked by someone else for that day. Please choose another vehicle.');
            }
            throw $e;
        }
    }
    $stmt->close();
}

/** The vehicle request a schedule was made from (0 if the schedule was created directly by an admin). */
function requestIdOfSchedule(mysqli $conn, int $schedule_id): int
{
    try {
        $row = $conn->query("SELECT vr.request_id FROM vehicle_requests vr
                              WHERE vr.schedule_id = {$schedule_id}
                                 OR vr.schedule_id IN (SELECT s2.schedule_id FROM schedules s2
                                      WHERE s2.job_group IS NOT NULL
                                        AND s2.job_group = (SELECT job_group FROM schedules WHERE schedule_id = {$schedule_id}))
                              LIMIT 1")->fetch_assoc();
        return $row ? (int)$row['request_id'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * After a schedule of a request was created, changed or removed: make the day holds match
 * the vehicles that schedule(s) really use.
 */
function syncHoldsForSchedule(mysqli $conn, int $schedule_id): void
{
    try {
        $row = $conn->query("SELECT vr.request_id, vr.trip_date, vr.status FROM vehicle_requests vr
                              WHERE vr.schedule_id = {$schedule_id}
                                 OR vr.schedule_id IN (SELECT s2.schedule_id FROM schedules s2
                                      WHERE s2.job_group IS NOT NULL
                                        AND s2.job_group = (SELECT job_group FROM schedules WHERE schedule_id = {$schedule_id}))
                              LIMIT 1")->fetch_assoc();
        if (!$row || !in_array($row['status'], REQUEST_ACTIVE_STATUSES, true)) { return; }
        $ids = requestScheduleIds($conn, (int)$row['request_id']);
        if (!$ids) { return; }
        $vehicles = [];
        $r = $conn->query("SELECT vehicle_id FROM schedules WHERE schedule_id IN (" . implode(',', array_map('intval', $ids)) . ") AND vehicle_id IS NOT NULL AND status <> 'cancelled'");
        while ($r && ($v = $r->fetch_assoc())) { $vehicles[] = (int)$v['vehicle_id']; }
        holdVehiclesForRequest($conn, (int)$row['request_id'], $vehicles, $row['trip_date']);
    } catch (Throwable $e) {
        error_log('syncHoldsForSchedule: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// Background jobs (R11)
// ------------------------------------------------------------

/**
 * Lapse old pending requests, complete finished trips, send the one automatic reminder.
 * @return array{lapsed:int, completed:int, reminded:int}
 */
function runRequestJobs(mysqli $conn): array
{
    $out = ['lapsed' => 0, 'completed' => 0, 'reminded' => 0];
    $hours = max(1, getSettingInt($conn, 'LAPSE_HOURS'));

    // 1. Pending too long (or the trip time has already passed)
    $r = $conn->query("SELECT request_id FROM vehicle_requests WHERE status = 'pending'
                         AND (created_at <= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
                              OR TIMESTAMP(trip_date, start_time) <= NOW())");
    while ($r && ($row = $r->fetch_assoc())) {
        $res = changeRequestStatus($conn, (int)$row['request_id'], 'lapsed', [
            'system' => true,
            'reason' => 'Your request lapsed because it was not approved within ' . lapseWindowText($conn) . '.',
        ]);
        if ($res['ok']) { $out['lapsed']++; }
    }

    // 2. Trip finished. A trip the Head of Admin never confirmed is cancelled: it was never final.
    $r = $conn->query("SELECT request_id FROM vehicle_requests WHERE status = 'assigned'
                         AND TIMESTAMP(trip_date, start_time) <= NOW()");
    while ($r && ($row = $r->fetch_assoc())) {
        changeRequestStatus($conn, (int)$row['request_id'], 'cancelled', [
            'system' => true, 'reason' => 'The driver assignment was not confirmed before the trip time.',
        ]);
    }
    $r = $conn->query("SELECT request_id FROM vehicle_requests WHERE status = 'processed'
                         AND TIMESTAMP(trip_date, end_time) < NOW()");
    while ($r && ($row = $r->fetch_assoc())) {
        if (changeRequestStatus($conn, (int)$row['request_id'], 'completed', ['system' => true])['ok']) { $out['completed']++; }
    }

    // 3. One automatic reminder to the Head of Section
    $after = max(1, getSettingInt($conn, 'REMINDER_AFTER_HOURS'));
    $r = $conn->query("SELECT vr.request_id, vr.supervisor_id, vr.destination, s.full_name
                         FROM vehicle_requests vr JOIN users s ON s.user_id = vr.staff_id
                        WHERE vr.status = 'pending' AND vr.supervisor_id IS NOT NULL
                          AND vr.created_at <= DATE_SUB(NOW(), INTERVAL {$after} HOUR)
                          AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.request_id = vr.request_id AND a.action = 'auto_reminder')");
    while ($r && ($row = $r->fetch_assoc())) {
        $id = (int)$row['request_id'];
        notifyUsers($conn, [(int)$row['supervisor_id']],
            "Reminder: request #{$id} from {$row['full_name']} to {$row['destination']} is still waiting for your decision and will lapse soon.",
            'supervisor/approvals.php');
        auditLog($conn, 'auto_reminder', $id, "Automatic reminder after {$after} hours", null, 'system');
        $out['reminded']++;
    }
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
// Who may see / write comments on a request (R9: comments are add-only)
// ------------------------------------------------------------

/**
 * @return array|null the request row when the current user may see it, else null.
 *   staff: their own request. supervisor: where they are the approver or the same department. admin: any.
 */
function requestForCurrentUser(mysqli $conn, int $request_id): ?array
{
    $stmt = $conn->prepare('SELECT vr.*, s.department AS staff_department, s.full_name AS staff_name
                              FROM vehicle_requests vr JOIN users s ON s.user_id = vr.staff_id
                             WHERE vr.request_id = ? LIMIT 1');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$req || !isLoggedIn()) { return null; }
    $uid = (int)$_SESSION['user_id'];
    if (isAdmin()) { return $req; }
    if ((int)$req['staff_id'] === $uid) { return $req; }   // every role is also staff: its own request
    if (isSupervisor() && ((int)$req['supervisor_id'] === $uid
        || (!empty($_SESSION['department']) && $req['staff_department'] === $_SESSION['department']))) { return $req; }
    return null;
}

// ============================================================
// Passwords
// ============================================================

/** Why a new password is not acceptable (null = fine): at least 8 characters, a letter and a number. */
function passwordProblem(string $pw): ?string
{
    if (strlen($pw) < 8) {
        return 'The password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'The password must contain at least one letter and one number.';
    }
    return null;
}

/** A random temporary password (no look-alike characters such as 0/O or 1/l). */
function generateTempPassword(int $length = 10): string
{
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $all     = $letters . $digits;
    $pw = $letters[random_int(0, strlen($letters) - 1)] . $digits[random_int(0, strlen($digits) - 1)];
    for ($i = strlen($pw); $i < $length; $i++) {
        $pw .= $all[random_int(0, strlen($all) - 1)];
    }
    return str_shuffle($pw);
}
