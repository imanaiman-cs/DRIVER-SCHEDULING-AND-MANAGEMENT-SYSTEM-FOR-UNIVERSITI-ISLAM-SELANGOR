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
        'processed' => 'bg-primary',
        'cancelled' => 'bg-secondary',
        default     => 'bg-secondary',
    };
}

// ============================================================
// Task allocation scoring (workload-balancing)
// Formula:
//   score = (task_load   × 50%)   fewer tasks this month  → higher
//         + (weekend_load × 30%)  fewer weekend tasks     → higher
//         + (experience  × 20%)   more experience         → higher
//
// All components are normalised to 0–1 before the weighted sum,
// then scaled to a 0–10 score. Drivers with lighter workloads are
// recommended first so tasks are spread evenly across the team.
// ============================================================

/**
 * Calculates a driver's task-allocation score used to recommend
 * drivers for new assignments.
 *
 * @param int   $tasks_this_month   Non-cancelled trips assigned this month
 * @param int   $weekend_tasks      Of those, trips falling on Sat/Sun
 * @param float $experience         Years of driving experience
 *
 * @return float Score rounded to 2 decimals (0–10; higher = recommend first)
 */
function calculateAllocationScore(
    int $tasks_this_month,
    int $weekend_tasks,
    float $experience
): float {
    // Fewer tasks this month → higher factor (cap at 10 tasks)
    $task_factor    = 1.0 - min($tasks_this_month / 10.0, 1.0);

    // Fewer weekend tasks → higher factor (cap at 4 weekend tasks)
    $weekend_factor = 1.0 - min($weekend_tasks / 4.0, 1.0);

    // More experience → higher factor (cap at 20 years)
    $exp_factor     = min($experience / 20.0, 1.0);

    $score = ($task_factor    * 0.50)
           + ($weekend_factor * 0.30)
           + ($exp_factor     * 0.20);

    return round($score * 10, 2);
}

/**
 * Returns this month's task counts for every driver in one query.
 *
 * @param mysqli      $conn  Active database connection
 * @param string|null $month Month in 'Y-m' format; defaults to current month
 *
 * @return array<int, array{tasks: int, weekend: int}> Keyed by driver_id
 */
function getMonthlyTaskCounts(mysqli $conn, ?string $month = null): array
{
    $month = $month ?? date('Y-m');

    $stmt = $conn->prepare(
        "SELECT driver_id,
                COUNT(*) AS tasks,
                COALESCE(SUM(DAYOFWEEK(trip_date) IN (1, 7)), 0) AS weekend
         FROM schedules
         WHERE driver_id IS NOT NULL
           AND status <> 'cancelled'
           AND DATE_FORMAT(trip_date, '%Y-%m') = ?
         GROUP BY driver_id"
    );
    $stmt->bind_param('s', $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $counts = [];
    while ($row = $result->fetch_assoc()) {
        $counts[(int)$row['driver_id']] = [
            'tasks'   => (int)$row['tasks'],
            'weekend' => (int)$row['weekend'],
        ];
    }
    $stmt->close();

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
