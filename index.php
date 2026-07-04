<?php
require_once 'config/database.php';
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: driver/dashboard.php');
    }
    exit();
}
header('Location: login.php');
exit();
?>
