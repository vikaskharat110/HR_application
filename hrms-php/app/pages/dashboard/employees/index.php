<?php
/** /dashboard/employees — port of app/dashboard/employees/page.tsx */
declare(strict_types=1);

const EMPLOYEES_PAGE_SIZE = 15;
$page = query_page();
[$in, $ids] = db_in(visible_employee_ids($auth));
$q = trim(query('q'));
$searchSql = '';
$searchArgs = [];
if ($q !== '') {
    $like = '%' . addcslashes(mb_substr($q, 0, 100), '\%_') . '%';
    $searchSql = " AND (CONCAT(e.firstName, ' ', e.lastName) LIKE ? OR e.employeeCode LIKE ? OR e.email LIKE ? OR e.designation LIKE ? OR d.name LIKE ?)";
    $searchArgs = [$like, $like, $like, $like, $like];
}
$total = (int)db_value("SELECT COUNT(*) FROM Employee e LEFT JOIN Department d ON d.id = e.departmentId WHERE e.id IN ($in)$searchSql", [...$ids, ...$searchArgs]);
$employees = db_all(
    "SELECT e.*, d.name AS department, b.name AS branch, m.firstName AS mFirst, m.lastName AS mLast
       FROM Employee e
       LEFT JOIN Department d ON d.id = e.departmentId
       LEFT JOIN Branch b ON b.id = e.branchId
       LEFT JOIN Employee m ON m.id = e.managerId
      WHERE e.id IN ($in)$searchSql ORDER BY e.firstName ASC, e.lastName ASC LIMIT " . EMPLOYEES_PAGE_SIZE . ' OFFSET ' . (($page - 1) * EMPLOYEES_PAGE_SIZE),
    [...$ids, ...$searchArgs]
);
$totalPages = max(1, (int)ceil($total / EMPLOYEES_PAGE_SIZE));
$canCreate = is_company_admin($auth);

$actions = '<div class="flex flex-wrap gap-2">'
    . '<a href="' . e(url('/api/reports/employees')) . '" class="btn btn-secondary">' . icon('download', 15) . ' Export</a>'
    . ($canCreate ? '<a href="' . e(url('/dashboard/employees/import')) . '" class="btn btn-secondary">' . icon('cloud-upload', 15) . ' Import</a>' : '')
    . '<a href="' . e(url('/dashboard/employees/confirmations')) . '" class="btn btn-secondary">' . icon('user-check', 15) . ' Confirmations</a>'
    . '<a href="' . e(url('/dashboard/employees/promotions')) . '" class="btn btn-secondary">' . icon('trending-up', 15) . ' Promotions &amp; Transfers</a>'
    . ($canCreate ? '<a href="' . e(url('/dashboard/employees/new')) . '" class="btn btn-primary">' . icon('user-plus', 15) . ' Add employee</a>' : '')
    . '</div>';

app_layout_open($auth, 'Employees');
echo temp_password_notice();
echo page_header('Employees', $q !== '' ? "$total result(s) for “{$q}”" : "$total people in view", $actions);
?>
<form method="get" class="mb-4 flex gap-2 max-w-md">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, code, email, designation or department" class="input" aria-label="Search employees">
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if ($q !== ''): ?><a href="<?= e(url('/dashboard/employees')) ?>" class="btn btn-secondary">Clear</a><?php endif; ?>
</form>
<div class="card overflow-x-auto">
    <?php if (!$employees): ?>
        <?= ($q !== '' ? empty_state('users', 'No matching employees', 'Try a different name, code or department.') : empty_state('users', 'No employees yet', 'Add your first employee to get started.')) ?>
    <?php else: ?>
        <table class="table-clean">
            <thead><tr><th>Employee</th><th>Designation</th><th>Department</th><th>Branch</th><th>Manager</th><th>Joined</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($employees as $e): ?>
                <tr>
                    <td>
                        <a href="<?= e(url('/dashboard/employees/' . $e['id'])) ?>" class="flex items-center gap-3 hover:underline">
                            <?= avatar($e['firstName'], $e['lastName'], $e['avatarColor']) ?>
                            <span>
                                <span class="block font-medium text-ink"><?= e($e['firstName'] . ' ' . $e['lastName']) ?></span>
                                <span class="block text-xs text-muted"><?= e($e['employeeCode']) ?></span>
                            </span>
                        </a>
                    </td>
                    <td><?= e($e['designation']) ?></td>
                    <td><?= e($e['department'] ?? DASH) ?></td>
                    <td><?= e($e['branch'] ?? DASH) ?></td>
                    <td><?= e($e['mFirst'] ? $e['mFirst'] . ' ' . $e['mLast'] : DASH) ?></td>
                    <td><?= e(format_date($e['dateOfJoining'])) ?></td>
                    <td><?= status_badge($e['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?= pagination($page, $totalPages, '/dashboard/employees', ['q' => $q]) ?>
<?php app_layout_close();
