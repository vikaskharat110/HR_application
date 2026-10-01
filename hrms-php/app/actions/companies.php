<?php
/** Platform (Super Admin) tenant management — port of lib/actions/companies.ts. */
declare(strict_types=1);

const COMPANY_STATUSES = ['ACTIVE', 'SUSPENDED', 'ARCHIVED'];

/** Default onboarding task templates for every new tenant (same set as the original). */
const DEFAULT_ONBOARDING_TEMPLATES = [
    ['HR', 'Collect ID proof & signed offer acceptance', 1],
    ['HR', 'Initiate background verification (BGV)', 2],
    ['HR', 'Share employee handbook & policy acknowledgement', 3],
    ['IT', 'Create company email account', 1],
    ['IT', 'Allocate laptop and other assets', 2],
    ['IT', 'Provision system/application access', 3],
    ['MANAGER', 'Prepare workstation and welcome kit', 1],
    ['MANAGER', 'Schedule day-one team introduction', 2],
];

function slugify(string $name): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))), '-');
}

function action_create_company(array $auth, string $name, string $industry, string $adminEmail): string
{
    if ($auth['role'] !== 'SUPER_ADMIN') {
        throw new UserError('Only the platform administrator can create companies.');
    }
    $name = trim($name);
    $industry = trim($industry);
    if ($name === '' || trim($adminEmail) === '') {
        throw new UserError('Company name and admin email are required.');
    }
    v_max($name, 'Company name');
    v_max($industry, 'Industry');
    $adminEmail = v_email($adminEmail, 'admin email');

    db_tx(function () use ($auth, $name, $industry, $adminEmail) {
        if (db_value('SELECT 1 FROM `User` WHERE email = ?', [$adminEmail])) {
            throw new UserError('A user with this email already exists on the platform. Choose a different admin email.');
        }
        $base = slugify($name) ?: 'company';
        $slug = $base;
        for ($i = 1; db_value('SELECT 1 FROM Company WHERE slug = ?', [$slug]); $i++) {
            $slug = "$base-$i";
        }
        $companyId = db_insert('Company', ['name' => $name, 'industry' => $industry, 'slug' => $slug]);
        db_insert('CompanyPolicy', ['companyId' => $companyId]);
        foreach (DEFAULT_ONBOARDING_TEMPLATES as [$category, $title, $order]) {
            db_insert('OnboardingTaskTemplate', ['companyId' => $companyId, 'category' => $category, 'title' => $title, 'sortOrder' => $order]);
        }
        $temp = generate_temp_password();
        $adminId = db_insert('User', ['email' => $adminEmail, 'passwordHash' => hash_password($temp), 'role' => 'COMPANY_ADMIN', 'companyId' => $companyId]);
        notify($adminId, 'Welcome to WorkNest',
            'Your company workspace is ready. Sign in with the temporary password your platform administrator gave you, then change it under My profile → Change password.',
            '/dashboard');
        record_audit(['actorId' => $auth['sub'], 'action' => 'CREATE', 'entityType' => 'Company', 'entityId' => $companyId, 'meta' => ['name' => $name]]);
        flash_temp_password($adminEmail, $temp);
    });
    flash('success', 'Company created.');
    return '/dashboard/companies';
}

function action_update_company_status(array $auth, string $companyId, string $status): void
{
    if ($auth['role'] !== 'SUPER_ADMIN') {
        throw new UserError('Only the platform administrator can change company status.');
    }
    $status = v_enum($status, COMPANY_STATUSES, 'status');
    $company = db_one('SELECT status, name FROM Company WHERE id = ?', [$companyId]);
    if ($company === null) {
        throw new UserError('Company not found.');
    }
    if ($company['status'] === $status) {
        return;
    }
    db_tx(function () use ($auth, $companyId, $company, $status) {
        db_update('Company', ['status' => $status], 'id = ?', [$companyId]);
        record_audit(['companyId' => $companyId, 'actorId' => $auth['sub'], 'action' => 'STATUS_CHANGE', 'entityType' => 'Company',
            'entityId' => $companyId, 'meta' => ['from' => $company['status'], 'to' => $status]]);
        if ($status !== 'ACTIVE') {
            foreach (db_column("SELECT id FROM `User` WHERE companyId = ? AND role = 'COMPANY_ADMIN'", [$companyId]) as $adminId) {
                notify($adminId, $status === 'SUSPENDED' ? 'Your workspace was suspended' : 'Your workspace was archived',
                    "{$company['name']} was " . strtolower($status) . ' by the platform administrator.');
            }
        }
    });
}

/** Super Admin enables/disables any login (never their own). */
function action_toggle_company_user(array $auth, string $userId, bool $isActive): void
{
    if ($auth['role'] !== 'SUPER_ADMIN') {
        throw new UserError('Only the platform administrator can do this.');
    }
    if ($userId === $auth['sub']) {
        throw new UserError('You cannot change your own account status.');
    }
    if (!db_value('SELECT 1 FROM `User` WHERE id = ?', [$userId])) {
        throw new UserError('User not found.');
    }
    db_tx(function () use ($auth, $userId, $isActive) {
        db_update('User', ['isActive' => $isActive], 'id = ?', [$userId]);
        record_audit(['actorId' => $auth['sub'], 'action' => $isActive ? 'ACTIVATE' : 'DEACTIVATE', 'entityType' => 'User', 'entityId' => $userId]);
    });
}
