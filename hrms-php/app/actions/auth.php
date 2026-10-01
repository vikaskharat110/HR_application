<?php
/**
 * Account actions — port of lib/actions/auth.ts and lib/actions/profile.ts.
 */
declare(strict_types=1);

const RESET_TOKEN_TTL_SECONDS = 30 * 60; // 30 minutes, as the original

/**
 * Login. Differences from the original, all hardening:
 *  - one message for "no such/inactive account" and "wrong password" (no user enumeration);
 *  - attempts are rate-limited per IP+email;
 *  - the session id is regenerated (session fixation).
 * The company status is still checked only after a correct password, so a
 * wrong password never reveals whether a tenant is suspended.
 *
 * @return string redirect target
 */
function action_login(string $email, string $password, string $next): string
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        throw new UserError('Enter both email and password.');
    }
    $limitKey = client_ip() . '|' . $email;
    if (!rate_limit_hit('login', $limitKey, (int)config('security.login_max_attempts'), (int)config('security.login_window_seconds'), false)) {
        throw new UserError('Too many sign-in attempts. Please wait 15 minutes and try again.');
    }

    $user = db_one('SELECT u.*, e.id AS employeeId FROM `User` u LEFT JOIN Employee e ON e.userId = u.id WHERE u.email = ?', [$email]);
    // Verify against a dummy hash when the user doesn't exist so timing doesn't reveal it.
    $hash = $user['passwordHash'] ?? '$2y$12$c.h1UkxlSrWsES.mR5gsLuB82p8h.UADGI2Wib41ID6K6fDxvjWQS';
    $valid = password_verify($password, $hash);
    if ($user === null || !(int)$user['isActive'] || !$valid) {
        rate_limit_hit('login', $limitKey, PHP_INT_MAX, (int)config('security.login_window_seconds'));
        throw new UserError('Incorrect email or password.');
    }

    if ($user['companyId']) {
        $status = db_value('SELECT status FROM Company WHERE id = ?', [$user['companyId']]);
        if ($status !== 'ACTIVE') {
            throw new UserError($status === 'SUSPENDED'
                ? 'Your company’s access has been suspended. Contact your platform administrator.'
                : 'Your company’s workspace has been archived and is no longer accessible.');
        }
    }

    rate_limit_reset('login', $limitKey);
    auth_login($user, $user['employeeId']);
    db_update('User', ['lastLoginAt' => now()], 'id = ?', [$user['id']]);
    record_audit(['companyId' => $user['companyId'], 'actorId' => $user['id'], 'action' => 'LOGIN', 'entityType' => 'User', 'entityId' => $user['id']]);

    return safe_next($next, '/dashboard');
}

function action_logout(): void
{
    session_destroy_all();
}

/**
 * Always reports success — confirming or denying an address would let anyone
 * enumerate logins. Only the SHA-256 of the token is stored (hardening: a DB
 * leak can't be replayed as reset links).
 */
function action_request_password_reset(string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        throw new UserError('Enter your email address.');
    }
    // Per-IP throttle so the form can't be used to spam an inbox.
    if (!rate_limit_hit('reset', client_ip(), 10, 3600)) {
        return;
    }
    $user = db_one('SELECT id, email, isActive FROM `User` WHERE email = ?', [$email]);
    if ($user === null || !(int)$user['isActive']) {
        return;
    }
    $token = bin2hex(random_bytes(32));
    db_insert('PasswordResetToken', [
        'userId' => $user['id'],
        'token' => hash('sha256', $token),
        'expiresAt' => now()->modify('+' . RESET_TOKEN_TTL_SECONDS . ' seconds'),
    ]);
    $resetUrl = app_origin() . '/reset-password?token=' . $token;
    send_mail($user['email'], 'Reset your WorkNest password',
        '<p>We received a request to reset your WorkNest password.</p>'
        . '<p><a href="' . e($resetUrl) . '">Reset your password</a> — this link expires in 30 minutes.</p>'
        . '<p>If you didn’t request this, you can safely ignore this email.</p>');
}

function action_reset_password(string $token, string $password, string $confirm): void
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new UserError('This reset link is invalid or has expired. Request a new one.');
    }
    if (strlen($password) < 8) {
        throw new UserError('Password must be at least 8 characters.');
    }
    if ($password !== $confirm) {
        throw new UserError('Passwords don’t match.');
    }
    db_tx(function () use ($token, $password) {
        $row = db_one('SELECT id, userId, expiresAt, usedAt FROM PasswordResetToken WHERE token = ? FOR UPDATE', [hash('sha256', $token)]);
        if ($row === null || $row['usedAt'] !== null || dt($row['expiresAt']) < now()) {
            throw new UserError('This reset link is invalid or has expired. Request a new one.');
        }
        db_update('User', ['passwordHash' => hash_password($password)], 'id = ?', [$row['userId']]);
        db_update('PasswordResetToken', ['usedAt' => now()], 'id = ?', [$row['id']]);
        record_audit(['actorId' => $row['userId'], 'action' => 'PASSWORD_RESET', 'entityType' => 'User', 'entityId' => $row['userId']]);
    });
}

function action_change_own_password(array $auth, string $current, string $new, string $confirm): void
{
    if ($current === '' || $new === '') {
        throw new UserError('Fill in both fields.');
    }
    if (strlen($new) < 8) {
        throw new UserError('New password must be at least 8 characters.');
    }
    if ($new !== $confirm) {
        throw new UserError('New passwords don’t match.');
    }
    $hash = db_value('SELECT passwordHash FROM `User` WHERE id = ?', [$auth['sub']]);
    if (!is_string($hash) || !password_verify($current, $hash)) {
        throw new UserError('Current password is incorrect.');
    }
    $newHash = hash_password($new);
    db_update('User', ['passwordHash' => $newHash], 'id = ?', [$auth['sub']]);
    audit($auth, 'CHANGE_PASSWORD', 'User', $auth['sub']);
    // Other sessions for this user are invalidated by the fingerprint check; keep this one.
    auth_refresh_password_fingerprint($newHash);
}

// ───────────────────────────────────────────── First-run installer ───

function install_lock_file(): string
{
    return STORAGE_PATH . '/install.lock';
}

/** The installer is available only until a Super Admin exists (then it locks itself). */
function install_is_locked(): bool
{
    if (is_file(install_lock_file())) {
        return true;
    }
    try {
        return (int)db_value("SELECT COUNT(*) FROM `User` WHERE role = 'SUPER_ADMIN'") > 0;
    } catch (PDOException) {
        return false; // schema not imported yet — the installer explains
    }
}

/** The configured SETUP_KEY when it is long enough to be used, otherwise null. */
function install_setup_key(): ?string
{
    $key = (string)config('security.setup_key');
    return strlen($key) >= 16 ? $key : null;
}

function install_create_super_admin(string $email, string $password, string $confirm, string $setupKey): string
{
    if (install_is_locked()) {
        throw new UserError('Setup has already been completed.');
    }
    $expected = install_setup_key() ?? throw new UserError('Set SETUP_KEY in .env first.');
    if (!rate_limit_hit('setup', client_ip(), 10, 3600)) {
        throw new UserError('Too many attempts. Please wait an hour and try again.');
    }
    if (!hash_equals($expected, $setupKey)) {
        throw new UserError('The setup key is incorrect.');
    }
    $email = v_email($email);
    if (strlen($password) < 12) {
        throw new UserError('Use at least 12 characters for the Super Admin password.');
    }
    if ($password !== $confirm) {
        throw new UserError('Passwords don’t match.');
    }
    $id = db_tx(function () use ($email, $password) {
        if ((int)db_value("SELECT COUNT(*) FROM `User` WHERE role = 'SUPER_ADMIN' FOR UPDATE") > 0) {
            throw new UserError('Setup has already been completed.');
        }
        if (db_value('SELECT id FROM `User` WHERE email = ?', [$email])) {
            throw new UserError('A user with this email already exists.');
        }
        $id = db_insert('User', ['email' => $email, 'passwordHash' => hash_password($password), 'role' => 'SUPER_ADMIN', 'companyId' => null]);
        record_audit(['actorId' => $id, 'action' => 'CREATE', 'entityType' => 'User', 'entityId' => $id, 'meta' => ['installer' => true]]);
        return $id;
    });
    @file_put_contents(install_lock_file(), 'Installed ' . date('c') . PHP_EOL, LOCK_EX);
    return $id;
}
