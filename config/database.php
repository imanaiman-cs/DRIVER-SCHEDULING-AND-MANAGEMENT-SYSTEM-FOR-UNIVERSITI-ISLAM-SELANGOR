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
// Database connection
// ============================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// ============================================================
// Session initialisation
// Must happen before any output is sent to the browser
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
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

// ============================================================
// Priority score calculation
// Formula:
//   priority = (exp_score  × 30%)
//            + (att_score  × 20%)
//            + (perf_score × 30%)
//            + (cert_score × 20%)
//
// All component scores are normalised to a 0–10 scale before
// the weighted sum is computed.
// ============================================================

/**
 * Calculates a driver's priority score used for schedule assignment ranking.
 *
 * @param float $experience    Years of driving experience (0–∞; capped at 20 for scoring)
 * @param float $attendance    Attendance rate as a percentage (0–100)
 * @param float $performance   Performance score on a 0–10 scale
 * @param float $certification Certification score on a 0–10 scale
 *
 * @return float Priority score rounded to 2 decimal places (range approx. 0–10)
 */
function calculatePriorityScore(
    float $experience,
    float $attendance,
    float $performance,
    float $certification
): float {
    // Normalise experience: cap at 20 years → 10 points maximum
    $exp_score = min(($experience / 20.0) * 10.0, 10.0);

    // Normalise attendance: 0–100 % → 0–10
    $att_score = ($attendance / 100.0) * 10.0;

    // Performance and certification are already on the 0–10 scale
    $perf_score = $performance;
    $cert_score = $certification;

    // Weighted priority score
    $priority = ($exp_score  * 0.30)
              + ($att_score  * 0.20)
              + ($perf_score * 0.30)
              + ($cert_score * 0.20);

    return round($priority, 2);
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
