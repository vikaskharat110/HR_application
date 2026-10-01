<?php
/**
 * GET /api/reports/payroll[?runId=…][&format=bank-file] — port of
 * app/api/reports/payroll/route.ts. Latest run when no runId; the bank file
 * only for a LOCKED run and only Payroll Admin / Company Admin.
 */
declare(strict_types=1);

if (!has_role($auth, ['COMPANY_ADMIN', 'HR', 'PAYROLL_ADMIN', 'FINANCE', 'AUDITOR', 'MANAGEMENT'])) {
    http_response_code(401);
    exit('Unauthorized');
}
$runId = query('runId');
$run = is_valid_id($runId)
    ? db_one('SELECT * FROM PayrollRun WHERE id = ? AND companyId = ?', [$runId, $auth['companyId']])
    : ($runId === '' ? db_one('SELECT * FROM PayrollRun WHERE companyId = ? ORDER BY year DESC, month DESC LIMIT 1', [$auth['companyId']]) : null);
if ($run === null) {
    http_response_code(404);
    exit('No payroll runs yet');
}
$slips = db_all(
    'SELECT p.*, e.employeeCode, e.firstName, e.lastName, e.bankAccountNumber, e.bankIfsc FROM Payslip p JOIN Employee e ON e.id = p.employeeId
      WHERE p.payrollRunId = ? ORDER BY e.firstName ASC',
    [$run['id']]
);
$money = static fn($n) => number_format((float)$n, 2, '.', '');

if (query('format') === 'bank-file') {
    if (!has_role($auth, ['PAYROLL_ADMIN', 'COMPANY_ADMIN'])) {
        http_response_code(401);
        exit('Unauthorized');
    }
    if ($run['status'] !== 'LOCKED') {
        http_response_code(400);
        exit('Bank file can only be generated for a locked payroll run.');
    }
    $rows = [['Employee Code', 'Name', 'Bank Account Number', 'IFSC', 'Net Pay']];
    foreach ($slips as $p) {
        $rows[] = [$p['employeeCode'], $p['firstName'] . ' ' . $p['lastName'], $p['bankAccountNumber'] ?? '', $p['bankIfsc'] ?? '', $money($p['netPay'])];
    }
    send_csv("bank-file-{$run['month']}-{$run['year']}.csv", $rows);
}

$rows = [['Employee Code', 'Name', 'Gross Pay', 'Deductions', 'Net Pay', 'LOP Days']];
foreach ($slips as $p) {
    $rows[] = [$p['employeeCode'], $p['firstName'] . ' ' . $p['lastName'], $money($p['grossPay']), $money($p['totalDeductions']), $money($p['netPay']), js_num($p['lopDays'])];
}
send_csv("payroll-{$run['month']}-{$run['year']}.csv", $rows);
