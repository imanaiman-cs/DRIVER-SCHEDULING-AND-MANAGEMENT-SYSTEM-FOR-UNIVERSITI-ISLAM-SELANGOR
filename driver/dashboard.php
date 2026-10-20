<?php
// The driver's home is "My Tasks" (UC029).
require_once '../config/database.php';
requireDriver();
header('Location: ' . SITE_URL . '/driver/schedules.php');
exit();
