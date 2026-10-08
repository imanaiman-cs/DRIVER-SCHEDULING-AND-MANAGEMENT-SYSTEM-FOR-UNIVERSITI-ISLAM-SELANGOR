<?php
// ============================================================
// UIS Driver Scheduling and Management System
// ajax/get_document.php  –  Access-checked document download
// Universiti Islam Selangor (UIS)
//
// GET id=<doc_id>[&download=1]
// Admin: any document. Staff: documents of their own requests.
// Supervisor: documents of requests assigned to them. Others: 403.
// ============================================================

require_once '../config/database.php';
require_once '../includes/upload.php';
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

$stmt = $conn->prepare(
    "SELECT d.stored_path, d.original_name, d.mime_type,
            vr.staff_id, vr.supervisor_id, vr.status
     FROM request_documents d
     JOIN vehicle_requests vr ON vr.request_id = d.request_id
     WHERE d.doc_id = ?
     LIMIT 1"
);
$stmt->bind_param('i', $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    documentError(404, 'Not found');
}

// ── Authorisation ─────────────────────────────────────────────
$uid     = (int)$_SESSION['user_id'];
$allowed = false;
if (isAdmin()) {
    // Admin only receives requests the Head of Section has approved
    $allowed = in_array($doc['status'], ['approved', 'processed'], true);
} elseif (isStaff()) {
    $allowed = ((int)$doc['staff_id'] === $uid);
} elseif (isSupervisor()) {
    $allowed = ($doc['supervisor_id'] !== null && (int)$doc['supervisor_id'] === $uid);
}
if (!$allowed) {
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
