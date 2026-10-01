<?php
/** /dashboard/settings/roles — port of app/dashboard/settings/roles/page.tsx + CreateCustomRoleForm (Company Admin). */
declare(strict_types=1);

handle_post([
    'create' => function () use ($auth) {
        action_create_custom_role($auth, post('name'), post('baseRole'));
        return null;
    },
    'delete' => function () use ($auth) {
        action_delete_custom_role($auth, post_id('id'));
        return null;
    },
    'toggle' => function () use ($auth) {
        action_set_custom_role_module_access($auth, post_id('customRoleId'), post('module'), post('allowed') === '1');
        return null;
    },
]);

$co = $auth['companyId'];
$customRoles = db_all('SELECT r.*, (SELECT COUNT(*) FROM `User` u WHERE u.customRoleId = r.id) AS users FROM CustomRole r WHERE r.companyId = ? ORDER BY r.createdAt ASC', [$co]);
$allowedMap = [];
if ($customRoles) {
    [$in, $ids] = db_in(array_column($customRoles, 'id'));
    foreach (db_all("SELECT role, module, allowed FROM RolePermission WHERE companyId = ? AND action = 'VIEW' AND role IN ($in)", [$co, ...$ids]) as $r) {
        $allowedMap[$r['role'] . ':' . $r['module']] = (bool)$r['allowed'];
    }
}

app_layout_open($auth, 'Custom roles');
echo back_link('/dashboard/settings', 'Back to settings');
echo page_header('Custom roles', 'Give a role its own name — it inherits the base role\'s approvals and business rules, but you control which dashboard sections it can see.');
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="card p-6">
        <p class="text-sm font-semibold text-ink mb-4">Create a role</p>
        <form method="post" class="space-y-3">
            <?= form_fields('create', 'role') ?>
            <div><label class="field-label">Role name</label><input name="name" required maxlength="191" value="<?= e(old('name')) ?>" placeholder="e.g. Team Lead" class="input"></div>
            <div>
                <label class="field-label">Based on</label>
                <select name="baseRole" required class="input">
                    <?php foreach (ASSIGNABLE_ROLES as $r): ?><option value="<?= e($r) ?>"<?= selected($r, old('baseRole')) ?>><?= e(ROLE_LABEL[$r]) ?></option><?php endforeach; ?>
                </select>
                <p class="text-xs text-muted mt-1.5">This role inherits everything the base role can do (approvals, who it can act on, payroll rights). Only which dashboard sections it can see is independently adjustable below.</p>
            </div>
            <?= flash_alerts('role') ?>
            <button type="submit" class="btn btn-primary w-full justify-center">Create role</button>
        </form>
    </div>
    <div class="lg:col-span-2 space-y-5">
        <div class="card p-5">
            <p class="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><?= icon('shield-check', 15) ?> Your roles</p>
            <?php if (!$customRoles): ?>
                <?= empty_state('shield-check', 'No custom roles yet', 'Create one on the left to get started.') ?>
            <?php else: ?>
                <ul class="divide-y divide-border-soft">
                    <?php foreach ($customRoles as $r): ?>
                        <li class="py-3 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-ink"><?= e($r['name']) ?></p>
                                <p class="text-xs text-muted mt-0.5">Based on <?= e(ROLE_LABEL[$r['baseRole']]) ?> · <?= (int)$r['users'] ?> user(s) assigned</p>
                            </div>
                            <form method="post"><?= form_fields('delete') ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                <?= confirm_button('Delete the "' . $r['name'] . '" role? This can\'t be undone.', icon('trash-2', 14),
                                    'btn btn-secondary !py-1.5 !px-2.5 text-danger disabled:opacity-40',
                                    (int)$r['users'] > 0 ? 'Reassign every user with this role before deleting it' : 'Delete role', (int)$r['users'] > 0) ?>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php if ($customRoles): ?>
            <div class="card overflow-x-auto">
                <table class="table-clean table-compact table-sticky-col">
                    <thead><tr><th>Module</th><?php foreach ($customRoles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach (configurable_modules() as $mod): ?>
                        <tr>
                            <td class="font-medium text-ink"><?= e($mod['label']) ?></td>
                            <?php foreach ($customRoles as $r):
                                if (!in_array($r['baseRole'], $mod['roles'], true)): ?>
                                    <td class="text-muted text-xs">—</td>
                                <?php continue; endif;
                                $allowed = $allowedMap[$r['id'] . ':' . $mod['href']] ?? true; ?>
                                <td>
                                    <form method="post"><?= form_fields('toggle') ?>
                                        <input type="hidden" name="customRoleId" value="<?= e($r['id']) ?>"><input type="hidden" name="module" value="<?= e($mod['href']) ?>">
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
        <?php endif; ?>
    </div>
</div>
<?php app_layout_close();
