<?php
// ============================================================
// UIS Driver Scheduling and Management System
// cron/run_jobs.php  -  scheduled housekeeping for vehicle requests
//
//   1. a request still "Pending" after 2 days (or whose trip time has passed) becomes "Lapsed":
//      the vehicle lock is released and the staff member and the Head of Department are told
//   2. UC012: a request still "Pending" after 1 day gets ONE reminder to the Head of Department (e-mail + in the system)
//   3. an "Approved" or "Driver Assigned" request that nobody confirmed before the trip time is cancelled
//   4. a "Confirmed" request whose trip is over becomes "Completed"
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
require_once __DIR__ . '/../includes/mailer.php';

$r = runRequestJobs($conn);
echo date('Y-m-d H:i:s') . " lapsed={$r['lapsed']} reminded={$r['reminded']} cancelled={$r['cancelled']} completed={$r['completed']}" . PHP_EOL;
