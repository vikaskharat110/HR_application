<?php
/**
 * Request/response helpers: base-path aware URLs (the app works at the
 * domain root or in a sub-folder), safe redirects, input access, JSON.
 */
declare(strict_types=1);

/** URL path prefix when installed in a sub-folder ("" at the domain root). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(dirname($script), '/.');
    }
    return $base;
}

/** Application path ("/dashboard/leave") → URL including the base path. */
function url(string $path = '/'): string
{
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_starts_with($path, '//')) {
        return $path; // already absolute (e.g. a Google Maps link)
    }
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : '0';
    return url('/assets/' . ltrim($path, '/')) . '?v=' . $v;
}

/** The request path relative to the base path, e.g. "/dashboard/employees/abc". */
function request_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri = rawurldecode($uri);
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    if (str_starts_with($uri, '/index.php')) {
        $uri = substr($uri, strlen('/index.php'));
    }
    $uri = '/' . trim($uri, '/');
    return $uri;
}

/** Current path + query string, for "redirect back". */
function current_url(): string
{
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    return request_path() . ($qs !== '' ? '?' . $qs : '');
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_starts_with(request_path(), '/api/');
}

/** Trimmed string from POST (never null). */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Raw (untrimmed) POST string — for passwords and multi-line text. */
function post_raw(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

/** Trimmed string from the query string. */
function query(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** An id from POST that must look like a primary key, else a 400. */
function post_id(string $key): string
{
    $v = post($key);
    if (!is_valid_id($v)) {
        throw new HttpError(400);
    }
    return $v;
}

/** Page number from ?page=, min 1. */
function query_page(): int
{
    return max(1, (int)query('page', '1'));
}

/**
 * Only internal application paths may be redirect targets (A5 — open
 * redirect fix). Rejects "//evil.com", "/\evil.com", "https://…",
 * "javascript:" and anything with control characters.
 */
function safe_next(?string $next, string $fallback = '/dashboard'): string
{
    $next = (string)$next;
    if (
        $next === ''
        || $next[0] !== '/'
        || str_starts_with($next, '//')
        || str_starts_with($next, '/\\')
        || preg_match('/[\x00-\x1F\x7F\\\\]/', $next)
        || preg_match('#^/[^/?]*:#', $next)
    ) {
        return $fallback;
    }
    return $next;
}

/** Redirects to an internal path and stops. */
function redirect(string $path, int $status = 303): never
{
    header('Location: ' . url(safe_next($path, '/dashboard')), true, $status);
    exit;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Absolute origin for links in emails: APP_URL if configured, else the request host. */
function app_origin(): string
{
    $configured = config('app.url');
    if ($configured) {
        return $configured;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost';
    }
    return (is_https() ? 'https' : 'http') . '://' . $host . base_path();
}

/** Security headers sent with every dynamic response. */
function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
