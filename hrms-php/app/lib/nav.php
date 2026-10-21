<?php
/**
 * Navigation + role catalogue — a direct port of lib/nav.ts and the role
 * constants in lib/session.ts. NAV_ITEMS[].roles is the hard ceiling for
 * module access (see permissions.php).
 */
declare(strict_types=1);

const ALL_ROLES = [
    'SUPER_ADMIN', 'COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE', 'RECRUITER',
    'PAYROLL_ADMIN', 'FINANCE', 'IT_ADMIN', 'AUDITOR', 'MANAGEMENT',
];

const ROLE_LABEL = [
    'SUPER_ADMIN' => 'Super Admin',
    'COMPANY_ADMIN' => 'Company Admin',
    'HR' => 'HR',
    'MANAGER' => 'Manager',
    'EMPLOYEE' => 'Employee',
    'RECRUITER' => 'Recruiter',
    'PAYROLL_ADMIN' => 'Payroll Admin',
    'FINANCE' => 'Finance',
    'IT_ADMIN' => 'IT / Admin',
    'AUDITOR' => 'Auditor',
    'MANAGEMENT' => 'Management',
];

/** Roles a Company Admin/HR may hand out inside a tenant (everything but SUPER_ADMIN). */
const ASSIGNABLE_ROLES = [
    'EMPLOYEE', 'MANAGER', 'HR', 'RECRUITER', 'PAYROLL_ADMIN', 'FINANCE',
    'IT_ADMIN', 'AUDITOR', 'MANAGEMENT', 'COMPANY_ADMIN',
];

function role_home(string $role): string
{
    return $role === 'SUPER_ADMIN' ? '/dashboard/companies' : '/dashboard';
}

/** @return list<array{label:string,href:string,icon:string,roles:list<string>}> */
function nav_items(): array
{
    return [
        ['label' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'layout-dashboard', 'roles' => ALL_ROLES],
        ['label' => 'Companies', 'href' => '/dashboard/companies', 'icon' => 'building-2', 'roles' => ['SUPER_ADMIN']],
        ['label' => 'Users', 'href' => '/dashboard/users', 'icon' => 'users', 'roles' => ['SUPER_ADMIN']],
        ['label' => 'Audit Logs', 'href' => '/dashboard/audit', 'icon' => 'history', 'roles' => ['SUPER_ADMIN', 'AUDITOR', 'COMPANY_ADMIN']],
        ['label' => 'Roles & Permissions', 'href' => '/dashboard/roles', 'icon' => 'shield-check', 'roles' => ['SUPER_ADMIN']],
        ['label' => 'Employees', 'href' => '/dashboard/employees', 'icon' => 'users', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER']],
        ['label' => 'Attendance', 'href' => '/dashboard/attendance', 'icon' => 'fingerprint', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE']],
        ['label' => 'Calendar', 'href' => '/dashboard/calendar', 'icon' => 'calendar-clock', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE', 'RECRUITER', 'PAYROLL_ADMIN', 'FINANCE', 'IT_ADMIN', 'AUDITOR', 'MANAGEMENT']],
        ['label' => 'Leave', 'href' => '/dashboard/leave', 'icon' => 'calendar-days', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE']],
        ['label' => 'Payroll', 'href' => '/dashboard/payroll', 'icon' => 'wallet', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE', 'PAYROLL_ADMIN', 'FINANCE']],
        ['label' => 'Recruitment', 'href' => '/dashboard/recruitment', 'icon' => 'briefcase', 'roles' => ['COMPANY_ADMIN', 'HR', 'RECRUITER']],
        ['label' => 'Onboarding', 'href' => '/dashboard/onboarding', 'icon' => 'clipboard-check', 'roles' => ['COMPANY_ADMIN', 'HR', 'IT_ADMIN', 'MANAGER']],
        ['label' => 'Performance', 'href' => '/dashboard/performance', 'icon' => 'target', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE']],
        ['label' => 'Expenses', 'href' => '/dashboard/expenses', 'icon' => 'receipt', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE', 'FINANCE']],
        ['label' => 'Documents', 'href' => '/dashboard/documents', 'icon' => 'folder-open', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE']],
        ['label' => 'Assets', 'href' => '/dashboard/assets', 'icon' => 'boxes', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE', 'IT_ADMIN']],
        ['label' => 'Helpdesk', 'href' => '/dashboard/helpdesk', 'icon' => 'life-buoy', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE', 'IT_ADMIN', 'FINANCE']],
        ['label' => 'Exit / Offboarding', 'href' => '/dashboard/exit', 'icon' => 'log-out', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'IT_ADMIN', 'FINANCE']],
        ['label' => 'Reports', 'href' => '/dashboard/reports', 'icon' => 'bar-chart-3', 'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'AUDITOR', 'MANAGEMENT', 'PAYROLL_ADMIN', 'RECRUITER']],
        ['label' => 'Settings', 'href' => '/dashboard/settings', 'icon' => 'settings', 'roles' => ['COMPANY_ADMIN']],
    ];
}

function nav_item(string $href): ?array
{
    foreach (nav_items() as $item) {
        if ($item['href'] === $href) {
            return $item;
        }
    }
    return null;
}

function nav_for_role(string $role): array
{
    return array_values(array_filter(nav_items(), static fn($i) => in_array($role, $i['roles'], true)));
}

function role_label(array $auth): string
{
    return $auth['customRoleName'] ?? ROLE_LABEL[$auth['role']] ?? $auth['role'];
}
