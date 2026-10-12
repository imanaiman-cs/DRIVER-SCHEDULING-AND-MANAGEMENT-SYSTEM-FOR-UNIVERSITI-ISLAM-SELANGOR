<?php
/**
 * SystemController - the "system check" page (admin only): PHP/PDO/database
 * status, table row counts, settings. Handy when installing on a new computer.
 */
class SystemController extends Controller
{
    public function check(): void
    {
        Auth::requireRole('admin_assistant', 'head_admin');

        $dbOk = true; $dbError = null; $counts = []; $byStatus = []; $settings = [];
        try {
            $check    = new SystemCheck();
            $counts   = $check->tableCounts();
            $byStatus = $check->requestsByStatus();
            $settings = (new Setting())->all();
        } catch (Throwable $e) {
            $dbOk = false;
            $dbError = $e->getMessage();
            app_log('System check failed: ' . $e->getMessage(), 'error.log');
        }

        $this->view('system/check', [
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
