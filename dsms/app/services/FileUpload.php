<?php
/**
 * FileUpload - safe handling of supporting documents.
 *
 * Rules (security requirement): only PDF / JPG / PNG, a size limit, the real file content is
 * checked (not just the name), the stored name is random, and the file lives in storage/
 * which the web server cannot open directly. Downloads go through a controller that
 * first checks the user is allowed to see the request.
 */
class FileUpload
{
    /**
     * Normalise PHP's awkward $_FILES layout for a multi-file input into a list of single files.
     * Empty slots (no file chosen) are dropped.
     */
    public static function collect(string $field): array
    {
        $raw = $_FILES[$field] ?? null;
        if (!$raw || !is_array($raw['name'])) {
            return [];
        }
        $files = [];
        foreach ($raw['name'] as $i => $name) {
            if ($raw['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = ['name' => $name, 'tmp_name' => $raw['tmp_name'][$i], 'error' => $raw['error'][$i], 'size' => $raw['size'][$i]];
        }
        return $files;
    }

    /** Returns an error message, or null when the file is acceptable. */
    public static function problem(array $file, int $maxMb, array $allowed): ?string
    {
        $name = (string)$file['name'];
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return "$name is too large (maximum $maxMb MB).";
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return "$name could not be uploaded. Please try again.";
        }
        if ($file['size'] > $maxMb * 1024 * 1024) {
            return "$name is too large (maximum $maxMb MB).";
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            return "$name: only PDF, JPG and PNG files are allowed.";
        }
        // Look at the content, because anybody can rename virus.php to virus.pdf.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if ($mime !== $allowed[$ext]) {
            return "$name does not look like a real " . strtoupper($ext) . ' file.';
        }
        return null;
    }

    /**
     * Move a validated file into storage under a random name.
     * @return array{file_path:string, original_name:string, mime_type:string, file_size:int}
     */
    public static function store(array $file, string $dir, array $allowed): array
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The upload folder cannot be created.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            // move_uploaded_file refuses files that did not come from an HTTP upload (e.g. in tests).
            if (!(PHP_SAPI === 'cli' && rename($file['tmp_name'], $dir . '/' . $stored))) {
                throw new RuntimeException('The file could not be saved.');
            }
        }
        return [
            'file_path'     => $stored,
            'original_name' => mb_substr(basename($file['name']), 0, 255),
            'mime_type'     => $allowed[$ext],
            'file_size'     => (int)$file['size'],
        ];
    }
}
