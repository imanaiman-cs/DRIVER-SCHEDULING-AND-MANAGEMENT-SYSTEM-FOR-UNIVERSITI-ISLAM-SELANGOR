<?php
// UC016C Change Password lives on the profile page.
require_once 'config/database.php';
requireLogin();
header('Location: ' . SITE_URL . '/profile.php#password');
exit();
