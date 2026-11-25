<?php
// ============================================================
// UIS Driver Scheduling and Management System
// cron/run_jobs.php  -  runs ScheduledJob (SDD Domain Layer) from the command line
//
//   1. approval emails that could not be sent are sent again (UC019)
//   2. one reminder email to the Head of Department after one day (UC020)
//   3. a request not approved by the Head of Department within two days, or not approved before the departure
//      time, becomes "Lapsed" and its vehicle reservation is released
//   4. an "Approved" request whose return time has passed becomes "Completed"
//
// Run it at least ONCE AN HOUR from the command line:     php cron/run_jobs.php
//
// Windows (XAMPP) - Task Scheduler, repeat every hour:
//   Program  : C:\xampp\php\php.exe
//   Arguments: C:\xampp\htdocs\DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR\cron\run_jobs.php
//
// The web pages also run the same jobs lightly (at most every 5 minutes), so the system works even when no
// scheduler is set up.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}
define('SKIP_REQUEST_JOBS', true);
require_once __DIR__ . '/../config/database.php';

$r = (new ScheduledJob())->run();
echo date('Y-m-d H:i:s') . ' ' . http_build_query($r, '', ' ') . PHP_EOL;
