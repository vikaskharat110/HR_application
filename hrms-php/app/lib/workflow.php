<?php
/**
 * Event → audit log → notification funnel (port of lib/workflow.ts), and
 * approver resolution. Notification links are the original app paths, which
 * the PHP router serves unchanged.
 */
declare(strict_types=1);

/**
 * @param array{companyId?:?string,actorId?:?string,action:string,entityType:string,entityId:string,meta?:array} $p
 */
function record_audit(array $p): void
{
    db_insert('AuditLog', [
        'companyId' => $p['companyId'] ?? null,
        'actorId' => $p['actorId'] ?? null,
        'action' => $p['action'],
        'entityType' => $p['entityType'],
        'entityId' => $p['entityId'],
        'meta' => json_encode((object)($p['meta'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
    ]);
}

/** Shorthand used by actions: audit as the authenticated user within their company. */
function audit(array $auth, string $action, string $entityType, string $entityId, array $meta = []): void
{
    record_audit([
        'companyId' => $auth['companyId'],
        'actorId' => $auth['sub'],
        'action' => $action,
        'entityType' => $entityType,
        'entityId' => $entityId,
        'meta' => $meta,
    ]);
}

function notify(string $userId, string $title, string $message, ?string $link = null): void
{
    db_insert('Notification', ['userId' => $userId, 'title' => $title, 'message' => $message, 'link' => $link]);
}

/** @param list<string> $userIds */
function notify_many(array $userIds, string $title, string $message, ?string $link = null): void
{
    foreach (array_unique($userIds) as $uid) {
        notify($uid, $title, $message, $link);
    }
}

/**
 * Who approves a request raised by $employeeId: their direct manager, or —
 * only for someone with no manager — another Company Admin/HR in the company
 * (Company Admin first). Never the requester. Returns an Employee row
 * (id, userId, firstName, lastName) or null.
 */
function resolve_approver(string $employeeId): ?array
{
    $employee = db_one('SELECT id, companyId, managerId FROM Employee WHERE id = ?', [$employeeId]);
    if ($employee === null) {
        return null;
    }
    if ($employee['managerId']) {
        $manager = db_one('SELECT id, userId, firstName, lastName FROM Employee WHERE id = ?', [$employee['managerId']]);
        if ($manager !== null) {
            return $manager;
        }
    }
    return db_one(
        "SELECT e.id, e.userId, e.firstName, e.lastName
           FROM `User` u JOIN Employee e ON e.userId = u.id
          WHERE u.companyId = ? AND u.role IN ('COMPANY_ADMIN', 'HR') AND e.id <> ?
          ORDER BY u.role ASC, u.createdAt ASC, u.id ASC
          LIMIT 1",
        [$employee['companyId'], $employeeId]
    );
}

/** A user's login id for an employee, or null. */
function employee_user_id(?string $employeeId): ?string
{
    return $employeeId ? db_value('SELECT userId FROM Employee WHERE id = ?', [$employeeId]) : null;
}

/**
 * A strong random temporary password (decision 8): shown once to the admin
 * who created the account, never stored in plain text anywhere.
 */
function generate_temp_password(): string
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '@#%&*!?'];
    $chars = [];
    foreach ($sets as $set) {
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }
    $all = implode('', $sets);
    while (count($chars) < 14) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Remembers a freshly generated temporary password for exactly one page view
 * of the admin who created it (session only — never DB, logs or audit).
 */
function flash_temp_password(string $email, string $password): void
{
    $_SESSION['_temp_password'] = ['email' => $email, 'password' => $password];
}

function take_temp_password(): ?array
{
    $v = $_SESSION['_temp_password'] ?? null;
    unset($_SESSION['_temp_password']);
    return is_array($v) ? $v : null;
}
