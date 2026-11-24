<?php
require_once 'config/database.php';
header('Location: ' . (isLoggedIn() ? roleHomeUrl() : SITE_URL . '/login.php'));
exit();
