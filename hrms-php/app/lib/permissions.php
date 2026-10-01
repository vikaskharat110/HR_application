<?php
/**
 * Module-level access control — port of lib/permissions.ts.
 *
 * A *restriction* layer, never a grant: a role outside the nav item's own
 * roles list is denied before the database is consulted. For allowed roles a
 * RolePermission row can switch a module off (tenant override first, then
 * the platform default); no row means "allowed". A custom role's own id is
 * the lookup key for module VIEW rows; its base role stays the ceiling.
 */
declare(strict_types=1);

const CONFIGURABLE_HREFS = [
    '/dashboard/employees', '/dashboard/attendance', '/dashboard/leave', '/dashboard/payroll',
    '/dashboard/recruitment', '/dashboard/performance', '/dashboard/expenses', '/dashboard/documents',
    '/dashboard/assets', '/dashboard/helpdesk', '/dashboard/reports', '/dashboard/settings',
];

const PAYROLL_MODULE = '/dashboard/payroll';
const PAYROLL_ACTIONS = ['EDIT_SALARY', 'PROCESS', 'APPROVE', 'LOCK'];
/** BRD 10.2 defaults when no override row exists. */
const PAYROLL_ACTION_DEFAULTS = [
    'EDIT_SALARY' => ['COMPANY_ADMIN', 'PAYROLL_ADMIN'],
    'PROCESS' => ['COMPANY_ADMIN', 'PAYROLL_ADMIN'],
    'APPROVE' => ['COMPANY_ADMIN', 'PAYROLL_ADMIN', 'FINANCE'],
    'LOCK' => ['COMPANY_ADMIN', 'PAYROLL_ADMIN'],
];

function configurable_modules(): array
{
    return array_values(array_filter(nav_items(), static fn($i) => in_array($i['href'], CONFIGURABLE_HREFS, true)));
}

/** Tenant row first, then platform default (companyId NULL). */
function permission_override(array $rows, ?string $companyId): ?array
{
    foreach ($rows as $r) {
        if ($companyId !== null && $r['companyId'] === $companyId) {
            return $r;
        }
    }
    foreach ($rows as $r) {
        if ($r['companyId'] === null) {
            return $r;
        }
    }
    return null;
}

function has_module_access(array $auth, string $href): bool
{
    $item = nav_item($href);
    if ($item === null) {
        return true;
    }
    if (!in_array($auth['role'], $item['roles'], true)) {
        return false;
    }
    if (!in_array($href, CONFIGURABLE_HREFS, true)) {
        return true;
    }
    $rows = db_all(
        "SELECT companyId, allowed FROM RolePermission
          WHERE role = ? AND module = ? AND action = 'VIEW' AND (companyId = ? OR companyId IS NULL)",
        [$auth['customRoleId'] ?? $auth['role'], $href, $auth['companyId'] ?? '__none__']
    );
    $override = permission_override($rows, $auth['companyId']);
    return $override === null ? true : (bool)$override['allowed'];
}

/** Every configurable module this session cannot open (one query) — for hiding nav entries. */
function hidden_hrefs(array $auth): array
{
    $candidates = array_values(array_filter(configurable_modules(), static fn($m) => in_array($auth['role'], $m['roles'], true)));
    if ($candidates === []) {
        return [];
    }
    [$in, $inParams] = db_in(array_column($candidates, 'href'));
    $rows = db_all(
        "SELECT module, companyId, allowed FROM RolePermission
          WHERE role = ? AND action = 'VIEW' AND module IN ($in) AND (companyId = ? OR companyId IS NULL)",
        [$auth['customRoleId'] ?? $auth['role'], ...$inParams, $auth['companyId'] ?? '__none__']
    );
    $hidden = [];
    foreach ($candidates as $mod) {
        $forModule = array_values(array_filter($rows, static fn($r) => $r['module'] === $mod['href']));
        $o = permission_override($forModule, $auth['companyId']);
        if ($o !== null && !(int)$o['allowed']) {
            $hidden[] = $mod['href'];
        }
    }
    return $hidden;
}

/** Platform defaults ("ROLE:/module" => allowed) for the Roles & Permissions screen. */
function platform_defaults(): array
{
    $map = [];
    foreach (db_all("SELECT role, module, allowed FROM RolePermission WHERE companyId IS NULL AND action = 'VIEW'") as $r) {
        $map[$r['role'] . ':' . $r['module']] = (bool)$r['allowed'];
    }
    return $map;
}

function has_payroll_action(array $auth, string $action): bool
{
    if (!in_array($action, PAYROLL_ACTIONS, true)) {
        return false;
    }
    $rows = db_all(
        'SELECT companyId, allowed FROM RolePermission
          WHERE role = ? AND module = ? AND action = ? AND (companyId = ? OR companyId IS NULL)',
        [$auth['role'], PAYROLL_MODULE, $action, $auth['companyId'] ?? '__none__']
    );
    $override = permission_override($rows, $auth['companyId']);
    if ($override !== null) {
        return (bool)$override['allowed'];
    }
    return in_array($auth['role'], PAYROLL_ACTION_DEFAULTS[$action], true);
}

/** Platform defaults ("ROLE:ACTION" => allowed) for the four payroll actions. */
function payroll_action_defaults(): array
{
    [$in, $params] = db_in(PAYROLL_ACTIONS);
    $map = [];
    foreach (db_all("SELECT role, action, allowed FROM RolePermission WHERE companyId IS NULL AND module = ? AND action IN ($in)", [PAYROLL_MODULE, ...$params]) as $r) {
        $map[$r['role'] . ':' . $r['action']] = (bool)$r['allowed'];
    }
    return $map;
}
