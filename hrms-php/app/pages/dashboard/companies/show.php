<?php
/** /dashboard/companies/{id} — port of app/dashboard/companies/[id]/page.tsx (Super Admin) */
declare(strict_types=1);

$id = $params['id'];
$company = db_one(
    'SELECT c.*,
            (SELECT COUNT(*) FROM Employee e WHERE e.companyId = c.id) AS employees,
            (SELECT COUNT(*) FROM `User` u WHERE u.companyId = c.id) AS users,
            (SELECT COUNT(*) FROM Branch b WHERE b.companyId = c.id) AS branches,
            (SELECT COUNT(*) FROM Department d WHERE d.companyId = c.id) AS departments
       FROM Company c WHERE c.id = ?',
    [$id]
);
if ($company === null) {
    throw new HttpError(404);
}
handle_post([
    'status' => function () use ($auth, $id) {
        action_update_company_status($auth, $id, post('status'));
        return null;
    },
    'toggle' => function () use ($auth) {
        action_toggle_company_user($auth, post_id('userId'), post('active') === '1');
        return null;
    },
]);

const COMPANY_PAGE_SIZE = 10;
$tabs = ['users' => ['Users', 'users'], 'activity' => ['Recent activity', 'history'], 'branches' => ['Branches', 'map-pin'],
    'departments' => ['Departments', 'layers'], 'policy' => ['Policy', 'building-2']];
$activeTab = array_key_exists(query('tab'), $tabs) ? query('tab') : 'users';
$page = query_page();
$offset = ($page - 1) * COMPANY_PAGE_SIZE;

app_layout_open($auth, $company['name']);
echo back_link('/dashboard/companies', 'Back to companies');
echo page_header($company['name'], ($company['industry'] ?: DASH) . ' · ' . $company['slug'] . ' · created ' . format_date($company['createdAt']),
    '<div class="flex items-center gap-2">' . status_badge($company['status'])
    . '<form method="post">' . form_fields('status') . auto_submit_select('status', $company['status'], COMPANY_STATUSES, 'input w-auto! text-sm') . '</form></div>');
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php foreach (['Employees' => 'employees', 'Logins' => 'users', 'Branches' => 'branches', 'Departments' => 'departments'] as $label => $k): ?>
        <div class="stat-card">
            <p class="text-xs font-semibold uppercase text-muted"><?= e($label) ?></p>
            <p class="font-display text-2xl font-semibold text-ink mt-1.5"><?= (int)$company[$k] ?></p>
        </div>
    <?php endforeach; ?>
</div>
<div class="inline-flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1 mb-5">
    <?php foreach ($tabs as $key => [$label, $iconName]): ?>
        <a href="<?= e(url("/dashboard/companies/$id?tab=$key")) ?>"
           class="flex items-center gap-1.5 px-3.5 py-1.5 text-sm font-medium rounded-md transition-colors <?= $activeTab === $key ? 'bg-brand text-white' : 'text-ink-soft hover:text-ink hover:bg-brand-softer' ?>">
            <?= icon($iconName, 14) ?> <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</div>
<div class="card p-5">
    <?php if ($activeTab === 'users'):
        $total = (int)db_value('SELECT COUNT(*) FROM `User` WHERE companyId = ?', [$id]);
        $users = db_all('SELECT u.*, cr.name AS customRoleName FROM `User` u LEFT JOIN CustomRole cr ON cr.id = u.customRoleId WHERE u.companyId = ? ORDER BY u.createdAt ASC LIMIT ' . COMPANY_PAGE_SIZE . " OFFSET $offset", [$id]); ?>
        <?php if (!$users): ?>
            <?= empty_state('users', 'No logins created for this company yet.') ?>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="table-clean">
                    <thead><tr><th>Email</th><th>Role</th><th>Last login</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="text-ink whitespace-nowrap"><?= e($u['email']) ?></td>
                            <td class="whitespace-nowrap"><?= e($u['customRoleName'] ?? ROLE_LABEL[$u['role']]) ?></td>
                            <td class="whitespace-nowrap"><?= e($u['lastLoginAt'] ? format_datetime($u['lastLoginAt']) : 'Never') ?></td>
                            <td class="whitespace-nowrap"><?= (int)$u['isActive'] ? 'Active' : 'Disabled' ?></td>
                            <td>
                                <form method="post"><?= form_fields('toggle') ?>
                                    <input type="hidden" name="userId" value="<?= e($u['id']) ?>"><input type="hidden" name="active" value="<?= (int)$u['isActive'] ? '0' : '1' ?>">
                                    <button class="text-sm font-medium text-brand hover:underline whitespace-nowrap"><?= (int)$u['isActive'] ? 'Disable' : 'Enable' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?= pagination($page, max(1, (int)ceil($total / COMPANY_PAGE_SIZE)), "/dashboard/companies/$id", ['tab' => 'users']) ?>

    <?php elseif ($activeTab === 'activity'):
        $total = (int)db_value('SELECT COUNT(*) FROM AuditLog WHERE companyId = ?', [$id]);
        $activity = db_all('SELECT l.*, u.email AS actorEmail FROM AuditLog l LEFT JOIN `User` u ON u.id = l.actorId WHERE l.companyId = ? ORDER BY l.createdAt DESC LIMIT ' . COMPANY_PAGE_SIZE . " OFFSET $offset", [$id]); ?>
        <?php if (!$activity): ?>
            <?= empty_state('history', 'No audited actions recorded for this company yet.') ?>
        <?php else: ?>
            <ul class="divide-y divide-border-soft">
                <?php foreach ($activity as $log): ?>
                    <li class="py-2.5 text-sm flex items-center justify-between gap-3">
                        <span class="text-ink-soft"><span class="font-medium text-ink"><?= e($log['actorEmail'] ?? 'system') ?></span> <?= e(strtolower($log['action'])) ?> <?= e($log['entityType']) ?></span>
                        <span class="text-xs text-muted shrink-0"><?= e(format_datetime($log['createdAt'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?= pagination($page, max(1, (int)ceil($total / COMPANY_PAGE_SIZE)), "/dashboard/companies/$id", ['tab' => 'activity']) ?>

    <?php elseif ($activeTab === 'branches'):
        $branches = db_all('SELECT * FROM Branch WHERE companyId = ? ORDER BY name', [$id]); ?>
        <?php if (!$branches): ?>
            <?= empty_state('map-pin', 'No branches yet.') ?>
        <?php else: ?>
            <ul class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(220px,1fr))]">
                <?php foreach ($branches as $b): ?>
                    <li class="flex items-center justify-between rounded-lg border border-border-soft px-4 py-3 text-sm">
                        <span class="text-ink-soft"><?= e($b['name']) ?> — <?= e($b['city']) ?></span>
                        <?php if ((int)$b['isHQ']): ?><span class="badge badge-info">HQ</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    <?php elseif ($activeTab === 'departments'):
        $departments = db_all('SELECT * FROM Department WHERE companyId = ? ORDER BY name', [$id]); ?>
        <?php if (!$departments): ?>
            <?= empty_state('layers', 'No departments yet.') ?>
        <?php else: ?>
            <ul class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(220px,1fr))]">
                <?php foreach ($departments as $d): ?><li class="rounded-lg border border-border-soft px-4 py-3 text-sm text-ink-soft"><?= e($d['name']) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>

    <?php else:
        $policy = db_one('SELECT * FROM CompanyPolicy WHERE companyId = ?', [$id]); ?>
        <?php if ($policy): ?>
            <div class="max-w-md">
                <ul class="space-y-1.5 text-sm">
                    <?php foreach ([
                        'Work week' => $policy['workWeek'], 'Check-in grace' => $policy['checkInGraceMins'] . ' min',
                        'Casual leave' => $policy['casualLeaveDays'] . '/yr', 'Sick leave' => $policy['sickLeaveDays'] . '/yr',
                        'Earned leave' => $policy['earnedLeaveDays'] . '/yr', 'Probation' => $policy['probationMonths'] . ' mo',
                        'Notice period' => $policy['noticePeriodDays'] . ' days',
                    ] as $label => $value): ?>
                        <li class="flex justify-between"><span class="text-muted"><?= e($label) ?></span><span class="text-ink"><?= e($value) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-xs text-muted mt-3">Edited by the Company Admin under their own Settings — read-only here.</p>
            </div>
        <?php else: ?>
            <?= empty_state('building-2', 'No policy configured yet.') ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php app_layout_close();
