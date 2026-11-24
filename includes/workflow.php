<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/workflow.php  -  shared building blocks (loaded by config/database.php)
//
//   * Settings            editable values (weights, lapse hours, charge per km ...)
//   * Dates               strict date / time checks
//   * Labels              the words people see for roles, statuses, types
//   * History             insert-only status history of a request (who, when, reason)
//   * People              Head of Department of a department, Administrative Assistants, Head of Admin
//   * Notifications       always saved in the system AND e-mailed (WhatsApp for drivers when switched on)
//
// The request flow lives in includes/requests.php, the tasks (internal tasks, welfare duty, replacements,
// schedule changes, task reports) in includes/tasks.php and the driver recommendation in includes/recommender.php.
// ============================================================

// ------------------------------------------------------------
// Settings (table `settings`)
// ------------------------------------------------------------

/** Defaults used when a setting row does not exist yet. */
const SETTING_DEFAULTS = [
    'W_TASKS'                  => '0.5',
    'W_WEEKEND'                => '0.3',
    'W_EXPERIENCE'             => '0.2',
    'RECOMMEND_TOP'            => '3',
    'LAPSE_HOURS'              => '48',
    'REMINDER_HOURS'           => '24',
    'URGENT_HOURS'             => '48',
    'TASK_DURATION_HOURS'      => '1',
    'RESERVATION_BUFFER_HOURS' => '1',
    'CHARGE_PER_KM'            => '3.00',
    'WELFARE_START'            => '08:00',
    'UIS_EMAIL_DOMAINS'        => 'uis.edu.my',
    'CODE_EXPIRY_MINUTES'      => '15',
    'SESSION_TIMEOUT_MIN'      => '15',
    'MAX_FAILED_ATTEMPTS'      => '5',
    'LOCKOUT_MINUTES'          => '15',
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

function getSettingFloat(mysqli $conn, string $key): float
{
    return (float)getSetting($conn, $key);
}

function saveSetting(mysqli $conn, string $key, string $value): void
{
    $stmt = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
    $GLOBALS['__settings_dirty'] = true;
}

// ------------------------------------------------------------
// Fixed lists (SRS)
// ------------------------------------------------------------
const VEHICLE_TYPES     = ['Car', 'MPV', 'Van', 'Bus', 'Lorry'];
const LICENCE_CLASSES   = ['D' => 'Class D (car, MPV, van)', 'GDL' => 'GDL (lorry)', 'PSV' => 'PSV (bus)'];
const FUEL_LEVELS       = ['Full', '3/4', '1/2', '1/4', 'Empty'];
const FUEL_TYPES        = ['Petrol', 'Diesel', 'Electric', 'Hybrid'];
const INTERNAL_TASK_TYPES = ['workshop' => 'Workshop', 'refuelling' => 'Refuelling', 'test_drive' => 'Test Drive'];
const PROBLEM_TYPES     = ['Plate Number', 'Tyre', 'Lights', 'Body Damage', 'Engine', 'Others'];
const WELFARE_VEHICLE_TYPES = ['Car', 'Van'];
const MAX_DOCUMENT_BYTES = 5242880;

/** The licence class a vehicle type needs (UC009: D for cars and vans, GDL for lorries, PSV for buses). */
function licenceForVehicleType(string $type): string
{
    return match ($type) { 'Lorry' => 'GDL', 'Bus' => 'PSV', default => 'D' };
}

/** Does a comma list of licence classes hold $required? */
function driverHoldsLicence(?string $list, string $required): bool
{
    return in_array($required, array_map('trim', explode(',', (string)$list)), true);
}

/** Cleans a list of licence classes: known classes only, in a fixed order, as "D,GDL". */
function normaliseLicenceList(array $classes): string
{
    return implode(',', array_values(array_intersect(array_keys(LICENCE_CLASSES), array_map('strtoupper', array_map('trim', $classes)))));
}

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

/** 'Y-m-d' + 'H:i' -> 'Y-m-d H:i:00', or null when either part is not valid. */
function joinDateTime(string $date, string $time): ?string
{
    if (!isValidDate($date) || !isValidTime($time)) { return null; }
    return $date . ' ' . substr($time . ':00', 0, 8);
}

/** "Mon, 24 Nov 2026, 9:00 am" */
function fmtDT(?string $dt): string
{
    return $dt ? date('D, d M Y, g:i a', strtotime($dt)) : '-';
}

/** "24 Nov 2026" */
function fmtD(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '-';
}

/** A period "Mon, 24 Nov 2026, 9:00 am - 5:00 pm" (the date is repeated only when the day changes). */
function fmtPeriod(string $start, string $end): string
{
    $s = strtotime($start); $e = strtotime($end);
    return date('D, d M Y, g:i a', $s) . ' - ' . (date('Y-m-d', $s) === date('Y-m-d', $e) ? date('g:i a', $e) : date('D, d M Y, g:i a', $e));
}

function isWeekend(string $dt): bool
{
    return (int)date('N', strtotime($dt)) >= 6;
}

/** Monday of the week of $date (Y-m-d). */
function weekMonday(string $date): string
{
    $t = strtotime($date);
    return date('Y-m-d', strtotime('-' . ((int)date('N', $t) - 1) . ' days', $t));
}

// ------------------------------------------------------------
// Labels (the same words everywhere)
// ------------------------------------------------------------
function roleLabel(string $role): string
{
    return match ($role) {
        'staff' => 'Staff', 'driver' => 'Driver', 'supervisor' => 'Head of Department',
        'admin' => 'Administrative Assistant', 'head_admin' => 'Head of Admin', default => ucfirst($role),
    };
}

const REQUEST_STATUSES = ['pending', 'hod_approved', 'driver_assigned', 'approved', 'rejected', 'cancelled', 'lapsed', 'completed'];
/** A request in one of these is still running (it holds its reservation and can be cancelled). */
const REQUEST_ACTIVE_STATUSES = ['pending', 'hod_approved', 'driver_assigned', 'approved'];

function requestStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending', 'hod_approved' => 'Approved by Head of Department', 'driver_assigned' => 'Driver Assigned',
        'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'lapsed' => 'Lapsed',
        'completed' => 'Completed', default => ucfirst($status),
    };
}

function requestStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'         => 'badge-warning',
        'hod_approved'    => 'badge-info',
        'driver_assigned' => 'badge-primary badge-outline',
        'approved'        => 'badge-success',
        'rejected'        => 'badge-error',
        'completed'       => 'badge-neutral',
        default           => 'border-0 bg-slate-500 text-white',
    };
}

function requestStatusBadge(string $status): string
{
    return '<span class="badge ' . requestStatusBadgeClass($status) . ' whitespace-nowrap">' . htmlspecialchars(requestStatusLabel($status)) . '</span>';
}

function driverOptionLabel(string $o): string
{
    return ['with_driver' => 'With Driver', 'pay_claim' => 'Pay and Claim', 'self_drive' => 'Self Drive'][$o] ?? $o;
}

function purposeTypeLabel(string $p): string
{
    return ['official' => 'Official', 'unofficial' => 'Unofficial', 'personal' => 'Personal'][$p] ?? $p;
}

function taskStatusLabel(string $s): string
{
    return ['waiting' => 'Waiting for Driver', 'driver_assigned' => 'Driver Assigned', 'approved' => 'Approved',
            'completed' => 'Completed', 'cancelled' => 'Cancelled'][$s] ?? ucfirst($s);
}

function taskStatusBadge(string $s): string
{
    $cls = ['waiting' => 'badge-warning', 'driver_assigned' => 'badge-primary badge-outline', 'approved' => 'badge-success',
            'completed' => 'badge-neutral', 'cancelled' => 'border-0 bg-slate-500 text-white'][$s] ?? 'badge-ghost';
    return '<span class="badge ' . $cls . ' whitespace-nowrap">' . htmlspecialchars(taskStatusLabel($s)) . '</span>';
}

/** "Request task (outbound)", "Workshop", "Welfare duty" */
function taskTypeLabel(array $t): string
{
    if ($t['task_source'] === 'request') {
        return 'Request task' . (!empty($t['trip_type']) ? ' (' . ($t['trip_type'] === 'return' ? 'return' : 'outbound') . ')' : '');
    }
    if ($t['task_source'] === 'welfare') { return 'Welfare duty'; }
    return INTERNAL_TASK_TYPES[$t['task_type']] ?? ucfirst((string)$t['task_type']);
}

function vehicleStatusLabel(string $s): string
{
    return ['active' => 'Active', 'maintenance' => 'Under Maintenance', 'inactive' => 'Inactive'][$s] ?? ucfirst($s);
}

function vehicleStatusBadge(string $s): string
{
    $cls = ['active' => 'badge-success', 'maintenance' => 'badge-warning', 'inactive' => 'border-0 bg-slate-500 text-white'][$s] ?? 'badge-ghost';
    return '<span class="badge ' . $cls . ' whitespace-nowrap">' . htmlspecialchars(vehicleStatusLabel($s)) . '</span>';
}

function userStatusBadge(string $s): string
{
    return $s === 'active' ? '<span class="badge badge-success">Active</span>' : '<span class="badge border-0 bg-slate-500 text-white">Inactive</span>';
}

function scheduleChangeStatusBadge(string $s): string
{
    $cls = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'rejected' => 'badge-error', 'cancelled' => 'border-0 bg-slate-500 text-white'][$s] ?? 'badge-ghost';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars(ucfirst($s)) . '</span>';
}

/** A registration number set like a number plate (HTML). */
function plate(?string $plateNumber): string
{
    $p = trim((string)$plateNumber);
    return $p === '' ? '' : '<span class="plate">' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** "BDF 4400 Proton X70" */
function vehicleText(?array $v): string
{
    if (!$v || empty($v['plate_number'])) { return '-'; }
    return trim($v['plate_number'] . ' ' . ($v['brand'] ?? '') . ' ' . ($v['model'] ?? ''));
}

function money(float $v): string
{
    return 'RM ' . number_format($v, 2);
}

function h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------
// Status history of a request (INSERT ONLY: database triggers refuse update/delete)
// ------------------------------------------------------------

/**
 * @param int|null $user_id null = done by the system (lapse job ...)
 */
function addHistory(mysqli $conn, ?int $request_id, string $action, ?string $status_to = null, ?string $reason = null, ?int $user_id = -1, ?string $role = null): void
{
    if ($user_id === -1) { $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
    if ($role === null) { $role = $user_id ? ($_SESSION['role'] ?? null) : 'system'; }
    $reason = $reason !== null ? mb_substr($reason, 0, 500) : null;
    $stmt = $conn->prepare('INSERT INTO request_history (request_id, action, status_to, reason, user_id, user_role) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssis', $request_id, $action, $status_to, $reason, $user_id, $role);
    $stmt->execute();
    $stmt->close();
}

function historyActionLabel(string $action): string
{
    return [
        'submitted' => 'Submitted', 'hod_approved' => 'Approved by Head of Department', 'rejected' => 'Rejected',
        'cancelled' => 'Cancelled', 'lapsed' => 'Lapsed', 'driver_assigned' => 'Driver assigned',
        'assignment_rejected' => 'Driver assignment returned', 'approved' => 'Approved by Head of Admin',
        'charge_confirmed' => 'Distance and charge confirmed', 'completed' => 'Completed',
        'replacement' => 'Driver replacement', 'replacement_approved' => 'Driver replacement approved',
        'replacement_rejected' => 'Driver replacement rejected', 'reminder' => 'Reminder email sent',
        'vehicle_changed' => 'Vehicle changed', 'distance_corrected' => 'Distance corrected',
    ][$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/** The status history of a request, oldest first, with the names of the users. */
function requestHistory(mysqli $conn, int $request_id): array
{
    $stmt = $conn->prepare('SELECT h.*, u.full_name FROM request_history h LEFT JOIN users u ON u.user_id = h.user_id
                             WHERE h.request_id = ? ORDER BY h.created_at, h.history_id');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ------------------------------------------------------------
// People
// ------------------------------------------------------------

/** One user row (with department name), or null. */
function loadUser(mysqli $conn, int $user_id): ?array
{
    $stmt = $conn->prepare('SELECT u.*, d.department_name, d.parent_id, dr.driver_id, dr.licence_classes, dr.licence_expiry, dr.start_date, dr.photo
                              FROM users u LEFT JOIN departments d ON d.department_id = u.department_id
                              LEFT JOIN drivers dr ON dr.user_id = u.user_id WHERE u.user_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function userIdsByRole(mysqli $conn, array $roles): array
{
    $in = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE status = 'active' AND role IN ($in)");
    $stmt->bind_param(str_repeat('s', count($roles)), ...$roles);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'user_id'));
    $stmt->close();
    return $ids;
}

function assistantUserIds(mysqli $conn): array { return userIdsByRole($conn, ['admin']); }
function headAdminUserIds(mysqli $conn): array { return userIdsByRole($conn, ['head_admin']); }

/** The active Head of Department of a department (user id), or null. */
function hodOfDepartment(mysqli $conn, ?int $department_id, int $exceptUser = 0): ?int
{
    if (!$department_id) { return null; }
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE department_id = ? AND role = 'supervisor' AND status = 'active' AND user_id <> ? ORDER BY user_id LIMIT 1");
    $stmt->bind_param('ii', $department_id, $exceptUser);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (int)$row[0] : null;
}

/**
 * Who approves a request of this user (UC019 1.1): the Head of Department of the user's department, or, for a
 * request made by a Head of Department, the Head of Department of the parent department.
 */
function approverFor(mysqli $conn, array $user): ?int
{
    if (($user['role'] ?? '') === 'supervisor') {
        return hodOfDepartment($conn, isset($user['parent_id']) ? (int)$user['parent_id'] : null, (int)$user['user_id']);
    }
    return hodOfDepartment($conn, isset($user['department_id']) ? (int)$user['department_id'] : null, (int)$user['user_id']);
}

function userIdOfDriver(mysqli $conn, ?int $driver_id): ?int
{
    if (!$driver_id) { return null; }
    $stmt = $conn->prepare('SELECT user_id FROM drivers WHERE driver_id = ?');
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (int)$row[0] : null;
}

function driverName(mysqli $conn, ?int $driver_id): string
{
    if (!$driver_id) { return '-'; }
    $stmt = $conn->prepare('SELECT u.full_name FROM drivers d JOIN users u ON u.user_id = d.user_id WHERE d.driver_id = ?');
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? (string)$row[0] : '-';
}

/** Is $email a UIS email (domain in the setting UIS_EMAIL_DOMAINS)? */
function isUisEmail(mysqli $conn, string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    foreach (explode(',', (string)getSetting($conn, 'UIS_EMAIL_DOMAINS')) as $d) {
        $d = strtolower(trim($d));
        if ($d !== '' && ($domain === $d || str_ends_with($domain, '.' . $d))) { return true; }
    }
    return false;
}

/** A password is at least 8 characters with a letter and a digit. Returns the problem, or null. */
function passwordProblem(string $p): ?string
{
    if (mb_strlen($p) < 8) { return 'The password must be at least 8 characters.'; }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) { return 'The password must contain letters and numbers.'; }
    return null;
}

// ------------------------------------------------------------
// Notifications: ALWAYS in the system and by e-mail (and WhatsApp for drivers, when switched on).
// If the e-mail fails the in-system notification is still saved and the failure is in email_log.
// ------------------------------------------------------------

/**
 * Tells one or more users. $link is a page below SITE_URL, e.g. 'staff/request.php?id=4'.
 *
 * @param int|int[] $user_ids
 * @return array{sent:int, failed:int, skipped:int, errors:string[]}
 */
function notifyUsers(mysqli $conn, $user_ids, string $message, ?string $link = null, string $kind = 'notice'): array
{
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$user_ids))));
    $msg = mb_substr($message, 0, 500);
    foreach ($ids as $uid) {
        $nid = 0;
        try {
            $stmt = $conn->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $uid, $msg, $link);
            $stmt->execute();
            $nid = (int)$stmt->insert_id;
            $stmt->close();
        } catch (Throwable $e) {
            error_log('notifyUsers (in-system): ' . $e->getMessage());
        }
        $u = null;
        try {
            $u = loadUser($conn, $uid);
            if ($u) {
                $res = sendUserEmail($conn, $u, '[UIS Vehicle Section] ' . mb_substr($msg, 0, 70), $msg, $link, $kind);
                $out[$res['status']] = ($out[$res['status']] ?? 0) + 1;
                if ($res['status'] === 'failed') { $out['errors'][] = $res['message']; }
                if ($res['status'] === 'sent' && $nid) { $conn->query('UPDATE notifications SET email_sent = 1 WHERE notification_id = ' . $nid); }
            }
        } catch (Throwable $e) {
            $out['failed']++;
            $out['errors'][] = $e->getMessage();
            error_log('notifyUsers (e-mail): ' . $e->getMessage());
        }
        // WhatsApp (OpenWA): drivers only; a failure is logged and never stops the other channels
        try {
            if ($u && $u['role'] === 'driver' && function_exists('sendWhatsApp')) {
                sendWhatsApp($conn, $uid, $u['phone'], "[UIS Vehicle Section] $msg\n" . notificationUrl($link));
            }
        } catch (Throwable $e) {
            error_log('notifyUsers (WhatsApp): ' . $e->getMessage());
        }
    }
    return $out;
}

/** Sends one e-mail to a user (row from loadUser). Returns sendMail()'s result. */
function sendUserEmail(mysqli $conn, array $u, string $subject, string $message, ?string $link = null, string $kind = 'notice'): array
{
    $url  = notificationUrl($link);
    $html = '<p>Salam ' . htmlspecialchars((string)$u['full_name']) . ',</p><p>' . nl2br(htmlspecialchars($message)) . '</p>'
          . ($link ? '<p><a href="' . htmlspecialchars($url) . '">Open in the system</a></p>' : '') . '<p>UIS Vehicle Section</p>';
    $text = "Salam {$u['full_name']},\n\n$message\n\n" . ($link ? "Open: $url\n\n" : '') . 'UIS Vehicle Section';
    return sendMail($conn, $u['email'], $subject, $html, $text, ['kind' => $kind, 'user_id' => (int)$u['user_id']]);
}

/** Turns a stored link into a full address. */
function notificationUrl(?string $link): string
{
    return $link ? rtrim(SITE_URL, '/') . '/' . ltrim($link, '/') : SITE_URL . '/notifications.php';
}

/** Binds a list of values to a prepared statement (types from the PHP values). */
function bindDynamic(mysqli_stmt $stmt, array $params): void
{
    if (!$params) { return; }
    $types = '';
    foreach ($params as $p) { $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's'); }
    $stmt->bind_param($types, ...$params);
}

/** Runs a SELECT with parameters and returns all rows. */
function dbAll(mysqli $conn, string $sql, array $params = []): array
{
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Runs a SELECT with parameters and returns the first row, or null. */
function dbOne(mysqli $conn, string $sql, array $params = []): ?array
{
    $rows = dbAll($conn, $sql, $params);
    return $rows[0] ?? null;
}

/** Runs a SELECT with parameters and returns the first column of the first row. */
function dbValue(mysqli $conn, string $sql, array $params = [])
{
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    return $row ? $row[0] : null;
}

/** Runs an INSERT / UPDATE / DELETE with parameters. Returns [affected rows, insert id]. */
function dbExec(mysqli $conn, string $sql, array $params = []): array
{
    $stmt = $conn->prepare($sql);
    bindDynamic($stmt, $params);
    $stmt->execute();
    $out = [(int)$stmt->affected_rows, (int)$stmt->insert_id];
    $stmt->close();
    return $out;
}

/** Shapes the answer of a workflow function: ['ok' => bool, 'message' => string] + extra. */
function wfOk(string $message, array $extra = []): array { return ['ok' => true, 'message' => $message] + $extra; }
function wfFail(string $message, array $extra = []): array { return ['ok' => false, 'message' => $message] + $extra; }
