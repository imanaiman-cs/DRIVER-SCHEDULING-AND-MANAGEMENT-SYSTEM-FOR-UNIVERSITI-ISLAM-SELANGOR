<?php
// driver/profile.php  -  replaced by the profile page of every role (SRS 10 Nov, UC016). Old links go to profile.php.
require_once '../config/database.php';
requireLogin();
header('Location: ' . SITE_URL . '/profile.php');
exit();
