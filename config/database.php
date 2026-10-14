<?php
// ============================================================
// UIS Driver Scheduling and Management System
// Database configuration and shared utility functions
// Universiti Islam Selangor (UIS)
// ============================================================

define('DB_HOST',   'localhost');
define('DB_USER',   'root');
define('DB_PASS',   '');
define('DB_NAME',   'uis_driver_db');
define('SITE_URL',  'http://localhost/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR');
define('SITE_NAME', 'UIS Driver Management System');

// ============================================================
// Time zone — the system runs on Malaysia time (UTC+8). Without this
// PHP falls back to UTC, so "today" is wrong between 00:00 and 08:00.
// ============================================================
date_default_timezone_set('Asia/Kuala_Lumpur');

// ============================================================
// Database connection
// ============================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");   // keep NOW()/CURDATE() in step with PHP

// ============================================================
// Automatic database upgrade
// The code is updated with "git pull", but a database that was imported earlier
// does not get the new columns by itself, and pages then crash with
// "Call to a member function execute() on bool". This adds whatever is
// missing (columns, keys, tables) - it never deletes or changes existing data.
// It is safe to run many times, and runs once per login session.
// ============================================================
function upgradeDatabaseSchema(mysqli $conn): void
{
    $db = DB_NAME;
    $hasTable = function (string $t) use ($conn, $db): bool {
        $r = $conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$t'");
        return $r && $r->num_rows > 0;
    };
    $hasColumn = function (string $t, string $c) use ($conn, $db): bool {
        $r = $conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$t' AND COLUMN_NAME='$c'");
        return $r && $r->num_rows > 0;
    };
    $hasIndex = function (string $t, string $i) use ($conn, $db): bool {
        $r = $conn->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='$t' AND INDEX_NAME='$i'");
        return $r && $r->num_rows > 0;
    };
    $run = function (string $sql) use ($conn): void {
        try {
            $conn->query($sql);
        } catch (Throwable $e) {
            error_log('Schema upgrade skipped: ' . $e->getMessage());
        }
    };

    // column additions: table => [column => definition]
    $columns = [
        'drivers' => [
            'driver_type' => "ENUM('top_management','regular') NOT NULL DEFAULT 'regular'",
            'assigned_to' => "VARCHAR(150) NULL DEFAULT NULL",
            'photo'       => "VARCHAR(255) NULL DEFAULT NULL",
        ],
        'vehicles' => [
            'photo' => "VARCHAR(255) NULL DEFAULT NULL",
        ],
        'users' => [
            'phone'                => "VARCHAR(30) NULL DEFAULT NULL",
            'failed_attempts'      => "TINYINT UNSIGNED NOT NULL DEFAULT 0",        // locks the account at 5
            'is_locked'            => "TINYINT(1) NOT NULL DEFAULT 0",
            'is_active'            => "TINYINT(1) NOT NULL DEFAULT 1",              // no hard delete: deactivate instead
            'must_change_password' => "TINYINT(1) NOT NULL DEFAULT 0",              // 1 = temporary password
            'last_login_at'        => "DATETIME NULL DEFAULT NULL",
        ],
        'schedules' => [
            'trip_type'     => "ENUM('regular','top_management') NOT NULL DEFAULT 'regular'",
            'officer_name'  => "VARCHAR(255) NULL DEFAULT NULL",
            'officer_phone' => "VARCHAR(50) NULL DEFAULT NULL",
            'waiting_place' => "VARCHAR(150) NULL DEFAULT NULL",
            'job_group'     => "INT NULL DEFAULT NULL",
        ],
        'vehicle_requests' => [
            'officer_name'    => "VARCHAR(255) NULL DEFAULT NULL",
            'officer_phone'   => "VARCHAR(50) NULL DEFAULT NULL",
            'waiting_place'   => "VARCHAR(150) NULL DEFAULT NULL",
            'vehicles_needed' => "TINYINT NOT NULL DEFAULT 1",
            'cancelled_at'    => "TIMESTAMP NULL DEFAULT NULL",
            'status_reason'   => "VARCHAR(500) NULL DEFAULT NULL",      // why it was rejected / cancelled / lapsed
            'cancelled_by'    => "INT NULL DEFAULT NULL",
            'cancelled_by_role' => "VARCHAR(20) NULL DEFAULT NULL",
        ],
    ];
    foreach ($columns as $table => $cols) {
        if (!$hasTable($table)) { continue; }
        foreach ($cols as $col => $def) {
            if (!$hasColumn($table, $col)) {
                $run("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            }
        }
    }

    // keys
    if ($hasTable('schedules') && $hasColumn('schedules', 'job_group') && !$hasIndex('schedules', 'idx_schedules_job_group')) {
        $run("ALTER TABLE schedules ADD KEY idx_schedules_job_group (job_group)");
    }
    if ($hasTable('drivers') && $hasColumn('drivers', 'assigned_to') && !$hasIndex('drivers', 'uq_drivers_assigned_to')) {
        $run("ALTER TABLE drivers ADD UNIQUE KEY uq_drivers_assigned_to (assigned_to)");
    }

    // vehicle_requests.status must allow every status of the lifecycle
    if ($hasTable('vehicle_requests')) {
        $r = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='vehicle_requests' AND COLUMN_NAME='status'");
        $type = $r ? (string)($r->fetch_row()[0] ?? '') : '';
        // 'assigned' = Driver Assigned (waiting for the Head of Admin), 'processed' = Confirmed
        if ($type !== '' && (stripos($type, 'cancelled') === false || stripos($type, 'lapsed') === false
                             || stripos($type, 'completed') === false || stripos($type, 'assigned') === false)) {
            $run("ALTER TABLE vehicle_requests MODIFY status ENUM('pending','approved','assigned','rejected','processed','cancelled','lapsed','completed') NOT NULL DEFAULT 'pending'");
        }
    }

    // users.role: the fifth role (head_admin) must be allowed
    if ($hasTable('users')) {
        $r = $conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users' AND COLUMN_NAME='role'");
        $type = $r ? (string)($r->fetch_row()[0] ?? '') : '';
        if ($type !== '' && stripos($type, 'head_admin') === false) {
            $run("ALTER TABLE users MODIFY role ENUM('admin','driver','staff','supervisor','head_admin') NOT NULL DEFAULT 'staff'");
        }
    }

    // tables
    if ($hasTable('vehicle_requests') && !$hasTable('request_vehicles')) {
        $run("CREATE TABLE request_vehicles (
                request_id INT NOT NULL, vehicle_id INT NOT NULL,
                PRIMARY KEY (request_id, vehicle_id),
                CONSTRAINT fk_rv_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE,
                CONSTRAINT fk_rv_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // earlier requests asked for one vehicle: keep that choice
        $run("INSERT IGNORE INTO request_vehicles (request_id, vehicle_id)
              SELECT request_id, vehicle_id FROM vehicle_requests WHERE vehicle_id IS NOT NULL");
    }
    if ($hasTable('vehicle_requests') && !$hasTable('request_documents')) {
        $run("CREATE TABLE request_documents (
                doc_id INT NOT NULL AUTO_INCREMENT, request_id INT NOT NULL,
                doc_type ENUM('release_letter','seminar_letter','approval_letter','programme','other') NOT NULL DEFAULT 'other',
                original_name VARCHAR(255) NOT NULL, stored_path VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL,
                file_size INT NOT NULL, uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (doc_id), INDEX idx_rd_request (request_id),
                CONSTRAINT fk_rd_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if ($hasTable('drivers') && !$hasTable('email_log')) {
        $run("CREATE TABLE email_log (
                log_id INT NOT NULL AUTO_INCREMENT, driver_id INT NULL DEFAULT NULL, to_email VARCHAR(190) NULL DEFAULT NULL,
                delivered_to VARCHAR(255) NULL DEFAULT NULL, subject VARCHAR(255) NOT NULL, kind VARCHAR(30) NOT NULL,
                ref_date DATE NULL DEFAULT NULL, schedule_ids VARCHAR(255) NULL DEFAULT NULL,
                status ENUM('sent','failed','skipped') NOT NULL, error_message VARCHAR(500) NULL DEFAULT NULL,
                body_html MEDIUMTEXT NULL DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (log_id), KEY idx_email_created (created_at), KEY idx_email_driver_kind (driver_id, kind, ref_date),
                CONSTRAINT fk_email_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE SET NULL
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    upgradeWorkflowTables($conn, $hasTable, $run);
}

/**
 * Tables of the request workflow (audit log, notifications, comments, reminders, settings,
 * vehicle holds, maintenance, leave, internal tasks, welfare roster, driver changes).
 * CREATE TABLE IF NOT EXISTS, so it is safe to run again and again.
 */
function upgradeWorkflowTables(mysqli $conn, callable $hasTable, callable $run): void
{
    $db = DB_NAME;
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $run("CREATE TABLE IF NOT EXISTS audit_log (
            log_id INT NOT NULL AUTO_INCREMENT, user_id INT NULL, user_role VARCHAR(20) NULL,
            action VARCHAR(50) NOT NULL, request_id INT NULL, reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (log_id), KEY idx_audit_request (request_id), KEY idx_audit_action (action, created_at)
          ) $opts");
    $run("CREATE TABLE IF NOT EXISTS notifications (
            notification_id INT NOT NULL AUTO_INCREMENT, user_id INT NOT NULL, message VARCHAR(500) NOT NULL,
            link VARCHAR(255) NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notification_id), KEY idx_notif_user (user_id, is_read, created_at),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
          ) $opts");
    $run("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(60) NOT NULL, setting_value VARCHAR(255) NOT NULL, description VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (setting_key)
          ) $opts");
    if ($hasTable('vehicle_requests')) {
        $run("CREATE TABLE IF NOT EXISTS request_comments (
                comment_id INT NOT NULL AUTO_INCREMENT, request_id INT NOT NULL, user_id INT NOT NULL, user_role VARCHAR(20) NOT NULL,
                comment TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (comment_id), KEY idx_comment_request (request_id, created_at),
                CONSTRAINT fk_comment_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE,
                CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES users(user_id)
              ) $opts");
        $run("CREATE TABLE IF NOT EXISTS reminders (
                reminder_id INT NOT NULL AUTO_INCREMENT, request_id INT NOT NULL, sent_by INT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (reminder_id), KEY idx_reminder_request (request_id),
                CONSTRAINT fk_reminder_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE
              ) $opts");
        // R3: one vehicle can be held by only ONE request on a given day (the database enforces it)
        $had_holds = $hasTable('vehicle_day_holds');
        $run("CREATE TABLE IF NOT EXISTS vehicle_day_holds (
                hold_id INT NOT NULL AUTO_INCREMENT, vehicle_id INT NOT NULL, hold_date DATE NOT NULL, request_id INT NOT NULL,
                PRIMARY KEY (hold_id), UNIQUE KEY uq_hold_vehicle_day (vehicle_id, hold_date), KEY idx_hold_request (request_id),
                CONSTRAINT fk_hold_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE,
                CONSTRAINT fk_hold_request FOREIGN KEY (request_id) REFERENCES vehicle_requests(request_id) ON DELETE CASCADE
              ) $opts");
        if (!$had_holds && $hasTable('request_vehicles')) {
            // First time: requests that already exist and are still active hold their vehicles from now on.
            // (INSERT IGNORE: if two old requests want the same vehicle on one day, the first keeps it.)
            $run("INSERT IGNORE INTO vehicle_day_holds (vehicle_id, hold_date, request_id)
                  SELECT rv.vehicle_id, vr.trip_date, vr.request_id
                    FROM vehicle_requests vr JOIN request_vehicles rv ON rv.request_id = vr.request_id
                   WHERE vr.status IN ('pending','approved') AND vr.trip_date >= CURDATE()
                   ORDER BY vr.created_at");
            $run("INSERT IGNORE INTO vehicle_day_holds (vehicle_id, hold_date, request_id)
                  SELECT s.vehicle_id, vr.trip_date, vr.request_id
                    FROM vehicle_requests vr
                    JOIN schedules s ON s.schedule_id = vr.schedule_id
                      OR (s.job_group IS NOT NULL AND s.job_group = (SELECT s0.job_group FROM schedules s0 WHERE s0.schedule_id = vr.schedule_id))
                   WHERE vr.status = 'processed' AND vr.trip_date >= CURDATE() AND s.vehicle_id IS NOT NULL AND s.status <> 'cancelled'");
        }
    }
    if ($hasTable('vehicles')) {
        $run("CREATE TABLE IF NOT EXISTS vehicle_maintenance (
                maintenance_id INT NOT NULL AUTO_INCREMENT, vehicle_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
                workshop VARCHAR(120) NULL, note VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (maintenance_id), KEY idx_maint_dates (vehicle_id, start_date, end_date),
                CONSTRAINT fk_maint_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
              ) $opts");
    }
    if ($hasTable('drivers')) {
        $run("CREATE TABLE IF NOT EXISTS driver_leaves (
                leave_id INT NOT NULL AUTO_INCREMENT, driver_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
                leave_type ENUM('leave','mc') NOT NULL DEFAULT 'leave', note VARCHAR(255) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (leave_id), KEY idx_leave_dates (driver_id, start_date, end_date),
                CONSTRAINT fk_leave_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE
              ) $opts");
        $run("CREATE TABLE IF NOT EXISTS internal_tasks (
                task_id INT NOT NULL AUTO_INCREMENT,
                task_type ENUM('workshop_send','workshop_collect','refuel','test_drive','road_tax','document_delivery','other') NOT NULL,
                task_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL,
                vehicle_id INT NULL, driver_id INT NOT NULL, location VARCHAR(150) NULL, description VARCHAR(255) NULL,
                created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (task_id), KEY idx_itask_driver (driver_id, task_date),
                CONSTRAINT fk_itask_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE
              ) $opts");
        $run("CREATE TABLE IF NOT EXISTS welfare_roster (
                roster_id INT NOT NULL AUTO_INCREMENT, duty_date DATE NOT NULL, driver_id INT NOT NULL,
                replaced_from_driver_id INT NULL, replacement_reason VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (roster_id), UNIQUE KEY uq_welfare_date (duty_date), KEY idx_welfare_driver (driver_id, duty_date),
                CONSTRAINT fk_welfare_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE CASCADE
              ) $opts");
    }
    if ($hasTable('schedules')) {
        // R7: every change of driver is logged
        $run("CREATE TABLE IF NOT EXISTS driver_replacements (
                replacement_id INT NOT NULL AUTO_INCREMENT, schedule_id INT NOT NULL, previous_driver_id INT NULL, new_driver_id INT NULL,
                reason VARCHAR(500) NOT NULL, changed_by INT NULL, changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (replacement_id), KEY idx_repl_schedule (schedule_id),
                CONSTRAINT fk_repl_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(schedule_id) ON DELETE CASCADE
              ) $opts");
    }

    // R9 / R10: the audit log and the comments can never be changed or deleted - the database refuses it.
    $triggers = [
        'trg_audit_no_update'   => ['audit_log', 'UPDATE', 'audit_log is insert-only'],
        'trg_audit_no_delete'   => ['audit_log', 'DELETE', 'audit_log is insert-only'],
        'trg_comment_no_update' => ['request_comments', 'UPDATE', 'Comments cannot be edited'],
        'trg_comment_no_delete' => ['request_comments', 'DELETE', 'Comments cannot be deleted'],
    ];
    foreach ($triggers as $name => [$table, $event, $message]) {
        if (!$hasTable($table)) { continue; }
        $r = $conn->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$db' AND TRIGGER_NAME='$name'");
        if ($r && $r->num_rows === 0) {
            $run("CREATE TRIGGER $name BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'");
        }
    }

    // default settings (an existing value is never overwritten)
    foreach (SETTING_DEFAULTS as $k => $v) {
        $run("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('" . $conn->real_escape_string($k) . "', '" . $conn->real_escape_string($v) . "')");
    }
}

// ============================================================
// Session initialisation
// Must happen before any output is sent to the browser
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');            // the server never accepts a session id it did not make
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                              // JavaScript cannot read the session cookie
        'samesite' => 'Lax',                             // not sent with cross-site POSTs
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

// Basic browser protections on every page
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');                     // nobody can show our pages in a frame (clickjacking)
    header('X-Content-Type-Options: nosniff');           // the browser must trust the declared file type
    header('Referrer-Policy: same-origin');              // page addresses are not leaked to other sites
}

// Bring an older database up to date once per session (see upgradeDatabaseSchema above).
// Raise SCHEMA_VERSION whenever the upgrade function learns something new.
const SCHEMA_VERSION = 5;
require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/recommender.php';
if (($_SESSION['schema_version'] ?? 0) < SCHEMA_VERSION) {
    upgradeDatabaseSchema($conn);
    $_SESSION['schema_version'] = SCHEMA_VERSION;
}

// R11: lapse / complete / remind, lightly, whenever a signed-in user opens a page
if (isLoggedIn() && !defined('SKIP_REQUEST_JOBS')) {
    try {
        runRequestJobsThrottled($conn);
    } catch (Throwable $e) {
        error_log('request jobs: ' . $e->getMessage());
    }
}

// ============================================================
// Security checks that run on EVERY request of a signed-in user
//   1. idle timeout (SESSION_TIMEOUT_MIN, default 15 minutes)
//   2. the account must still be active and not locked (an admin may have changed it)
//   3. a temporary password must be changed before anything else
//   4. CSRF: every POST must carry the session's token (added automatically by assets/js/ui.js)
// ============================================================

/** The CSRF token of this session (created on first use). */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Is this request an AJAX / JSON call (so it gets a JSON answer instead of a redirect)? */
function isAjaxRequest(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/ajax/')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function securityStop(int $http, string $message, ?string $redirect = null): void
{
    if (isAjaxRequest()) {
        http_response_code($http);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }
    if ($redirect !== null) {
        header('Location: ' . $redirect);
        exit();
    }
    http_response_code($http);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>' . $http . '</title><body style="font-family:sans-serif;max-width:32rem;margin:4rem auto">'
       . '<h2>' . htmlspecialchars($message) . '</h2><p><a href="' . htmlspecialchars(SITE_URL) . '/login.php">Back to the system</a></p></body>';
    exit();
}

function runSecurityChecks(mysqli $conn): void
{
    if (PHP_SAPI === 'cli' || !isLoggedIn()) {
        return;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');

    // 1. idle timeout
    $limit = max(1, getSettingInt($conn, 'SESSION_TIMEOUT_MIN')) * 60;
    if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $limit) {
        $minutes = (int)($limit / 60);
        $keep = $_SESSION['schema_version'] ?? 0;
        session_unset();
        $_SESSION['schema_version'] = $keep;
        $_SESSION['login_notice'] = "You were signed out after $minutes minutes without activity. Please sign in again.";
        securityStop(401, 'Your session expired. Please sign in again.', SITE_URL . '/login.php');
    }
    $_SESSION['last_activity'] = time();

    // 2 + 3. the account itself
    try {
        $uid = (int)$_SESSION['user_id'];
        $u = $conn->query("SELECT is_active, is_locked, must_change_password FROM users WHERE user_id = $uid")->fetch_assoc();
        if (!$u || !(int)$u['is_active'] || (int)$u['is_locked']) {
            session_unset();
            $_SESSION['login_notice'] = 'Your account is not available. Please contact the transport unit.';
            securityStop(401, 'Your account is not available.', SITE_URL . '/login.php');
        }
        $_SESSION['must_change_password'] = (bool)$u['must_change_password'];
        if ($_SESSION['must_change_password'] && !in_array($script, ['change_password.php', 'logout.php'], true)) {
            securityStop(403, 'Please change your temporary password first.', SITE_URL . '/change_password.php');
        }
    } catch (Throwable $e) {
        // columns not created yet (very first request): nothing to check
    }

    // Put the CSRF token into every POST form of the page on the server, so forms work
    // even if a script is cached, blocked or fails to load.
    if (!isAjaxRequest()) {
        $token = csrfToken();
        ob_start(function (string $html) use ($token) {
            if (stripos($html, '<form') === false) {
                return false;
            }
            foreach (headers_list() as $h) {
                if (stripos($h, 'content-type:') === 0 && stripos($h, 'html') === false) {
                    return false;
                }
            }
            $field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
            return preg_replace_callback('/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>/i', function ($m) use ($field) {
                return $m[0] . $field;
            }, $html);
        });
    }

    // 4. CSRF on every state-changing request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals(csrfToken(), $sent)) {
            securityStop(419, 'Your page expired. Please go back, reload it and try again.');
        }
    }
}
runSecurityChecks($conn);

// ============================================================
// Authentication helper functions
// ============================================================

/**
 * Returns true when a valid user session exists.
 *
 * @return bool
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Returns true when the current session belongs to an admin user.
 *
 * @return bool
 */
function isAdmin(): bool
{
    // admin = Admin Assistant; the Head of Admin can do everything an Admin Assistant can do
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'head_admin'], true);
}

/** The Head of Admin: everything an admin can do, plus approving driver assignments. */
function isHeadAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'head_admin';
}

function requireHeadAdmin(): void
{
    requireLogin();
    if (!isHeadAdmin()) {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
        exit();
    }
}

/**
 * Every role is also Staff: all of them may submit a vehicle request and follow / cancel their own.
 */
function requireRequester(): void
{
    requireLogin();
}

/** Display name of a role (same words everywhere). */
function roleLabel(string $role): string
{
    return match ($role) {
        'staff' => 'Staff', 'driver' => 'Driver', 'supervisor' => 'Head of Section',
        'admin', 'superadmin' => 'Admin Assistant', 'head_admin' => 'Head of Admin', default => $role,
    };
}

/**
 * Returns true when the current session belongs to a driver user.
 *
 * @return bool
 */
function isDriver(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'driver';
}

/**
 * Redirects to the login page if the visitor is not authenticated.
 * Call at the top of every protected page.
 *
 * @return void
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
}

/**
 * Ensures the current user is an admin.
 * Non-admin authenticated users are redirected to the driver dashboard.
 *
 * @return void
 */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/driver/dashboard.php');
        exit();
    }
}

/**
 * Ensures the current user is a driver.
 * Non-driver authenticated users are redirected to the admin dashboard.
 *
 * @return void
 */
function requireDriver(): void
{
    requireLogin();
    if (!isDriver()) {
        header('Location: ' . SITE_URL . '/admin/dashboard.php');
        exit();
    }
}

/**
 * Returns true when the current session belongs to a staff user.
 *
 * @return bool
 */
function isStaff(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

/**
 * Returns true when the current session belongs to a supervisor
 * (head of section) user.
 *
 * @return bool
 */
function isSupervisor(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'supervisor';
}

/**
 * Ensures the current user is a staff member.
 *
 * @return void
 */
function requireStaff(): void
{
    requireLogin();
    if (!isStaff()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
}

/**
 * Ensures the current user is a supervisor (head of section).
 *
 * @return void
 */
function requireSupervisor(): void
{
    requireLogin();
    if (!isSupervisor()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
}

/**
 * Returns a Bootstrap badge class string for a vehicle request status.
 *
 * @param string $status One of: pending, approved, rejected, processed, cancelled
 *
 * @return string Bootstrap badge colour class
 */
function requestStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'   => 'bg-warning text-dark',
        'approved'  => 'bg-success',
        'rejected'  => 'bg-danger',
        'assigned'  => 'bg-info text-dark',
        'processed' => 'bg-primary',
        'cancelled' => 'bg-secondary',
        'lapsed'    => 'bg-secondary',
        'completed' => 'bg-dark',
        default     => 'bg-secondary',
    };
}

/** The words people see for a stored status ('assigned' = Driver Assigned, 'processed' = Confirmed). */
function requestStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending Approval', 'approved' => 'Approved', 'assigned' => 'Driver Assigned',
        'processed' => 'Confirmed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
        'lapsed' => 'Lapsed', 'completed' => 'Completed', default => ucfirst($status),
    };
}

// ============================================================
// Driver allocation score (weighted scoring) - see includes/recommender.php
//
//   score = 100 - ( W_TASKS * normTasks + W_AFTER_HOURS * normAfterHours ) * 100 - welfare penalty
//
//   tasks       = trips + internal tasks the driver already has this month
//   after-hours = how many of those are on Saturday/Sunday, start before 08:00 or end after 17:30
//   normX       = the driver's number / the largest number among the drivers compared (0 to 1)
//
// The driver with the FEWEST jobs scores highest, so the work is shared fairly.
// W_TASKS (0.6), W_AFTER_HOURS (0.4) and WELFARE_PENALTY (20) are in the settings table.
// ============================================================

/**
 * A driver's workload score (0-100, higher = recommend first), normalised against the busiest
 * driver of the month that getMonthlyTaskCounts() just loaded. (Welfare duty is added by
 * recommendDrivers(), which knows the date of the trip.)
 *
 * @param int   $tasks_this_month  trips + internal tasks this month
 * @param int   $after_hours       of those, the after-hours ones (older code calls this "weekend")
 * @param float $experience        no longer used (kept so older calls still work)
 */
function calculateAllocationScore(
    int $tasks_this_month,
    int $after_hours,
    float $experience = 0.0
): float {
    global $conn;
    $max = $GLOBALS['__load_max'] ?? ['tasks' => 0, 'after' => 0];
    $norm_t = $max['tasks'] > 0 ? min(1.0, $tasks_this_month / $max['tasks']) : 0.0;
    $norm_a = $max['after'] > 0 ? min(1.0, $after_hours / $max['after']) : 0.0;
    return recommenderScore(
        $norm_t, $norm_a, false,
        (float)getSetting($conn, 'W_TASKS'), (float)getSetting($conn, 'W_AFTER_HOURS'), 0.0
    );
}

/**
 * Returns this month's workload for every driver in one query.
 * 'tasks' counts trips (not cancelled) AND internal tasks; 'weekend' counts the after-hours ones
 * (Saturday/Sunday, before the office opens or after it closes).
 * The largest values are remembered, because the score is relative to the busiest driver.
 *
 * @return array<int, array{tasks: int, weekend: int}> Keyed by driver_id
 */
function getMonthlyTaskCounts(mysqli $conn, ?string $month = null): array
{
    $month = $month ?? date('Y-m');
    $ws = getSetting($conn, 'WORK_START') . ':00';
    $we = getSetting($conn, 'WORK_END') . ':00';

    $stmt = $conn->prepare(
        "SELECT driver_id, COUNT(*) AS tasks, COALESCE(SUM(is_after), 0) AS after_hours FROM (
             SELECT driver_id,
                    (DAYOFWEEK(trip_date) IN (1, 7) OR start_time < ? OR end_time > ?) AS is_after
               FROM schedules
              WHERE driver_id IS NOT NULL AND status <> 'cancelled' AND DATE_FORMAT(trip_date, '%Y-%m') = ?
             UNION ALL
             SELECT driver_id,
                    (DAYOFWEEK(task_date) IN (1, 7) OR start_time < ? OR end_time > ?) AS is_after
               FROM internal_tasks
              WHERE DATE_FORMAT(task_date, '%Y-%m') = ?
         ) x GROUP BY driver_id"
    );
    $stmt->bind_param('ssssss', $ws, $we, $month, $ws, $we, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $counts = [];
    $max = ['tasks' => 0, 'after' => 0];
    while ($row = $result->fetch_assoc()) {
        $counts[(int)$row['driver_id']] = ['tasks' => (int)$row['tasks'], 'weekend' => (int)$row['after_hours']];
        $max['tasks'] = max($max['tasks'], (int)$row['tasks']);
        $max['after'] = max($max['after'], (int)$row['after_hours']);
    }
    $stmt->close();
    $GLOBALS['__load_max'] = $max;

    return $counts;
}

/**
 * Finds a schedule that already books the same driver or vehicle in an
 * overlapping time slot on the same day (cancelled trips are ignored).
 *
 * @param string $column       'driver_id' or 'vehicle_id'
 * @param int[]  $exclude_ids  Schedule ids that must not count as a clash
 *
 * @return array|null  The clashing schedule (id, destination, times) or null
 */
function findResourceConflict(mysqli $conn, string $column, int $id, string $date,
                              string $start, string $end, array $exclude_ids = []): ?array
{
    if (!in_array($column, ['driver_id', 'vehicle_id'], true) || $id <= 0) {
        return null;
    }
    $exclude_ids = array_values(array_filter(array_map('intval', $exclude_ids)));
    $not_in = $exclude_ids ? ' AND schedule_id NOT IN (' . implode(',', $exclude_ids) . ')' : '';

    $stmt = $conn->prepare(
        "SELECT schedule_id, destination, start_time, end_time
         FROM schedules
         WHERE {$column} = ? AND trip_date = ? AND status <> 'cancelled'
           AND start_time < ? AND end_time > ?{$not_in}
         LIMIT 1"
    );
    $stmt->bind_param('isss', $id, $date, $end, $start);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Finds a job that is already booked for the same day, start time and
 * destination (case and spacing ignored). Used to stop the same job being
 * entered twice. Cancelled trips and the rows of $exclude_group / the
 * schedule ids in $exclude_ids are ignored.
 *
 * @return array|null  The existing schedule (id, destination, times) or null
 */
function findDuplicateJob(mysqli $conn, string $date, string $start, string $destination,
                          array $exclude_ids = [], int $exclude_group = 0): ?array
{
    $exclude_ids = array_values(array_filter(array_map('intval', $exclude_ids)));
    $not_in = $exclude_ids ? ' AND schedule_id NOT IN (' . implode(',', $exclude_ids) . ')' : '';
    $not_group = $exclude_group > 0
        ? ' AND (job_group IS NULL OR job_group <> ' . (int)$exclude_group . ')'
        : '';
    $dest = mb_strtolower(preg_replace('/\s+/', ' ', trim($destination)));

    $stmt = $conn->prepare(
        "SELECT schedule_id, destination, start_time, end_time
         FROM schedules
         WHERE trip_date = ? AND start_time = ? AND status <> 'cancelled'
           AND LOWER(TRIM(destination)) = ?{$not_in}{$not_group}
         LIMIT 1"
    );
    $stmt->bind_param('sss', $date, $start, $dest);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Returns the other drivers on the same multi-driver job.
 * Empty array for a normal single-driver job.
 *
 * @return array<int, array{schedule_id:int, driver_id:?int, driver_name:?string,
 *                          plate_number:?string, status:string}>
 */
function getJobTeam(mysqli $conn, int $schedule_id): array
{
    $stmt = $conn->prepare(
        "SELECT m.schedule_id, m.driver_id, d.name AS driver_name,
                v.plate_number, m.status
         FROM schedules s
         JOIN schedules m ON m.job_group = s.job_group AND m.schedule_id <> s.schedule_id
         LEFT JOIN drivers  d ON d.driver_id  = m.driver_id
         LEFT JOIN vehicles v ON v.vehicle_id = m.vehicle_id
         WHERE s.schedule_id = ? AND s.job_group IS NOT NULL AND m.status <> 'cancelled'
         ORDER BY d.name"
    );
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Team lookup for a whole list: schedule_id => list of the other drivers.
 * One query instead of one per row.
 *
 * @param int[] $schedule_ids
 * @return array<int, array<int, array{driver_name:?string, plate_number:?string, schedule_id:int, status:string}>>
 */
function getJobTeams(mysqli $conn, array $schedule_ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $schedule_ids))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    $res = $conn->query(
        "SELECT s.schedule_id AS owner_id, m.schedule_id, d.name AS driver_name,
                v.plate_number, m.status
         FROM schedules s
         JOIN schedules m ON m.job_group = s.job_group AND m.schedule_id <> s.schedule_id
         LEFT JOIN drivers  d ON d.driver_id  = m.driver_id
         LEFT JOIN vehicles v ON v.vehicle_id = m.vehicle_id
         WHERE s.schedule_id IN ({$in}) AND s.job_group IS NOT NULL AND m.status <> 'cancelled'
         ORDER BY d.name"
    );
    $teams = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $teams[(int)$row['owner_id']][] = $row;
    }
    return $teams;
}

/**
 * The vehicles each e-Kenderaan request asked for (a trip may need several).
 * Falls back to vehicle_requests.vehicle_id for requests without rows.
 *
 * @param int[] $request_ids
 * @return array<int, array<int, array{vehicle_id:int, plate_number:string, vehicle_type:string,
 *                                     brand:?string, model:?string, capacity:int}>>  keyed by request_id
 */
function getRequestVehicles(mysqli $conn, array $request_ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $request_ids))));
    if (!$ids) {
        return [];
    }
    $in  = implode(',', $ids);
    $map = [];

    $res = $conn->query(
        "SELECT rv.request_id, v.vehicle_id, v.plate_number, v.vehicle_type, v.brand, v.model, v.capacity
         FROM request_vehicles rv JOIN vehicles v ON v.vehicle_id = rv.vehicle_id
         WHERE rv.request_id IN ({$in})
         ORDER BY v.vehicle_type, v.plate_number"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $map[(int)$r['request_id']][] = [
            'vehicle_id'   => (int)$r['vehicle_id'],
            'plate_number' => $r['plate_number'],
            'vehicle_type' => $r['vehicle_type'],
            'brand'        => $r['brand'],
            'model'        => $r['model'],
            'capacity'     => (int)$r['capacity'],
        ];
    }

    // Older requests that only have vehicle_requests.vehicle_id
    $res = $conn->query(
        "SELECT r.request_id, v.vehicle_id, v.plate_number, v.vehicle_type, v.brand, v.model, v.capacity
         FROM vehicle_requests r JOIN vehicles v ON v.vehicle_id = r.vehicle_id
         WHERE r.request_id IN ({$in})"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        if (!isset($map[(int)$r['request_id']])) {
            $map[(int)$r['request_id']][] = [
                'vehicle_id'   => (int)$r['vehicle_id'],
                'plate_number' => $r['plate_number'],
                'vehicle_type' => $r['vehicle_type'],
                'brand'        => $r['brand'],
                'model'        => $r['model'],
                'capacity'     => (int)$r['capacity'],
            ];
        }
    }
    return $map;
}

/**
 * Drivers (with phone) and vehicles assigned to the schedules created from
 * e-Kenderaan requests, so staff and Head of Section can contact them.
 * A multi-driver job lists every driver on it.
 *
 * @param int[] $schedule_ids
 * @return array<int, array<int, array{driver_name:string, driver_phone:?string, plate_number:?string, vehicle:?string}>>
 *         keyed by the request's schedule_id
 */
function getAssignedDrivers(mysqli $conn, array $schedule_ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $schedule_ids))));
    if (!$ids) {
        return [];
    }
    $in  = implode(',', $ids);
    $res = $conn->query(
        "SELECT base.schedule_id AS base_id, d.name AS driver_name, d.phone AS driver_phone,
                v.plate_number, CONCAT_WS(' ', v.brand, v.model) AS vehicle
         FROM schedules base
         JOIN schedules m ON m.schedule_id = base.schedule_id
                          OR (base.job_group IS NOT NULL AND m.job_group = base.job_group)
         JOIN drivers d ON d.driver_id = m.driver_id
         LEFT JOIN vehicles v ON v.vehicle_id = m.vehicle_id
         WHERE base.schedule_id IN ({$in}) AND m.status <> 'cancelled'
         ORDER BY (m.schedule_id = base.schedule_id) DESC, d.name"
    );
    $map = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $map[(int)$row['base_id']][] = [
            'driver_name'  => $row['driver_name'],
            'driver_phone' => $row['driver_phone'],
            'plate_number' => $row['plate_number'],
            'vehicle'      => $row['vehicle'],
        ];
    }
    return $map;
}

/**
 * Returns the licence classes that may operate a given vehicle type.
 *
 * B2 = motorcycle · D = car/van/minibus · E = bus/lorry (E holders
 * may also drive D-class vehicles).
 *
 * @param string $vehicle_type One of: Bus, Van, Car, Minibus, Lorry, Motorcycle
 *
 * @return string[] Acceptable licence classes
 */
function requiredLicenseClasses(string $vehicle_type): array
{
    return match ($vehicle_type) {
        'Motorcycle'   => ['B2'],
        'Bus', 'Lorry' => ['E'],
        default        => ['D', 'E'],
    };
}

/**
 * Checks whether a driver's licence (comma list, e.g. 'B2,D') covers
 * any of the acceptable classes for a vehicle.
 *
 * @param string|null $license_class Driver's licence classes
 * @param string[]    $required      Acceptable classes from requiredLicenseClasses()
 *
 * @return bool
 */
function driverHasLicense(?string $license_class, array $required): bool
{
    if ($license_class === null || $license_class === '') {
        return false;
    }
    $held = array_map('trim', explode(',', strtoupper($license_class)));
    return count(array_intersect($held, array_map('strtoupper', $required))) > 0;
}

// ============================================================
// Workload helper
// ============================================================

/**
 * Returns the number of trips and total driving hours for a driver
 * on a given date (defaults to today).
 *
 * Non-cancelled schedules are counted.
 *
 * @param mysqli $conn      Active database connection
 * @param int    $driver_id Driver primary key
 * @param string|null $date Date string in Y-m-d format; defaults to today
 *
 * @return array{count: int, total_hours: float}
 */
function getDriverWorkload(mysqli $conn, int $driver_id, ?string $date = null): array
{
    if ($date === null) {
        $date = date('Y-m-d');
    }

    $stmt = $conn->prepare(
        "SELECT
             COUNT(*) AS count,
             COALESCE(SUM(TIMESTAMPDIFF(HOUR, start_time, end_time)), 0) AS total_hours
         FROM schedules
         WHERE driver_id = ?
           AND trip_date  = ?
           AND status NOT IN ('cancelled')"
    );

    $stmt->bind_param('is', $driver_id, $date);
    $stmt->execute();

    /** @var array{count: int, total_hours: float} $result */
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result;
}

// ============================================================
// Security / sanitisation helpers
// ============================================================

/**
 * Strips HTML tags, trims whitespace, and encodes special characters.
 * Use for displaying user-supplied strings in HTML output.
 *
 * @param string $data Raw input string
 *
 * @return string Sanitised string safe for HTML output
 */
function sanitize(string $data): string
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Verifies a plain-text password against a stored hash.
 *
 * The system supports two storage formats:
 *   1. password_hash() (bcrypt/argon2) — preferred for new accounts.
 *   2. SHA-256 hex string — used by the SQL seed data for backwards
 *      compatibility during initial import; accounts are migrated on
 *      first successful login.
 *
 * @param string $plain      The plain-text password submitted by the user
 * @param string $stored     The hash retrieved from the database
 *
 * @return bool
 */
function verifyPassword(string $plain, string $stored): bool
{
    // Modern bcrypt/argon hash (starts with $2y$, $argon2i$, etc.)
    if (password_verify($plain, $stored)) {
        return true;
    }

    // Legacy SHA2-256 hex fallback (64-char hex string from SQL seed)
    if (strlen($stored) === 64 && hash('sha256', $plain) === $stored) {
        return true;
    }

    return false;
}

/**
 * Upgrades a SHA-256 hex password to a bcrypt hash in-place.
 * Called automatically after a successful legacy-format login.
 *
 * @param mysqli $conn    Active database connection
 * @param int    $user_id User primary key
 * @param string $plain   The verified plain-text password
 *
 * @return void
 */
function upgradeLegacyPassword(mysqli $conn, int $user_id, string $plain): void
{
    $newHash = password_hash($plain, PASSWORD_BCRYPT);
    $stmt    = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
    $stmt->bind_param('si', $newHash, $user_id);
    $stmt->execute();
    $stmt->close();
}

// ============================================================
// Flash message helpers
// ============================================================

/**
 * Stores a one-time flash message in the session.
 *
 * @param string $type    Bootstrap alert type: 'success', 'danger', 'warning', 'info'
 * @param string $message The message text
 *
 * @return void
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieves and clears the pending flash message.
 * Returns null when no flash message is queued.
 *
 * @return array{type: string, message: string}|null
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Renders the flash message as a Bootstrap alert div (if one is pending).
 * Echoes HTML directly; call inside the page body.
 *
 * @return void
 */
function showFlash(): void
{
    $flash = getFlash();
    if ($flash !== null) {
        $type    = sanitize($flash['type']);
        $message = sanitize($flash['message']);
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
           . $message
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
           . '</div>';
    }
}

// ============================================================
// Pagination helper
// ============================================================

/**
 * Computes pagination metadata for a given total row count.
 *
 * @param int $total_rows    Total number of records in the result set
 * @param int $current_page  Current page number (1-based)
 * @param int $rows_per_page Number of records to display per page
 *
 * @return array{total_rows: int, total_pages: int, current_page: int, offset: int, rows_per_page: int}
 */
function paginate(int $total_rows, int $current_page = 1, int $rows_per_page = 10): array
{
    $total_pages  = (int) ceil($total_rows / $rows_per_page);
    $current_page = max(1, min($current_page, $total_pages ?: 1));
    $offset       = ($current_page - 1) * $rows_per_page;

    return [
        'total_rows'   => $total_rows,
        'total_pages'  => $total_pages,
        'current_page' => $current_page,
        'offset'       => $offset,
        'rows_per_page'=> $rows_per_page,
    ];
}

// ============================================================
// Date / time formatting helpers
// ============================================================

/**
 * Formats a MySQL DATE string (Y-m-d) to a human-readable Malaysian format.
 * Example: '2025-03-10' → '10 Mar 2025'
 *
 * @param string|null $date MySQL date string or null
 *
 * @return string Formatted date or '—' if null/empty
 */
function formatDate(?string $date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '&mdash;';
    }
    return date('d M Y', strtotime($date));
}

/**
 * Formats a MySQL TIME string (H:i:s) to 12-hour format with am/pm.
 * Example: '07:30:00' → '7:30 am'
 *
 * @param string|null $time MySQL time string or null
 *
 * @return string Formatted time or '—' if null/empty
 */
function formatTime(?string $time): string
{
    if (empty($time)) {
        return '&mdash;';
    }
    return date('g:i a', strtotime($time));
}

/**
 * Returns a Bootstrap badge class string for a schedule status value.
 *
 * @param string $status One of: pending, approved, in_progress, completed, cancelled
 *
 * @return string Bootstrap badge colour class, e.g. 'bg-warning'
 */
function statusBadgeClass(string $status): string
{
    return match ($status) {
        'pending'     => 'bg-warning text-dark',
        'approved'    => 'bg-primary',
        'in_progress' => 'bg-info text-dark',
        'completed'   => 'bg-success',
        'cancelled'   => 'bg-secondary',
        default       => 'bg-dark',
    };
}

/**
 * Returns a Bootstrap badge class string for a driver status value.
 *
 * @param string $status One of: active, inactive, on_leave
 *
 * @return string Bootstrap badge colour class
 */
function driverStatusBadgeClass(string $status): string
{
    return match ($status) {
        'active'   => 'bg-success',
        'inactive' => 'bg-danger',
        'on_leave' => 'bg-warning text-dark',
        default    => 'bg-secondary',
    };
}

/**
 * Returns a Bootstrap badge class string for a vehicle status value.
 *
 * @param string $status One of: available, in_use, maintenance, retired
 *
 * @return string Bootstrap badge colour class
 */
function vehicleStatusBadgeClass(string $status): string
{
    return match ($status) {
        'available'   => 'bg-success',
        'in_use'      => 'bg-primary',
        'maintenance' => 'bg-warning text-dark',
        'retired'     => 'bg-secondary',
        default       => 'bg-dark',
    };
}

// ============================================================
// Photo helpers (vehicles and drivers)
// Uploaded photos are stored as a path relative to the project
// root (e.g. 'uploads/vehicles/abc123.jpg') in the `photo` column.
// When no photo has been uploaded, a bundled placeholder is used.
// ============================================================

/**
 * Returns the URL of a vehicle's photo, or a type-specific illustration
 * when no photo has been uploaded.
 *
 * @param string|null $photo        Stored relative path, or null
 * @param string      $vehicle_type Bus, Minibus, Van, Car, Lorry or Motorcycle
 *
 * @return string
 */
function vehiclePhotoUrl(?string $photo, string $vehicle_type = 'Car'): string
{
    if (!empty($photo)) {
        return SITE_URL . '/' . ltrim($photo, '/');
    }
    $slug = strtolower($vehicle_type);
    if (!in_array($slug, ['bus', 'minibus', 'van', 'car', 'lorry', 'motorcycle'], true)) {
        $slug = 'car';
    }
    return SITE_URL . '/assets/images/vehicles/' . $slug . '.svg';
}

/**
 * Returns the URL of a driver's photo, or the dummy avatar when no photo
 * has been uploaded.
 *
 * @param string|null $photo Stored relative path, or null
 *
 * @return string
 */
function driverPhotoUrl(?string $photo): string
{
    if (!empty($photo)) {
        return SITE_URL . '/' . ltrim($photo, '/');
    }
    return SITE_URL . '/assets/images/drivers/avatar.svg';
}
