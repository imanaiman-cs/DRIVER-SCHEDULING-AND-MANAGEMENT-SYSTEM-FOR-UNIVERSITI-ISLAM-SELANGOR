<?php
// ============================================================
// UIS Driver Scheduling and Management System (DSMS)
// config/database.php  -  the start of every page: settings, the three layers, session and security checks
//
//   View Layer (SDD_PKG_100)         the pages in admin/ staff/ supervisor/ driver/ and the root, with includes/ui.php
//   Domain Layer (SDD_PKG_200)       app/domain/*.php  (controllers and services)
//   Data Access Layer (SDD_PKG_300)  app/data/*.php    (Database + one class per table)
// A page never runs SQL: it calls the Domain Layer, which calls the Data Access Layer.
// ============================================================

// Optional config/local.php (not uploaded to GitHub) overrides these for a real server; see config/local.sample.php
$_local = is_file(__DIR__ . '/local.php') ? (array)(include __DIR__ . '/local.php') : [];
define('DB_HOST',   $_local['db_host'] ?? 'localhost');
define('DB_USER',   $_local['db_user'] ?? 'root');
define('DB_PASS',   $_local['db_pass'] ?? '');
define('DB_NAME',   getenv('DSMS_DB') ?: ($_local['db_name'] ?? 'dsms_db'));
define('SITE_URL',  rtrim($_local['site_url'] ?? 'http://localhost/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR', '/'));
define('SITE_NAME', 'UIS Driver Scheduling and Management System');

// The system runs on Malaysia time (UTC+8)
date_default_timezone_set('Asia/Kuala_Lumpur');

// The classes of the Domain Layer and the Data Access Layer load themselves when first used
spl_autoload_register(function (string $class): void {
    foreach (['domain', 'data'] as $layer) {
        $file = __DIR__ . '/../app/' . $layer . '/' . $class . '.php';
        if (is_file($file)) { require $file; return; }
    }
});

// Session (before any output)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/i18n.php';
uisI18nBoot();                                              // ?lang=ms|en, and the BM translation of the page when chosen
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/mailer.php';          // PHPMailer (SMTP), used by NotificationService
require_once __DIR__ . '/../includes/whatsapp.php';        // optional WhatsApp to drivers (OpenWA)
require_once __DIR__ . '/../includes/report_export.php';   // PDF / Excel writers, used by ReportController

// This version needs dsms_db of database.sql (SDD Section 3)
if (empty($_SESSION['schema_ok'])) {
    try {
        Database::value('SELECT 1 FROM vehicle_reservation LIMIT 1');
        $_SESSION['schema_ok'] = true;
    } catch (Throwable $e) {
        http_response_code(503);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Database needed</title><body style="font-family:sans-serif;max-width:36rem;margin:4rem auto">'
           . '<h2>The database must be imported</h2><p>Import <code>database.sql</code> (phpMyAdmin &gt; Import, or <code>mysql -uroot &lt; database.sql</code>). '
           . 'It creates <code>' . htmlspecialchars(DB_NAME) . '</code> with the 14 tables of the SDD and demo data.</p></body>';
        exit();
    }
}

// Lapse / remind / complete, lightly, whenever a signed-in user opens a page (cron/run_jobs.php does it hourly)
if (!empty($_SESSION['user_id']) && !defined('SKIP_REQUEST_JOBS')) {
    ScheduledJob::runThrottled();
}

// ============================================================
// Security checks on every request of a signed-in user
//   1. idle timeout (AccountController::idleTimeout, 15 minutes)
//   2. the account must still be active; the role is refreshed
//   3. CSRF: every POST must carry the session's token
// ============================================================

/** The CSRF token of this session (created on first use). */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf_token'];
}

function isAjaxRequest(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/ajax/') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function securityStop(int $http, string $message, ?string $redirect = null): never
{
    if (isAjaxRequest()) {
        http_response_code($http);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }
    if ($redirect !== null) { header('Location: ' . $redirect); exit(); }
    http_response_code($http);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>' . $http . '</title><body style="font-family:sans-serif;max-width:32rem;margin:4rem auto"><h2>'
       . htmlspecialchars($message) . '</h2><p><a href="' . htmlspecialchars(SITE_URL) . '/login.php">Back to the system</a></p></body>';
    exit();
}

function runSecurityChecks(): void
{
    if (PHP_SAPI === 'cli' || !isLoggedIn()) { return; }
    $limit = (new AccountController())->getIdleTimeout() * 60;
    if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $limit) {
        session_unset();
        $_SESSION['login_notice'] = 'You were signed out after ' . (int)($limit / 60) . ' minutes without activity. Please sign in again.';
        securityStop(401, 'Your session expired. Please sign in again.', SITE_URL . '/login.php');
    }
    $_SESSION['last_activity'] = time();
    $u = currentUser();
    if (!$u || $u['status'] !== User::ACTIVE) {
        session_unset();
        $_SESSION['login_notice'] = 'Your account is not available. Please contact the Vehicle Section.';
        securityStop(401, 'Your account is not available.', SITE_URL . '/login.php');
    }
    $_SESSION['role'] = $u['role'];                  // a role changed by the Administrative Assistant applies at once
    $_SESSION['full_name'] = $u['name'];
    $_SESSION['department_id'] = (int)$u['department_id'];

    // put the CSRF token into every POST form of the page on the server
    if (!isAjaxRequest()) {
        $token = csrfToken();
        ob_start(function (string $html) use ($token) {
            if (stripos($html, '<form') === false) { return false; }
            foreach (headers_list() as $hd) { if (stripos($hd, 'content-type:') === 0 && stripos($hd, 'html') === false) { return false; } }
            $field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
            return preg_replace_callback('/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>/i', fn($m) => $m[0] . $field, $html);
        });
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        securityStop(413, 'The form was too large and was not received. Each document must be 5 MB or smaller. Please go back and try again.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sent = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals(csrfToken(), $sent)) { securityStop(419, 'Your page expired. Please go back, reload it and try again.'); }
    }
}

// ============================================================
// Authentication helpers
// ============================================================
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

/** The signed-in user (User::findByID: department, driver details), loaded once per request. */
function currentUser(): array
{
    static $u = null;
    if ($u === null) { $u = isLoggedIn() ? (User::findByID((int)$_SESSION['user_id']) ?? []) : []; }
    return $u;
}

/** Administrative Assistant or Head of Admin (the Head of Admin can do everything the Assistant does). */
function isAdmin(): bool
{
    return in_array($_SESSION['role'] ?? '', [User::AA, User::HOA], true);
}

function isHeadAdmin(): bool
{
    return ($_SESSION['role'] ?? '') === User::HOA;
}

/** Where each role lands. */
function roleHomeUrl(?string $role = null): string
{
    return SITE_URL . '/' . match ($role ?? ($_SESSION['role'] ?? '')) {
        User::AA, User::HOA => 'admin/dashboard.php',
        User::HOD           => 'supervisor/dashboard.php',
        User::DRIVER        => 'driver/dashboard.php',
        User::STAFF         => 'staff/dashboard.php',
        default             => 'login.php',
    };
}

function requireLogin(): void
{
    if (!isLoggedIn()) { header('Location: ' . SITE_URL . '/login.php'); exit(); }
}

/** Only these roles may open the page; others go to their home page. */
function requireRoles(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        if (isAjaxRequest()) { securityStop(403, 'You do not have access to this action.'); }
        header('Location: ' . roleHomeUrl());
        exit();
    }
}

function requireAdmin(): void { requireRoles([User::AA, User::HOA]); }
function requireHeadAdmin(): void { requireRoles([User::HOA]); }

runSecurityChecks();

// ============================================================
// Small page helpers
// ============================================================
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function showFlash(): void
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) { return; }
    $tone = ['danger' => 'error', 'success' => 'success', 'warning' => 'warning', 'info' => 'info'][$f['type']] ?? 'info';
    $icon = ['error' => 'circle-exclamation', 'success' => 'circle-check', 'warning' => 'triangle-exclamation', 'info' => 'circle-info'][$tone];
    echo '<div class="alert alert-soft alert-' . $tone . ' mb-6" role="alert"><i class="fas fa-' . $icon . '" aria-hidden="true"></i><span>' . h($f['message']) . '</span>'
       . '<button type="button" class="btn btn-ghost btn-xs btn-square" onclick="this.parentElement.remove()" aria-label="Close"><i class="fas fa-xmark" aria-hidden="true"></i></button></div>';
}

/** Redirects with a flash message built from a domain result. */
function redirectWithResult(array $res, string $url): never
{
    setFlash($res['ok'] ? 'success' : 'danger', $res['message']);
    header('Location: ' . $url);
    exit();
}

/** Pagination numbers for a total row count. */
function paginate(int $total_rows, int $current_page = 1, int $rows_per_page = 10): array
{
    $total_pages = (int)ceil($total_rows / $rows_per_page);
    $current_page = max(1, min($current_page, $total_pages ?: 1));
    return ['total_rows' => $total_rows, 'total_pages' => $total_pages, 'current_page' => $current_page,
            'offset' => ($current_page - 1) * $rows_per_page, 'rows_per_page' => $rows_per_page];
}

/** The picture of a vehicle type. */
function vehiclePhotoUrl(?string $photo, string $type = 'Car'): string
{
    $slug = strtolower($type === 'MPV' ? 'minibus' : $type);
    return SITE_URL . '/assets/images/vehicles/' . (in_array($slug, ['bus', 'minibus', 'van', 'car', 'lorry'], true) ? $slug : 'car') . '.svg';
}
