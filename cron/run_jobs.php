<?php
// ============================================================
// UIS Driver Scheduling and Management System
// cron/run_jobs.php  –  scheduled housekeeping for vehicle requests (R11)
//
//   1. a request still "pending" after 48 hours (or whose trip time has passed) becomes "lapsed"
//   2. a "processed" request whose trip has finished becomes "completed"
//   3. the Head of Section gets ONE automatic reminder after 24 hours
//
// Run it from the command line:        php cron/run_jobs.php
//
// Windows (XAMPP) - Task Scheduler, every 15 minutes:
//   Program  : C:\xampp\php\php.exe
//   Arguments: C:\xampp\htdocs\DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR\cron\run_jobs.php
//
// The web pages also run the same jobs lightly (at most every 5 minutes),
// so the system works even when no scheduler is set up.
// ============================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}
define('SKIP_REQUEST_JOBS', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mailer.php';

$r = runRequestJobs($conn);
echo date('Y-m-d H:i:s') . " lapsed={$r['lapsed']} completed={$r['completed']} reminded={$r['reminded']}" . PHP_EOL;
