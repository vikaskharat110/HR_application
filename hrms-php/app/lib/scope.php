<?php
/**
 * Data scope and approval authority — port of lib/scope.ts. Every list of
 * employee ids here is derived from the authenticated user's company in the
 * database, never from request input.
 */
declare(strict_types=1);

/** Roles that can see data beyond their own employee record. */
function is_managerial(array $auth): bool
{
    return in_array($auth['role'], ['COMPANY_ADMIN', 'HR', 'MANAGER'], true);
}

/** Company Admin or HR (company-wide administrative scope). */
function is_company_admin(array $auth): bool
{
    return in_array($auth['role'], ['COMPANY_ADMIN', 'HR'], true);
}

/**
 * Employee ids visible for team-scoped modules: Admin/HR → whole company;
 * Manager → reporting line (5 levels) plus self; everyone else → self.
 *
 * @return list<string>
 */
function visible_employee_ids(array $auth): array
{
    static $cache = [];
    $key = $auth['sub'];
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    if (!$auth['companyId']) {
        return $cache[$key] = [];
    }
    if (is_company_admin($auth)) {
        return $cache[$key] = db_column('SELECT id FROM Employee WHERE companyId = ?', [$auth['companyId']]);
    }
    if ($auth['role'] === 'MANAGER' && $auth['employeeId']) {
        $ids = [$auth['employeeId'] => true];
        $frontier = [$auth['employeeId']];
        for ($depth = 0; $depth < 5 && $frontier; $depth++) {
            [$in, $params] = db_in($frontier);
            $reports = db_column("SELECT id FROM Employee WHERE managerId IN ($in) AND companyId = ?", [...$params, $auth['companyId']]);
            $frontier = array_values(array_filter($reports, static fn($id) => !isset($ids[$id])));
            foreach ($frontier as $id) {
                $ids[$id] = true;
            }
        }
        return $cache[$key] = array_keys($ids);
    }
    return $cache[$key] = $auth['employeeId'] ? [$auth['employeeId']] : [];
}

/** True if $employeeId is inside the caller's visible scope. */
function can_see_employee(array $auth, string $employeeId): bool
{
    return in_array($employeeId, visible_employee_ids($auth), true);
}

/** Employee ids of every HR user in the company (HR can't approve HR). */
function hr_employee_ids(?string $companyId): array
{
    if (!$companyId) {
        return [];
    }
    return db_column("SELECT e.id FROM Employee e JOIN `User` u ON u.id = e.userId WHERE e.companyId = ? AND u.role = 'HR'", [$companyId]);
}

/**
 * WHERE fragment for an approvals queue (leave/expense/regularization/
 * promotion tables all have employeeId + approverId): Company Admin sees all
 * company requests; HR the same minus fellow HR's; a Manager only requests
 * assigned to them.
 *
 * @return array{0:string,1:list<mixed>}
 */
function approval_queue_where(array $auth, string $alias = ''): array
{
    $col = static fn(string $c) => $alias !== '' ? "$alias.$c" : $c;
    [$in, $params] = db_in(visible_employee_ids($auth));
    $sql = $col('employeeId') . " IN ($in)";

    if ($auth['role'] === 'HR') {
        $excluded = hr_employee_ids($auth['companyId']);
        if ($excluded) {
            [$notIn, $exParams] = db_in($excluded);
            $sql .= ' AND ' . $col('employeeId') . " NOT IN ($notIn)";
            $params = [...$params, ...$exParams];
        }
        return [$sql, $params];
    }
    if (is_company_admin($auth)) {
        return [$sql, $params];
    }
    return [$sql . ' AND ' . $col('approverId') . ' = ?', [...$params, $auth['employeeId'] ?? '__none__']];
}

/**
 * Guards every "act on someone else's request" action. HR/Company Admin
 * have a company-wide override; a Manager may act only as the specifically
 * assigned approver; nobody decides their own request; HR can't decide a
 * fellow HR's request unless they are that person's assigned approver.
 */
function assert_can_act_on(array $auth, string $targetEmployeeId, ?string $assignedApproverId): void
{
    if (!is_managerial($auth)) {
        throw new UserError('You are not authorized to act on this request.');
    }
    if ($targetEmployeeId === $auth['employeeId']) {
        throw new UserError('You cannot approve or reject your own request.');
    }
    if ($auth['role'] === 'HR') {
        $targetRole = db_value('SELECT u.role FROM Employee e JOIN `User` u ON u.id = e.userId WHERE e.id = ?', [$targetEmployeeId]);
        if ($targetRole === 'HR') {
            if ($auth['employeeId'] !== $assignedApproverId) {
                throw new UserError('An HR request can only be approved by the Company Admin or the requester’s own manager.');
            }
            return;
        }
    }
    if (is_company_admin($auth)) {
        if (!can_see_employee($auth, $targetEmployeeId)) {
            throw new UserError('You are not authorized to act on this request.');
        }
        return;
    }
    if ($auth['employeeId'] === null || $auth['employeeId'] !== $assignedApproverId) {
        throw new UserError('Only the assigned approver, or HR/Admin, can act on this request.');
    }
}
