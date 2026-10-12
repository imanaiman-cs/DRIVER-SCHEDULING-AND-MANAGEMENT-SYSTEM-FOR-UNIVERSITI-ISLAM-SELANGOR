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

/** Link to a page: url('requests/view', ['id' => 5])  ->  /dsms/public/index.php?page=requests/view&id=5 */
function url(string $page = 'home/index', array $params = []): string
{
    $query = http_build_query(['page' => $page] + $params);
    return base_url() . '/index.php?' . $query;
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
