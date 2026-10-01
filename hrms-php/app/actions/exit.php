<?php
/**
 * Exit / offboarding — port of lib/actions/exit.ts:
 * resign → manager review → HR sign-off (LWD, NOTICE_PERIOD, 5 clearances)
 * → department clearances → Full & Final settlement → approve (EXITED,
 * login disabled, relieving + experience letters).
 */
declare(strict_types=1);

const EXIT_DEPARTMENTS = ['MANAGER', 'HR', 'IT', 'FINANCE', 'ASSET'];
const CLEARANCE_ROLES = [
    'MANAGER' => ['COMPANY_ADMIN', 'HR'], 'HR' => ['COMPANY_ADMIN', 'HR'], 'IT' => ['COMPANY_ADMIN', 'IT_ADMIN'],
    'FINANCE' => ['COMPANY_ADMIN', 'FINANCE'], 'ASSET' => ['COMPANY_ADMIN', 'IT_ADMIN'],
];

function find_company_exit(array $auth, string $exitId): ?array
{
    return is_valid_id($exitId) ? db_one(
        'SELECT x.*, e.firstName, e.lastName, e.managerId, e.userId AS employeeUserId, e.companyId FROM EmployeeExit x JOIN Employee e ON e.id = x.employeeId
          WHERE x.id = ? AND e.companyId = ?',
        [$exitId, $auth['companyId'] ?? '']
    ) : null;
}

/** Employees resign for themselves; HR/Company Admin can raise it on someone's behalf. Returns the exit id. */
function action_initiate_resignation(array $auth, string $employeeIdRaw, string $dateRaw, string $reason): string
{
    $companyId = require_company($auth);
    $employeeId = $employeeIdRaw !== '' ? $employeeIdRaw : (string)$auth['employeeId'];
    if ($employeeId === '' || !is_valid_id($employeeId)) {
        throw new UserError('No employee record linked to this account.');
    }
    if ($employeeId !== $auth['employeeId'] && !is_company_admin($auth)) {
        throw new UserError('You can only initiate a resignation for yourself.');
    }
    $employee = db_one('SELECT * FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]) ?? throw new UserError('Employee not found.');
    $date = trim($dateRaw) === '' ? today_start() : v_date($dateRaw, 'resignation date');
    $reason = v_required(trim($reason), 'A reason', 5000);
    return db_tx(function () use ($auth, $companyId, $employee, $date, $reason) {
        db_value('SELECT id FROM Employee WHERE id = ? FOR UPDATE', [$employee['id']]);
        if (db_value("SELECT 1 FROM EmployeeExit WHERE employeeId = ? AND status NOT IN ('COMPLETED', 'REJECTED')", [$employee['id']])) {
            throw new UserError('This employee already has an exit in progress.');
        }
        $notice = (int)(db_value('SELECT noticePeriodDays FROM CompanyPolicy WHERE companyId = ?', [$companyId]) ?? 30);
        $id = db_insert('EmployeeExit', ['employeeId' => $employee['id'], 'requestedById' => $auth['employeeId'] ?? $employee['id'],
            'resignationDate' => $date, 'reason' => $reason, 'noticePeriodDays' => $notice]);
        if ($employee['managerId'] && ($mgrUser = employee_user_id($employee['managerId']))) {
            notify($mgrUser, 'Resignation submitted', "{$employee['firstName']} {$employee['lastName']} has submitted their resignation.", "/dashboard/exit/$id");
        }
        audit($auth, 'CREATE', 'EmployeeExit', $id);
        return $id;
    });
}

function action_review_exit_as_manager(array $auth, string $exitId, bool $approve, ?string $comments = null): void
{
    $exit = find_company_exit($auth, $exitId) ?? throw new UserError('Exit request not found.');
    if ($exit['status'] !== 'PENDING') {
        throw new UserError('This exit request has already moved past manager review.');
    }
    assert_can_act_on($auth, $exit['employeeId'], $exit['managerId']);
    db_tx(function () use ($auth, $exitId, $approve, $comments) {
        if (db_value('SELECT status FROM EmployeeExit WHERE id = ? FOR UPDATE', [$exitId]) !== 'PENDING') {
            throw new UserError('This exit request has already moved past manager review.');
        }
        db_update('EmployeeExit', ['status' => $approve ? 'MANAGER_APPROVED' : 'REJECTED', 'managerApproverId' => $auth['employeeId'], 'actedAt' => now()], 'id = ?', [$exitId]);
        audit($auth, $approve ? 'MANAGER_APPROVE' : 'REJECT', 'EmployeeExit', $exitId, ['comments' => $comments]);
    });
}

function action_approve_exit_as_hr(array $auth, string $exitId, string $lwdRaw): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can sign off an exit.');
    }
    $exit = find_company_exit($auth, $exitId) ?? throw new UserError('Exit request not found.');
    $lwd = v_date($lwdRaw, 'last working date');
    db_tx(function () use ($auth, $exit, $lwd) {
        if (db_value('SELECT status FROM EmployeeExit WHERE id = ? FOR UPDATE', [$exit['id']]) !== 'MANAGER_APPROVED') {
            throw new UserError('Manager approval is required before HR sign-off.');
        }
        db_update('EmployeeExit', ['status' => 'CLEARANCE', 'hrApproverId' => $auth['employeeId'], 'lastWorkingDate' => $lwd, 'actedAt' => now()], 'id = ?', [$exit['id']]);
        db_update('Employee', ['status' => 'NOTICE_PERIOD'], 'id = ?', [$exit['employeeId']]);
        foreach (EXIT_DEPARTMENTS as $dept) {
            db_insert('ExitClearance', ['exitId' => $exit['id'], 'department' => $dept]);
        }
        notify_many(db_column("SELECT id FROM `User` WHERE companyId = ? AND role IN ('IT_ADMIN', 'FINANCE')", [$exit['companyId']]),
            'Exit clearance required', "{$exit['firstName']} {$exit['lastName']} is exiting — please complete your department's clearance.", "/dashboard/exit/{$exit['id']}");
        audit($auth, 'HR_APPROVE', 'EmployeeExit', $exit['id'], ['lastWorkingDate' => db_dt($lwd)]);
    });
}

function action_clear_department(array $auth, string $clearanceId, string $remarks): void
{
    $c = is_valid_id($clearanceId) ? db_one(
        'SELECT c.*, x.employeeId, e.managerId FROM ExitClearance c JOIN EmployeeExit x ON x.id = c.exitId JOIN Employee e ON e.id = x.employeeId
          WHERE c.id = ? AND e.companyId = ?',
        [$clearanceId, $auth['companyId'] ?? '']
    ) : null;
    $c ?? throw new UserError('Clearance record not found.');
    if ($c['status'] === 'CLEARED') {
        return;
    }
    $isDepartmentManager = $c['department'] === 'MANAGER' && $auth['employeeId'] !== null && $auth['employeeId'] === $c['managerId'];
    if (!$isDepartmentManager && !has_role($auth, CLEARANCE_ROLES[$c['department']])) {
        throw new UserError('You are not authorized to clear this department.');
    }
    // FR-101: every asset must be returned before the ASSET clearance.
    if ($c['department'] === 'ASSET' && (int)db_value('SELECT COUNT(*) FROM AssetAssignment WHERE employeeId = ? AND returnedDate IS NULL', [$c['employeeId']]) > 0) {
        throw new UserError('This employee still has unreturned assets — return them before clearing.');
    }
    $remarks = v_max(trim($remarks), 'Remarks', 2000);
    db_tx(function () use ($auth, $c, $remarks) {
        db_update('ExitClearance', ['status' => 'CLEARED', 'clearedById' => $auth['employeeId'], 'clearedAt' => now(), 'remarks' => $remarks], 'id = ? AND status = ?', [$c['id'], 'PENDING']);
        audit($auth, 'CLEAR', 'ExitClearance', $c['id'], ['department' => $c['department']]);
    });
}

/**
 * Full & Final: leave encashment (unused balance this year × gross/30) +
 * approved expenses − unprocessed LOP since the last payroll run (× gross/30).
 */
function action_generate_full_final(array $auth, string $exitId): void
{
    if (!has_role($auth, ['COMPANY_ADMIN', 'HR', 'FINANCE'])) {
        throw new UserError('You are not authorized to generate settlements.');
    }
    $companyId = require_company($auth);
    $exit = find_company_exit($auth, $exitId) ?? throw new UserError('Exit request not found.');
    db_tx(function () use ($auth, $companyId, $exit) {
        $status = db_value('SELECT status FROM EmployeeExit WHERE id = ? FOR UPDATE', [$exit['id']]);
        $clearances = db_column('SELECT status FROM ExitClearance WHERE exitId = ?', [$exit['id']]);
        // Hardening: the original only checked "no uncleared clearance", which an exit with none yet passes.
        if ($status !== 'CLEARANCE' || !$clearances || in_array('PENDING', $clearances, true)) {
            throw new UserError('All department clearances must be complete first.');
        }
        if (db_value('SELECT 1 FROM FullFinalSettlement WHERE exitId = ?', [$exit['id']])) {
            throw new UserError('A settlement has already been generated for this exit.');
        }
        $s = db_one('SELECT basic, hra, conveyance, medicalAllowance, specialAllowance FROM SalaryStructure WHERE employeeId = ?', [$exit['employeeId']]);
        $gross = $s ? $s['basic'] + $s['hra'] + $s['conveyance'] + $s['medicalAllowance'] + $s['specialAllowance'] : 0.0;
        $perDay = $gross / 30;
        $unused = (float)db_value('SELECT COALESCE(SUM(GREATEST(0, allocated - used)), 0) FROM LeaveBalance WHERE employeeId = ? AND year = ?', [$exit['employeeId'], (int)now()->format('Y')]);
        $leaveEncashment = js_round($unused * $perDay);
        $last = db_one('SELECT month, year FROM PayrollRun WHERE companyId = ? ORDER BY year DESC, month DESC LIMIT 1', [$companyId]);
        $since = $last
            ? (new DateTimeImmutable(sprintf('%04d-%02d-01', $last['year'], $last['month']), app_tz()))->modify('last day of this month')->modify('+1 day')
            : new DateTimeImmutable('1970-01-02', app_tz());
        $until = $exit['lastWorkingDate'] ? dt($exit['lastWorkingDate']) : now();
        $lop = $since <= $until ? (unauthorized_absence_days($companyId, [$exit['employeeId']], $since, $until)[$exit['employeeId']] ?? 0) : 0;
        $lopDeduction = js_round($lop * $perDay);
        $pendingExpenses = (float)db_value("SELECT COALESCE(SUM(amount), 0) FROM ExpenseClaim WHERE employeeId = ? AND status = 'APPROVED'", [$exit['employeeId']]);
        $net = js_round($leaveEncashment + $pendingExpenses - $lopDeduction);
        $id = db_insert('FullFinalSettlement', ['exitId' => $exit['id'], 'leaveEncashment' => $leaveEncashment, 'lopDeduction' => $lopDeduction,
            'pendingExpenses' => $pendingExpenses, 'netPayable' => $net]);
        db_update('EmployeeExit', ['status' => 'SETTLED'], 'id = ?', [$exit['id']]);
        audit($auth, 'GENERATE', 'FullFinalSettlement', $id);
    });
}

function action_approve_full_final(array $auth, string $exitId): void
{
    if (!has_role($auth, ['COMPANY_ADMIN', 'HR', 'FINANCE'])) {
        throw new UserError('You are not authorized to approve settlements.');
    }
    $companyId = require_company($auth);
    $exit = find_company_exit($auth, $exitId) ?? throw new UserError('No settlement to approve.');
    db_tx(function () use ($auth, $companyId, $exit) {
        $st = db_one('SELECT id, status FROM FullFinalSettlement WHERE exitId = ? FOR UPDATE', [$exit['id']]) ?? throw new UserError('No settlement to approve.');
        if ($st['status'] !== 'DRAFT') {
            throw new UserError('This settlement has already been approved.');
        }
        db_update('FullFinalSettlement', ['status' => 'APPROVED', 'approvedAt' => now()], 'id = ?', [$st['id']]);
        db_update('EmployeeExit', ['status' => 'COMPLETED'], 'id = ?', [$exit['id']]);
        db_update('Employee', ['status' => 'EXITED', 'dateOfExit' => $exit['lastWorkingDate'] ? dt($exit['lastWorkingDate']) : now()], 'id = ?', [$exit['employeeId']]);
        if ($exit['employeeUserId']) {
            db_update('User', ['isActive' => false], 'id = ?', [$exit['employeeUserId']]);
        }
        generate_letter_for_employee($companyId, $exit['employeeId'], 'RELIEVING');
        generate_letter_for_employee($companyId, $exit['employeeId'], 'EXPERIENCE');
        audit($auth, 'APPROVE', 'FullFinalSettlement', $st['id']);
    });
}

function action_record_exit_interview(array $auth, string $exitId, string $notes): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can record exit interviews.');
    }
    $exit = find_company_exit($auth, $exitId) ?? throw new UserError('Exit request not found.');
    db_update('EmployeeExit', ['exitInterviewNotes' => v_max(normalize_line_endings($notes), 'Notes', 20000)], 'id = ?', [$exit['id']]);
}
