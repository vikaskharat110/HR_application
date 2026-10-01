<?php
/**
 * Authentication & request guards (A7).
 *
 * The session stores only the user id plus a few integrity markers. On every
 * request the user is reloaded from the database, so:
 *   - a deactivated or deleted user is logged out immediately;
 *   - a changed employee link invalidates the session (as the original did);
 *   - a password change/reset elsewhere invalidates other sessions
 *     (password fingerprint);
 *   - role / custom-role changes apply on the next request;
 *   - a suspended/archived company is blocked on every protected route.
 * A login is valid for at most 7 days (absolute), matching the original.
 */
declare(strict_types=1);

/** The authenticated context for this request, or null. Shape mirrors the original SessionPayload. */
function auth(): ?array
{
    static $loaded = false, $ctx = null;
    if ($loaded) {
        return $ctx;
    }
    $loaded = true;

    $uid = $_SESSION['uid'] ?? null;
    if (!is_string($uid) || $uid === '') {
        return null;
    }
    if ((int)($_SESSION['login_at'] ?? 0) + (int)config('session.lifetime') < time()) {
        auth_clear();
        return null;
    }

    $row = db_one(
        'SELECT u.id, u.email, u.role, u.isActive, u.companyId, u.customRoleId, u.passwordHash,
                e.id AS employeeId, e.firstName, e.lastName,
                cr.name AS customRoleName, c.name AS companyName, c.status AS companyStatus
           FROM `User` u
           LEFT JOIN `Employee` e ON e.userId = u.id
           LEFT JOIN `CustomRole` cr ON cr.id = u.customRoleId
           LEFT JOIN `Company` c ON c.id = u.companyId
          WHERE u.id = ?',
        [$uid]
    );
    $sessionEmployee = $_SESSION['eid'] ?? null;
    if (
        $row === null
        || !(int)$row['isActive']
        || ($sessionEmployee !== null && $sessionEmployee !== $row['employeeId'])
        || !hash_equals((string)($_SESSION['pwf'] ?? ''), password_fingerprint($row['passwordHash']))
    ) {
        auth_clear();
        return null;
    }

    $ctx = [
        'sub' => $row['id'],
        'role' => $row['role'],
        'companyId' => $row['companyId'],
        'employeeId' => $row['employeeId'],
        'name' => $row['employeeId'] ? trim($row['firstName'] . ' ' . $row['lastName']) : $row['email'],
        'email' => $row['email'],
        'customRoleId' => $row['customRoleId'],
        'customRoleName' => $row['customRoleName'],
        'companyName' => $row['companyName'],
        'companyStatus' => $row['companyStatus'],
    ];
    return $ctx;
}

function password_fingerprint(string $hash): string
{
    return substr(hash('sha256', $hash), 0, 32);
}

/** Starts an authenticated session for $user (a `User` row incl. passwordHash). */
function auth_login(array $user, ?string $employeeId): void
{
    session_regenerate_id(true);
    $_SESSION = [
        'uid' => $user['id'],
        'eid' => $employeeId,
        'login_at' => time(),
        'pwf' => password_fingerprint($user['passwordHash']),
    ];
    csrf_rotate();
}

/** Refreshes the password fingerprint after the user changes their own password (keeps this session). */
function auth_refresh_password_fingerprint(string $newHash): void
{
    $_SESSION['pwf'] = password_fingerprint($newHash);
}

function auth_clear(): void
{
    unset($_SESSION['uid'], $_SESSION['eid'], $_SESSION['login_at'], $_SESSION['pwf']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** Denied access: JSON 403 for API calls, otherwise a redirect (original behaviour: back to /dashboard). */
function deny(string $to = '/dashboard'): never
{
    if (wants_json()) {
        throw new HttpError(403);
    }
    redirect($to);
}

function require_login(): array
{
    $a = auth();
    if ($a === null) {
        if (wants_json()) {
            throw new HttpError(401);
        }
        redirect('/login?next=' . rawurlencode(current_url()));
    }
    if ($a['companyId'] !== null && $a['companyStatus'] !== 'ACTIVE') {
        // Suspended/archived tenant: nothing but sign-out is available.
        if (wants_json()) {
            throw new HttpError(403, 'Your company’s access is suspended.');
        }
        http_response_code(403);
        echo view('layout/suspended', ['status' => $a['companyStatus']]);
        exit;
    }
    return $a;
}

/** requireRole: the base role must be one of $roles, otherwise back to /dashboard. */
function require_role(array $roles): array
{
    $a = require_login();
    if (!in_array($a['role'], $roles, true)) {
        deny();
    }
    return $a;
}

/** requireModuleAccess: nav role ceiling + RolePermission overrides. */
function require_module(string $href): array
{
    $a = require_login();
    if (!has_module_access($a, $href)) {
        deny();
    }
    return $a;
}

function has_role(array $auth, array $roles): bool
{
    return in_array($auth['role'], $roles, true);
}

// ───────────────────────────────────────────── Login rate limiting ───

function rate_limit_key(string $bucket, string $subject): string
{
    return STORAGE_PATH . '/cache/ratelimit-' . $bucket . '-' . hash('sha256', $subject) . '.json';
}

/** True if another attempt is allowed; records the attempt when $record is true. */
function rate_limit_hit(string $bucket, string $subject, int $max, int $window, bool $record = true): bool
{
    $file = rate_limit_key($bucket, $subject);
    $now = time();
    $hits = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - $window));
    $allowed = count($hits) < $max;
    if ($record) {
        $hits[] = $now;
        @file_put_contents($file, json_encode($hits), LOCK_EX);
    }
    return $allowed;
}

function rate_limit_reset(string $bucket, string $subject): void
{
    @unlink(rate_limit_key($bucket, $subject));
}
