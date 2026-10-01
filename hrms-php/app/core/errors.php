<?php
/**
 * Error handling. Production never shows SQL errors, stack traces, paths or
 * configuration — the technical detail goes to storage/logs/ with a short
 * reference id, and the user sees a safe message quoting that id.
 */
declare(strict_types=1);

/** A validation/business-rule failure whose message is safe to show the user. */
class UserError extends RuntimeException
{
}

/** An HTTP-level stop (404, 403, 405, 419 ...) — rendered as a friendly page. */
class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : http_status_text($status), $status);
    }
}

function http_status_text(int $status): string
{
    return match ($status) {
        400 => 'Bad request',
        401 => 'Please sign in',
        403 => 'You don’t have access to this page',
        404 => 'Page not found',
        405 => 'Method not allowed',
        419 => 'Your session expired — please go back, refresh the page and try again',
        429 => 'Too many attempts — please wait a few minutes and try again',
        default => 'Something went wrong. Please try again or contact the administrator.',
    };
}

function errors_install(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', config('app.debug') ? '1' : '0');
    ini_set('log_errors', '1');

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(static function (Throwable $e): void {
        handle_uncaught($e);
    });
}

/** Writes a technical log line and returns a short reference id for the user. */
function log_error(Throwable $e, array $context = []): string
{
    $ref = strtoupper(bin2hex(random_bytes(4)));
    $line = sprintf(
        "[%s] ref=%s %s: %s in %s:%d | %s %s | user=%s %s\n%s\n",
        date('Y-m-d H:i:s'),
        $ref,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        $_SERVER['REQUEST_URI'] ?? '',
        $_SESSION['uid'] ?? '-',
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '',
        $e->getTraceAsString()
    );
    log_write('app', $line);
    return $ref;
}

function log_write(string $channel, string $line): void
{
    $dir = STORAGE_PATH . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($dir . '/' . $channel . '-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function handle_uncaught(Throwable $e): void
{
    if ($e instanceof HttpError) {
        render_error_page($e->status, $e->getMessage());
        return;
    }
    $ref = log_error($e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e . PHP_EOL);
        exit(1);
    }
    render_error_page(500, 'Something went wrong. Please try again or contact the administrator.', $ref, config('app.debug') ? (string)$e : null);
}

function render_error_page(int $status, string $message, ?string $ref = null, ?string $debug = null): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
    }
    if (function_exists('wants_json') && wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message, 'ref' => $ref]);
        return;
    }
    $title = http_status_text($status);
    require VIEWS_PATH . '/layout/error.php';
}
