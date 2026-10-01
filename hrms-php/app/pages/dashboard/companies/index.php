<?php
/** /dashboard/companies — port of app/dashboard/companies/page.tsx (Super Admin) */
declare(strict_types=1);

const COMPANIES_PAGE_SIZE = 12;
$page = query_page();
$total = (int)db_value('SELECT COUNT(*) FROM Company');
$companies = db_all(
    'SELECT c.*,
            (SELECT COUNT(*) FROM Employee e WHERE e.companyId = c.id) AS employees,
            (SELECT COUNT(*) FROM `User` u WHERE u.companyId = c.id) AS users,
            (SELECT COUNT(*) FROM Branch b WHERE b.companyId = c.id) AS branches
       FROM Company c ORDER BY c.createdAt DESC LIMIT ' . COMPANIES_PAGE_SIZE . ' OFFSET ' . (($page - 1) * COMPANIES_PAGE_SIZE)
);
$totalPages = max(1, (int)ceil($total / COMPANIES_PAGE_SIZE));

app_layout_open($auth, 'Companies');
echo temp_password_notice();
echo page_header('Companies', 'Every tenant provisioned on WorkNest.',
    '<a href="' . e(url('/dashboard/companies/new')) . '" class="btn btn-primary">' . icon('plus', 15) . ' New company</a>');
?>
<div class="card overflow-x-auto">
    <?php if (!$companies): ?>
        <?= empty_state('building-2', 'No companies yet') ?>
    <?php else: ?>
        <table class="table-clean">
            <thead><tr><th>Company</th><th>Industry</th><th>Employees</th><th>Logins</th><th>Branches</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($companies as $c): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('/dashboard/companies/' . $c['id'])) ?>" class="block">
                            <span class="font-display font-semibold text-ink hover:underline"><?= e($c['name']) ?></span>
                            <span class="block text-xs text-muted mt-0.5"><?= e($c['slug']) ?></span>
                        </a>
                    </td>
                    <td class="whitespace-nowrap"><?= e($c['industry'] ?: DASH) ?></td>
                    <td class="whitespace-nowrap"><?= (int)$c['employees'] ?></td>
                    <td class="whitespace-nowrap"><?= (int)$c['users'] ?></td>
                    <td class="whitespace-nowrap"><?= (int)$c['branches'] ?></td>
                    <td><?= status_badge($c['status']) ?></td>
                    <td class="whitespace-nowrap"><?= e(format_date($c['createdAt'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?= pagination($page, $totalPages, '/dashboard/companies') ?>
<?php app_layout_close();
