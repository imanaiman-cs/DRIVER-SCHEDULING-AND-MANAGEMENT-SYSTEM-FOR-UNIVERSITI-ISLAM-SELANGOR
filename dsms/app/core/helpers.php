<?php
/**
 * app/core/helpers.php
 * Small global functions used by the views. Kept very short on purpose.
 */

/** Escape text for HTML output. EVERY value printed in a view goes through this (prevents XSS). */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Base URL of the public/ folder, e.g. /dsms/public  (works in any sub-folder of htdocs). */
function base_url(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $dir === '' ? '' : $dir;
}

/** Link to a page: url('requests/show', ['id' => 5])  ->  /dsms/public/index.php?page=requests/show&id=5 */
function url(string $page = 'home/index', array $params = []): string
{
    // Keep the "/" in page=controller/action readable (http_build_query would write %2F).
    $query = 'page=' . $page;
    if ($params) {
        $query .= '&' . http_build_query($params);
    }
    return base_url() . '/index.php?' . $query;
}

/**
 * A link stored in the database (notifications, e-mails): "?page=requests/show&id=5".
 * It has no folder in front of it, because the same link must work from a browser page
 * AND from the cron script, which does not know the web folder.
 */
function page_link(string $page, array $params = []): string
{
    return '?page=' . $page . ($params ? '&' . http_build_query($params) : '');
}

/** Turn a stored page_link() into a clickable address for the current page. */
function link_href(?string $stored): string
{
    return ($stored !== null && str_starts_with($stored, '?page=')) ? base_url() . '/index.php' . $stored : url('home/index');
}

/** Link to a file in public/assets. */
function asset(string $path): string
{
    return base_url() . '/assets/' . ltrim($path, '/');
}

/** Write one line to storage/logs/<file>. Used for errors and the mail log. */
function app_log(string $message, string $file = 'app.log'): void
{
    global $config;
    $dir = $config['log_dir'] ?? (dirname(__DIR__, 2) . '/storage/logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/' . $file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

/** "12 Oct 2026" */
function fmt_date(?string $value): string
{
    return $value ? date('d M Y', strtotime($value)) : '-';
}

/** "12 Oct 2026, 09:00" */
function fmt_datetime(?string $value): string
{
    return $value ? date('d M Y, H:i', strtotime($value)) : '-';
}

/** "09:00" */
function fmt_time(?string $value): string
{
    return $value ? date('H:i', strtotime($value)) : '-';
}

/** Readable role name for the screen: admin_assistant -> "Admin Assistant". */
function role_label(?string $role): string
{
    return [
        'staff' => 'Staff', 'driver' => 'Driver', 'hod' => 'Head of Department',
        'admin_assistant' => 'Admin Assistant', 'head_admin' => 'Head Admin',
    ][$role] ?? (string)$role;
}

/**
 * Status badge. Grey/black shades only (the theme is black, white and grey);
 * the TEXT carries the meaning, not the colour, so it is also readable when printed.
 */
function status_badge(string $status): string
{
    $class = [
        'Pending Approval' => 'text-bg-light border',
        'Approved'         => 'text-bg-secondary',
        'Driver Assigned'  => 'text-bg-secondary',
        'Confirmed'        => 'text-bg-dark',
        'Completed'        => 'text-bg-dark',
        'Rejected'         => 'text-bg-light border text-decoration-line-through',
        'Cancelled'        => 'text-bg-light border text-decoration-line-through',
        'Lapsed'           => 'text-bg-light border text-decoration-line-through',
    ][$status] ?? 'text-bg-light border';
    return '<span class="badge status-badge ' . $class . '">' . e($status) . '</span>';
}

/** Value of a posted/previous form field, escaped, for re-filling forms after an error. */
function old(array $source, string $key, string $default = ''): string
{
    return e((string)($source[$key] ?? $default));
}
