<?php
// driver/update_vehicle.php  -  replaced by the task report (SRS 10 Nov, UC024). Old links go to driver/task_report.php.
require_once '../config/database.php';
requireDriver();
$q = http_build_query(array_filter(['trip' => (int)($_GET['trip'] ?? 0), 'internal' => (int)($_GET['internal'] ?? 0)]));
header('Location: ' . SITE_URL . '/driver/task_report.php' . ($q !== '' ? '?' . $q : ''));
exit();
