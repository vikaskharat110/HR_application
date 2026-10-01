<?php
/**
 * WorkNest — front controller. Every request that isn't a static asset is
 * rewritten here by .htaccess.
 *
 * Two supported layouts:
 *   Recommended — application code outside the web root:
 *       /home/<account>/app, storage, .env      (private)
 *       /home/<account>/public_html/index.php   (this file)
 *   Fallback — everything inside public_html, private folders blocked by
 *   .htaccess (app/, storage/, database/, dev/, .env).
 */
declare(strict_types=1);

// PHP's built-in dev server: let it serve real files under /assets directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/assets/') && is_file($file)) {
        return false;
    }
}

define('PUBLIC_PATH', __DIR__);
if (is_file(dirname(__DIR__) . '/app/bootstrap.php')) {
    define('BASE_PATH', dirname(__DIR__));          // recommended layout
} elseif (is_file(__DIR__ . '/app/bootstrap.php')) {
    define('BASE_PATH', __DIR__);                   // fallback layout
} else {
    http_response_code(500);
    exit('Application files not found. See README.md → Deployment.');
}

require BASE_PATH . '/app/bootstrap.php';
require APP_PATH . '/routes.php';

header_remove('X-Powered-By');
send_security_headers();
header('Cache-Control: no-store, private');
session_boot();

try {
    dispatch(request_path(), request_method());
} catch (Throwable $e) {
    handle_uncaught($e);
}
