<?php
/**
 * Probation confirmation — port of lib/actions/confirmation.ts.
 * Due dates are computed on read (joining date + probation months), no cron.
 */
declare(strict_types=1);

function probation_months(string $companyId): int
{
    return (int)(db_value('SELECT probationMonths FROM CompanyPolicy WHERE companyId = ?', [$companyId]) ?? 3);
}

/**
 * Active employees in $employeeIds past probation end with no open
 * confirmation — or whose extended probation has now also ended.
 */
function probation_due_employees(string $companyId, array $employeeIds): array
{
    if (!$employeeIds) {
        return [];
    }
    $months = probation_months($companyId);
    [$in, $params] = db_in($employeeIds);
    $employees = db_all("SELECT * FROM Employee WHERE id IN ($in) AND companyId = ? AND status = 'ACTIVE' ORDER BY firstName", [...$params, $companyId]);
    $today = now();
    $due = [];
    foreach ($employees as $e) {
        if (add_months_js(dt($e['dateOfJoining']), $months) > $today) {
            continue;
        }
        $latest = db_one('SELECT status, extendedToDate FROM Confirmation WHERE employeeId = ? ORDER BY createdAt DESC LIMIT 1', [$e['id']]);
        if ($latest === null
            || ($latest['status'] === 'EXTENDED' && $latest['extendedToDate'] !== null && dt($latest['extendedToDate']) <= $today)) {
            $due[] = $e;
        }
    }
    return $due;
}

function action_raise_confirmation(array $auth, string $employeeId): void
{
    $companyId = require_company($auth);
    $employee = db_one('SELECT * FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]);
    if ($employee === null) {
        throw new UserError('Employee not found.');
    }
    $dueDate = add_months_js(dt($employee['dateOfJoining']), probation_months($companyId));
    db_tx(function () use ($auth, $employee, $dueDate) {
        $id = db_insert('Confirmation', ['employeeId' => $employee['id'], 'dueDate' => $dueDate]);
        if ($employee['managerId'] && ($mgrUser = employee_user_id($employee['managerId']))) {
            notify($mgrUser, 'Confirmation review needed',
                "{$employee['firstName']} {$employee['lastName']} has reached the end of probation — please recommend confirmation.",
                '/dashboard/employees/confirmations');
        }
        audit($auth, 'CREATE', 'Confirmation', $id);
    });
}

function action_recommend_confirmation(array $auth, string $confirmationId, string $recommendation): void
{
    $recommendation = v_enum(trim($recommendation), ['Confirm', 'Extend probation'], 'recommendation');
    $c = db_one(
        'SELECT c.*, e.managerId FROM Confirmation c JOIN Employee e ON e.id = c.employeeId WHERE c.id = ? AND e.companyId = ?',
        [$confirmationId, $auth['companyId'] ?? '']
    );
    if ($c === null) {
        throw new UserError('Confirmation not found.');
    }
    if ($c['status'] !== 'PENDING') {
        throw new UserError('This confirmation has already been finalized.');
    }
    assert_can_act_on($auth, $c['employeeId'], $c['managerId']);
    db_tx(function () use ($auth, $confirmationId, $recommendation) {
        db_update('Confirmation', ['managerRecommendation' => $recommendation, 'managerActedAt' => now()], 'id = ?', [$confirmationId]);
        audit($auth, 'RECOMMEND', 'Confirmation', $confirmationId, ['recommendation' => $recommendation]);
    });
}

function action_finalize_confirmation(array $auth, string $confirmationId, string $decision, string $extendedToRaw): void
{
    $companyId = require_company($auth);
    $decision = v_enum($decision, ['CONFIRMED', 'EXTENDED'], 'decision');
    $c = db_one(
        'SELECT c.*, e.userId, e.id AS empId FROM Confirmation c JOIN Employee e ON e.id = c.employeeId WHERE c.id = ? AND e.companyId = ?',
        [$confirmationId, $companyId]
    );
    if ($c === null) {
        throw new UserError('Confirmation not found.');
    }
    if ($c['status'] !== 'PENDING') {
        throw new UserError('This confirmation has already been finalized.');
    }
    $extendedTo = null;
    if ($decision === 'EXTENDED') {
        if ($extendedToRaw === '') {
            throw new UserError('An extended probation end date is required.');
        }
        $extendedTo = v_date($extendedToRaw, 'extended probation end date');
    }
    db_tx(function () use ($auth, $companyId, $c, $confirmationId, $decision, $extendedTo, $extendedToRaw) {
        // Re-check under lock so two HR users can't finalize twice.
        if (db_value('SELECT status FROM Confirmation WHERE id = ? FOR UPDATE', [$confirmationId]) !== 'PENDING') {
            throw new UserError('This confirmation has already been finalized.');
        }
        db_update('Confirmation', [
            'status' => $decision, 'hrApproverId' => $auth['employeeId'], 'hrActedAt' => now(), 'extendedToDate' => $extendedTo,
        ], 'id = ?', [$confirmationId]);
        if ($decision === 'CONFIRMED') {
            generate_letter_for_employee($companyId, $c['empId'], 'CONFIRMATION');
        }
        if ($c['userId']) {
            notify($c['userId'],
                $decision === 'CONFIRMED' ? 'Employment confirmed' : 'Probation extended',
                $decision === 'CONFIRMED' ? 'Congratulations — your employment has been confirmed.' : "Your probation period has been extended to $extendedToRaw.",
                "/dashboard/employees/{$c['empId']}");
        }
        audit($auth, $decision, 'Confirmation', $confirmationId);
    });
}
