<?php
/**
 * Application bootstrap — loaded by public_html/index.php, install scripts
 * and CLI tools. Defines paths, loads .env + config, installs the error
 * handlers and wires the core helpers. Nothing here produces output.
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PAGES_PATH', APP_PATH . '/pages');
define('VIEWS_PATH', APP_PATH . '/views');
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', is_dir(BASE_PATH . '/public_html') ? BASE_PATH . '/public_html' : BASE_PATH);
}

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('WorkNest requires PHP 8.2 or newer.');
}

require APP_PATH . '/core/env.php';
env_load(BASE_PATH . '/.env');

$GLOBALS['__config'] = require APP_PATH . '/config/config.php';

require APP_PATH . '/core/errors.php';
require APP_PATH . '/core/dates.php';
require APP_PATH . '/core/db.php';
require APP_PATH . '/core/ids.php';
require APP_PATH . '/core/http.php';
require APP_PATH . '/core/session.php';
require APP_PATH . '/core/csrf.php';
require APP_PATH . '/core/flash.php';
require APP_PATH . '/core/view.php';
require APP_PATH . '/core/validate.php';
require APP_PATH . '/core/router.php';
require APP_PATH . '/core/auth.php';
require APP_PATH . '/views/components.php';
require APP_PATH . '/views/layout.php';

foreach (glob(APP_PATH . '/lib/*.php') ?: [] as $lib) {
    require $lib;
}
foreach (glob(APP_PATH . '/actions/*.php') ?: [] as $action) {
    require $action;
}

date_default_timezone_set(config('app.timezone'));
mb_internal_encoding('UTF-8');
errors_install();

/** Read a config value with dot notation, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}
