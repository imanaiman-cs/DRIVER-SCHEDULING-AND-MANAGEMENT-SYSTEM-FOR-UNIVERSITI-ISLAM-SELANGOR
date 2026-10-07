<?php
// ============================================================
// UIS Driver Scheduling and Management System
// cron/send_reminders.php  –  Day-before reminder e-mails
//
// Sends ONE reminder e-mail to every driver who has a task tomorrow.
// A driver who already got a reminder for that date is skipped, so it
// is safe to run this script more than once a day.
//
// This script can only be run from the command line (it refuses web
// requests). Settings come from config/mail.php.
//
// ── Windows Task Scheduler (XAMPP) ──────────────────────────
//  1. Open "Task Scheduler" > "Create Basic Task...".
//  2. Name: "UIS driver reminders".   Trigger: Daily, at 5:00 PM.
//  3. Action: "Start a program", then fill in:
//       Program/script:  C:\xampp\php\php.exe
//       Add arguments:   C:\xampp\htdocs\DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR\cron\send_reminders.php
//  4. Finish. Right-click the task > "Run" once to test it, then check
//     Admin > Notifications to see the e-mail log.
//  (The computer must be on and MySQL (XAMPP) must be running at 5:00 PM.)
//
// Manual test from a terminal:
//   C:\xampp\php\php.exe cron\send_reminders.php
//
// Exit code: 0 = finished (including "nothing to send"), 1 = at least one
// e-mail failed or the script could not start.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Work from the project root whatever folder the task was started in.
chdir(dirname(__DIR__));

// config/database.php starts a session; in the command line that must not
// try to send cookie or cache headers.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/mailer.php';

    $stamp = date('Y-m-d H:i:s');
    $date  = date('Y-m-d', strtotime('+1 day'));
    echo "[$stamp] UIS reminders for $date" . PHP_EOL;

    if (!mailEnabled()) {
        echo 'E-mail sending is switched off (config/mail.php): messages are only saved in the e-mail log.' . PHP_EOL;
    }

    $results = sendRemindersForDate($conn);

    if (!$results) {
        echo 'No reminders to send (no drivers have tasks tomorrow, or all were already reminded).' . PHP_EOL;
        exit(0);
    }

    $failed = 0;
    foreach ($results as $i => $r) {
        echo sprintf('%2d. [%s] %s', $i + 1, strtoupper($r['status']), $r['message']) . PHP_EOL;
        if ($r['status'] === 'failed') {
            $failed++;
        }
    }
    echo 'Summary: ' . emailSummary($results) . PHP_EOL;

    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Reminder run failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
