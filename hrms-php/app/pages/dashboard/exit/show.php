<?php
/**
 * /dashboard/exit/{id} — port of app/dashboard/exit/[id]/page.tsx. The exiting
 * employee can follow their own exit; staff with the Exit module see their
 * scope (IT/Finance reach it through their clearance queue).
 */
declare(strict_types=1);

$exit = find_company_exit($auth, $params['id']) ?? throw new HttpError(404);
$isOwner = $auth['employeeId'] === $exit['employeeId'];
$staffAccess = has_module_access($auth, '/dashboard/exit');
$companyWide = $staffAccess && is_company_admin($auth);
$clearances = db_all('SELECT * FROM ExitClearance WHERE exitId = ? ORDER BY FIELD(department, \'MANAGER\', \'HR\', \'IT\', \'FINANCE\', \'ASSET\')', [$exit['id']]);
if (!$isOwner && $staffAccess) {
    if (!$companyWide && !can_see_employee($auth, $exit['employeeId']) && !$clearances) {
        redirect('/dashboard/exit');
    }
} elseif (!$isOwner) {
    redirect('/dashboard');
}

handle_post([
    'manager' => function () use ($auth, $exit) {
        action_review_exit_as_manager($auth, $exit['id'], post('approve') === '1');
        return null;
    },
    'hr' => function () use ($auth, $exit) {
        action_approve_exit_as_hr($auth, $exit['id'], post('lastWorkingDate'));
        return null;
    },
    'clear' => function () use ($auth) {
        action_clear_department($auth, post_id('clearanceId'), post('remarks'));
        return null;
    },
    'generate' => function () use ($auth, $exit) {
        action_generate_full_final($auth, $exit['id']);
        return null;
    },
    'approve_settlement' => function () use ($auth, $exit) {
        action_approve_full_final($auth, $exit['id']);
        return null;
    },
    'interview' => function () use ($auth, $exit) {
        action_record_exit_interview($auth, $exit['id'], post_raw('notes'));
        flash('success', 'Notes saved.', 'interview');
        return null;
    },
]);

$employee = db_one('SELECT designation, avatarColor FROM Employee WHERE id = ?', [$exit['employeeId']]);
$settlement = db_one('SELECT * FROM FullFinalSettlement WHERE exitId = ?', [$exit['id']]);
$isTheirManager = $auth['employeeId'] !== null && $auth['employeeId'] === $exit['managerId'];
$canReviewAsManager = $staffAccess && $exit['status'] === 'PENDING' && ($isTheirManager || $companyWide);
$canApproveAsHr = $staffAccess && $exit['status'] === 'MANAGER_APPROVED' && $companyWide;
$allCleared = $clearances && !array_filter($clearances, static fn($c) => $c['status'] !== 'CLEARED');
$canGenerate = $staffAccess && $exit['status'] === 'CLEARANCE' && $allCleared && ($companyWide || $auth['role'] === 'FINANCE');
$canApproveSettlement = $staffAccess && $exit['status'] === 'SETTLED' && ($settlement['status'] ?? '') === 'DRAFT' && ($companyWide || $auth['role'] === 'FINANCE');
const EXIT_DEPT_LABEL = ['MANAGER' => 'Manager', 'HR' => 'HR', 'IT' => 'IT', 'FINANCE' => 'Finance', 'ASSET' => 'Asset'];
$money = static fn(string $label, float $v, bool $bold = false) =>
    '<div><p class="text-xs text-muted">' . e($label) . '</p><p class="mt-0.5 ' . ($bold ? 'font-semibold text-ink' : 'text-ink-soft') . '">' . e(format_currency($v)) . '</p></div>';

app_layout_open($auth, 'Exit — ' . $exit['firstName'] . ' ' . $exit['lastName']);
?>
<div class="max-w-3xl">
    <?= back_link($staffAccess ? '/dashboard/exit' : '/dashboard', $staffAccess ? 'Back to exit / offboarding' : 'Back to dashboard') ?>
    <?= page_header($isOwner && !$staffAccess ? 'Your resignation' : $exit['firstName'] . ' ' . $exit['lastName'],
        $employee['designation'] . ' — resigned ' . format_date($exit['resignationDate']), status_badge($exit['status'])) ?>
    <?= flash_alerts('exit') ?>
    <?php if ($isOwner && !$staffAccess): ?>
        <div class="card p-4 mb-5 text-sm text-ink-soft bg-[var(--color-brand-softer)] border-none">
            Your resignation was submitted on <?= e(format_date($exit['resignationDate'])) ?> and is being processed. This page
            updates as your manager, HR, and clearance departments act on it — no action is needed from you unless you’re contacted directly.
        </div>
    <?php endif; ?>
    <div class="card p-5 mb-5">
        <div class="flex items-center gap-3 mb-4">
            <?= avatar($exit['firstName'], $exit['lastName'], $employee['avatarColor']) ?>
            <a href="<?= e(url('/dashboard/employees/' . $exit['employeeId'])) ?>" class="text-sm text-brand hover:underline">View employee profile</a>
        </div>
        <p class="text-sm text-ink-soft"><span class="font-medium text-ink">Reason:</span> <?= e($exit['reason']) ?></p>
        <p class="text-sm text-ink-soft mt-1"><span class="font-medium text-ink">Notice period:</span> <?= (int)$exit['noticePeriodDays'] ?> days
            <?php if ($exit['lastWorkingDate']): ?> · <span class="font-medium text-ink">Last working day:</span> <?= e(format_date($exit['lastWorkingDate'])) ?><?php endif; ?></p>
    </div>

    <?php if ($canReviewAsManager): ?>
        <div class="card p-5 mb-5">
            <p class="text-sm font-semibold text-ink mb-3">Manager review</p>
            <div class="flex gap-2">
                <form method="post"><?= form_fields('manager', 'exit') ?><input type="hidden" name="approve" value="1"><button class="btn btn-primary">Approve</button></form>
                <form method="post"><?= form_fields('manager', 'exit') ?><input type="hidden" name="approve" value="0"><button class="btn btn-secondary text-danger">Reject</button></form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canApproveAsHr): ?>
        <div class="card p-5 mb-5">
            <p class="text-sm font-semibold text-ink mb-3">HR sign-off — set last working day &amp; start clearance</p>
            <form method="post" class="flex flex-wrap items-end gap-3">
                <?= form_fields('hr', 'exit') ?>
                <div><label class="field-label">Last working date</label><input type="date" name="lastWorkingDate" required class="input"></div>
                <button type="submit" class="btn btn-primary">Approve &amp; start clearance</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($clearances): ?>
        <div class="card p-5 mb-5">
            <p class="text-sm font-semibold text-ink mb-3">Department clearance</p>
            <ul class="divide-y divide-border-soft">
                <?php foreach ($clearances as $c): ?>
                    <li class="py-2.5 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <?= $c['status'] === 'CLEARED' ? icon('circle-check', 16, 'text-brand shrink-0') : icon('circle', 16, 'text-muted shrink-0') ?>
                            <span class="text-sm text-ink"><?= e(EXIT_DEPT_LABEL[$c['department']]) ?></span>
                            <?php if ($c['remarks']): ?><span class="text-xs text-muted">— <?= e($c['remarks']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($c['status'] !== 'CLEARED' && $staffAccess): ?>
                            <form method="post" class="flex items-center gap-2">
                                <?= form_fields('clear', 'exit') ?><input type="hidden" name="clearanceId" value="<?= e($c['id']) ?>">
                                <input name="remarks" maxlength="2000" placeholder="Remarks (optional)" class="input !py-1 text-xs !w-40">
                                <button class="btn btn-secondary !py-1 !px-2.5 text-xs">Clear</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($canGenerate): ?>
        <div class="card p-5 mb-5">
            <p class="text-sm text-ink-soft mb-3">All departments have cleared — generate the Full &amp; Final settlement.</p>
            <form method="post"><?= form_fields('generate', 'exit') ?><button class="btn btn-primary">Generate Full &amp; Final settlement</button></form>
        </div>
    <?php endif; ?>

    <?php if ($settlement): ?>
        <div class="card p-5 mb-5">
            <p class="text-sm font-semibold text-ink mb-3">Full &amp; Final settlement</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm mb-4">
                <?= $money('Leave encashment', (float)$settlement['leaveEncashment']) ?>
                <?= $money('LOP deduction', -(float)$settlement['lopDeduction']) ?>
                <?= $money('Pending expenses', (float)$settlement['pendingExpenses']) ?>
                <?= $money('Net payable', (float)$settlement['netPayable'], true) ?>
            </div>
            <?= status_badge($settlement['status']) ?>
            <?php if ($canApproveSettlement): ?>
                <form method="post" class="mt-3"><?= form_fields('approve_settlement', 'exit') ?><button class="btn btn-primary">Approve settlement &amp; complete exit</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($exit['status'] === 'COMPLETED'): ?>
        <div class="card p-5 mb-5">
            <p class="text-sm font-semibold text-ink mb-2">Letters</p>
            <p class="text-sm text-ink-soft">Relieving and experience letters were generated — see the employee’s Documents.</p>
        </div>
    <?php endif; ?>

    <?php if ($companyWide): ?>
        <div class="card p-5">
            <p class="text-sm font-semibold text-ink mb-3">Exit interview notes</p>
            <form method="post" class="space-y-3">
                <?= form_fields('interview', 'interview') ?>
                <textarea name="notes" rows="4" maxlength="20000" class="input" placeholder="Notes from the exit interview"><?= e($exit['exitInterviewNotes'] ?? '') ?></textarea>
                <?= flash_alerts('interview') ?>
                <button type="submit" class="btn btn-secondary">Save notes</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php app_layout_close();
