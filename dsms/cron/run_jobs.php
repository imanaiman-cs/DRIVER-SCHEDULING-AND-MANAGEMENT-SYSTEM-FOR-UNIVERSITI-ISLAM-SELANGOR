<?php
/**
 * cron/run_jobs.php - the scheduled housekeeping (lapse, expire, auto-complete).
 *
 * Run it from the command line:      php cron/run_jobs.php
 *
 * XAMPP on Windows (Task Scheduler), every 15 minutes:
 *   Program : C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\dsms\cron\run_jobs.php
 *
 * Linux crontab, every 15 minutes:  every-15-minutes  php /var/www/dsms/cron/run_jobs.php
 *
 * The web pages also run these jobs lightly, so the demo works without a scheduler.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$result = (new Jobs())->run();
echo date('Y-m-d H:i:s') . " lapsed={$result['lapsed']} expired={$result['expired']} completed={$result['completed']} reminded={$result['reminded']}" . PHP_EOL;
