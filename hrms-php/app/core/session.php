<?php
/**
 * PHP session setup (A7). Sessions are file-based under storage/sessions so
 * the 7-day lifetime isn't cut short by a shared host's global session GC.
 */
declare(strict_types=1);

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = (int)config('session.lifetime');
    $dir = STORAGE_PATH . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    ini_set('session.save_path', $dir);
    ini_set('session.use_strict_mode', '1');     // reject ids the server never issued
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.gc_probability', '1');      // our own directory — nobody else cleans it
    ini_set('session.gc_divisor', '100');
    session_name(config('session.name'));
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => base_path() === '' ? '/' : base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Destroys the session and its cookie completely (logout). */
function session_destroy_all(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $p['path'],
        'secure' => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_destroy();
}
