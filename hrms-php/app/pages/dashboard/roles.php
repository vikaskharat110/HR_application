<?php
/** /dashboard/roles — port of app/dashboard/roles/page.tsx + RolesPermissionsTabs (Super Admin). */
declare(strict_types=1);

handle_post([
    'module' => function () use ($auth) {
        action_set_module_access($auth, post('role'), post('module'), post('allowed') === '1');
        return '/dashboard/roles#tab-modules';
    },
    'payroll' => function () use ($auth) {
        action_set_payroll_action($auth, post('role'), post('payrollAction'), post('allowed') === '1');
        return '/dashboard/roles#tab-payroll';
    },
]);

const DISPLAY_ROLES = ['COMPANY_ADMIN', 'HR', 'MANAGER', 'EMPLOYEE', 'RECRUITER', 'PAYROLL_ADMIN', 'FINANCE', 'IT_ADMIN', 'AUDITOR', 'MANAGEMENT'];
const PAYROLL_ROLES = ['COMPANY_ADMIN', 'HR', 'MANAGER', 'PAYROLL_ADMIN', 'FINANCE'];
const PAYROLL_ACTION_LABEL = ['EDIT_SALARY' => 'Edit salary', 'PROCESS' => 'Process payroll', 'APPROVE' => 'Approve payroll', 'LOCK' => 'Lock payroll'];
$defaults = platform_defaults();
$payrollDefaults = payroll_action_defaults();

ob_start(); ?>
<div>
    <p class="text-sm text-muted mb-4">Platform-wide defaults for which roles can open which modules. A blank cell means the role has no access to that module in the underlying app — no toggle can grant it.</p>
    <div class="card overflow-x-auto">
        <table class="table-clean table-compact table-sticky-col">
            <thead><tr><th>Module</th><?php foreach (DISPLAY_ROLES as $r): ?><th><?= e(ROLE_LABEL[$r]) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach (configurable_modules() as $mod): ?>
                <tr>
                    <td class="font-medium text-ink"><?= e($mod['label']) ?></td>
                    <?php foreach (DISPLAY_ROLES as $role):
                        if (!in_array($role, $mod['roles'], true)): ?>
                            <td class="text-muted text-xs">—</td>
                        <?php continue; endif;
                        $allowed = $defaults["$role:{$mod['href']}"] ?? true; ?>
                        <td>
                            <form method="post"><?= form_fields('module') ?>
                                <input type="hidden" name="role" value="<?= e($role) ?>"><input type="hidden" name="module" value="<?= e($mod['href']) ?>">
                                <input type="hidden" name="allowed" value="<?= $allowed ? '0' : '1' ?>">
                                <button class="text-sm font-medium hover:underline <?= $allowed ? 'text-brand' : 'text-danger' ?>"><?= $allowed ? 'Allowed' : 'Blocked' ?></button>
                            </form>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $modulesPanel = ob_get_clean();

ob_start(); ?>
<div>
    <p class="text-sm text-muted mb-4">Payroll and salary data are the most sensitive information in the system, so these four actions are gated individually — beyond just opening the Payroll module.</p>
    <div class="card overflow-x-auto">
        <table class="table-clean table-compact table-sticky-col">
            <thead><tr><th>Action</th><?php foreach (PAYROLL_ROLES as $r): ?><th><?= e(ROLE_LABEL[$r]) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach (PAYROLL_ACTIONS as $action): ?>
                <tr>
                    <td class="font-medium text-ink"><?= e(PAYROLL_ACTION_LABEL[$action]) ?></td>
                    <?php foreach (PAYROLL_ROLES as $role):
                        $resolved = $payrollDefaults["$role:$action"] ?? null; ?>
                        <td>
                            <form method="post"><?= form_fields('payroll') ?>
                                <input type="hidden" name="role" value="<?= e($role) ?>"><input type="hidden" name="payrollAction" value="<?= e($action) ?>">
                                <input type="hidden" name="allowed" value="<?= $resolved ? '0' : '1' ?>">
                                <button class="text-sm font-medium hover:underline <?= $resolved ? 'text-brand' : 'text-muted' ?>"><?= $resolved === null ? 'Default' : ($resolved ? 'Allowed' : 'Blocked') ?></button>
                            </form>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-xs text-muted mt-3">Changes apply platform-wide immediately — every company using the default (none has an override yet) picks this up on their next page load, hiding the module from their sidebar and blocking direct URL access.</p>
</div>
<?php $payrollPanel = ob_get_clean();

app_layout_open($auth, 'Roles & Permissions');
echo page_header('Roles & Permissions', 'Platform-wide defaults for which roles can access what.');
echo tabbed_panels([
    ['key' => 'modules', 'label' => 'Module access', 'content' => $modulesPanel],
    ['key' => 'payroll', 'label' => 'Payroll access control', 'content' => $payrollPanel],
], 'roles-tabs');
app_layout_close();
