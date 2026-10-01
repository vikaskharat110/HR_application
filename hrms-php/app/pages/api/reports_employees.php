<?php
/** GET /api/reports/employees — port of app/api/reports/employees/route.ts (company-scoped CSV). */
declare(strict_types=1);

if (!has_role($auth, ['COMPANY_ADMIN', 'HR', 'MANAGER', 'AUDITOR', 'MANAGEMENT', 'PAYROLL_ADMIN', 'RECRUITER'])) {
    http_response_code(401);
    exit('Unauthorized');
}
$rows = db_all(
    'SELECT e.*, d.name AS department, b.name AS branch, m.employeeCode AS managerCode
       FROM Employee e
       LEFT JOIN Department d ON d.id = e.departmentId
       LEFT JOIN Branch b ON b.id = e.branchId
       LEFT JOIN Employee m ON m.id = e.managerId
      WHERE e.companyId = ? ORDER BY e.firstName ASC',
    [$auth['companyId'] ?? '']
);
send_csv('employees.csv', [EMPLOYEE_CSV_HEADERS, ...array_map('employee_to_row', $rows)]);
