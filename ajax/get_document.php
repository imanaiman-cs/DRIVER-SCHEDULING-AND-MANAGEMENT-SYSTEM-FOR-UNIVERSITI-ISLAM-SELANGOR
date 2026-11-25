<?php
// ============================================================
// ajax/get_document.php  -  View Layer: opens the supporting letter of a request (request.supporting_doc)
// GET id=<request_id>[&download=1]
// Only the people who can see the request (RequestController::isVisibleTo) can open it.
// ============================================================
require_once '../config/database.php';
requireLogin();

function documentError(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit();
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { documentError(404, 'Not found'); }
$rc = new RequestController();
$req = $rc->getRequestDetails($id);
if (!$req || !$rc->isVisibleTo($req, currentUser())) { documentError(403, 'Forbidden'); }

$root = realpath(__DIR__ . '/../uploads/documents');
$path = (string)($req['supporting_doc'] ?? '');
$real = ($root !== false && $path !== '' && strpos($path, "\0") === false) ? realpath(__DIR__ . '/../' . $path) : false;
if ($real === false || !is_file($real) || !is_readable($real) || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0
    || in_array(basename($real), ['.htaccess', '.gitkeep'], true)) {
    documentError(404, 'Not found');
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
$disposition = ($mime !== 'application/octet-stream' && ($_GET['download'] ?? '') !== '1') ? 'inline' : 'attachment';
$name = 'request-' . $id . '-letter.' . $ext;
session_write_close();
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, no-store');
readfile($real);
exit();
