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

function action_apply_leave(array $auth, string $leaveTypeId, string $startRaw, string $endRaw, string $reason): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $reason = trim($reason);
    $start = parse_date_input($startRaw);
    $end = parse_date_input($endRaw);
    if (!is_valid_id($leaveTypeId) || $reason === '' || $start === null || $end === null || $end < $start) {
        throw new UserError('Please fill in all fields correctly.');
    }
    v_max($reason, 'Reason', 5000);
    // B5: never accept another company's leave type id.
    $type = db_one('SELECT id, name FROM LeaveType WHERE id = ? AND companyId = ?', [$leaveTypeId, $auth['companyId']]);
    if ($type === null) {
        throw new UserError('Choose a valid leave type.');
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
    assert_can_act_on($auth, $req['employeeId'], $req['approverId']);

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
