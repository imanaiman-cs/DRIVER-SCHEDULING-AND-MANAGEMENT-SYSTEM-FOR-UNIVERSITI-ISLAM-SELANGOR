<?php
// ============================================================
// UIS Driver Scheduling and Management System
// Database configuration and shared utility functions
// Universiti Islam Selangor (UIS)
// ============================================================

define('DB_HOST',   'localhost');
define('DB_USER',   'root');
define('DB_PASS',   '');
define('DB_NAME',   getenv('DSMS_DB') ?: 'uis_driver_db');   // DSMS_DB: only used to run tests against another database
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
// Database migrations
// The schema lives in database.sql (fresh install) and in migrations/*.sql (upgrade of an existing
// database). Every file in migrations/ that has not been applied yet is applied ONCE, the first time a
// page is opened, so "git pull" is enough. It can also be run by hand:
//     mysql -uroot uis_driver_db < migrations/2026_10_align_srs.sql
// A migration is written so that running it twice does no harm.
// ============================================================
function applyPendingMigrations(mysqli $conn): void
{
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $done = [];
        $r = $conn->query('SELECT name FROM schema_migrations');
        while ($r && ($row = $r->fetch_row())) { $done[$row[0]] = true; }
        $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file, '.sql');
            if (isset($done[$name])) { continue; }
            $lock = $conn->query("SELECT GET_LOCK('uis_migrate', 30)")->fetch_row()[0];
            if ((int)$lock !== 1) { return; }
            $conn->query("SELECT 1");
            $sql = file_get_contents($file);
            if ($conn->multi_query($sql)) {
                do { if ($res = $conn->store_result()) { $res->free(); } } while ($conn->more_results() && $conn->next_result());
            }
            if ($conn->errno) { error_log("Migration $name failed: " . $conn->error); }
            $conn->query("SELECT RELEASE_LOCK('uis_migrate')");
        }
    } catch (Throwable $e) {
        error_log('Migration skipped: ' . $e->getMessage());
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

require_once __DIR__ . '/../includes/workflow.php';
require_once __DIR__ . '/../includes/recommender.php';
// Apply new migrations once per session (cheap: one SELECT when nothing is pending)
if (empty($_SESSION['migrations_checked'])) {
    applyPendingMigrations($conn);
    $_SESSION['migrations_checked'] = true;
}

// Lapse / cancel / complete, lightly, whenever a signed-in user opens a page (cron/run_jobs.php does it hourly)
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
//   2. the account must still be active (accounts come from the UIS staff system) and the role is refreshed
//   3. (removed) there is no temporary password any more
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
        session_unset();
        $_SESSION['login_notice'] = "You were signed out after $minutes minutes without activity. Please sign in again.";
        securityStop(401, 'Your session expired. Please sign in again.', SITE_URL . '/login.php');
    }
    $_SESSION['last_activity'] = time();

    // 2. the account itself
    try {
        $uid = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare('SELECT is_active, role, department FROM users WHERE user_id = ?');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u || !(int)$u['is_active']) {
            session_unset();
            $_SESSION['login_notice'] = 'Your account is not available. Please contact the Vehicle Section.';
            securityStop(401, 'Your account is not available.', SITE_URL . '/login.php');
        }
        $_SESSION['role'] = $u['role'];                 // a role changed in the UIS staff system applies at once
        $_SESSION['department'] = $u['department'];
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

    // A POST bigger than post_max_size arrives empty: say so, instead of 'page expired'
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        securityStop(413, 'The form was too large and was not received. Each document must be 5 MB or smaller. Please go back and try again.');
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

/** Where each role lands (also where someone is sent when they open a page that is not theirs). */
function roleHomeUrl(?string $role = null): string
{
    $role = $role ?? ($_SESSION['role'] ?? '');
    return SITE_URL . '/' . match ($role) {
        'admin', 'superadmin', 'head_admin' => 'admin/dashboard.php',
        'staff'      => 'staff/dashboard.php',
        'supervisor' => 'supervisor/approvals.php',
        'driver'     => 'driver/schedules.php',
        default      => 'login.php',
    };
}

function requireHeadAdmin(): void
{
    requireLogin();
    if (!isHeadAdmin()) {
        header('Location: ' . roleHomeUrl());
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
        'staff' => 'Staff', 'driver' => 'Driver', 'supervisor' => 'Head of Department',
        'admin', 'superadmin' => 'Administrative Assistant', 'head_admin' => 'Head of Admin', default => $role,
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
        header('Location: ' . roleHomeUrl());
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
        header('Location: ' . roleHomeUrl());
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
        header('Location: ' . roleHomeUrl());
        exit();
    }
}

/**
 * Ensures the current user is a Head of Department (role key 'supervisor').
 *
 * @return void
 */
function requireSupervisor(): void
{
    requireLogin();
    if (!isSupervisor()) {
        header('Location: ' . roleHomeUrl());
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
        'pending' => 'Pending', 'approved' => 'Approved', 'assigned' => 'Driver Assigned',
        'processed' => 'Confirmed', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled',
        'lapsed' => 'Lapsed', 'completed' => 'Completed', default => ucfirst($status),
    };
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
    return password_verify($plain, $stored);
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
        'inactive'    => 'bg-secondary',
        default       => 'bg-dark',
    };
}

/** The words people see for a vehicle status. In Use is worked out by the system (see vehicleDisplayStatus). */
function vehicleStatusLabel(string $status): string
{
    return ['available' => 'Available', 'in_use' => 'In Use', 'maintenance' => 'Under Maintenance', 'inactive' => 'Inactive'][$status] ?? ucfirst($status);
}

/**
 * The status to SHOW for a vehicle. "In Use" is not stored: it is shown when the vehicle has a
 * Confirmed trip (or an internal task) that covers this moment.
 */
function vehicleDisplayStatus(mysqli $conn, int $vehicle_id, string $stored_status): string
{
    if ($stored_status !== 'available') { return $stored_status; }
    $stmt = $conn->prepare(
        "SELECT 1 FROM schedules s JOIN vehicle_requests vr ON vr.request_id = s.request_id
          WHERE s.vehicle_id = ? AND s.status <> 'cancelled' AND vr.status = 'processed' AND s.start_dt <= NOW() AND s.end_dt > NOW() LIMIT 1"
    );
    $stmt->bind_param('i', $vehicle_id);
    $stmt->execute();
    $busy = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $busy ? 'in_use' : 'available';
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
