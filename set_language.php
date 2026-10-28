<?php
// ============================================================
// set_language.php  -  switch between English and Bahasa Melayu (cosmetic setting, stored in the cookie and on the account)
// ============================================================
require_once 'config/database.php';

setLanguage($conn, (string)($_GET['lang'] ?? 'en'));

// go back to the page the person came from; only a path on this same server is accepted
$back = (string)($_GET['back'] ?? '');
$base = parse_url(SITE_URL, PHP_URL_PATH) ?: '';
if ($back === '' || $back[0] !== '/' || strpos($back, '//') === 0 || strpos($back, '\\') !== false || preg_match('/[\r\n]/', $back)) {
    $back = $base . '/index.php';
}
header('Location: ' . $back);
exit();
