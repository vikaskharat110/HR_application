<?php
/**
 * Secure uploads (A1) and protected file storage (A2).
 *
 * Files live in storage/uploads/<companyId>/<category>/ — outside the web
 * root — under random server-generated names. Each upload is checked for:
 * PHP upload errors, size (10MB), an allowed extension for its purpose,
 * the MIME type sniffed from the content (finfo) matching that extension,
 * the real file structure (image dimensions, PDF header, Office ZIP parts)
 * and embedded PHP. Nothing is ever served straight from disk: downloads go
 * through /files/{documentId} with authentication + permission checks.
 *
 * Stored references ("fileUrl" columns) look like
 *   storage:<companyId>/employee-documents/<random>.pdf
 * Legacy values from the Next.js app ("/uploads/<companyId>/<name>") are
 * still resolved (copy the old public/uploads/* into storage/uploads/).
 */
declare(strict_types=1);

const UPLOAD_CATEGORIES = ['employee-documents', 'company-documents', 'other'];

/** Extension → acceptable sniffed MIME types. */
const UPLOAD_MIME = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
    'doc' => ['application/msword', 'application/cdfv2', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream'],
    'xls' => ['application/vnd.ms-excel', 'application/cdfv2', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream'],
    'txt' => ['text/plain'],
    'csv' => ['text/csv', 'text/plain', 'application/csv'],
];

/** Allowed extensions per purpose. */
const UPLOAD_ALLOWED = [
    'document' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'doc', 'xls', 'txt', 'csv'],
    'image' => ['png', 'jpg', 'jpeg', 'webp'],
    'docx' => ['docx'],
    'csv' => ['csv', 'txt'],
];

/** Content type we send back for each stored extension (never trust the upload's own header). */
const DOWNLOAD_MIME = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'txt' => 'text/plain; charset=utf-8', 'csv' => 'text/csv; charset=utf-8',
];

/** The $_FILES entry for $field if a file was actually chosen, else null. */
function uploaded_file(string $field): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return $f;
}

/**
 * Validates an uploaded file for $purpose without storing it.
 * @return string the normalised extension
 */
function validate_upload(array $file, string $purpose, ?int $maxBytes = null): string
{
    $maxBytes ??= (int)config('uploads.max_bytes');
    $limitMb = (int)round($maxBytes / 1048576);
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new UserError("That file is larger than {$limitMb}MB — pick a smaller one.");
        default:
            throw new UserError('The upload did not complete. Please try again.');
    }
    if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
        throw new UserError('The upload did not complete. Please try again.');
    }
    $size = (int)$file['size'];
    if ($size <= 0) {
        throw new UserError('That file is empty.');
    }
    if ($size > $maxBytes) {
        throw new UserError("That file is larger than {$limitMb}MB — pick a smaller one.");
    }
    $name = (string)$file['name'];
    if (preg_match('/[\x00-\x1F]/', $name) || mb_strlen($name) > 255) {
        throw new UserError('That file name is not allowed.');
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = UPLOAD_ALLOWED[$purpose] ?? [];
    if (!in_array($ext, $allowed, true)) {
        throw new UserError('That file type isn’t allowed. Allowed: ' . implode(', ', $allowed) . '.');
    }
    $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']));
    if (!in_array($mime, UPLOAD_MIME[$ext], true)) {
        throw new UserError('The file’s contents don’t match its .' . $ext . ' extension.');
    }
    upload_verify_structure($file['tmp_name'], $ext);
    return $ext;
}

/** Deep checks: the bytes really are what the extension claims, and carry no PHP. */
function upload_verify_structure(string $path, string $ext): void
{
    $head = (string)file_get_contents($path, false, null, 0, 4096);
    if (stripos($head, '<?php') !== false || preg_match('/<\?=|<script\s+language\s*=\s*["\']?php/i', $head)) {
        throw new UserError('That file isn’t allowed.');
    }
    $fail = static fn() => throw new UserError('That file appears to be damaged or isn’t a real .' . $ext . ' file.');
    switch ($ext) {
        case 'pdf':
            str_starts_with($head, '%PDF-') || $fail();
            break;
        case 'jpg': case 'jpeg': case 'png': case 'webp': case 'gif':
            $info = @getimagesize($path);
            ($info !== false && $info[0] > 0 && $info[1] > 0) || $fail();
            break;
        case 'docx': case 'xlsx': case 'pptx': case 'odt': case 'ods':
            $marker = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml', 'odt' => 'content.xml', 'ods' => 'content.xml'][$ext];
            $zip = new ZipArchive();
            ($zip->open($path) === true && $zip->locateName($marker) !== false) || $fail();
            $zip->close();
            break;
        case 'doc': case 'xls':
            str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") || $fail();
            break;
        case 'txt': case 'csv':
            mb_check_encoding($head, 'UTF-8') || preg_match('//u', mb_convert_encoding($head, 'UTF-8', 'Windows-1252')) || $fail();
            break;
    }
}

/**
 * Validates and stores an upload. Returns the storage reference for the
 * database ("storage:<companyId>/<category>/<random>.<ext>").
 */
function store_upload(array $file, string $companyId, string $category, string $purpose): string
{
    if (!in_array($category, UPLOAD_CATEGORIES, true) || !is_valid_id($companyId)) {
        throw new LogicException('Invalid upload destination');
    }
    $ext = validate_upload($file, $purpose);
    $rel = $companyId . '/' . $category . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = storage_file_path($rel, true);
    $ok = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest);
    if (!$ok) {
        throw new RuntimeException('Could not store uploaded file');
    }
    @chmod($dest, 0640);
    return 'storage:' . $rel;
}

/** Stores generated bytes (letters) and returns the storage reference. */
function store_generated(string $bytes, string $companyId, string $category, string $ext): string
{
    $rel = $companyId . '/' . $category . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    file_put_contents(storage_file_path($rel, true), $bytes, LOCK_EX);
    return 'storage:' . $rel;
}

/** Absolute path for a storage-relative path, refusing traversal. */
function storage_file_path(string $rel, bool $createDir = false): string
{
    if (!preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_.-]+)+$#', $rel) || str_contains($rel, '..')) {
        throw new LogicException('Invalid storage path');
    }
    $path = config('uploads.path') . '/' . $rel;
    if ($createDir && !is_dir(dirname($path))) {
        mkdir(dirname($path), 0750, true);
    }
    return $path;
}

/**
 * Resolves a stored reference to an absolute file path inside
 * storage/uploads/<companyId>/, or null ("#", missing, foreign company).
 */
function resolve_stored_file(?string $ref, string $companyId): ?string
{
    if ($ref === null || $ref === '' || $ref === '#') {
        return null;
    }
    if (str_starts_with($ref, 'storage:')) {
        $rel = substr($ref, 8);
    } elseif (str_starts_with($ref, '/uploads/')) {
        $rel = substr($ref, 9); // legacy Next.js public URL
    } else {
        return null;
    }
    if (!str_starts_with($rel, $companyId . '/')) {
        return null; // never serve another tenant's file
    }
    try {
        $path = storage_file_path($rel);
    } catch (LogicException) {
        return null;
    }
    return is_file($path) ? $path : null;
}

/** Removes a stored file (best effort) — used when a document is replaced or deleted. */
function delete_stored_file(?string $ref, string $companyId): void
{
    $path = resolve_stored_file($ref, $companyId);
    if ($path !== null) {
        @unlink($path);
    }
}

/**
 * Streams a stored file. PDFs/images open inline (as the original's "open in
 * new tab"), everything else downloads. Sandboxed CSP so even a crafted file
 * can't run script in our origin.
 */
function send_stored_file(string $path, string $downloadName): never
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = DOWNLOAD_MIME[$ext] ?? 'application/octet-stream';
    $inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    $base = trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', pathinfo($downloadName, PATHINFO_FILENAME) ?: 'file'));
    $fileName = ($base !== '' ? mb_substr($base, 0, 120) : 'file') . '.' . $ext;
    $ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', $fileName);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
