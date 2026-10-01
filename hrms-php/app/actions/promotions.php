<?php
/**
 * Promotions & transfers — port of lib/actions/promotions.ts. Changes apply
 * immediately on approval (effectiveDate is the recorded business date).
 */
declare(strict_types=1);

function action_request_promotion_transfer(array $auth, array $in): string
{
    $companyId = require_company($auth);
    $employeeId = v_id((string)($in['employeeId'] ?? ''), 'employee');
    $type = v_enum((string)($in['type'] ?? 'TRANSFER'), ['PROMOTION', 'TRANSFER', 'BOTH'], 'type');
    $newDesignation = v_optional(trim((string)($in['newDesignation'] ?? '')), 'New designation');
    $newDepartmentId = v_id(trim((string)($in['newDepartmentId'] ?? '')), 'department', false);
    $newBranchId = v_id(trim((string)($in['newBranchId'] ?? '')), 'branch', false);
    $newManagerId = v_id(trim((string)($in['newManagerId'] ?? '')), 'manager', false);
    $newBasicRaw = trim((string)($in['newBasic'] ?? ''));
    $newBasic = $newBasicRaw !== '' ? v_number($newBasicRaw, 'New basic salary', 0) : null;
    $reason = trim((string)($in['reason'] ?? ''));
    $effectiveRaw = trim((string)($in['effectiveDate'] ?? ''));

    $employee = db_one('SELECT * FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]);
    if ($employee === null) {
        throw new UserError('Employee not found.');
    }
    if ($reason === '' || $effectiveRaw === '') {
        throw new UserError('Reason and effective date are required.');
    }
    $effectiveDate = v_date($effectiveRaw, 'effective date');
    if ($newDesignation === null && $newDepartmentId === null && $newBranchId === null && $newManagerId === null && $newBasic === null) {
        throw new UserError('Provide at least one change — designation, department, branch, manager, or salary.');
    }
    assert_org_refs($companyId, $newDepartmentId, $newBranchId, $newManagerId);
    if ($newManagerId !== null && would_create_manager_cycle($employeeId, $newManagerId)) {
        throw new UserError('That would create a reporting cycle.');
    }

    db_tx(function () use ($auth, $employee, $employeeId, $type, $newDesignation, $newDepartmentId, $newBranchId, $newManagerId, $newBasic, $effectiveDate, $reason) {
        $approver = resolve_approver($employeeId);
        $id = db_insert('PromotionTransfer', [
            'employeeId' => $employeeId,
            'requestedById' => $auth['employeeId'] ?? $employeeId,
            'type' => $type,
            'newDesignation' => $newDesignation,
            'newDepartmentId' => $newDepartmentId,
            'newBranchId' => $newBranchId,
            'newManagerId' => $newManagerId,
            'newBasic' => $newBasic,
            'effectiveDate' => $effectiveDate,
            'reason' => $reason,
            'approverId' => $approver['id'] ?? null,
        ]);
        if (!empty($approver['userId'])) {
            notify($approver['userId'], 'Promotion/transfer awaiting your approval',
                "{$employee['firstName']} {$employee['lastName']} has a proposed " . strtolower($type) . ' to review.',
                '/dashboard/employees/promotions');
        }
        audit($auth, 'CREATE', 'PromotionTransfer', $id, ['employeeId' => $employeeId, 'type' => $type]);
    });
    flash('success', 'Submitted for approval.');
    return "/dashboard/employees/$employeeId";
}

function action_decide_promotion_transfer(array $auth, string $id, bool $approve): void
{
    $existing = db_one(
        'SELECT p.* FROM PromotionTransfer p JOIN Employee e ON e.id = p.employeeId WHERE p.id = ? AND e.companyId = ?',
        [$id, $auth['companyId'] ?? '']
    );
    if ($existing === null) {
        throw new UserError('Request not found.');
    }
    assert_can_act_on($auth, $existing['employeeId'], $existing['approverId']);

    db_tx(function () use ($auth, $id, $approve) {
        $record = db_one('SELECT * FROM PromotionTransfer WHERE id = ? FOR UPDATE', [$id]);
        if ($record['status'] !== 'PENDING') {
            throw new UserError('This request has already been actioned.');
        }
        db_update('PromotionTransfer', ['status' => $approve ? 'APPROVED' : 'REJECTED', 'actedAt' => now()], 'id = ?', [$id]);
        $employee = db_one('SELECT * FROM Employee WHERE id = ?', [$record['employeeId']]);

        if ($approve) {
            $patch = [];
            foreach (['newDesignation' => 'designation', 'newDepartmentId' => 'departmentId', 'newBranchId' => 'branchId', 'newManagerId' => 'managerId'] as $from => $to) {
                if ($record[$from] !== null && $record[$from] !== '') {
                    $patch[$to] = $record[$from];
                }
            }
            if ($patch) {
                apply_org_field_changes($auth, $employee['companyId'], $employee['id'], $employee, $patch,
                    $record['type'] . '_APPROVED', ['promotionTransferId' => $id, 'effectiveDate' => db_dt(dt($record['effectiveDate']))]);
            }
            if ($record['newBasic'] !== null) {
                $before = db_one('SELECT id, basic FROM SalaryStructure WHERE employeeId = ?', [$employee['id']]);
                if ($before) {
                    db_update('SalaryStructure', ['basic' => (float)$record['newBasic']], 'id = ?', [$before['id']]);
                } else {
                    db_insert('SalaryStructure', ['employeeId' => $employee['id'], 'basic' => (float)$record['newBasic'], 'hra' => js_round($record['newBasic'] * 0.4)]);
                }
                record_audit([
                    'companyId' => $employee['companyId'], 'actorId' => $auth['sub'], 'action' => 'SALARY_REVISION',
                    'entityType' => 'SalaryStructure', 'entityId' => $employee['id'],
                    'meta' => ['promotionTransferId' => $id, 'from' => $before ? (float)$before['basic'] : null, 'to' => (float)$record['newBasic']],
                ]);
            }
        }
        if ($employee['userId']) {
            $label = $record['type'] === 'PROMOTION' ? 'Promotion' : 'Transfer';
            notify($employee['userId'], "$label " . ($approve ? 'approved' : 'rejected'),
                $approve
                    ? 'Your ' . strtolower($record['type']) . ', effective ' . dt($record['effectiveDate'])->format('D M d Y') . ', has been approved.'
                    : 'Your proposed ' . strtolower($record['type']) . ' was not approved.',
                "/dashboard/employees/{$employee['id']}");
        }
        audit($auth, $approve ? 'APPROVE' : 'REJECT', 'PromotionTransfer', $id);
    });
}
