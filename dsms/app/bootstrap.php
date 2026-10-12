<?php
/**
 * app/bootstrap.php
 * ---------------------------------------------------------------
 * Runs once at the start of every request (loaded by public/index.php
 * and by cron/run_jobs.php). It prepares everything the rest of the
 * code relies on:
 *   1. configuration  2. class autoloading  3. error handling  4. session
 */

define('APP_ROOT', dirname(__DIR__));          // .../dsms
define('APP_DIR',  __DIR__);                   // .../dsms/app

// 1. Configuration (available everywhere as $config) -----------------
$config = require APP_DIR . '/config/config.php';
date_default_timezone_set($config['timezone']);
require_once APP_DIR . '/core/helpers.php';

// 2. Autoloading ------------------------------------------------------
// Composer packages (PHPMailer, Dompdf) - only present after "composer install".
if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}
// Our own classes: ClassName.php is looked for in these folders.
spl_autoload_register(function (string $class): void {
    foreach (['core', 'controllers', 'models', 'services'] as $folder) {
        $file = APP_DIR . "/$folder/$class.php";
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// 3. Error handling ---------------------------------------------------
// Users must never see raw SQL/PHP errors: log the detail, show a friendly page.
error_reporting(E_ALL);
ini_set('display_errors', $config['debug'] ? '1' : '0');

set_exception_handler(function (Throwable $e) use ($config): void {
    app_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'error.log');
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    View::render('errors/500', [
        'title'  => 'Something went wrong',
        'detail' => $config['debug'] ? $e->getMessage() : null,
    ]);
});

// 4. Session (web requests only) --------------------------------------
if (PHP_SAPI !== 'cli') {
    Session::start((int)$config['session_timeout_minutes']);
}
