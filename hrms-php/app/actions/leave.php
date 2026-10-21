<?php
/**
 * Leave — port of lib/actions/leave.ts, with the approved fixes:
 *  B1 approval is one transaction — validate, check balance, update request,
 *     deduct balance, audit, commit; any failure rolls back and the request
 *     stays PENDING (the original set APPROVED before the balance check).
 *  B5 the leave type must belong to the applicant's company.
 *  B3 balances for the request's year are created on demand at approval.
 */
declare(strict_types=1);

/** Calendar days inclusive (weekends/holidays included — original behaviour). */
function leave_day_count(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    return (int)$start->diff($end)->days + 1;
}

/** The company's unpaid leave type (created on first use if the company has none). */
function unpaid_leave_type(string $companyId): array
{
    $type = db_one('SELECT id, name FROM LeaveType WHERE companyId = ? AND paid = 0 ORDER BY name LIMIT 1', [$companyId]);
    if ($type === null) {
        $id = db_insert('LeaveType', ['companyId' => $companyId, 'name' => 'Unpaid Leave', 'daysPerYear' => 0, 'carryForward' => 0, 'paid' => 0]);
        $type = ['id' => $id, 'name' => 'Unpaid Leave'];
    }
    return $type;
}

/** How far back HR may file leave on an employee's behalf (regularising past absences). */
const LEAVE_ON_BEHALF_MAX_DAYS_BACK = 30;

/**
 * HR files leave for an employee. The request goes PENDING to the employee's manager and the
 * Company Admin; HR (the applier) can never decide it. $kind is 'PAID' or 'UNPAID'.
 */
function action_apply_leave_for_employee(array $auth, string $employeeId, string $kind, string $leaveTypeId, string $startRaw, string $endRaw, string $reason): void
{
    if ($auth['role'] !== 'HR' || !$auth['employeeId'] || !$auth['companyId']) {
        throw new UserError('Only HR can apply leave on behalf of an employee.');
    }
    $companyId = $auth['companyId'];
    $employee = is_valid_id($employeeId)
        ? db_one("SELECT id, firstName, lastName, userId, status FROM Employee WHERE id = ? AND companyId = ?", [$employeeId, $companyId])
        : null;
    if ($employee === null) {
        throw new UserError('Choose an employee.');
    }
    if ($employee['id'] === $auth['employeeId']) {
        throw new UserError('Use “Apply for leave” for your own leave — it goes to your reporting manager or the Company Admin.');
    }
    if ($employee['status'] === 'EXITED') {
        throw new UserError('This employee has exited.');
    }
    $reason = trim($reason);
    $start = parse_date_input($startRaw);
    $end = parse_date_input($endRaw);
    if (!in_array($kind, ['PAID', 'UNPAID'], true)) {
        throw new UserError('Choose paid or unpaid leave.');
    }
    if ($reason === '' || $start === null || $end === null || $end < $start) {
        throw new UserError('Please fill in all fields correctly.');
    }
    v_max($reason, 'Reason', 5000);
    if ($start < today_start()->modify('-' . LEAVE_ON_BEHALF_MAX_DAYS_BACK . ' days')) {
        throw new UserError('Leave can only be applied up to ' . LEAVE_ON_BEHALF_MAX_DAYS_BACK . ' days in the past.');
    }
    if ($kind === 'UNPAID') {
        $type = unpaid_leave_type($companyId);
    } else {
        $type = is_valid_id($leaveTypeId)
            ? db_one("SELECT id, name FROM LeaveType WHERE id = ? AND companyId = ? AND paid = 1 AND name <> 'Earned Leave'", [$leaveTypeId, $companyId])
            : null;
        if ($type === null) {
            throw new UserError('Choose a valid paid leave type.');
        }
    }
    $days = leave_day_count($start, $end);

    db_tx(function () use ($auth, $companyId, $employee, $type, $start, $end, $days, $reason) {
        $clash = db_value(
            "SELECT 1 FROM LeaveRequest WHERE employeeId = ? AND status IN ('PENDING','APPROVED') AND startDate <= ? AND endDate >= ? LIMIT 1",
            [$employee['id'], $end, $start]
        );
        if ($clash) {
            throw new UserError('This employee already has a pending or approved leave overlapping those dates.');
        }
        $approver = resolve_approver($employee['id']);
        if ($approver !== null && $approver['id'] === $auth['employeeId']) {
            $approver = null; // HR is the applier — never also the approver; the Company Admin decides.
        }
        $id = db_insert('LeaveRequest', [
            'employeeId' => $employee['id'], 'leaveTypeId' => $type['id'], 'startDate' => $start, 'endDate' => $end,
            'days' => (float)$days, 'reason' => $reason, 'approverId' => $approver['id'] ?? null, 'appliedById' => $auth['employeeId'],
        ]);
        $name = $employee['firstName'] . ' ' . $employee['lastName'];
        $recipients = db_column("SELECT id FROM `User` WHERE companyId = ? AND role = 'COMPANY_ADMIN'", [$companyId]);
        if (!empty($approver['userId'])) {
            $recipients[] = $approver['userId'];
        }
        notify_many($recipients, 'Leave request from HR', "{$auth['name']} applied {$days} day(s) of leave for $name — approval needed.", '/dashboard/leave/approvals');
        if ($employee['userId']) {
            notify($employee['userId'], 'Leave applied for you', "HR applied {$days} day(s) of {$type['name']} for you, pending approval.", '/dashboard/leave');
        }
        audit($auth, 'CREATE', 'LeaveRequest', $id, ['days' => $days, 'onBehalfOf' => $employee['id']]);
    });
}

/** $kind is 'PAID' or 'UNPAID'. Unpaid ignores $leaveTypeId and uses the company's unpaid type. */
function action_apply_leave(array $auth, string $kind, string $leaveTypeId, string $startRaw, string $endRaw, string $reason): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $reason = trim($reason);
    $start = parse_date_input($startRaw);
    $end = parse_date_input($endRaw);
    if (!in_array($kind, ['PAID', 'UNPAID'], true)) {
        throw new UserError('Choose paid or unpaid leave.');
    }
    if ($reason === '' || $start === null || $end === null || $end < $start) {
        throw new UserError('Please fill in all fields correctly.');
    }
    v_max($reason, 'Reason', 5000);
    // B5: never accept another company's leave type id.
    if ($kind === 'UNPAID') {
        $type = unpaid_leave_type($auth['companyId']);
    } else {
        $type = is_valid_id($leaveTypeId)
            ? db_one("SELECT id, name FROM LeaveType WHERE id = ? AND companyId = ? AND paid = 1 AND name <> 'Earned Leave'", [$leaveTypeId, $auth['companyId']])
            : null;
        if ($type === null) {
            throw new UserError('Choose a valid paid leave type.');
        }
    }
    $days = leave_day_count($start, $end);

    db_tx(function () use ($auth, $type, $start, $end, $days, $reason) {
        $approver = resolve_approver($auth['employeeId']);
        $id = db_insert('LeaveRequest', [
            'employeeId' => $auth['employeeId'], 'leaveTypeId' => $type['id'], 'startDate' => $start, 'endDate' => $end,
            'days' => (float)$days, 'reason' => $reason, 'approverId' => $approver['id'] ?? null,
        ]);
        if (!empty($approver['userId'])) {
            notify($approver['userId'], 'New leave request', "{$auth['name']} requested $days day(s) of leave.", '/dashboard/leave/approvals');
        }
        audit($auth, 'CREATE', 'LeaveRequest', $id, ['days' => $days]);
    });
}

function action_decide_leave(array $auth, string $id, bool $approve, ?string $comments = null): void
{
    $req = db_one(
        'SELECT r.id, r.employeeId, r.approverId FROM LeaveRequest r JOIN Employee e ON e.id = r.employeeId WHERE r.id = ? AND e.companyId = ?',
        [$id, $auth['companyId'] ?? '']
    );
    if ($req === null) {
        throw new UserError('Leave request not found.');
    }
    $appliedById = db_value('SELECT appliedById FROM LeaveRequest WHERE id = ?', [$id]);
    if ($appliedById !== null) {
        // Filed by HR on the employee's behalf: only the Company Admin or the assigned manager decide — never HR.
        if ($auth['role'] !== 'COMPANY_ADMIN' && ($auth['employeeId'] === null || $auth['employeeId'] !== $req['approverId'] || $auth['employeeId'] === $appliedById)) {
            throw new UserError('This request was applied by HR — only the Company Admin or the employee’s manager can approve or reject it.');
        }
        if ($auth['employeeId'] === $req['employeeId']) {
            throw new UserError('You cannot approve or reject your own request.');
        }
    } else {
        assert_can_act_on($auth, $req['employeeId'], $req['approverId']);
    }

    db_tx(function () use ($auth, $id, $approve, $comments) {
        // Lock the request so two approvers can't both act on it.
        $r = db_one(
            'SELECT r.*, t.name AS typeName, t.paid, e.companyId FROM LeaveRequest r
               JOIN LeaveType t ON t.id = r.leaveTypeId JOIN Employee e ON e.id = r.employeeId
              WHERE r.id = ? FOR UPDATE',
            [$id]
        );
        if ($r['status'] !== 'PENDING') {
            throw new UserError('This request has already been actioned.');
        }
        if ($approve) {
            $year = (int)dt($r['startDate'])->format('Y');
            ensure_leave_balances($r['companyId'], $r['employeeId'], $year);
            $balance = db_one('SELECT id, allocated, used FROM LeaveBalance WHERE employeeId = ? AND leaveTypeId = ? AND year = ? FOR UPDATE',
                [$r['employeeId'], $r['leaveTypeId'], $year]);
            if ((int)$r['paid']) {
                $remaining = (float)($balance['allocated'] ?? 0) - (float)($balance['used'] ?? 0);
                if ((float)$r['days'] > $remaining) {
                    throw new UserError("Approving this would exceed the employee’s remaining {$r['typeName']} balance (" . js_num($remaining)
                        . ' day(s) left). Reject it instead, or have them re-apply for fewer days.');
                }
            }
            if ($balance !== null) {
                db_exec('UPDATE LeaveBalance SET used = used + ? WHERE id = ?', [(float)$r['days'], $balance['id']]);
            }
        }
        db_update('LeaveRequest', ['status' => $approve ? 'APPROVED' : 'REJECTED', 'actedAt' => now(), 'comments' => $comments], 'id = ?', [$id]);
        if ($uid = employee_user_id($r['employeeId'])) {
            notify($uid, 'Leave request ' . ($approve ? 'approved' : 'rejected'),
                "Your {$r['typeName']} request for " . js_num($r['days']) . ' day(s) was ' . ($approve ? 'approved' : 'rejected') . '.', '/dashboard/leave');
        }
        audit($auth, $approve ? 'APPROVE' : 'REJECT', 'LeaveRequest', $id);
    });
}

/** Only the requester, only while PENDING (original behaviour). */
function action_cancel_leave(array $auth, string $id): void
{
    db_exec("UPDATE LeaveRequest SET status = 'CANCELLED' WHERE id = ? AND employeeId = ? AND status = 'PENDING'", [$id, $auth['employeeId'] ?? '__none__']);
}
