<?php
// ============================================================
// Settings for a real server (VPS / hosting)
// Copy this file to config/local.php (that file is NOT uploaded to GitHub) and fill in the values.
// Without config/local.php the XAMPP defaults in config/database.php are used.
// ============================================================
return [
    'db_host'  => 'localhost',
    'db_user'  => 'dsms',                 // a dedicated database user, never root
    'db_pass'  => '',
    'db_name'  => 'uis_driver_db',
    'site_url' => 'https://dsms.example.my',   // the public address, no trailing slash
];
