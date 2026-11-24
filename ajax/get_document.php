<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/get_document.php  –  Access-checked document download
// Universiti Islam Selangor (UIS)
//
// GET id=<doc_id>[&download=1]
// Only the people who can see the request (requestVisibleTo) can open its documents.
// ============================================================

require_once '../config/database.php';
requireLogin();

/** Sends a plain-text error and stops. */
function documentError(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit();
}

$doc_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$doc_id) {
    documentError(404, 'Not found');
}

$doc = dbOne($conn, 'SELECT d.stored_path, d.original_name, d.mime_type, d.request_id FROM request_documents d WHERE d.doc_id = ? LIMIT 1', [$doc_id]);
if (!$doc) {
    documentError(404, 'Not found');
}
// ── Authorisation: the same people who can see the request ──
$req = loadRequest($conn, (int)$doc['request_id']);
if (!$req || !requestVisibleTo($conn, $req, currentUser())) {
    documentError(403, 'Forbidden');
}

// ── Resolve the file, confined to uploads/documents/ ──────────
$root = realpath(__DIR__ . '/../uploads/documents');
$path = (string)$doc['stored_path'];
$real = ($root !== false && $path !== '' && strpos($path, "\0") === false)
    ? realpath(__DIR__ . '/../' . $path)
    : false;

if ($real === false || !is_file($real) || !is_readable($real)
    || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0
    || in_array(basename($real), ['.htaccess', '.gitkeep'], true)) {
    documentError(404, 'Not found');
}

// ── Headers ───────────────────────────────────────────────────
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
$mime         = in_array($doc['mime_type'], $allowedMimes, true) ? $doc['mime_type'] : 'application/octet-stream';
$forceDl      = isset($_GET['download']) && $_GET['download'] === '1';
$disposition  = ($mime !== 'application/octet-stream' && !$forceDl) ? 'inline' : 'attachment';

$name = sanitizeDocumentName((string)$doc['original_name']);
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
$ascii = trim((string)$ascii, '_');
if ($ascii === '' || $ascii[0] === '.') {
    $ascii = 'document' . ($ascii !== '' ? $ascii : '');
}

$size = filesize($real);

// Release the session lock before streaming a possibly large file.
session_write_close();

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition
    . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
if ($size !== false) {
    header('Content-Length: ' . $size);
}
header('Cache-Control: private, no-store');

readfile($real);
exit();
