<?php
/**
 * Central router. Routes live in app/routes.php and keep the original
 * Next.js URLs exactly. Each route declares its guards; the router enforces
 * them (plus CSRF on every POST) before the page handler runs, and every
 * {param} must pass the id shape check before reaching any query.
 *
 * Route options:
 *   'auth'   => bool     login required (default true)
 *   'roles'  => string[] allowed roles (requireRole equivalent)
 *   'module' => string   module href for the RolePermission layer (requireModuleAccess)
 *   'dir'    => string   page directory (default app/pages; dev/routes.php uses dev/pages)
 *   'methods'=> string[] default ['GET','POST']
 */
declare(strict_types=1);

$GLOBALS['__routes'] = [];

function route(string $pattern, string $handler, array $options = []): void
{
    $regex = preg_replace_callback('/\{(\w+)\}/', static fn($m) => '(?P<' . $m[1] . '>[^/]+)', $pattern);
    $GLOBALS['__routes'][] = [
        'pattern' => $pattern,
        'regex' => '#^' . $regex . '$#',
        'handler' => $handler,
        'options' => $options + ['auth' => true, 'methods' => ['GET', 'POST']],
    ];
}

function dispatch(string $path, string $method): void
{
    foreach ($GLOBALS['__routes'] as $route) {
        if (!preg_match($route['regex'], $path, $m)) {
            continue;
        }
        $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
        foreach ($params as $value) {
            if (!is_valid_id($value)) {
                throw new HttpError(404);
            }
        }
        $opts = $route['options'];
        if (!in_array($method, $opts['methods'], true)) {
            header('Allow: ' . implode(', ', $opts['methods']));
            throw new HttpError(405);
        }
        if ($method === 'POST') {
            csrf_verify();
        }

        $auth = null;
        if ($opts['auth']) {
            $auth = require_login();
            if (!empty($opts['roles'])) {
                require_role($opts['roles']);
            }
            if (!empty($opts['module'])) {
                require_module($opts['module']);
            }
        }

        run_handler($route['handler'], $params, $auth, $opts['dir'] ?? PAGES_PATH);
        return;
    }
    throw new HttpError(404);
}

/** Includes a page file in an isolated scope with $params and $auth defined. */
function run_handler(string $handler, array $params, ?array $auth, string $dir = PAGES_PATH): void
{
    $file = $dir . '/' . $handler;
    if (!preg_match('#^[a-z0-9_/-]+\.php$#', $handler) || !is_file($file)) {
        throw new LogicException("Page handler not found: $handler");
    }
    (static function (string $__file, array $params, ?array $auth): void {
        require $__file;
    })($file, $params, $auth);
}

/**
 * Standard POST handling for a page: runs the handler named by `_action`,
 * then redirects (Post/Redirect/Get).
 *
 * - A handler may return an internal path to redirect to; otherwise the
 *   current URL is reloaded.
 * - UserError → its message is flashed (scoped to `_form` if given) and the
 *   submitted input is kept for re-display.
 * - DB constraint violations → a safe generic message (no SQL shown).
 *
 * @param array<string, callable():(string|null)> $handlers
 */
function handle_post(array $handlers): void
{
    if (!is_post()) {
        return;
    }
    $action = post('_action');
    $scope = preg_replace('/[^a-z0-9_-]/i', '', post('_form'));
    if (!isset($handlers[$action])) {
        throw new HttpError(400);
    }
    $back = safe_next(post('_back'), current_url());
    try {
        $target = $handlers[$action]();
        redirect(is_string($target) ? $target : $back);
    } catch (UserError $e) {
        flash('error', $e->getMessage(), $scope);
        flash_old($_POST);
        redirect($back);
    } catch (PDOException $e) {
        if (!db_is_constraint_violation($e)) {
            throw $e;
        }
        log_error($e, ['action' => $action]);
        flash('error', db_is_duplicate_key($e)
            ? 'That record already exists — please use a different value.'
            : 'This change conflicts with related records and was not saved.', $scope);
        flash_old($_POST);
        redirect($back);
    }
}
