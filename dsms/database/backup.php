<?php
/**
 * database/backup.php - back up the whole database to one .sql file (no mysqldump needed).
 *
 *     php database/backup.php                 -> storage/backups/dsms_2026-05-01_093000.sql
 *     php database/backup.php /path/file.sql  -> write to a chosen file
 *
 * Restore with:   mysql -u root < storage/backups/dsms_....sql      (or import it in phpMyAdmin)
 * Run it from Task Scheduler / cron once a day for a real backup routine.
 * The backup contains personal data: keep it somewhere safe (storage/backups is ignored by Git).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$db   = Database::connection();
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
$file = $argv[1] ?? (APP_ROOT . '/storage/backups/dsms_' . date('Y-m-d_His') . '.sql');
if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true)) {
    fwrite(STDERR, "Cannot create the folder for $file\n");
    exit(1);
}
$out = fopen($file, 'wb');
$w = fn(string $s) => fwrite($out, $s . "\n");

$w("-- DSMS backup of `$dbName` created " . date('Y-m-d H:i:s'));
$w("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
$w("USE `$dbName`;");
$w('SET FOREIGN_KEY_CHECKS = 0;');
$w("SET NAMES utf8mb4;");

$tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    $w("\nDROP TABLE IF EXISTS `$table`;");
    $w($create . ';');
    $stmt = $db->query("SELECT * FROM `$table`");
    $batch = [];
    $flush = function () use (&$batch, $w, $table): void {
        if ($batch) { $w("INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ';'); $batch = []; }
    };
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $row)) . ')';
        if (count($batch) >= 200) { $flush(); }
    }
    $flush();
}

// The insert-only triggers (R9 / R10) are part of the design, so they are part of the backup.
$w("\nDELIMITER $$");
foreach ($db->query('SHOW TRIGGERS')->fetchAll() as $t) {
    $create = $db->query('SHOW CREATE TRIGGER `' . $t['Trigger'] . '`')->fetch(PDO::FETCH_NUM)[2];
    $create = preg_replace('/^CREATE DEFINER=`[^`]*`@`[^`]*` /', 'CREATE ', $create);   // do not depend on the original MySQL user
    $w('DROP TRIGGER IF EXISTS `' . $t['Trigger'] . "`$$");
    $w($create . '$$');
}
$w('DELIMITER ;');
$w('SET FOREIGN_KEY_CHECKS = 1;');
fclose($out);
echo "Backup written: $file (" . number_format(filesize($file) / 1024, 1) . " KB, " . count($tables) . " tables)\n";
