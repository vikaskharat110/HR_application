<?php
/** /dashboard/users — port of app/dashboard/users/page.tsx (Super Admin, every tenant). */
declare(strict_types=1);

handle_post([
    'toggle' => function () use ($auth) {
        action_toggle_company_user($auth, post_id('userId'), post('active') === '1');
        return null;
    },
]);

const USERS_PAGE_SIZE = 25;
$q = query('q');
$role = in_array(query('role'), ALL_ROLES, true) ? query('role') : '';
$companyId = query('companyId');
$status = in_array(query('status'), ['active', 'disabled'], true) ? query('status') : '';
$page = query_page();

$where = ['1=1'];
$p = [];
if ($q !== '') {
    $where[] = '(u.email LIKE ? OR c.name LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $p[] = $like;
    $p[] = $like;
}
if ($role !== '') {
    $where[] = 'u.role = ?';
    $p[] = $role;
}
if ($companyId === '__platform__') {
    $where[] = 'u.companyId IS NULL';
} elseif (is_valid_id($companyId)) {
    $where[] = 'u.companyId = ?';
    $p[] = $companyId;
}
if ($status !== '') {
    $where[] = 'u.isActive = ?';
    $p[] = $status === 'active' ? 1 : 0;
}
$w = implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) FROM `User` u LEFT JOIN Company c ON c.id = u.companyId WHERE $w", $p);
$users = db_all(
    "SELECT u.*, c.name AS companyName, cr.name AS customRoleName FROM `User` u
       LEFT JOIN Company c ON c.id = u.companyId LEFT JOIN CustomRole cr ON cr.id = u.customRoleId
      WHERE $w ORDER BY c.name IS NULL, c.name ASC, u.email ASC LIMIT " . USERS_PAGE_SIZE . ' OFFSET ' . (($page - 1) * USERS_PAGE_SIZE),
    $p
);
$companies = db_all('SELECT id, name FROM Company ORDER BY name');
$totalPages = max(1, (int)ceil($total / USERS_PAGE_SIZE));

app_layout_open($auth, 'Users');
echo page_header('Users', "$total login(s) across every tenant on the platform.");
?>
<form class="card p-4 mb-5 flex flex-wrap items-end gap-3" method="get">
    <div><label class="field-label">Search email or company</label><input name="q" value="<?= e($q) ?>" placeholder="name@company.com or company name" class="input"></div>
    <div>
        <label class="field-label">Role</label>
        <select name="role" class="input"><option value="">All roles</option>
            <?php foreach (ALL_ROLES as $r): ?><option value="<?= e($r) ?>"<?= selected($r, $role) ?>><?= e(ROLE_LABEL[$r]) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="field-label">Company</label>
        <select name="companyId" class="input">
            <option value="">All companies</option>
            <option value="__platform__"<?= selected('__platform__', $companyId) ?>>Platform (no company)</option>
            <?php foreach ($companies as $c): ?><option value="<?= e($c['id']) ?>"<?= selected($c['id'], $companyId) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="field-label">Status</label>
        <select name="status" class="input">
            <option value="">Any status</option>
            <option value="active"<?= selected('active', $status) ?>>Active</option>
            <option value="disabled"<?= selected('disabled', $status) ?>>Disabled</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ($q !== '' || $role !== '' || $companyId !== '' || $status !== ''): ?>
        <a href="<?= e(url('/dashboard/users')) ?>" class="text-sm text-muted hover:text-ink">Clear</a>
    <?php endif; ?>
</form>
<div class="card overflow-x-auto">
    <?php if (!$users): ?>
        <?= empty_state('users', 'No users match these filters') ?>
    <?php else: ?>
        <table class="table-clean">
            <thead><tr><th>Email</th><th>Role</th><th>Last login</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php $prevKey = null;
            foreach ($users as $u):
                $key = $u['companyId'] ?? '__platform__';
                if ($key !== $prevKey): ?>
                    <tr class="bg-brand-softer">
                        <td colspan="5" class="text-xs font-semibold uppercase tracking-wide text-ink-soft py-2">
                            <?php if ($u['companyId']): ?>
                                <a href="<?= e(url('/dashboard/companies/' . $u['companyId'])) ?>" class="hover:underline"><?= e($u['companyName']) ?></a>
                            <?php else: ?>Platform (no company)<?php endif; ?>
                        </td>
                    </tr>
                <?php endif;
                $prevKey = $key; ?>
                <tr>
                    <td class="text-ink"><?= e($u['email']) ?></td>
                    <td><?= e($u['customRoleName'] ?? ROLE_LABEL[$u['role']]) ?></td>
                    <td><?= e($u['lastLoginAt'] ? format_datetime($u['lastLoginAt']) : 'Never') ?></td>
                    <td><?= (int)$u['isActive'] ? 'Active' : 'Disabled' ?></td>
                    <td>
                        <?php if ($u['id'] === $auth['sub']): ?>
                            <span class="text-xs text-muted">You</span>
                        <?php else: ?>
                            <form method="post"><?= form_fields('toggle') ?>
                                <input type="hidden" name="userId" value="<?= e($u['id']) ?>"><input type="hidden" name="active" value="<?= (int)$u['isActive'] ? '0' : '1' ?>">
                                <button class="text-sm font-medium text-brand hover:underline"><?= (int)$u['isActive'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?= pagination($page, $totalPages, '/dashboard/users', ['q' => $q, 'role' => $role, 'companyId' => $companyId, 'status' => $status]) ?>
<?php app_layout_close();
