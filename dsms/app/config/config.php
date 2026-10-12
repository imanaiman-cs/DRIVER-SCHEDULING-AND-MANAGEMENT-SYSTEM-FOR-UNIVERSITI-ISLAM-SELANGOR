<?php
/**
 * app/config/config.php
 * ---------------------------------------------------------------
 * Application-wide settings. Everything that may change between
 * your laptop (XAMPP) and a real server lives here, so the rest of
 * the code never contains hard-coded values.
 *
 * To override a value without touching this file (e.g. on a server),
 * create app/config/config.local.php returning an array with the same
 * keys. That file is ignored by Git, so passwords never get committed.
 */

$config = [
    // Shown in the browser tab and the navbar.
    'app_name'  => 'DSMS',
    'app_title' => 'Driver Scheduling and Management System',
    'org_name'  => 'Universiti Islam Selangor (UIS) - Vehicle Section',

    // Show detailed errors on screen? true while developing, FALSE in production
    // (users must never see raw SQL errors; they are written to storage/logs instead).
    'debug'     => true,

    // All dates/times in the system use Malaysia time.
    'timezone'  => 'Asia/Kuala_Lumpur',

    // Session security (rule: log out after 15 minutes of inactivity).
    // The real value is read from the settings table once it exists;
    // this is the fallback used before the database is reachable.
    'session_timeout_minutes' => 15,

    // Uploads: stored OUTSIDE public/ so they can never be run as scripts.
    'upload_dir'     => dirname(__DIR__, 2) . '/storage/uploads',
    'upload_max_mb'  => 5,
    'upload_allowed' => ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'],

    // Logs.
    'log_dir' => dirname(__DIR__, 2) . '/storage/logs',

    // Email through SMTP (PHPMailer, added in Step 5). Leave 'host' empty to
    // NOT send real mail: every email is then written to storage/logs/mail.log
    // so the demo still works without any mail server.
    'mail' => [
        'host'       => '',            // e.g. smtp.gmail.com
        'port'       => 587,
        'encryption' => 'tls',         // 'tls', 'ssl' or ''
        'username'   => '',
        'password'   => '',            // Gmail needs an "App password" here
        'from_email' => 'no-reply@dsms.test',
        'from_name'  => 'DSMS - UIS Vehicle Section',
    ],
];

// Optional machine-specific overrides (not committed to Git).
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
