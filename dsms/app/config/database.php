<?php
/**
 * app/config/database.php
 * ---------------------------------------------------------------
 * Database connection settings. The defaults match a fresh XAMPP
 * (user "root", empty password). Change them in
 * app/config/database.local.php (ignored by Git) if yours differ.
 */

$db = [
    'host'    => 'localhost',
    'port'    => 3306,
    'name'    => 'dsms',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
];

$local = __DIR__ . '/database.local.php';
if (is_file($local)) {
    $db = array_replace($db, require $local);
}

return $db;
