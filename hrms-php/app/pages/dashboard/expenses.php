<?php
/** /dashboard/expenses — port of app/dashboard/expenses/page.tsx + SubmitExpenseForm */
declare(strict_types=1);

handle_post([
    'submit' => function () use ($auth) {
        action_submit_expense($auth, post('category'), post('amount'), post_raw('description'));
        flash('success', 'Claim submitted.', 'claim');
        return null;
    },
    'decide' => function () use ($auth) {
        action_decide_expense($auth, post_id('id'), post('approve') === '1');
        return null;
    },
]);

$managerial = is_managerial($auth);
$isFinance = $auth['role'] === 'FINANCE';
$myClaims = $auth['employeeId'] ? db_all('SELECT * FROM ExpenseClaim WHERE employeeId = ? ORDER BY submittedAt DESC', [$auth['employeeId']]) : [];
$pending = [];
if ($managerial) {
    [$qSql, $qParams] = approval_queue_where($auth, 'c');
    $pending = db_all("SELECT c.*, e.firstName, e.lastName, e.avatarColor FROM ExpenseClaim c JOIN Employee e ON e.id = c.employeeId
                        WHERE $qSql AND c.status = 'PENDING' ORDER BY c.submittedAt ASC", $qParams);
} elseif ($isFinance) {
    $pending = db_all("SELECT c.*, e.firstName, e.lastName, e.avatarColor FROM ExpenseClaim c JOIN Employee e ON e.id = c.employeeId
                        WHERE e.companyId = ? AND c.status = 'PENDING' ORDER BY c.submittedAt ASC", [$auth['companyId']]);
}

app_layout_open($auth, 'Expenses');
echo page_header('Expenses', 'Submit claims and track reimbursement status.');
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="card p-6">
        <p class="text-sm font-semibold text-ink mb-4">Submit a claim</p>
        <?php if ($auth['employeeId']): ?>
            <form method="post" class="space-y-4" data-pending-form>
                <?= form_fields('submit', 'claim') ?>
                <div>
                    <label class="field-label">Category</label>
                    <select name="category" class="input"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option value="<?= e($c) ?>"<?= selected($c, old('category')) ?>><?= e($c) ?></option><?php endforeach; ?></select>
                </div>
                <div><label class="field-label">Amount (₹)</label><input type="number" name="amount" min="1" step="any" required value="<?= e(old('amount')) ?>" class="input"></div>
                <div><label class="field-label">Description</label><textarea name="description" rows="3" maxlength="5000" class="input"><?= e(old('description')) ?></textarea></div>
                <?= flash_alerts('claim') ?>
                <button type="submit" class="btn btn-primary w-full justify-center disabled:opacity-70" data-pending-label="Submitting…">Submit claim</button>
            </form>
        <?php else: ?>
            <p class="text-sm text-muted">This account isn’t linked to an employee record, so it can’t submit expense claims.</p>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2 space-y-5">
        <?php if ($managerial || $isFinance): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Awaiting your approval</p>
                <?php if (!$pending): ?>
                    <?= empty_state('receipt', 'Nothing pending') ?>
                <?php else: ?>
                    <ul class="divide-y divide-border-soft">
                        <?php foreach ($pending as $c): ?>
                            <li class="py-3 flex items-start justify-between gap-3">
                                <div class="flex items-start gap-2.5">
                                    <?= avatar($c['firstName'], $c['lastName'], $c['avatarColor']) ?>
                                    <div>
                                        <p class="text-sm font-medium text-ink"><?= e($c['firstName'] . ' ' . $c['lastName']) ?> · <?= e(format_currency((float)$c['amount'])) ?></p>
                                        <p class="text-xs text-muted"><?= e($c['category']) ?> · <?= e(format_date($c['submittedAt'])) ?></p>
                                        <p class="text-sm text-ink-soft mt-1"><?= e($c['description']) ?></p>
                                    </div>
                                </div>
                                <div class="flex gap-1.5 shrink-0">
                                    <form method="post"><?= form_fields('decide') ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="approve" value="1">
                                        <button class="btn btn-secondary !py-1.5 !px-2.5" title="Approve"><?= icon('check', 14) ?></button></form>
                                    <form method="post"><?= form_fields('decide') ?><input type="hidden" name="id" value="<?= e($c['id']) ?>"><input type="hidden" name="approve" value="0">
                                        <button class="btn btn-secondary !py-1.5 !px-2.5 text-danger" title="Reject"><?= icon('x', 14) ?></button></form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="card p-5 overflow-x-auto">
            <p class="text-sm font-semibold text-ink mb-3">My claims</p>
            <table class="table-clean">
                <thead><tr><th>Category</th><th>Amount</th><th>Submitted</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($myClaims as $c): ?>
                    <tr><td><?= e($c['category']) ?></td><td><?= e(format_currency((float)$c['amount'])) ?></td><td><?= e(format_date($c['submittedAt'])) ?></td><td><?= status_badge($c['status']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$myClaims): ?><tr><td colspan="4" class="text-center text-muted py-6">No claims submitted yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php app_layout_close();
