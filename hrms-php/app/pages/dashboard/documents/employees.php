<?php
/** /dashboard/documents/employees — port of app/dashboard/documents/employees/page.tsx */
declare(strict_types=1);

$empIds = visible_employee_ids($auth);
// A plain employee's scope is just themselves — skip the one-entry folder list.
if (count($empIds) === 1) {
    redirect('/dashboard/documents/' . $empIds[0]);
}
[$in, $ids] = db_in($empIds);
$folders = db_all(
    "SELECT e.id, e.firstName, e.lastName, e.avatarColor, COUNT(d.id) AS files FROM Document d JOIN Employee e ON e.id = d.employeeId
      WHERE d.companyId = ? AND d.employeeId IN ($in) GROUP BY e.id, e.firstName, e.lastName, e.avatarColor ORDER BY e.firstName",
    [$auth['companyId'], ...$ids]
);

app_layout_open($auth, 'Employee files');
echo back_link('/dashboard/documents', 'Back to documents');
echo page_header('Employee files', 'Each employee\'s own documents — visible to them, HR, and Admin.');
?>
<div class="card p-5 max-w-2xl">
    <?php if (!$folders): ?>
        <?= empty_state('folder', 'No employee-specific documents yet') ?>
    <?php else: ?>
        <ul class="divide-y divide-border-soft">
            <?php foreach ($folders as $f): ?>
                <li>
                    <a href="<?= e(url('/dashboard/documents/' . $f['id'])) ?>" class="flex items-center justify-between gap-3 py-2.5 hover:bg-brand-softer -mx-1 px-1 rounded-lg">
                        <div class="flex items-center gap-3 min-w-0"><?= avatar($f['firstName'], $f['lastName'], $f['avatarColor']) ?><span class="text-sm font-medium text-ink"><?= e($f['firstName'] . ' ' . $f['lastName']) ?></span></div>
                        <span class="flex items-center gap-1.5 text-xs text-muted shrink-0"><?= (int)$f['files'] ?> file<?= (int)$f['files'] === 1 ? '' : 's' ?> <?= icon('chevron-right', 14) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php app_layout_close();
