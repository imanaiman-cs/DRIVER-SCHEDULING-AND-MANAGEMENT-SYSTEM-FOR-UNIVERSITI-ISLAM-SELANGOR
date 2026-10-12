<?php
/**
 * HomeController - Step 1 landing page.
 *
 * For now it is a "system check" page so you can see that the router, the
 * layout, the database connection and the seed data all work. In Step 2 it
 * becomes the role-based dashboard after login.
 */
class HomeController extends Controller
{
    public function index(): void
    {
        $dbOk    = true;
        $dbError = null;
        $counts  = [];
        $byStatus = [];
        $settings = [];

        try {
            $check    = new SystemCheck();
            $counts   = $check->tableCounts();
            $byStatus = $check->requestsByStatus();
            $settings = (new Setting())->all();
        } catch (Throwable $e) {
            $dbOk    = false;
            $dbError = $e->getMessage();
            app_log('Home check failed: ' . $e->getMessage(), 'error.log');
        }

        $this->view('home/index', [
            'title'    => 'System check',
            'phpOk'    => version_compare(PHP_VERSION, '8.0.0', '>='),
            'pdoOk'    => extension_loaded('pdo_mysql'),
            'dbOk'     => $dbOk,
            'dbError'  => $GLOBALS['config']['debug'] ? $dbError : null,
            'counts'   => $counts,
            'byStatus' => $byStatus,
            'settings' => $settings,
        ]);
    }
}
