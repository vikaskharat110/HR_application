<?php
/**
 * CSRF protection (A6). One random token per session; every POST (forms and
 * AJAX) must echo it back in `_csrf` or the X-CSRF-Token header. Verified
 * centrally by the router before any handler runs.
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['_csrf'] ?? '';
    if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
        // 403 rather than the non-standard 419: Apache rewrites unknown statuses to 500.
        throw new HttpError(403, http_status_text(419));
    }
}

/** A new token after login/logout so a pre-login token can't be replayed. */
function csrf_rotate(): void
{
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
