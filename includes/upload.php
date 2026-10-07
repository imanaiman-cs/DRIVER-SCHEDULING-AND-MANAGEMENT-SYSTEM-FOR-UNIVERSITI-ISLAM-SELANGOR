<?php
// ============================================================
// UIS Driver Scheduling and Management System
// includes/upload.php  –  Image upload helpers (vehicles/drivers)
// Universiti Islam Selangor (UIS)
// ============================================================

if (!defined('UPLOAD_MAX_IMAGE_DIMENSION')) {
    define('UPLOAD_MAX_IMAGE_DIMENSION', 6000);
}

/**
 * Validates and stores an uploaded image under uploads/<subdir>/.
 *
 * @param array  $file     An entry of $_FILES (e.g. $_FILES['photo'])
 * @param string $subdir   'vehicles' or 'drivers'
 * @param int    $maxBytes Maximum accepted size in bytes (default 2 MB)
 *
 * @return array{ok: bool, path: ?string, error: ?string}
 *         ok=true,path=null  -> no file was submitted
 *         ok=true,path=str   -> stored; path relative to project root
 *         ok=false           -> error holds a user-facing message
 */
function saveUploadedImage(array $file, string $subdir, int $maxBytes = 2097152): array
{
    $fail = static function (string $msg): array {
        return ['ok' => false, 'path' => null, 'error' => $msg];
    };

    if (!in_array($subdir, ['vehicles', 'drivers'], true)) {
        return $fail('Invalid upload destination.');
    }

    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_array($err)) {
        return $fail('Invalid upload.');
    }
    $err = (int)$err;

    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }

    switch ($err) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return $fail('The photo is too large. Maximum size is ' . round($maxBytes / 1048576, 1) . ' MB.');
        case UPLOAD_ERR_PARTIAL:
            return $fail('The photo was only partially uploaded. Please try again.');
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return $fail('The server could not store the uploaded photo.');
        default:
            return $fail('The photo could not be uploaded.');
    }

    $tmp  = $file['tmp_name'] ?? '';
    $size = (int)($file['size'] ?? 0);

    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
        return $fail('Invalid upload.');
    }
    if ($size <= 0 || $size > $maxBytes || filesize($tmp) > $maxBytes) {
        return $fail('The photo is too large. Maximum size is ' . round($maxBytes / 1048576, 1) . ' MB.');
    }

    // Real MIME type from file contents, never from the client.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        return $fail('Only JPG, PNG or WebP images are allowed.');
    }

    $info = @getimagesize($tmp);
    if ($info === false || !isset($info[0], $info[1]) || ($info['mime'] ?? '') !== $mime) {
        return $fail('The file is not a valid image.');
    }
    if ($info[0] < 1 || $info[1] < 1
        || $info[0] > UPLOAD_MAX_IMAGE_DIMENSION || $info[1] > UPLOAD_MAX_IMAGE_DIMENSION) {
        return $fail('The image dimensions must not exceed ' . UPLOAD_MAX_IMAGE_DIMENSION . ' x ' . UPLOAD_MAX_IMAGE_DIMENSION . ' pixels.');
    }

    $dir = __DIR__ . '/../uploads/' . $subdir . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return $fail('The server could not store the uploaded photo.');
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];

    if (!move_uploaded_file($tmp, $dir . $filename)) {
        return $fail('The server could not store the uploaded photo.');
    }
    @chmod($dir . $filename, 0644);

    return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $filename, 'error' => null];
}

/**
 * Deletes a previously stored upload. Only files that resolve inside the
 * project's uploads/ directory are removed; anything else (including
 * traversal attempts, missing files and null) is silently ignored.
 *
 * @param string|null $path Relative path as stored in the photo column
 */
function deleteUploadedImage(?string $path): void
{
    if ($path === null || $path === '' || strpos($path, "\0") !== false) {
        return;
    }

    $root = realpath(__DIR__ . '/../uploads');
    if ($root === false) {
        return;
    }

    // Stored paths are relative to the project root; resolve against it.
    $candidate = (strpos($path, '/') === 0 || preg_match('#^[A-Za-z]:[\\\\/]#', $path))
        ? $path
        : __DIR__ . '/../' . $path;

    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return;
    }

    if (strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }

    // Never delete the protective files.
    if (in_array(basename($real), ['.htaccess', '.gitkeep'], true)) {
        return;
    }

    @unlink($real);
}

// ============================================================
// Supporting documents (PDF / images) for vehicle requests
// Stored privately in uploads/documents/ and served only via
// ajax/get_document.php.
// ============================================================

/**
 * Allowed supporting-document types: value => display label.
 *
 * @return array<string,string>
 */
function documentTypes(): array
{
    return [
        'release_letter'  => 'Release Letter (Surat Pelepasan)',
        'seminar_letter'  => 'Seminar Letter (Surat Seminar)',
        'approval_letter' => 'Approval Letter (Surat Kelulusan)',
        'programme'       => 'Programme Schedule (Aturcara Program)',
        'other'           => 'Other Document (Lain-lain)',
    ];
}

/**
 * Normalises a multi-file $_FILES[$field] (name[], type[], ...) into a list
 * of single-file arrays keyed by the ORIGINAL index, so file i can be paired
 * with $_POST['doc_type'][i]. UPLOAD_ERR_NO_FILE entries are skipped. The
 * non-array (single input) shape is accepted too and returned at index 0.
 *
 * @return array<int|string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function collectUploadedFiles(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }
    $f = $_FILES[$field];

    if (!isset($f['name'], $f['tmp_name'], $f['error'])) {
        return [];
    }

    // Single-file shape.
    if (!is_array($f['name'])) {
        if (is_array($f['tmp_name']) || is_array($f['error'])) {
            return [];
        }
        if ((int)$f['error'] === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [0 => [
            'name'     => (string)$f['name'],
            'type'     => (string)($f['type'] ?? ''),
            'tmp_name' => (string)$f['tmp_name'],
            'error'    => (int)$f['error'],
            'size'     => (int)($f['size'] ?? 0),
        ]];
    }

    // Multi-file shape.
    if (!is_array($f['tmp_name']) || !is_array($f['error'])) {
        return [];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $err = $f['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($err) || is_array($name)) {
            continue; // nested arrays are not supported
        }
        $err = (int)$err;
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $tmp = $f['tmp_name'][$i] ?? '';
        $typ = $f['type'][$i] ?? '';
        $siz = $f['size'][$i] ?? 0;
        if (is_array($tmp) || is_array($typ) || is_array($siz)) {
            continue;
        }
        $out[$i] = [
            'name'     => (string)$name,
            'type'     => (string)$typ,
            'tmp_name' => (string)$tmp,
            'error'    => $err,
            'size'     => (int)$siz,
        ];
    }
    return $out;
}

/**
 * Pure validation of a candidate document file (no moving, no is_uploaded_file).
 * The type is derived from the file CONTENT; client name/MIME are never used.
 *
 * @return array{ok:bool,mime:?string,ext:?string,error:?string}
 */
function validateDocumentFile(string $tmpPath, int $size, int $maxBytes = 5242880): array
{
    $fail = static function (string $msg): array {
        return ['ok' => false, 'mime' => null, 'ext' => null, 'error' => $msg];
    };
    $maxLabel = round($maxBytes / 1048576, 1) . ' MB';

    if ($tmpPath === '' || strpos($tmpPath, "\0") !== false || !is_file($tmpPath) || !is_readable($tmpPath)) {
        return $fail('The uploaded file could not be read.');
    }

    $actual = filesize($tmpPath);
    if ($size < 1 || $actual === false || $actual < 1) {
        return $fail('The file is empty.');
    }
    if ($size > $maxBytes || $actual > $maxBytes) {
        return $fail('The file is too large. Maximum size is ' . $maxLabel . '.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmpPath);
    $extensions = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        return $fail('Only PDF, JPG, PNG or WebP files are allowed.');
    }

    if ($mime === 'application/pdf') {
        $fh   = @fopen($tmpPath, 'rb');
        $head = $fh ? fread($fh, 5) : false;
        if ($fh) {
            fclose($fh);
        }
        if ($head !== '%PDF-') {
            return $fail('The file is not a valid PDF.');
        }
    } else {
        $info = @getimagesize($tmpPath);
        if ($info === false || !isset($info[0], $info[1]) || $info[0] < 1 || $info[1] < 1) {
            return $fail('The file is not a valid image.');
        }
    }

    return ['ok' => true, 'mime' => $mime, 'ext' => $extensions[$mime], 'error' => null];
}

/**
 * Makes a client-supplied filename safe for storage/display: basename only,
 * no control characters or path separators, max 200 characters, Unicode kept.
 */
function sanitizeDocumentName(string $name): string
{
    $name = basename(str_replace("\\", '/', $name));
    // Drop control characters (byte-wise, safe for invalid UTF-8 too).
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
    $name = str_replace(['/', '\\', '"'], '', $name);
    $name = trim($name, " .\t");
    if (!mb_check_encoding($name, 'UTF-8')) {
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
    }
    if (mb_strlen($name, 'UTF-8') > 200) {
        $name = mb_substr($name, 0, 200, 'UTF-8');
    }
    return $name === '' ? 'document' : $name;
}

/**
 * Validates and stores one uploaded supporting document under
 * uploads/documents/ with a random server-side filename.
 *
 * @param array $file An entry shaped like a single $_FILES[...] file
 *
 * @return array{ok:bool,path:?string,original_name:?string,mime:?string,size:?int,error:?string}
 */
function saveUploadedDocument(array $file, int $maxBytes = 5242880): array
{
    $fail = static function (string $msg): array {
        return ['ok' => false, 'path' => null, 'original_name' => null,
                'mime' => null, 'size' => null, 'error' => $msg];
    };
    $maxLabel = round($maxBytes / 1048576, 1) . ' MB';

    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_array($err)) {
        return $fail('Invalid upload.');
    }
    switch ((int)$err) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return $fail('No file was uploaded.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return $fail('The file is too large. Maximum size is ' . $maxLabel . '.');
        case UPLOAD_ERR_PARTIAL:
            return $fail('The file was only partially uploaded. Please try again.');
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return $fail('The server could not store the uploaded file.');
        default:
            return $fail('The file could not be uploaded.');
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp) || $tmp === '') {
        return $fail('Invalid upload.');
    }

    $testMode = defined('UPLOAD_TEST_MODE') && UPLOAD_TEST_MODE === true;
    $isUpload = is_uploaded_file($tmp);
    if (!$isUpload && !$testMode) {
        return $fail('Invalid upload.');
    }

    $size = (int)($file['size'] ?? 0);
    $v = validateDocumentFile($tmp, $size, $maxBytes);
    if (!$v['ok']) {
        return $fail((string)$v['error']);
    }

    $dir = __DIR__ . '/../uploads/documents/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return $fail('The server could not store the uploaded file.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $v['ext'];
    $dest     = $dir . $filename;

    if ($isUpload) {
        $moved = move_uploaded_file($tmp, $dest);
    } else { // test mode only
        $moved = @rename($tmp, $dest) || (@copy($tmp, $dest) && @unlink($tmp));
    }
    if (!$moved) {
        return $fail('The server could not store the uploaded file.');
    }
    @chmod($dest, 0644);

    return [
        'ok'            => true,
        'path'          => 'uploads/documents/' . $filename,
        'original_name' => sanitizeDocumentName((string)($file['name'] ?? '')),
        'mime'          => $v['mime'],
        'size'          => (int)filesize($dest),
        'error'         => null,
    ];
}

/**
 * Deletes a stored supporting document. Only files that resolve inside
 * uploads/documents/ are removed; traversal attempts, missing files, null and
 * the protective .htaccess/.gitkeep files are silently ignored.
 */
function deleteStoredDocument(?string $path): void
{
    if ($path === null || $path === '' || strpos($path, "\0") !== false) {
        return;
    }

    $root = realpath(__DIR__ . '/../uploads/documents');
    if ($root === false) {
        return;
    }

    $candidate = (strpos($path, '/') === 0 || preg_match('#^[A-Za-z]:[\\\\/]#', $path))
        ? $path
        : __DIR__ . '/../' . $path;

    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return;
    }
    if (strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }
    if (in_array(basename($real), ['.htaccess', '.gitkeep'], true)) {
        return;
    }

    @unlink($real);
}

/**
 * URL of the access-checked download endpoint for a document.
 */
function documentDownloadUrl(int $doc_id): string
{
    return SITE_URL . '/ajax/get_document.php?id=' . $doc_id;
}

/**
 * Human-readable file size, e.g. '1.2 MB', '340 KB', '512 B'.
 */
function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return max(0, $bytes) . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024) . ' KB';
    }
    return number_format($bytes / 1048576, 1, '.', '') . ' MB';
}

/**
 * Loads the supporting documents of several requests with ONE query.
 *
 * @param int[] $request_ids
 * @return array<int,array<int,array<string,mixed>>> [request_id => [doc, ...]]
 */
function getRequestDocuments(mysqli $conn, array $request_ids): array
{
    $ids = [];
    foreach ($request_ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if (!$ids) {
        return [];
    }

    $labels       = documentTypes();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT doc_id, request_id, doc_type, original_name, mime_type, file_size, uploaded_at
         FROM request_documents
         WHERE request_id IN ($placeholders)
         ORDER BY request_id, uploaded_at, doc_id"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $type = (string)$row['doc_type'];
        $out[(int)$row['request_id']][] = [
            'doc_id'         => (int)$row['doc_id'],
            'doc_type'       => $type,
            'doc_type_label' => $labels[$type] ?? $labels['other'],
            'original_name'  => $row['original_name'],
            'mime_type'      => $row['mime_type'],
            'file_size'      => (int)$row['file_size'],
            'uploaded_at'    => $row['uploaded_at'],
            'url'            => documentDownloadUrl((int)$row['doc_id']),
        ];
    }
    $stmt->close();

    return $out;
}
