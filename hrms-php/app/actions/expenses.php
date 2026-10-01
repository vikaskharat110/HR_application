<?php
/** Expenses — port of lib/actions/expenses.ts. */
declare(strict_types=1);

const EXPENSE_CATEGORIES = ['Travel', 'Meals', 'Software', 'Training', 'Client Entertainment', 'Office Supplies', 'Other'];

function action_submit_expense(array $auth, string $category, string $amountRaw, string $description): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    if (trim($category) === '') {
        throw new UserError('Category is required.');
    }
    $category = v_enum($category, EXPENSE_CATEGORIES, 'category');
    if (!is_numeric($amountRaw) || (float)$amountRaw <= 0 || !is_finite((float)$amountRaw) || (float)$amountRaw > 1e9) {
        throw new UserError('Enter an amount greater than zero.');
    }
    $amount = (float)$amountRaw;
    $description = v_max(normalize_line_endings($description), 'Description', 5000);
    db_tx(function () use ($auth, $category, $amount, $description) {
        $approver = resolve_approver($auth['employeeId']);
        $id = db_insert('ExpenseClaim', ['employeeId' => $auth['employeeId'], 'category' => $category, 'amount' => $amount,
            'description' => $description, 'approverId' => $approver['id'] ?? null]);
        if (!empty($approver['userId'])) {
            notify($approver['userId'], 'New expense claim', "{$auth['name']} submitted a $category claim for " . rupees($amount) . '.', '/dashboard/expenses');
        }
        audit($auth, 'CREATE', 'ExpenseClaim', $id);
    });
}

/** Finance has a company-wide override (BRD Section 10); everyone else follows assert_can_act_on. */
function action_decide_expense(array $auth, string $id, bool $approve, ?string $comments = null): void
{
    $claim = db_one(
        'SELECT c.employeeId, c.approverId FROM ExpenseClaim c JOIN Employee e ON e.id = c.employeeId WHERE c.id = ? AND e.companyId = ?',
        [$id, $auth['companyId'] ?? '']
    );
    if ($claim === null) {
        throw new UserError('Expense claim not found.');
    }
    if ($auth['role'] === 'FINANCE') {
        if ($claim['employeeId'] === $auth['employeeId']) {
            throw new UserError('You cannot approve or reject your own request.');
        }
    } else {
        assert_can_act_on($auth, $claim['employeeId'], $claim['approverId']);
    }
    db_tx(function () use ($auth, $id, $approve, $comments) {
        $c = db_one('SELECT * FROM ExpenseClaim WHERE id = ? FOR UPDATE', [$id]);
        if ($c['status'] !== 'PENDING') {
            throw new UserError('This claim has already been actioned.');
        }
        db_update('ExpenseClaim', ['status' => $approve ? 'APPROVED' : 'REJECTED', 'actedAt' => now(), 'comments' => $comments], 'id = ?', [$id]);
        if ($uid = employee_user_id($c['employeeId'])) {
            notify($uid, 'Expense claim ' . ($approve ? 'approved' : 'rejected'),
                "Your {$c['category']} claim for " . rupees((float)$c['amount']) . ' was ' . ($approve ? 'approved' : 'rejected') . '.', '/dashboard/expenses');
        }
        audit($auth, $approve ? 'APPROVE' : 'REJECT', 'ExpenseClaim', $id);
    });
}
