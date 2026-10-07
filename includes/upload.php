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
