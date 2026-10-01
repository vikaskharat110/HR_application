<?php
/**
 * Payroll — port of lib/actions/payroll.ts, with B2: a payroll run is one
 * transaction (run row + every payslip + audit). If anything fails, nothing
 * is kept — never a PROCESSED run with missing payslips.
 */
declare(strict_types=1);

const SALARY_HOLD_ROLES = ['COMPANY_ADMIN', 'HR', 'PAYROLL_ADMIN'];

function weekdays_in_month(int $year, int $month): int
{
    $days = (int)(new DateTimeImmutable("$year-$month-01", app_tz()))->format('t');
    $count = 0;
    for ($d = 1; $d <= $days; $d++) {
        $dow = (int)(new DateTimeImmutable("$year-$month-$d", app_tz()))->format('w');
        if ($dow !== 0 && $dow !== 6) {
            $count++;
        }
    }
    return $count;
}

function assert_payroll_action(array $auth, string $action, string $message): string
{
    $companyId = require_company($auth);
    if (!has_payroll_action($auth, $action)) {
        throw new UserError($message);
    }
    return $companyId;
}

/** @return string redirect to the new run */
function action_run_payroll(array $auth, string $monthRaw, string $yearRaw, string $payDateRaw): string
{
    $companyId = assert_payroll_action($auth, 'PROCESS', 'You are not authorized to process payroll.');
    $month = v_int($monthRaw, 'Month', 1, 12);
    $year = v_int($yearRaw, 'Year', 2000, 2100);
    if (trim($payDateRaw) === '') {
        throw new UserError('Set the salary/payment date for this run.');
    }
    $payDate = v_date($payDateRaw, 'salary date');

    try {
        $runId = db_tx(function () use ($auth, $companyId, $month, $year, $payDate) {
            // The unique (companyId, month, year) key is the single source of
            // truth for "already run" — no check-then-insert race.
            $runId = db_insert('PayrollRun', [
                'companyId' => $companyId, 'month' => $month, 'year' => $year, 'payDate' => $payDate,
                'status' => 'PROCESSED', 'processedAt' => now(),
            ]);
            $employees = db_all(
                "SELECT e.id, e.salaryOnHold, e.salaryHoldReason, s.basic, s.hra, s.conveyance, s.medicalAllowance, s.specialAllowance,
                        s.pfEmployee, s.esi, s.professionalTax, s.tds, s.id AS salaryId
                   FROM Employee e LEFT JOIN SalaryStructure s ON s.employeeId = e.id
                  WHERE e.companyId = ? AND e.status IN ('ACTIVE', 'ON_LEAVE', 'NOTICE_PERIOD')",
                [$companyId]
            );
            $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), app_tz());
            $periodEnd = $periodStart->modify('last day of this month')->setTime(23, 59, 59);
            $workingDays = weekdays_in_month($year, $month);
            $lop = unauthorized_absence_days($companyId, array_column($employees, 'id'), $periodStart, $periodEnd);

            foreach ($employees as $e) {
                if ($e['salaryId'] === null) {
                    continue;
                }
                $gross = $e['basic'] + $e['hra'] + $e['conveyance'] + $e['medicalAllowance'] + $e['specialAllowance'];
                $lopDays = $lop[$e['id']] ?? 0;
                $perDay = $gross / $workingDays;
                $lopDeduction = js_round($perDay * $lopDays);
                $totalDeductions = $e['pfEmployee'] + $e['esi'] + $e['professionalTax'] + $e['tds'] + $lopDeduction;
                $netPay = js_round($gross - $totalDeductions);
                db_insert('Payslip', [
                    'payrollRunId' => $runId, 'employeeId' => $e['id'],
                    'grossPay' => (float)$gross, 'totalDeductions' => (float)$totalDeductions, 'netPay' => (float)$netPay,
                    'workingDays' => $workingDays, 'paidDays' => (float)($workingDays - $lopDays), 'lopDays' => (float)$lopDays,
                    'isHeld' => (bool)(int)$e['salaryOnHold'], 'holdReason' => (int)$e['salaryOnHold'] ? $e['salaryHoldReason'] : null,
                    'breakdown' => [
                        'basic' => (float)$e['basic'], 'hra' => (float)$e['hra'], 'conveyance' => (float)$e['conveyance'],
                        'medicalAllowance' => (float)$e['medicalAllowance'], 'specialAllowance' => (float)$e['specialAllowance'],
                        'pfEmployee' => (float)$e['pfEmployee'], 'esi' => (float)$e['esi'], 'professionalTax' => (float)$e['professionalTax'],
                        'tds' => (float)$e['tds'], 'lopDays' => $lopDays, 'lopDeduction' => (float)$lopDeduction,
                    ],
                ]);
            }
            audit($auth, 'PAYROLL_RUN', 'PayrollRun', $runId, ['month' => $month, 'year' => $year, 'employees' => count($employees)]);
            return $runId;
        });
    } catch (PDOException $e) {
        if (db_is_duplicate_key($e)) {
            throw new UserError('Payroll for ' . month_name($month) . " $year has already been run.");
        }
        throw $e;
    }
    return "/dashboard/payroll/$runId";
}

/** Locks the run row and checks its status inside a transaction. */
function lock_run(string $companyId, string $runId, string $expected): array
{
    $run = db_one('SELECT * FROM PayrollRun WHERE id = ? AND companyId = ? FOR UPDATE', [$runId, $companyId]);
    if ($run === null) {
        throw new UserError('Payroll run not found.');
    }
    if ($run['status'] !== $expected) {
        throw new UserError("Payroll run must be $expected to perform this action.");
    }
    return $run;
}

function action_approve_payroll_run(array $auth, string $runId): void
{
    $companyId = assert_payroll_action($auth, 'APPROVE', 'You are not authorized to approve payroll.');
    db_tx(function () use ($auth, $companyId, $runId) {
        lock_run($companyId, $runId, 'PROCESSED');
        db_update('PayrollRun', ['status' => 'APPROVED', 'approvedAt' => now()], 'id = ?', [$runId]);
        audit($auth, 'PAYROLL_APPROVED', 'PayrollRun', $runId);
    });
}

function action_lock_payroll_run(array $auth, string $runId): void
{
    $companyId = assert_payroll_action($auth, 'LOCK', 'You are not authorized to lock payroll.');
    db_tx(function () use ($auth, $companyId, $runId) {
        $run = lock_run($companyId, $runId, 'APPROVED');
        db_update('PayrollRun', ['status' => 'LOCKED', 'lockedAt' => now()], 'id = ?', [$runId]);
        $slips = db_all('SELECT p.isHeld, e.userId FROM Payslip p JOIN Employee e ON e.id = p.employeeId WHERE p.payrollRunId = ?', [$runId]);
        foreach ($slips as $p) {
            if (!$p['userId']) {
                continue;
            }
            notify($p['userId'],
                (int)$p['isHeld'] ? 'Payslip published — payment on hold' : 'Payslip published',
                (int)$p['isHeld']
                    ? "Your payslip for {$run['month']}/{$run['year']} is ready to view, but payment is currently on hold. Contact HR for details."
                    : "Your payslip for {$run['month']}/{$run['year']} is ready to view.",
                '/dashboard/payroll');
        }
        audit($auth, 'PAYROLL_LOCKED', 'PayrollRun', $runId);
    });
}

/** Company Admin only; reason is mandatory and audited. Payslips are kept as they were. */
function action_reopen_payroll_run(array $auth, string $runId, string $reason): void
{
    if ($auth['role'] !== 'COMPANY_ADMIN') {
        throw new UserError('Only the Company Admin can reopen a locked payroll run.');
    }
    $companyId = require_company($auth);
    $reason = trim($reason);
    if ($reason === '') {
        throw new UserError('A reason is required to reopen a locked payroll run.');
    }
    v_max($reason, 'Reason', 2000);
    db_tx(function () use ($auth, $companyId, $runId, $reason) {
        $run = db_one('SELECT status FROM PayrollRun WHERE id = ? AND companyId = ? FOR UPDATE', [$runId, $companyId]);
        if ($run === null) {
            throw new UserError('Payroll run not found.');
        }
        if ($run['status'] !== 'LOCKED') {
            throw new UserError('Only a locked payroll run can be reopened.');
        }
        db_update('PayrollRun', ['status' => 'PROCESSED', 'approvedAt' => null, 'lockedAt' => null, 'reopenReason' => $reason], 'id = ?', [$runId]);
        audit($auth, 'PAYROLL_REOPENED', 'PayrollRun', $runId, ['reason' => $reason]);
    });
}

/**
 * Salary structures are stored monthly; YEARLY input is divided by 12
 * (rounded). PF/ESI/PT/TDS are always computed from the company's statutory
 * configuration, never typed in.
 */
function action_upsert_salary_structure(array $auth, array $in): void
{
    $companyId = assert_payroll_action($auth, 'EDIT_SALARY', 'You are not authorized to edit salary structures.');
    $employeeId = v_id((string)($in['employeeId'] ?? ''), 'employee');
    if (!db_value('SELECT 1 FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId])) {
        throw new UserError('Employee not found.');
    }
    $divisor = ($in['payPeriod'] ?? 'MONTHLY') === 'YEARLY' ? 12 : 1;
    $amount = static function (string $k, string $label) use ($in, $divisor): float {
        $raw = trim((string)($in[$k] ?? ''));
        return js_round(($raw === '' ? 0.0 : v_number($raw, $label, 0, 1e10)) / $divisor);
    };
    $data = [
        'basic' => $amount('basic', 'Basic'),
        'hra' => $amount('hra', 'HRA'),
        'conveyance' => $amount('conveyance', 'Conveyance'),
        'medicalAllowance' => $amount('medicalAllowance', 'Medical allowance'),
        'specialAllowance' => $amount('specialAllowance', 'Special allowance'),
    ];
    $data += compute_statutory_deductions($companyId, $data['basic']);
    db_tx(function () use ($auth, $employeeId, $data) {
        $existing = db_value('SELECT id FROM SalaryStructure WHERE employeeId = ? FOR UPDATE', [$employeeId]);
        if ($existing) {
            db_update('SalaryStructure', $data, 'id = ?', [$existing]);
        } else {
            db_insert('SalaryStructure', ['employeeId' => $employeeId] + $data);
        }
        audit($auth, 'UPSERT', 'SalaryStructure', $employeeId);
    });
}

/**
 * A standing per-employee hold, applied when a future payroll run generates
 * the payslip (never retroactively). The employee is always notified.
 */
function action_set_salary_hold(array $auth, string $employeeId, bool $hold, string $reason): void
{
    if (!has_role($auth, SALARY_HOLD_ROLES)) {
        throw new UserError('You are not authorized to hold salaries.');
    }
    $companyId = require_company($auth);
    $reason = trim($reason);
    if ($hold && $reason === '') {
        throw new UserError('Give a reason for the hold.');
    }
    $employee = db_one('SELECT id, userId FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]);
    if ($employee === null) {
        throw new UserError('Employee not found.');
    }
    db_tx(function () use ($auth, $employee, $hold, $reason) {
        db_update('Employee', ['salaryOnHold' => $hold, 'salaryHoldReason' => $hold ? $reason : null], 'id = ?', [$employee['id']]);
        if ($employee['userId']) {
            notify($employee['userId'],
                $hold ? 'Your salary has been put on hold' : 'Your salary hold has been lifted',
                $hold ? "Future payroll runs will withhold your payment until this is lifted. Reason: $reason" : 'Your salary will be included normally in future payroll runs.',
                '/dashboard/payroll');
        }
        audit($auth, $hold ? 'SALARY_HOLD' : 'SALARY_HOLD_LIFTED', 'Employee', $employee['id'], $hold ? ['reason' => $reason] : []);
    });
}

/** Toggles the hold on one generated payslip; the run must not be locked. */
function action_set_payslip_hold(array $auth, string $payslipId, bool $held, string $reason): void
{
    if (!has_role($auth, SALARY_HOLD_ROLES)) {
        throw new UserError('You are not authorized to hold payslips.');
    }
    $companyId = require_company($auth);
    $reason = trim($reason);
    if ($held && $reason === '') {
        throw new UserError('Give a reason for the hold.');
    }
    db_tx(function () use ($auth, $companyId, $payslipId, $held, $reason) {
        $p = db_one(
            'SELECT p.id, r.status FROM Payslip p JOIN PayrollRun r ON r.id = p.payrollRunId JOIN Employee e ON e.id = p.employeeId
              WHERE p.id = ? AND e.companyId = ? FOR UPDATE',
            [$payslipId, $companyId]
        );
        if ($p === null) {
            throw new UserError('Payslip not found.');
        }
        if ($p['status'] === 'LOCKED') {
            throw new UserError('This run is locked — reopen it first before changing a hold.');
        }
        db_update('Payslip', ['isHeld' => $held, 'holdReason' => $held ? $reason : null], 'id = ?', [$payslipId]);
        audit($auth, $held ? 'PAYSLIP_HOLD' : 'PAYSLIP_HOLD_LIFTED', 'Payslip', $payslipId, $held ? ['reason' => $reason] : []);
    });
}
