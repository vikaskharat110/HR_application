<?php
/** /dashboard/settings/users — port of app/dashboard/settings/users/page.tsx (Company Admin). */
declare(strict_types=1);

handle_post([
    'invite' => function () use ($auth) {
        action_invite_user($auth, post('email'), post('role'));
        return null;
    },
    'toggle' => function () use ($auth) {
        action_set_user_active($auth, post_id('userId'), post('active') === '1');
        return null;
    },
]);

const SETTINGS_USERS_PAGE_SIZE = 25;
$co = $auth['companyId'];
$page = query_page();
$total = (int)db_value('SELECT COUNT(*) FROM `User` WHERE companyId = ?', [$co]);
$users = db_all(
    'SELECT u.*, cr.name AS customRoleName, e.firstName, e.lastName FROM `User` u
       LEFT JOIN CustomRole cr ON cr.id = u.customRoleId LEFT JOIN Employee e ON e.userId = u.id
      WHERE u.companyId = ? ORDER BY u.createdAt ASC LIMIT ' . SETTINGS_USERS_PAGE_SIZE . ' OFFSET ' . (($page - 1) * SETTINGS_USERS_PAGE_SIZE),
    [$co]
);
$customRoles = db_all('SELECT id, name FROM CustomRole WHERE companyId = ? ORDER BY name', [$co]);
$totalPages = max(1, (int)ceil($total / SETTINGS_USERS_PAGE_SIZE));

app_layout_open($auth, 'Users & access');
echo back_link('/dashboard/settings', 'Back to settings');
echo temp_password_notice();
echo page_header('Users & access', "$total portal login(s) and their roles.");
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="card p-6">
        <p class="text-sm font-semibold text-ink mb-4">Invite a user</p>
        <?= flash_alerts('invite') ?>
        <form method="post" class="space-y-3">
            <?= form_fields('invite', 'invite') ?>
            <input name="email" type="email" required maxlength="191" value="<?= e(old('email')) ?>" placeholder="email@company.com" class="input">
            <select name="role" class="input">
                <optgroup label="Standard roles">
                    <?php foreach (ASSIGNABLE_ROLES as $r): ?><option value="<?= e($r) ?>"<?= selected($r, old('role', 'EMPLOYEE')) ?>><?= e(ROLE_LABEL[$r]) ?></option><?php endforeach; ?>
                </optgroup>
                <?php if ($customRoles): ?>
                    <optgroup label="Custom roles">
                        <?php foreach ($customRoles as $cr): ?><option value="custom:<?= e($cr['id']) ?>"<?= selected('custom:' . $cr['id'], old('role')) ?>><?= e($cr['name']) ?></option><?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <button type="submit" class="btn btn-primary w-full justify-center">Create login</button>
        </form>
        <p class="text-xs text-muted mt-2">A secure temporary password is generated and shown to you once.</p>
    </div>
    <div class="lg:col-span-2">
        <div class="card overflow-x-auto">
            <table class="table-clean">
                <thead><tr><th>Email</th><th>Role</th><th>Linked employee</th><th>Joined</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e($u['customRoleName'] ?? ROLE_LABEL[$u['role']]) ?></td>
                        <td><?= e($u['firstName'] ? $u['firstName'] . ' ' . $u['lastName'] : DASH) ?></td>
                        <td><?= e(format_date($u['createdAt'])) ?></td>
                        <td><?= (int)$u['isActive'] ? 'Active' : 'Disabled' ?></td>
                        <td>
                            <form method="post"><?= form_fields('toggle') ?>
                                <input type="hidden" name="userId" value="<?= e($u['id']) ?>"><input type="hidden" name="active" value="<?= (int)$u['isActive'] ? '0' : '1' ?>">
                                <button class="text-sm font-medium text-brand hover:underline"><?= (int)$u['isActive'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination($page, $totalPages, '/dashboard/settings/users') ?>
    </div>
</div>
<?php app_layout_close();
