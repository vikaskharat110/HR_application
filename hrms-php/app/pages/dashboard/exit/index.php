<?php
/** /dashboard/exit — port of app/dashboard/exit/page.tsx */
declare(strict_types=1);

$companyWide = is_company_admin($auth);
$queueDepartment = $auth['role'] === 'IT_ADMIN' ? 'IT' : ($auth['role'] === 'FINANCE' ? 'FINANCE' : null);
$sql = "SELECT x.*, e.firstName, e.lastName, e.avatarColor,
               (SELECT COUNT(*) FROM ExitClearance c WHERE c.exitId = x.id) AS clearances,
               (SELECT COUNT(*) FROM ExitClearance c WHERE c.exitId = x.id AND c.status = 'CLEARED') AS cleared
          FROM EmployeeExit x JOIN Employee e ON e.id = x.employeeId WHERE e.companyId = ?";
$p = [$auth['companyId']];
if (!$companyWide && $queueDepartment) {
    $sql .= ' AND EXISTS (SELECT 1 FROM ExitClearance c WHERE c.exitId = x.id AND c.department = ?)';
    $p[] = $queueDepartment;
} elseif (!$companyWide) {
    [$in, $ids] = db_in(visible_employee_ids($auth));
    $sql .= " AND x.employeeId IN ($in)";
    $p = [...$p, ...$ids];
}
$exits = db_all($sql . ' ORDER BY x.createdAt DESC', $p);

app_layout_open($auth, 'Exit / Offboarding');
echo page_header('Exit / Offboarding', 'Resignation, notice period, department clearance and Full & Final settlement.',
    '<a href="' . e(url('/dashboard/exit/new')) . '" class="btn btn-primary">' . icon('plus', 15) . ' Initiate resignation</a>');
?>
<?php if (!$exits): ?>
    <div class="card"><?= empty_state('log-out', 'No exits in progress') ?></div>
<?php else: ?>
    <div class="card divide-y divide-border-soft">
        <?php foreach ($exits as $x): ?>
            <a href="<?= e(url('/dashboard/exit/' . $x['id'])) ?>" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-[var(--color-brand-softer)] transition-colors">
                <div class="flex items-center gap-3 min-w-0">
                    <?= avatar($x['firstName'], $x['lastName'], $x['avatarColor']) ?>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-ink truncate"><?= e($x['firstName'] . ' ' . $x['lastName']) ?></p>
                        <p class="text-xs text-muted">Resigned <?= e(format_date($x['resignationDate'])) ?><?= $x['lastWorkingDate'] ? ' · last day ' . e(format_date($x['lastWorkingDate'])) : '' ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <?php if ((int)$x['clearances'] > 0): ?><span class="text-xs text-muted"><?= (int)$x['cleared'] ?>/<?= (int)$x['clearances'] ?> cleared</span><?php endif; ?>
                    <?= status_badge($x['status']) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php app_layout_close();
