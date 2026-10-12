<?php
/**
 * public/index.php  -  the FRONT CONTROLLER.
 * Every browser request enters the application here, nowhere else.
 * (Only the public/ folder is meant to be reachable from the web.)
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

// R11 housekeeping also runs lightly on page loads (at most every 5 minutes), so lapsed and
// finished requests are up to date even if the scheduled cron job is not set up.
if (Auth::check()) {
    try {
        (new Jobs())->runThrottled();
    } catch (Throwable $e) {
        app_log('Jobs failed: ' . $e->getMessage(), 'error.log');   // never block the page
    }
}

(new Router())->dispatch();
