<?php
/**
 * Phase 8 — Payroll + Statutory. Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase8_payroll.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$tz = new DateTimeZone('Asia/Kolkata');
$admin = Client::as('admin@meridianworks.com');
$hr = Client::as('hr@meridianworks.com');
$payroll = Client::as('payroll@meridianworks.com');
$finance = Client::as('finance@meridianworks.com');
$employee = Client::as('employee@meridianworks.com');
$manager = Client::as('manager@meridianworks.com');
$empId = emp_id('employee@meridianworks.com');

/** Independent re-implementation of the LOP + payslip maths (written from the spec, not the app code). */
function expected_payslips(string $companyId, int $year, int $month): array
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
    $days = (int)$first->format('t');
    $weekdays = [];
    for ($d = 0; $d < $days; $d++) {
        $day = $first->modify("+$d day");
        if (!in_array((int)$day->format('w'), [0, 6], true)) {
            $weekdays[] = $day->format('Y-m-d');
        }
    }
    $holidays = array_map(fn($r) => substr($r['date'], 0, 10), q('SELECT date FROM Holiday WHERE companyId = ?', [$companyId]));
    $out = [];
    foreach (q("SELECT e.*, s.basic, s.hra, s.conveyance, s.medicalAllowance, s.specialAllowance, s.pfEmployee, s.esi, s.professionalTax, s.tds
                  FROM Employee e JOIN SalaryStructure s ON s.employeeId = e.id
                 WHERE e.companyId = ? AND e.status IN ('ACTIVE','ON_LEAVE','NOTICE_PERIOD')", [$companyId]) as $e) {
        $present = array_map(fn($r) => substr($r['date'], 0, 10), q('SELECT date FROM Attendance WHERE employeeId = ? AND checkIn IS NOT NULL', [$e['id']]));
        $leaves = q("SELECT startDate, endDate FROM LeaveRequest WHERE employeeId = ? AND status = 'APPROVED'", [$e['id']]);
        $lop = 0;
        foreach ($weekdays as $d) {
            if (in_array($d, $holidays, true) || in_array($d, $present, true) || $d < substr($e['dateOfJoining'], 0, 10)) {
                continue;
            }
            foreach ($leaves as $l) {
                if ($d >= substr($l['startDate'], 0, 10) && $d <= substr($l['endDate'], 0, 10)) {
                    continue 2;
                }
            }
            $lop++;
        }
        $gross = $e['basic'] + $e['hra'] + $e['conveyance'] + $e['medicalAllowance'] + $e['specialAllowance'];
        $lopDed = floor($gross / count($weekdays) * $lop + 0.5);
        $ded = $e['pfEmployee'] + $e['esi'] + $e['professionalTax'] + $e['tds'] + $lopDed;
        $out[$e['id']] = ['gross' => (float)$gross, 'lop' => $lop, 'lopDed' => (float)$lopDed, 'ded' => (float)$ded,
            'net' => (float)floor($gross - $ded + 0.5), 'working' => count($weekdays), 'held' => (int)$e['salaryOnHold']];
    }
    return $out;
}

section('Run payroll — September 2026');
// An absent weekday covered by approved leave must not be LOP (original counted day 1 of every leave).
$absent = null;
for ($d = 1; $d <= 30; $d++) {
    $day = sprintf('2026-09-%02d', $d);
    $dow = (int)(new DateTimeImmutable($day, $tz))->format('w');
    if ($dow !== 0 && $dow !== 6 && !qv('SELECT id FROM Attendance WHERE employeeId = ? AND date = ? AND checkIn IS NOT NULL', [$empId, "$day 00:00:00.000"])) {
        $absent = $day;
        break;
    }
}
if ($absent === null) {
    $absent = '2026-09-15';
    q('DELETE FROM Attendance WHERE employeeId = ? AND date = ?', [$empId, "$absent 00:00:00.000"]);
}
$casual = (string)qv("SELECT id FROM LeaveType WHERE companyId = ? AND name = 'Casual Leave'", [$MW]);
q("INSERT INTO LeaveRequest (id, employeeId, leaveTypeId, startDate, endDate, days, reason, status) VALUES ('ctestapprovedleave000001', ?, ?, ?, ?, 1, 'test', 'APPROVED')",
    [$empId, $casual, "$absent 00:00:00.000", "$absent 00:00:00.000"]);
q('UPDATE Employee SET salaryOnHold = 1, salaryHoldReason = ? WHERE id = ?', ['Docs pending', $empId]);

$r = $employee->post('/dashboard/payroll', ['_action' => 'run', 'month' => '9', 'year' => '2026', 'payDate' => '2026-09-30']);
check('employee cannot process payroll', (int)qv('SELECT COUNT(*) FROM PayrollRun WHERE month = 9 AND year = 2026') === 0);
$finance->submit('/dashboard/payroll', ['_action' => 'run', '_form' => 'run', 'month' => '9', 'year' => '2026', 'payDate' => '2026-09-30']);
check('finance cannot process (PROCESS default = Admin/Payroll Admin)', (int)qv('SELECT COUNT(*) FROM PayrollRun WHERE month = 9 AND year = 2026') === 0);
$expected = expected_payslips($MW, 2026, 9);
$r = $payroll->post('/dashboard/payroll', ['_action' => 'run', '_form' => 'run', 'month' => '9', 'year' => '2026', 'payDate' => '2026-09-30']);
$run = q1('SELECT * FROM PayrollRun WHERE companyId = ? AND month = 9 AND year = 2026', [$MW]);
check('run created as PROCESSED → redirect to run page', $run !== null && $run['status'] === 'PROCESSED' && str_ends_with($r->location, '/dashboard/payroll/' . $run['id']));
$slips = [];
foreach (q('SELECT * FROM Payslip WHERE payrollRunId = ?', [$run['id']]) as $p) {
    $slips[$p['employeeId']] = $p;
}
check('one payslip per eligible employee with a salary structure', count($slips) === count($expected), count($slips) . ' vs ' . count($expected));
$mismatch = [];
foreach ($expected as $id => $x) {
    $p = $slips[$id] ?? null;
    if (!$p || (float)$p['grossPay'] !== $x['gross'] || (float)$p['lopDays'] !== (float)$x['lop'] || (float)$p['totalDeductions'] !== $x['ded']
        || (float)$p['netPay'] !== $x['net'] || (int)$p['workingDays'] !== $x['working'] || (float)$p['paidDays'] !== (float)($x['working'] - $x['lop'])
        || (int)$p['isHeld'] !== $x['held']) {
        $mismatch[] = $id . ':' . json_encode([$p ? [$p['grossPay'], $p['lopDays'], $p['totalDeductions'], $p['netPay']] : null, $x]);
    }
}
check('every payslip matches an independent calculation (gross, LOP, deductions, net, paid days, hold)', $mismatch === [], implode(' | ', array_slice($mismatch, 0, 2)));
$b = json_decode((string)$slips[$empId]['breakdown'], true);
check('breakdown JSON has components + LOP', isset($b['basic'], $b['pfEmployee'], $b['lopDays'], $b['lopDeduction']) && (float)$b['lopDeduction'] === $expected[$empId]['lopDed']);
check("approved leave day ($absent) not charged as LOP", $expected[$empId]['lop'] === (int)$slips[$empId]['lopDays']);
check('standing salary hold carried into the payslip', (int)$slips[$empId]['isHeld'] === 1 && $slips[$empId]['holdReason'] === 'Docs pending');
check('PAYROLL_RUN audited', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'PAYROLL_RUN'", [$run['id']]) === 1);
$dup = $payroll->submit('/dashboard/payroll', ['_action' => 'run', '_form' => 'run', 'month' => '9', 'year' => '2026', 'payDate' => '2026-09-30']);
check('second run for the same month refused with a friendly message', $dup->has('Payroll for September 2026 has already been run.') && (int)qv('SELECT COUNT(*) FROM PayrollRun WHERE month = 9 AND year = 2026') === 1);
$bad = $payroll->submit('/dashboard/payroll', ['_action' => 'run', '_form' => 'run', 'month' => '13', 'year' => '2026', 'payDate' => '2026-09-30']);
check('invalid month refused', $bad->has('Month is out of range.'));

section('B2 — atomic payroll run');
$victim = (string)qv("SELECT e.id FROM Employee e JOIN SalaryStructure s ON s.employeeId = e.id WHERE e.companyId = ? AND e.id <> ? ORDER BY e.firstName DESC LIMIT 1", [$MW, $empId]);
pdo()->exec("CREATE TRIGGER test_fail_payslip BEFORE INSERT ON Payslip FOR EACH ROW BEGIN IF NEW.employeeId = '$victim' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated failure'; END IF; END");
$r = $payroll->post('/dashboard/payroll', ['_action' => 'run', '_form' => 'run', 'month' => '10', 'year' => '2026', 'payDate' => '2026-10-31']);
pdo()->exec('DROP TRIGGER test_fail_payslip');
check('failure mid-run → safe 500 page (no SQL shown)', $r->status === 500 && !$r->has('simulated failure') && !$r->has('SQLSTATE'));
check('B2: no PayrollRun kept for the failed month', (int)qv('SELECT COUNT(*) FROM PayrollRun WHERE month = 10 AND year = 2026') === 0);
check('B2: no orphan payslips', (int)qv('SELECT COUNT(*) FROM Payslip p LEFT JOIN PayrollRun r ON r.id = p.payrollRunId WHERE r.id IS NULL') === 0);
check('B2: no PAYROLL_RUN audit for the failed run', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE action = 'PAYROLL_RUN' AND meta LIKE '%\"month\":10%'") === 0);

section('Holds, approve, lock, publish');
$other = array_key_first(array_diff_key($slips, [$empId => 1]));
$hr->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'hold', 'payslipId' => $slips[$other]['id'], 'held' => '1', 'reason' => 'Bank details missing']);
check('HR holds one payslip before lock', (int)qv('SELECT isHeld FROM Payslip WHERE id = ?', [$slips[$other]['id']]) === 1);
$page = $hr->get('/dashboard/payroll/' . $run['id']);
$heldTotal = (float)qv('SELECT SUM(netPay) FROM Payslip WHERE payrollRunId = ? AND isHeld = 1', [$run['id']]);
check('run page shows On-hold total', $page->has(htmlspecialchars('₹' . number_format($heldTotal, 2), ENT_QUOTES)) || $page->has('On hold'));
check('employee cannot view run page', $employee->get('/dashboard/payroll/' . $run['id'])->status === 303);
check('employee cannot see unpublished payslip', $employee->get('/payslips/' . $slips[$empId]['id'])->status === 404);
check('not yet in My payslips', !$employee->get('/dashboard/payroll')->has('September 2026'));
$payroll->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'lock']);
check('cannot lock before approval', qv('SELECT status FROM PayrollRun WHERE id = ?', [$run['id']]) === 'PROCESSED');
$finance->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'approve']);
check('finance approves', qv('SELECT status FROM PayrollRun WHERE id = ?', [$run['id']]) === 'APPROVED');
$finance->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'lock']);
check('finance cannot lock', qv('SELECT status FROM PayrollRun WHERE id = ?', [$run['id']]) === 'APPROVED');
$payroll->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'lock']);
check('payroll admin locks & publishes', qv('SELECT status FROM PayrollRun WHERE id = ?', [$run['id']]) === 'LOCKED' && qv('SELECT lockedAt FROM PayrollRun WHERE id = ?', [$run['id']]) !== null);
$notes = (int)qv("SELECT COUNT(*) FROM Notification WHERE title LIKE 'Payslip published%' AND message LIKE '%9/2026%'");
check('employees with logins notified', $notes === (int)qv('SELECT COUNT(*) FROM Payslip p JOIN Employee e ON e.id = p.employeeId WHERE p.payrollRunId = ? AND e.userId IS NOT NULL', [$run['id']]));
check('held employee told payment is on hold', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Payslip published — payment on hold'", [$empId]) === 1);
$hr->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'hold', 'payslipId' => $slips[$other]['id'], 'held' => '0', 'reason' => '']);
check('locked run: holds can no longer change', (int)qv('SELECT isHeld FROM Payslip WHERE id = ?', [$slips[$other]['id']]) === 1);
$mine = $employee->get('/dashboard/payroll');
check('published payslip appears in My payslips with On hold badge', $mine->has('September 2026') && $mine->has('On hold'));
$ps = $employee->get('/payslips/' . $slips[$empId]['id']);
check('employee opens own payslip', $ps->status === 200 && $ps->has('Payslip — September 2026') && $ps->has('Payment on hold.'));
check('payslip shows ₹ net pay', $ps->has(htmlspecialchars('₹', ENT_QUOTES)) || $ps->has('₹'));
check("employee cannot open a colleague's payslip", $employee->get('/payslips/' . $slips[$other]['id'])->status === 404);
check('finance (staff) can open any payslip in company', $finance->get('/payslips/' . $slips[$other]['id'])->status === 200);
$nw = Client::as('admin@northwindretail.com');
check('other tenant cannot open the payslip', $nw->get('/payslips/' . $slips[$empId]['id'])->status === 404);
check('other tenant cannot open the run', $nw->get('/dashboard/payroll/' . $run['id'])->status === 404);

section('Exports');
$csv = $hr->get('/api/reports/payroll?runId=' . $run['id']);
check('payroll CSV for HR', str_starts_with($csv->body, 'Employee Code,Name,Gross Pay,Deductions,Net Pay,LOP Days') && substr_count($csv->body, "\n") === count($slips));
check('latest run used when no runId', str_contains($hr->get('/api/reports/payroll')->header('content-disposition') ?? '', 'payroll-9-2026.csv'));
check('HR cannot download the bank file', $hr->get('/api/reports/payroll?runId=' . $run['id'] . '&format=bank-file')->status === 401);
$bank = $payroll->get('/api/reports/payroll?runId=' . $run['id'] . '&format=bank-file');
check('payroll admin gets bank file for a locked run', $bank->status === 200 && str_starts_with($bank->body, 'Employee Code,Name,Bank Account Number,IFSC,Net Pay'));
check('employee cannot export payroll', $employee->get('/api/reports/payroll')->status === 401);
$jul = (string)qv('SELECT id FROM PayrollRun WHERE companyId = ? AND month = 7', [$MW]);
q("UPDATE PayrollRun SET status = 'PROCESSED' WHERE id = ?", [$jul]);
check('bank file refused for an unlocked run', $payroll->get("/api/reports/payroll?runId=$jul&format=bank-file")->status === 400);
q("UPDATE PayrollRun SET status = 'LOCKED' WHERE id = ?", [$jul]);

section('Reopen');
$payroll->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'reopen', 'reason' => 'x']);
check('only the Company Admin can reopen', qv('SELECT status FROM PayrollRun WHERE id = ?', [$run['id']]) === 'LOCKED');
$r = $admin->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'reopen', 'reason' => '   ']);
check('reason required', $r->has('A reason is required') && qv('SELECT status FROM PayrollRun WHERE id = ?', [$run['id']]) === 'LOCKED');
$admin->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'reopen', 'reason' => 'LOP correction <script>']);
$re = q1('SELECT * FROM PayrollRun WHERE id = ?', [$run['id']]);
check('reopened to PROCESSED, dates cleared, reason kept', $re['status'] === 'PROCESSED' && $re['approvedAt'] === null && $re['lockedAt'] === null && $re['reopenReason'] === 'LOP correction <script>');
check('reopen reason shown escaped', $admin->get('/dashboard/payroll/' . $run['id'])->has('LOP correction &lt;script&gt;'));
check('PAYROLL_REOPENED audited with reason', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'PAYROLL_REOPENED'", [$run['id']]) === 1);
check('reopened run hidden from My payslips again', !$employee->get('/dashboard/payroll')->has('September 2026'));

section('Salary structures + statutory');
check('HR (no EDIT_SALARY) redirected away', $hr->get('/dashboard/payroll/salary')->status === 303);
$hr->post('/dashboard/payroll/salary', ['_action' => 'save', 'employeeId' => $empId, 'payPeriod' => 'MONTHLY', 'basic' => '1'], '/dashboard/payroll');
check('HR POST refused', (float)qv('SELECT basic FROM SalaryStructure WHERE employeeId = ?', [$empId]) !== 1.0);
$admin->submit('/dashboard/settings/statutory', ['_action' => 'save', '_form' => 'statutory', 'pfEmployeeRate' => '0.12', 'pfEmployerRate' => '0.12', 'pfWageCeiling' => '15000',
    'esiEmployeeRate' => '0.0075', 'esiEmployerRate' => '0.0325', 'esiWageCeiling' => '21000',
    'ptSlabs' => '[{"upto":15000,"amount":0},{"upto":25000,"amount":150},{"upto":null,"amount":200}]',
    'tdsSlabs' => '[{"upto":600000,"monthlyDeduction":0},{"upto":1200000,"monthlyDeduction":2500},{"upto":null,"monthlyDeduction":8000}]']);
$payroll->submit('/dashboard/payroll/salary', ['_action' => 'save', 'employeeId' => $empId, 'payPeriod' => 'MONTHLY', 'basic' => '20000', 'hra' => '8000', 'conveyance' => '', 'medicalAllowance' => '1250', 'specialAllowance' => '0']);
$s = q1('SELECT * FROM SalaryStructure WHERE employeeId = ?', [$empId]);
check('monthly figures stored', (float)$s['basic'] === 20000.0 && (float)$s['hra'] === 8000.0 && (float)$s['conveyance'] === 0.0);
check('PF = 12% of min(basic, 15000) = 1800', (float)$s['pfEmployee'] === 1800.0);
check('ESI applies at/below ceiling = 150', (float)$s['esi'] === 150.0);
check('PT slab 15k–25k = 150', (float)$s['professionalTax'] === 150.0);
check('TDS slab on annualised 2.4L = 0', (float)$s['tds'] === 0.0);
$payroll->submit('/dashboard/payroll/salary', ['_action' => 'save', 'employeeId' => $empId, 'payPeriod' => 'YEARLY', 'basic' => '1500000', 'hra' => '600000', 'conveyance' => '0', 'medicalAllowance' => '0', 'specialAllowance' => '0']);
$s = q1('SELECT * FROM SalaryStructure WHERE employeeId = ?', [$empId]);
check('yearly figures divided by 12', (float)$s['basic'] === 125000.0 && (float)$s['hra'] === 50000.0);
check('high earner: PF capped (1800), no ESI, PT 200, TDS 8000', (float)$s['pfEmployee'] === 1800.0 && (float)$s['esi'] === 0.0 && (float)$s['professionalTax'] === 200.0 && (float)$s['tds'] === 8000.0);
$bad = $payroll->submit('/dashboard/payroll/salary', ['_action' => 'save', '_form' => 'salary-' . $empId, 'employeeId' => $empId, 'payPeriod' => 'MONTHLY', 'basic' => '-5']);
check('negative amount refused', (float)qv('SELECT basic FROM SalaryStructure WHERE employeeId = ?', [$empId]) === 125000.0);
$admin->submit('/dashboard/settings/statutory', ['_action' => 'save', '_form' => 'statutory', 'pfEmployeeRate' => '0.10', 'pfEmployerRate' => '0.12', 'pfWageCeiling' => '15000',
    'esiEmployeeRate' => '0.0075', 'esiEmployerRate' => '0.0325', 'esiWageCeiling' => '21000', 'ptSlabs' => '[]', 'tdsSlabs' => '[]']);
$payroll->submit('/dashboard/payroll/salary', ['_action' => 'save', 'employeeId' => $empId, 'payPeriod' => 'MONTHLY', 'basic' => '20000', 'hra' => '0', 'conveyance' => '0', 'medicalAllowance' => '0', 'specialAllowance' => '0']);
check('statutory config is the source of truth (PF rate 10% → 1500, empty slabs → 0)',
    (float)qv('SELECT pfEmployee FROM SalaryStructure WHERE employeeId = ?', [$empId]) === 1500.0 && (float)qv('SELECT professionalTax FROM SalaryStructure WHERE employeeId = ?', [$empId]) === 0.0);
$bad = $admin->submit('/dashboard/settings/statutory', ['_action' => 'save', '_form' => 'statutory', 'pfEmployeeRate' => '0.12', 'pfEmployerRate' => '0.12', 'pfWageCeiling' => '15000',
    'esiEmployeeRate' => '0.0075', 'esiEmployerRate' => '0.0325', 'esiWageCeiling' => '21000', 'ptSlabs' => '{oops', 'tdsSlabs' => '[]']);
check('invalid slab JSON refused', $bad->has('Slab tables must be valid JSON arrays'));
check('statutory change audited', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityType = 'StatutoryConfig' AND companyId = ?", [$MW]) === 2);
check('payroll admin cannot open statutory page (module guard, as original)', $payroll->get('/dashboard/settings/statutory')->status === 303);
check('salary page renders for payroll admin', $payroll->get('/dashboard/payroll/salary')->has('Save &amp; recalculate'));

finish();
