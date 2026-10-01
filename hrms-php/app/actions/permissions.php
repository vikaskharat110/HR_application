<?php
/**
 * Platform-wide permission defaults (Super Admin) — port of
 * lib/actions/permissions.ts — plus tenant custom roles (Company Admin) —
 * port of lib/actions/customRoles.ts.
 */
declare(strict_types=1);

/** Upserts a RolePermission row. companyId null = platform default. */
function upsert_role_permission(string $role, string $module, string $action, bool $allowed, ?string $companyId): void
{
    $existing = $companyId === null
        ? db_value('SELECT id FROM RolePermission WHERE role = ? AND module = ? AND action = ? AND companyId IS NULL', [$role, $module, $action])
        : db_value('SELECT id FROM RolePermission WHERE role = ? AND module = ? AND action = ? AND companyId = ?', [$role, $module, $action, $companyId]);
    if ($existing) {
        db_update('RolePermission', ['allowed' => $allowed], 'id = ?', [$existing]);
    } else {
        db_insert('RolePermission', ['role' => $role, 'module' => $module, 'action' => $action, 'allowed' => $allowed, 'companyId' => $companyId]);
    }
}

function action_set_module_access(array $auth, string $role, string $module, bool $allowed): void
{
    if ($auth['role'] !== 'SUPER_ADMIN') {
        throw new UserError('Only the platform administrator can change permissions.');
    }
    v_enum($role, ALL_ROLES, 'role');
    v_enum($module, CONFIGURABLE_HREFS, 'module');
    db_tx(function () use ($auth, $role, $module, $allowed) {
        upsert_role_permission($role, $module, 'VIEW', $allowed, null);
        record_audit(['actorId' => $auth['sub'], 'action' => 'CONFIGURE', 'entityType' => 'RolePermission', 'entityId' => "$role:$module",
            'meta' => ['role' => $role, 'module' => $module, 'allowed' => $allowed]]);
    });
}

function action_set_payroll_action(array $auth, string $role, string $action, bool $allowed): void
{
    if ($auth['role'] !== 'SUPER_ADMIN') {
        throw new UserError('Only the platform administrator can change permissions.');
    }
    v_enum($role, ALL_ROLES, 'role');
    v_enum($action, PAYROLL_ACTIONS, 'payroll action');
    db_tx(function () use ($auth, $role, $action, $allowed) {
        upsert_role_permission($role, PAYROLL_MODULE, $action, $allowed, null);
        record_audit(['actorId' => $auth['sub'], 'action' => 'CONFIGURE', 'entityType' => 'RolePermission', 'entityId' => "$role:" . PAYROLL_MODULE . ":$action",
            'meta' => ['role' => $role, 'module' => PAYROLL_MODULE, 'action' => $action, 'allowed' => $allowed]]);
    });
}

// ───────────────────────────────────────────── Custom roles (tenant) ───

function action_create_custom_role(array $auth, string $name, string $baseRole): void
{
    $companyId = require_company($auth);
    $name = trim($name);
    if ($name === '') {
        throw new UserError('Give the role a name.');
    }
    v_max($name, 'Role name');
    if (!in_array($baseRole, ASSIGNABLE_ROLES, true)) {
        throw new UserError('Pick a valid base role.');
    }
    db_tx(function () use ($auth, $companyId, $name, $baseRole) {
        if (db_value('SELECT 1 FROM CustomRole WHERE companyId = ? AND name = ?', [$companyId, $name])) {
            throw new UserError('A role with this name already exists.');
        }
        $id = db_insert('CustomRole', ['companyId' => $companyId, 'name' => $name, 'baseRole' => $baseRole]);
        audit($auth, 'CREATE', 'CustomRole', $id, ['name' => $name, 'baseRole' => $baseRole]);
    });
}

function action_delete_custom_role(array $auth, string $customRoleId): void
{
    $companyId = require_company($auth);
    $role = db_one('SELECT id, name FROM CustomRole WHERE id = ? AND companyId = ?', [$customRoleId, $companyId]);
    if ($role === null) {
        throw new UserError('Role not found.');
    }
    $assigned = (int)db_value('SELECT COUNT(*) FROM `User` WHERE customRoleId = ?', [$customRoleId]);
    if ($assigned > 0) {
        throw new UserError("$assigned user(s) still have this role — reassign them before deleting it.");
    }
    db_tx(function () use ($auth, $companyId, $role) {
        db_exec('DELETE FROM RolePermission WHERE role = ? AND companyId = ?', [$role['id'], $companyId]);
        db_exec('DELETE FROM CustomRole WHERE id = ?', [$role['id']]);
        audit($auth, 'DELETE', 'CustomRole', $role['id'], ['name' => $role['name']]);
    });
}

function action_set_custom_role_module_access(array $auth, string $customRoleId, string $module, bool $allowed): void
{
    $companyId = require_company($auth);
    v_enum($module, CONFIGURABLE_HREFS, 'module');
    if (!db_value('SELECT 1 FROM CustomRole WHERE id = ? AND companyId = ?', [$customRoleId, $companyId])) {
        throw new UserError('Role not found.');
    }
    db_tx(function () use ($auth, $companyId, $customRoleId, $module, $allowed) {
        upsert_role_permission($customRoleId, $module, 'VIEW', $allowed, $companyId);
        audit($auth, 'CONFIGURE', 'CustomRole', $customRoleId, ['module' => $module, 'allowed' => $allowed]);
    });
}
