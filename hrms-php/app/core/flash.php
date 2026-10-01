<?php
/**
 * One-request flash messages (success/error after a POST → redirect) and
 * "old input" so a form re-renders with what the user typed after an error.
 * Messages may be scoped to a named form so they render beside it.
 */
declare(strict_types=1);

function flash(string $type, string $message, string $scope = ''): void
{
    $_SESSION['_flash'][$scope][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> — consumed on read */
function flashes(string $scope = ''): array
{
    $items = $_SESSION['_flash'][$scope] ?? [];
    unset($_SESSION['_flash'][$scope]);
    return $items;
}

function has_flash(string $scope = ''): bool
{
    return !empty($_SESSION['_flash'][$scope]);
}

/** Remembers submitted input (minus secrets) for one request. */
function flash_old(array $input): void
{
    foreach (['password', 'currentPassword', 'newPassword', 'confirmPassword', '_csrf'] as $secret) {
        unset($input[$secret]);
    }
    $_SESSION['_old'] = $input;
}

function old(string $key, mixed $default = ''): mixed
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
    }
    return $old[$key] ?? $default;
}
