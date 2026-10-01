<?php
/** /dashboard/employees/confirmations — port of app/dashboard/employees/confirmations/page.tsx */
declare(strict_types=1);

$companyWide = is_company_admin($auth);
handle_post([
    'raise' => function () use ($auth, $companyWide) {
        $companyWide || throw new UserError('Only HR or the Company Admin can raise a confirmation.');
        action_raise_confirmation($auth, post_id('employeeId'));
        return null;
    },
    'recommend' => function () use ($auth) {
        action_recommend_confirmation($auth, post_id('confirmationId'), post('recommendation'));
        return null;
    },
    'finalize' => function () use ($auth, $companyWide) {
        $companyWide || throw new UserError('Only HR or the Company Admin can finalize a confirmation.');
        action_finalize_confirmation($auth, post_id('confirmationId'), post('decision'), post('extendedToDate'));
        return null;
    },
]);

$empIds = visible_employee_ids($auth);
[$in, $ids] = db_in($empIds);
$due = $auth['companyId'] ? probation_due_employees($auth['companyId'], $empIds) : [];
$pending = db_all(
    "SELECT c.*, e.firstName, e.lastName, e.avatarColor, e.managerId FROM Confirmation c JOIN Employee e ON e.id = c.employeeId
      WHERE c.employeeId IN ($in) AND c.status = 'PENDING' ORDER BY c.createdAt ASC", $ids);
$recent = db_all(
    "SELECT c.*, e.firstName, e.lastName FROM Confirmation c JOIN Employee e ON e.id = c.employeeId
      WHERE c.employeeId IN ($in) AND c.status <> 'PENDING' ORDER BY c.hrActedAt DESC LIMIT 10", $ids);

app_layout_open($auth, 'Confirmations');
?>
<?= back_link('/dashboard/employees', 'Back to employees') ?>
<?= page_header('Confirmations', 'Probation end-of-term review — manager recommendation, then HR sign-off.') ?>
<?php if ($companyWide): ?>
    <div class="card p-5 mb-5">
        <p class="text-sm font-semibold text-ink mb-3">Probation due</p>
        <?php if (!$due): ?>
            <?= empty_state('user-check', 'Nobody due right now') ?>
        <?php else: ?>
            <ul class="divide-y divide-border-soft">
                <?php foreach ($due as $e): ?>
                    <li class="py-3 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <?= avatar($e['firstName'], $e['lastName'], $e['avatarColor']) ?>
                            <span class="text-sm text-ink"><?= e($e['firstName'] . ' ' . $e['lastName']) ?> <span class="text-muted">— joined <?= e(format_date($e['dateOfJoining'])) ?></span></span>
                        </div>
                        <form method="post"><?= form_fields('raise') ?><input type="hidden" name="employeeId" value="<?= e($e['id']) ?>">
                            <button class="btn btn-secondary !py-1.5 !px-2.5 text-xs">Raise confirmation</button></form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card p-5 mb-5">
    <p class="text-sm font-semibold text-ink mb-3">Awaiting a decision</p>
    <?php if (!$pending): ?>
        <?= empty_state('clock', 'Nothing pending') ?>
    <?php else: ?>
        <ul class="divide-y divide-border-soft">
            <?php foreach ($pending as $c):
                $isManager = $auth['employeeId'] !== null && $auth['employeeId'] === $c['managerId']; ?>
                <li class="py-3 space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <?= avatar($c['firstName'], $c['lastName'], $c['avatarColor']) ?>
                            <span class="text-sm text-ink"><?= e($c['firstName'] . ' ' . $c['lastName']) ?></span>
                        </div>
                        <span class="text-xs text-muted">Due <?= e(format_date($c['dueDate'])) ?></span>
                    </div>
                    <?php if ($c['managerRecommendation']): ?>
                        <p class="text-xs text-ink-soft pl-10">Manager recommendation: <?= e($c['managerRecommendation']) ?></p>
                    <?php elseif ($isManager || $companyWide): ?>
                        <form method="post" class="flex items-center gap-2 pl-10">
                            <?= form_fields('recommend') ?><input type="hidden" name="confirmationId" value="<?= e($c['id']) ?>">
                            <select name="recommendation" class="input !py-1 text-xs !w-auto">
                                <option value="Confirm">Recommend: Confirm</option>
                                <option value="Extend probation">Recommend: Extend probation</option>
                            </select>
                            <button class="btn btn-secondary !py-1 !px-2.5 text-xs">Submit recommendation</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($companyWide && $c['managerRecommendation']): ?>
                        <form method="post" class="flex flex-wrap items-center gap-2 pl-10">
                            <?= form_fields('finalize') ?><input type="hidden" name="confirmationId" value="<?= e($c['id']) ?>">
                            <select name="decision" class="input !py-1 text-xs !w-auto">
                                <option value="CONFIRMED">Confirm employment</option>
                                <option value="EXTENDED">Extend probation</option>
                            </select>
                            <input type="date" name="extendedToDate" class="input !py-1 text-xs !w-auto">
                            <button class="btn btn-primary !py-1 !px-2.5 text-xs"><?= icon('check', 12) ?> Finalize</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card p-5 overflow-x-auto">
    <p class="text-sm font-semibold text-ink mb-3">Recent decisions</p>
    <?php if (!$recent): ?>
        <p class="text-sm text-muted py-4">No decided confirmations yet.</p>
    <?php else: ?>
        <table class="table-clean">
            <thead><tr><th>Employee</th><th>Due date</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $c): ?>
                <tr><td><?= e($c['firstName'] . ' ' . $c['lastName']) ?></td><td><?= e(format_date($c['dueDate'])) ?></td><td><?= status_badge($c['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php app_layout_close();
