<?php
/** /dashboard/onboarding — port of app/dashboard/onboarding/page.tsx */
declare(strict_types=1);

$companyWide = has_role($auth, ['COMPANY_ADMIN', 'HR', 'IT_ADMIN']);
$sql = 'SELECT o.*, e.firstName, e.lastName, e.avatarColor, e.designation,
               (SELECT COUNT(*) FROM OnboardingTask t WHERE t.onboardingId = o.id) AS total,
               (SELECT COUNT(*) FROM OnboardingTask t WHERE t.onboardingId = o.id AND t.status = \'DONE\') AS done
          FROM Onboarding o JOIN Employee e ON e.id = o.employeeId WHERE e.companyId = ?';
$p = [$auth['companyId']];
if (!$companyWide) {
    [$in, $ids] = db_in(visible_employee_ids($auth));
    $sql .= " AND e.id IN ($in)";
    $p = [...$p, ...$ids];
}
$onboardings = db_all($sql . ' ORDER BY o.startedAt DESC', $p);

app_layout_open($auth, 'Onboarding');
echo page_header('Onboarding', 'Pre-boarding, BGV and IT/HR/Manager checklists for new hires.');
?>
<?php if (!$onboardings): ?>
    <div class="card"><?= empty_state('clipboard-check', 'No onboarding checklists yet', 'These are created automatically when a hired candidate is onboarded as an employee.') ?></div>
<?php else: ?>
    <div class="card divide-y divide-border-soft">
        <?php foreach ($onboardings as $o):
            $pct = (int)$o['total'] ? (int)js_round($o['done'] / $o['total'] * 100) : 0; ?>
            <a href="<?= e(url('/dashboard/onboarding/' . $o['id'])) ?>" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-[var(--color-brand-softer)] transition-colors">
                <div class="flex items-center gap-3 min-w-0">
                    <?= avatar($o['firstName'], $o['lastName'], $o['avatarColor']) ?>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-ink truncate"><?= e($o['firstName'] . ' ' . $o['lastName']) ?></p>
                        <p class="text-xs text-muted"><?= e($o['designation']) ?> · started <?= e(format_date($o['startedAt'])) ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <div class="w-32 h-1.5 rounded-full bg-[var(--color-border-soft)] overflow-hidden"><div class="h-full bg-[var(--color-brand)]" style="width: <?= $pct ?>%"></div></div>
                    <span class="text-xs text-muted w-16 text-right"><?= (int)$o['done'] ?>/<?= (int)$o['total'] ?> tasks</span>
                    <?= status_badge($o['status']) ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php app_layout_close();
