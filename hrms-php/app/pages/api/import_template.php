<?php
/** GET /api/employees/import-template — port of app/api/employees/import-template/route.ts */
declare(strict_types=1);

if (!is_company_admin($auth)) {
    http_response_code(401);
    exit('Unauthorized');
}
send_csv('employee-import-template.csv', [EMPLOYEE_CSV_HEADERS, EMPLOYEE_CSV_TEMPLATE_EXAMPLE]);
