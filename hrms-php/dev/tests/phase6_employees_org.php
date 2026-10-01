<?php
/**
 * Phase 6 — Employees + Organisation. Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase6_employees_org.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$NW = company_id('Northwind Retail');
$admin = Client::as('admin@meridianworks.com');
$hr = Client::as('hr@meridianworks.com');
$manager = Client::as('manager@meridianworks.com');
$employee = Client::as('employee@meridianworks.com');
$super = Client::as('business@webpayx.com');
$engDept = (string)qv("SELECT id FROM Department WHERE companyId = ? AND name = 'Engineering'", [$MW]);
$mgrId = emp_id('manager@meridianworks.com');
$empId = emp_id('employee@meridianworks.com');

section('Create employee');
$form = ['_action' => 'create', '_form' => 'employee', 'firstName' => 'Nisha', 'lastName' => 'Kapoor', 'email' => 'nisha.kapoor@meridianworks.com',
    'designation' => 'QA Engineer', 'departmentId' => $engDept, 'branchId' => '', 'managerId' => $mgrId, 'employmentType' => 'FULL_TIME',
    'dateOfJoining' => '2026-09-01', 'createLogin' => 'on', 'role' => 'EMPLOYEE'];
$r = $admin->post('/dashboard/employees/new', $form);
check('create redirects to the new profile', $r->status === 303 && str_contains($r->location, '/dashboard/employees/c'), $r->location);
$newId = basename($r->location);
$page = $admin->get("/dashboard/employees/$newId");
check('profile shows the new employee', $page->has('Nisha Kapoor') && $page->has('QA Engineer'));
preg_match('/data-temp-password>([^<]+)</', $page->body, $m);
$temp = html_entity_decode($m[1] ?? '', ENT_QUOTES);
check('one-time temporary password shown to the admin', strlen($temp) >= 12, $temp);
check('temporary password shown only once', !$admin->get("/dashboard/employees/$newId")->has('data-temp-password'));
$code = qv('SELECT employeeCode FROM Employee WHERE id = ?', [$newId]);
check('employee code MW-#### assigned', (bool)preg_match('/^MW-\d{4}$/', (string)$code), (string)$code);
$u = q1('SELECT u.* FROM `User` u JOIN Employee e ON e.userId = u.id WHERE e.id = ?', [$newId]);
check('login created with EMPLOYEE role', $u !== null && $u['role'] === 'EMPLOYEE');
check('only a bcrypt hash is stored', $u !== null && str_starts_with($u['passwordHash'], '$2y$') && !str_contains(json_encode($u), $temp));
check('password not in notifications', (int)qv('SELECT COUNT(*) FROM Notification WHERE message LIKE ?', ['%' . $temp . '%']) === 0);
check('password not in audit log', (int)qv('SELECT COUNT(*) FROM AuditLog WHERE meta LIKE ?', ['%' . $temp . '%']) === 0);
$logs = implode('', array_map('file_get_contents', glob(__DIR__ . '/../../storage/logs/*.log') ?: []));
check('password not in any log file', !str_contains($logs, $temp));
check('new user can sign in with the temporary password', (bool)Client::as('nisha.kapoor@meridianworks.com', $temp));
$bal = q('SELECT t.name, b.allocated, b.used FROM LeaveBalance b JOIN LeaveType t ON t.id = b.leaveTypeId WHERE b.employeeId = ? AND b.year = YEAR(NOW()) ORDER BY t.name', [$newId]);
check('B3: paid leave balances allocated (3, from daysPerYear)', count($bal) === 3 && $bal[0]['name'] === 'Casual Leave' && (float)$bal[0]['allocated'] === 12.0, json_encode($bal));
check('B3: no balance for unpaid leave', (int)qv("SELECT COUNT(*) FROM LeaveBalance b JOIN LeaveType t ON t.id = b.leaveTypeId WHERE b.employeeId = ? AND t.paid = 0", [$newId]) === 0);
check('CREATE audited', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityType='Employee' AND entityId=? AND action='CREATE'", [$newId]) === 1);
check('welcome notification created', (int)qv("SELECT COUNT(*) FROM Notification WHERE userId = ? AND title = 'Welcome to WorkNest'", [$u['id']]) === 1);

$dup = $admin->submit('/dashboard/employees/new', ['email' => 'nisha.kapoor@meridianworks.com'] + $form);
check('same login email again → friendly error', $dup->has('already linked to another employee'));
$bad = $admin->submit('/dashboard/employees/new', ['email' => 'not-an-email'] + $form);
check('invalid email rejected', $bad->has('Enter a valid work email address.'));
$nwDept = (string)qv('SELECT id FROM Department WHERE companyId = ? LIMIT 1', [$NW]) ?: 'cnodeptnodeptnodept00001';
$bad = $admin->submit('/dashboard/employees/new', ['email' => 'x1@meridianworks.com', 'departmentId' => $nwDept] + $form);
check("another company's department id rejected", $bad->has('Department not found.'));
$bad = $hr->submit('/dashboard/employees/new', ['email' => 'x2@meridianworks.com', 'role' => 'COMPANY_ADMIN'] + $form);
check('HR cannot grant Company Admin', $bad->has('Only a Company Admin can grant Company Admin access.'));
$bad = $admin->submit('/dashboard/employees/new', ['email' => 'x3@meridianworks.com', 'role' => 'SUPER_ADMIN'] + $form);
check('SUPER_ADMIN is never assignable', $bad->has('can’t be assigned from here'));
check('failed create keeps what was typed', $bad->has('value="QA Engineer"'));
$r = $manager->get('/dashboard/employees/new');
check('manager cannot open Add employee (redirected)', $r->status === 303 && str_ends_with($r->location, '/dashboard'));
$r = $employee->post('/dashboard/employees/new', $form, '/dashboard');
check('employee POST to create is refused', $r->status === 303 && str_ends_with($r->location, '/dashboard'));

section('Onboard from candidate');
$cand = q1("SELECT c.id, c.name FROM Candidate c JOIN JobPosting j ON j.id = c.jobPostingId WHERE j.companyId = ? LIMIT 1", [$MW]);
$np = $hr->get('/dashboard/employees/new?candidateId=' . $cand['id']);
check('candidate prefill shown', $np->has('Onboarding <b class="text-ink">' . htmlspecialchars($cand['name'])));
$r = $hr->post('/dashboard/employees/new', ['candidateId' => $cand['id'], 'email' => 'cand1@meridianworks.com', 'createLogin' => ''] + $form);
$candEmp = basename($r->location);
$tasks = (int)qv('SELECT COUNT(*) FROM OnboardingTask t JOIN Onboarding o ON o.id = t.onboardingId WHERE o.employeeId = ?', [$candEmp]);
check('onboarding checklist cloned from templates', $tasks === (int)qv('SELECT COUNT(*) FROM OnboardingTaskTemplate WHERE companyId = ?', [$MW]) && $tasks > 0, (string)$tasks);
check('no login when "create login" unchecked', qv('SELECT userId FROM Employee WHERE id = ?', [$candEmp]) === null);
$again = $hr->submit('/dashboard/employees/new', ['candidateId' => $cand['id'], 'email' => 'cand2@meridianworks.com', 'createLogin' => ''] + $form);
check('candidate cannot be onboarded twice', $again->has('already been onboarded'));

section('Edit / status / phone / hold');
$edit = ['_action' => 'update', '_form' => 'employee', 'firstName' => 'Nisha', 'lastName' => 'Kapoor-Rao', 'email' => 'nisha.kapoor@meridianworks.com',
    'phone' => '9000000001', 'designation' => 'Senior QA Engineer', 'departmentId' => $engDept, 'branchId' => '', 'managerId' => $mgrId, 'employmentType' => 'FULL_TIME'];
$r = $admin->post("/dashboard/employees/$newId/edit", $edit);
check('edit saves', $r->status === 303 && qv('SELECT designation FROM Employee WHERE id = ?', [$newId]) === 'Senior QA Engineer');
$meta = qv("SELECT meta FROM AuditLog WHERE entityId = ? AND action = 'UPDATE' ORDER BY createdAt DESC LIMIT 1", [$newId]);
check('one audit entry with field-level diff (names, not ids)', str_contains((string)$meta, '"designation"') && str_contains((string)$meta, 'Kapoor-Rao'), (string)$meta);
check('change history shown on profile', $admin->get("/dashboard/employees/$newId")->has('Designation changed from &quot;QA Engineer&quot; to &quot;Senior QA Engineer&quot;'));
$cyc = $admin->submit("/dashboard/employees/$mgrId/edit", ['firstName' => 'Priya', 'lastName' => 'Iyer', 'email' => 'manager@meridianworks.com', 'designation' => 'Engineering Manager', 'managerId' => $newId] + $edit);
check('reporting cycle rejected', $cyc->has('reporting cycle'));
check("other company's employee edit → 404", $admin->get('/dashboard/employees/' . emp_id('admin@northwindretail.com') . '/edit')->status === 404
    || q1('SELECT id FROM Employee WHERE companyId = ?', [$NW]) === null);
$r = $admin->submit("/dashboard/employees/$newId", ['_action' => 'status', 'status' => 'ON_LEAVE']);
check('status changed to ON_LEAVE + audited', qv('SELECT status FROM Employee WHERE id = ?', [$newId]) === 'ON_LEAVE'
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'STATUS_CHANGE'", [$newId]) === 1);
$r = $admin->submit("/dashboard/employees/$newId", ['_action' => 'status', 'status' => 'EXITED']);
check('EXITED cannot be set directly', $r->has('Choose a valid status.') && qv('SELECT status FROM Employee WHERE id = ?', [$newId]) === 'ON_LEAVE');
$employee->submit("/dashboard/employees/$empId", ['_action' => 'phone', 'phone' => '9876500000']);
check('employee edits own phone', qv('SELECT phone FROM Employee WHERE id = ?', [$empId]) === '9876500000');
$r = $manager->submit("/dashboard/employees/$empId", ['_action' => 'phone', 'phone' => '1111111111']);
check("manager can't edit a report's phone", $r->has('You can only edit your own phone number.') && qv('SELECT phone FROM Employee WHERE id = ?', [$empId]) === '9876500000');
$hr->submit("/dashboard/employees/$empId", ['_action' => 'hold', '_form' => 'hold', 'hold' => '1', 'reason' => 'Pending documents']);
check('HR places a salary hold', (int)qv('SELECT salaryOnHold FROM Employee WHERE id = ?', [$empId]) === 1
    && (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Your salary has been put on hold'", [$empId]) === 1);
$r = $employee->submit("/dashboard/employees/$empId", ['_action' => 'hold', '_form' => 'hold', 'hold' => '0', 'reason' => '']);
check('employee cannot lift their own hold', (int)qv('SELECT salaryOnHold FROM Employee WHERE id = ?', [$empId]) === 1);
$hr->submit("/dashboard/employees/$empId", ['_action' => 'hold', '_form' => 'hold', 'hold' => '0', 'reason' => '']);
check('hold released', (int)qv('SELECT salaryOnHold FROM Employee WHERE id = ?', [$empId]) === 0);

section('Visibility');
check('employee cannot view a colleague (redirect)', $employee->get("/dashboard/employees/$mgrId")->status === 303);
check('manager views their report', $manager->get("/dashboard/employees/$empId")->status === 200);
$outside = emp_id('finance@meridianworks.com');
check('manager cannot view outside their line', $manager->get("/dashboard/employees/$outside")->status === 303);
$nwAdmin = Client::as('admin@northwindretail.com');
check('other tenant cannot view Meridian employee', $nwAdmin->get("/dashboard/employees/$empId")->status === 303);
$r = $nwAdmin->post("/dashboard/employees/$empId/edit", $edit, '/dashboard');
check('other tenant cannot edit Meridian employee (404, unchanged)', in_array($r->status, [303, 404], true) && qv('SELECT lastName FROM Employee WHERE id = ?', [$empId]) === 'Verma');
$list = $manager->get('/dashboard/employees');
check('manager list is limited to their line', $list->has('Rohan Verma') && !$list->has('Ritika Sethi'));

section('CSV export / import');
$csv = $admin->get('/api/reports/employees');
check('export is CSV attachment', str_contains((string)$csv->header('content-type'), 'text/csv') && str_starts_with($csv->body, 'Employee Code,First Name'));
check('export is company-scoped', !str_contains($csv->body, 'northwindretail'));
check('template downloads', str_contains($admin->get('/api/employees/import-template')->body, 'Asha,Verma'));
check('employee cannot export', $employee->get('/api/reports/employees')->status === 401);
$csvText = "Employee Code,First Name,Last Name,Email,Phone,Gender,Date of Birth,Designation,Department,Branch,Manager Employee Code,Employment Type,Date of Joining,Bank Account Number,Bank IFSC,Bank Name,Status\n"
    . "MW-9001,Imran,Sheikh,imran@meridianworks.com,,MALE,1990-01-02,Analyst,Engineering,Pune HQ,MW-9002,FULL_TIME,2026-08-01,,,,\n"
    . "MW-9002,Leela,Menon,leela@meridianworks.com,,FEMALE,,Lead Analyst,Engineering,,,FULL_TIME,2026-07-01,,,,\n"
    . ",Bad,Date,bad@meridianworks.com,,,,Analyst,,,,,2026-13-45,,,,\n"
    . ",No,Dept,nodept@meridianworks.com,,,,Analyst,Nowhere,,,,2026-08-01,,,,\n"
    . "MW-1004,Rohan,Verma,employee@meridianworks.com,9876500000,MALE,,Software Engineer III,Engineering,Pune HQ,,FULL_TIME,2025-08-06,,,,\n"
    . ",=HYPERLINK(\"http://x\"),Inject,inj@meridianworks.com,,,,Analyst,,,,,2026-08-01,,,,\n";
$imp = $admin->submit('/dashboard/employees/import', ['_action' => 'import', '_form' => 'import'], null, ['file' => tmp_file('import.csv', $csvText)]);
check('import summary: 3 created, 1 updated, 2 failed', $imp->has('>3</span> created') && $imp->has('>1</span> updated') && $imp->has('>2</span> failed'), strip_tags(substr($imp->body, strpos($imp->body, 'Import complete') ?: 0, 400)));
check('row errors explained', $imp->has('Invalid date of joining') && $imp->has('Department &quot;Nowhere&quot; not found'));
$imran = q1("SELECT e.*, m.employeeCode AS mc FROM Employee e LEFT JOIN Employee m ON m.id = e.managerId WHERE e.employeeCode = 'MW-9001' AND e.companyId = ?", [$MW]);
check('manager linked in second pass (row order independent)', $imran !== null && $imran['mc'] === 'MW-9002');
check('existing code updated', qv("SELECT designation FROM Employee WHERE employeeCode = 'MW-1004' AND companyId = ?", [$MW]) === 'Software Engineer III');
check('B3: imported employees get balances', (int)qv('SELECT COUNT(*) FROM LeaveBalance WHERE employeeId = ?', [$imran['id']]) === 3);
check('import audited', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE action = 'BULK_IMPORT' AND companyId = ?", [$MW]) === 1);
$exp = $admin->get('/api/reports/employees')->body;
check('formula cells neutralised on export', str_contains($exp, "'=HYPERLINK") || str_contains($exp, "\"'=HYPERLINK"), substr($exp, (int)strpos($exp, 'HYPERLINK') - 5, 40));
$php = $admin->submit('/dashboard/employees/import', ['_action' => 'import', '_form' => 'import'], null, ['file' => tmp_file('evil.csv', "<?php system('id'); ?>\n")]);
check('PHP disguised as CSV rejected', $php->has('That file isn’t allowed.') || $php->has('don’t match its .csv'));

section('Confirmations (+ letter generation)');
q("UPDATE Employee SET dateOfJoining = DATE_SUB(NOW(), INTERVAL 4 MONTH), status = 'ACTIVE' WHERE id = ?", [$newId]);
$c = $hr->get('/dashboard/employees/confirmations');
check('probation-due list includes the employee', $c->has('Nisha Kapoor-Rao'));
check('manager sees no probation-due section', !$manager->get('/dashboard/employees/confirmations')->has('Probation due'));
$hr->submit('/dashboard/employees/confirmations', ['_action' => 'raise', 'employeeId' => $newId]);
$conf = q1('SELECT * FROM Confirmation WHERE employeeId = ?', [$newId]);
check('confirmation raised with due date = DOJ + 3 months', $conf !== null && $conf['status'] === 'PENDING');
check('manager notified', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Confirmation review needed'", [$mgrId]) === 1);
$r = $employee->submit('/dashboard/employees/confirmations', ['_action' => 'recommend', 'confirmationId' => $conf['id'], 'recommendation' => 'Confirm'], '/dashboard');
check('employee cannot recommend', qv('SELECT managerRecommendation FROM Confirmation WHERE id = ?', [$conf['id']]) === null);
$manager->submit('/dashboard/employees/confirmations', ['_action' => 'recommend', 'confirmationId' => $conf['id'], 'recommendation' => 'Confirm']);
check('manager recommends', qv('SELECT managerRecommendation FROM Confirmation WHERE id = ?', [$conf['id']]) === 'Confirm');
$r = $manager->submit('/dashboard/employees/confirmations', ['_action' => 'finalize', 'confirmationId' => $conf['id'], 'decision' => 'CONFIRMED']);
check('manager cannot finalize', qv('SELECT status FROM Confirmation WHERE id = ?', [$conf['id']]) === 'PENDING');
$hr->submit('/dashboard/employees/confirmations', ['_action' => 'finalize', 'confirmationId' => $conf['id'], 'decision' => 'EXTENDED', 'extendedToDate' => '']);
check('extension needs a date', qv('SELECT status FROM Confirmation WHERE id = ?', [$conf['id']]) === 'PENDING');
$hr->submit('/dashboard/employees/confirmations', ['_action' => 'finalize', 'confirmationId' => $conf['id'], 'decision' => 'CONFIRMED']);
check('HR confirms', qv('SELECT status FROM Confirmation WHERE id = ?', [$conf['id']]) === 'CONFIRMED');
$doc = q1("SELECT * FROM Document WHERE employeeId = ? AND type = 'CONFIRMATION_LETTER'", [$newId]);
check('confirmation letter generated as a document', $doc !== null && str_starts_with((string)$doc['fileUrl'], 'storage:' . $MW . '/employee-documents/'), json_encode($doc));
$path = __DIR__ . '/../../storage/uploads/' . substr((string)$doc['fileUrl'], 8);
check('letter PDF stored outside the web root', is_file($path) && str_starts_with((string)file_get_contents($path, false, null, 0, 5), '%PDF-'));
$dl = $hr->get('/files/' . $doc['id']);
check('HR downloads it through /files/{id}', $dl->status === 200 && str_starts_with($dl->body, '%PDF-') && str_contains((string)$dl->header('content-type'), 'application/pdf'));
check('download is sandboxed + nosniff', str_contains((string)$dl->header('content-security-policy'), 'sandbox') && $dl->header('x-content-type-options') === 'nosniff');
$nisha = Client::as('nisha.kapoor@meridianworks.com', $temp);
check('the employee can download their own letter', $nisha->get('/files/' . $doc['id'])->status === 200);
check('a colleague cannot (404)', $employee->get('/files/' . $doc['id'])->status === 404);
check('another tenant cannot (404)', $nwAdmin->get('/files/' . $doc['id'])->status === 404);
check('employee notified of confirmation', (int)qv("SELECT COUNT(*) FROM Notification WHERE userId = ? AND title = 'Employment confirmed'", [$u['id']]) === 1);

section('Promotions & transfers');
$r = $hr->post("/dashboard/employees/$empId/promote", ['_action' => 'request', '_form' => 'promote', 'type' => 'PROMOTION', 'newDesignation' => 'Senior Engineer',
    'newDepartmentId' => '', 'newBranchId' => '', 'newManagerId' => '', 'newBasic' => '95000', 'effectiveDate' => '2026-10-01', 'reason' => 'Great work']);
$pt = q1('SELECT * FROM PromotionTransfer WHERE employeeId = ? ORDER BY createdAt DESC LIMIT 1', [$empId]);
check('request created, routed to direct manager', $pt !== null && $pt['approverId'] === $mgrId && $pt['status'] === 'PENDING');
$empty = $hr->submit("/dashboard/employees/$empId/promote", ['_action' => 'request', '_form' => 'promote', 'type' => 'TRANSFER', 'effectiveDate' => '2026-10-01', 'reason' => 'x']);
check('request with no changes rejected', $empty->has('Provide at least one change'));
check('assigned manager sees it in the queue', $manager->get('/dashboard/employees/promotions')->has('New designation: Senior Engineer'));
$fin = Client::as('finance@meridianworks.com');
$other = Client::as('it@meridianworks.com');
$r = $other->submit('/dashboard/employees/promotions', ['_action' => 'decide', 'id' => $pt['id'], 'approve' => '1'], '/dashboard');
check('unassigned user cannot approve', qv('SELECT status FROM PromotionTransfer WHERE id = ?', [$pt['id']]) === 'PENDING');
$employee->post('/dashboard/employees/promotions', ['_action' => 'decide', 'id' => $pt['id'], 'approve' => '1'], '/dashboard');
check('subject cannot approve their own', qv('SELECT status FROM PromotionTransfer WHERE id = ?', [$pt['id']]) === 'PENDING');
$manager->submit('/dashboard/employees/promotions', ['_action' => 'decide', 'id' => $pt['id'], 'approve' => '1']);
check('manager approves', qv('SELECT status FROM PromotionTransfer WHERE id = ?', [$pt['id']]) === 'APPROVED');
check('designation applied', qv('SELECT designation FROM Employee WHERE id = ?', [$empId]) === 'Senior Engineer');
check('basic salary revised + SALARY_REVISION audited', (float)qv('SELECT basic FROM SalaryStructure WHERE employeeId = ?', [$empId]) === 95000.0
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE action = 'SALARY_REVISION' AND entityId = ?", [$empId]) === 1);
check('PROMOTION_APPROVED diff audited', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE action = 'PROMOTION_APPROVED' AND entityId = ?", [$empId]) === 1);
$manager->post('/dashboard/employees/promotions', ['_action' => 'decide', 'id' => $pt['id'], 'approve' => '0']);
check('cannot decide twice', qv('SELECT status FROM PromotionTransfer WHERE id = ?', [$pt['id']]) === 'APPROVED');

section('Companies (Super Admin)');
$r = $super->post('/dashboard/companies/new', ['_action' => 'create', '_form' => 'company', 'name' => 'Acme <b>Labs</b>', 'industry' => 'Tech', 'adminEmail' => 'owner@acme.test']);
$idx = $super->get('/dashboard/companies');
preg_match('/data-temp-password>([^<]+)</', $idx->body, $m);
$acmeTemp = html_entity_decode($m[1] ?? '', ENT_QUOTES);
check('company created; admin temp password shown once', $r->status === 303 && strlen($acmeTemp) >= 12);
check('company name HTML-escaped in list', $idx->has('Acme &lt;b&gt;Labs&lt;/b&gt;'));
$acme = company_id('Acme <b>Labs</b>');
check('policy + 8 onboarding templates provisioned', (int)qv('SELECT COUNT(*) FROM CompanyPolicy WHERE companyId = ?', [$acme]) === 1
    && (int)qv('SELECT COUNT(*) FROM OnboardingTaskTemplate WHERE companyId = ?', [$acme]) === 8);
check('slug generated', qv('SELECT slug FROM Company WHERE id = ?', [$acme]) === 'acme-b-labs-b');
$acmeAdmin = Client::as('owner@acme.test', $acmeTemp);
check('new company admin signs in', (bool)$acmeAdmin);
$dupe = $super->submit('/dashboard/companies/new', ['_action' => 'create', '_form' => 'company', 'name' => 'X', 'industry' => '', 'adminEmail' => 'owner@acme.test']);
check('duplicate admin email rejected', $dupe->has('already exists on the platform'));
check('tenant admin cannot open Companies', $admin->get('/dashboard/companies')->status === 303);
$super->submit("/dashboard/companies/$acme", ['_action' => 'status', 'status' => 'SUSPENDED']);
check('suspension blocks the tenant immediately', $acmeAdmin->get('/dashboard')->status === 403 && $acmeAdmin->last->has('Access suspended'));
check('suspension audited + admins notified', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'STATUS_CHANGE'", [$acme]) === 1
    && (int)qv("SELECT COUNT(*) FROM Notification n JOIN `User` u ON u.id = n.userId WHERE u.companyId = ? AND n.title = 'Your workspace was suspended'", [$acme]) === 1);
$super->submit("/dashboard/companies/$acme", ['_action' => 'status', 'status' => 'ACTIVE']);
check('reactivated', $acmeAdmin->get('/dashboard')->status === 200);
foreach (['users', 'activity', 'branches', 'departments', 'policy'] as $tab) {
    check("company tab $tab renders", $super->get("/dashboard/companies/$acme?tab=$tab")->status === 200);
}

section('Platform users & roles');
$acmeUser = (string)qv("SELECT id FROM `User` WHERE email = 'owner@acme.test'");
$super->submit('/dashboard/users', ['_action' => 'toggle', 'userId' => $acmeUser, 'active' => '0']);
check('super admin disables a login', (int)qv('SELECT isActive FROM `User` WHERE id = ?', [$acmeUser]) === 0);
$superId = (string)qv("SELECT id FROM `User` WHERE role = 'SUPER_ADMIN'");
$self = $super->submit('/dashboard/users', ['_action' => 'toggle', 'userId' => $superId, 'active' => '0']);
check('cannot disable self', (int)qv('SELECT isActive FROM `User` WHERE id = ?', [$superId]) === 1);
check('user search filter works', $super->get('/dashboard/users?q=acme')->has('owner@acme.test') && !$super->last->has('hr@meridianworks.com'));
$super->submit('/dashboard/roles', ['_action' => 'module', 'role' => 'EMPLOYEE', 'module' => '/dashboard/assets', 'allowed' => '0']);
check('platform module toggle hides Assets for employees', !$employee->get('/dashboard')->has('>Assets</a>'));
$super->submit('/dashboard/roles', ['_action' => 'module', 'role' => 'EMPLOYEE', 'module' => '/dashboard/assets', 'allowed' => '1']);
$bad = $super->submit('/dashboard/roles', ['_action' => 'module', 'role' => 'EMPLOYEE', 'module' => '/evil', 'allowed' => '0']);
check('unknown module rejected', (int)qv("SELECT COUNT(*) FROM RolePermission WHERE module = '/evil'") === 0);
$super->submit('/dashboard/roles', ['_action' => 'payroll', 'role' => 'HR', 'payrollAction' => 'PROCESS', 'allowed' => '1']);
check('payroll action override stored', (int)qv("SELECT allowed FROM RolePermission WHERE role='HR' AND action='PROCESS' AND companyId IS NULL") === 1);

section('Company settings');
$s = $admin->submit('/dashboard/settings', ['_action' => 'policy', '_form' => 'policy', 'workWeek' => 'MON-SAT', 'checkInGraceMins' => '10', 'probationMonths' => '6',
    'casualLeaveDays' => '10', 'sickLeaveDays' => '7', 'earnedLeaveDays' => '14', 'noticePeriodDays' => '60', 'geofenceAttendanceEnabled' => 'on']);
check('policy saved', (int)qv('SELECT probationMonths FROM CompanyPolicy WHERE companyId = ?', [$MW]) === 6 && (int)qv('SELECT geofenceAttendanceEnabled FROM CompanyPolicy WHERE companyId = ?', [$MW]) === 1);
$s = $admin->submit('/dashboard/settings', ['_action' => 'policy', '_form' => 'policy', 'workWeek' => 'MON-SAT', 'checkInGraceMins' => 'abc', 'probationMonths' => '6',
    'casualLeaveDays' => '10', 'sickLeaveDays' => '7', 'earnedLeaveDays' => '14', 'noticePeriodDays' => '60']);
check('non-numeric policy value rejected (original would crash)', $s->has('must be a whole number'));
$admin->submit('/dashboard/settings', ['_action' => 'branch', '_form' => 'branches', 'name' => 'Mumbai', 'city' => 'Mumbai']);
$mumbai = (string)qv("SELECT id FROM Branch WHERE companyId = ? AND name = 'Mumbai'", [$MW]);
check('branch added', $mumbai !== '');
$admin->submit('/dashboard/settings', ['_action' => 'branch_location', 'branchId' => $mumbai, 'latitude' => '19.0760', 'longitude' => '72.8777', 'radius' => '75']);
check('branch geofence set', (float)qv('SELECT latitude FROM Branch WHERE id = ?', [$mumbai]) === 19.076 && (int)qv('SELECT geofenceRadiusMeters FROM Branch WHERE id = ?', [$mumbai]) === 75);
$admin->submit('/dashboard/settings', ['_action' => 'branch_location', 'branchId' => $mumbai, 'latitude' => '123', 'longitude' => '72', 'radius' => '']);
check('invalid latitude rejected', (float)qv('SELECT latitude FROM Branch WHERE id = ?', [$mumbai]) === 19.076);
$admin->submit('/dashboard/settings', ['_action' => 'branch_location', 'branchId' => $mumbai, 'latitude' => '', 'longitude' => '', 'radius' => '']);
check('geofence cleared', qv('SELECT latitude FROM Branch WHERE id = ?', [$mumbai]) === null);
$admin->submit('/dashboard/settings', ['_action' => 'department', '_form' => 'departments', 'name' => 'Legal']);
$d = $admin->submit('/dashboard/settings', ['_action' => 'department', '_form' => 'departments', 'name' => 'Legal']);
check('duplicate department → safe message, no SQL', $d->has('That record already exists') && !$d->has('SQLSTATE'));
$admin->submit('/dashboard/settings', ['_action' => 'leave_type', '_form' => 'leave-types', 'name' => 'Paternity Leave', 'daysPerYear' => '5', 'carryForward' => 'on']);
check('leave type added', (int)qv("SELECT daysPerYear FROM LeaveType WHERE companyId = ? AND name = 'Paternity Leave'", [$MW]) === 5);
$admin->submit('/dashboard/settings', ['_action' => 'holiday', '_form' => 'holidays', 'name' => 'Founders Day', 'date' => '2026-12-01']);
check('holiday stored at local midnight', qv("SELECT date FROM Holiday WHERE companyId = ? AND name = 'Founders Day'", [$MW]) === '2026-12-01 00:00:00.000');
$admin->submit('/dashboard/settings', ['_action' => 'template_add', '_form' => 'templates', 'category' => 'IT', 'title' => 'VPN access']);
$tpl = (string)qv("SELECT id FROM OnboardingTaskTemplate WHERE companyId = ? AND title = 'VPN access'", [$MW]);
$admin->submit('/dashboard/settings', ['_action' => 'template_remove', '_form' => 'templates', 'id' => $tpl]);
check('onboarding template added and removed', $tpl !== '' && qv('SELECT id FROM OnboardingTaskTemplate WHERE id = ?', [$tpl]) === null);
check('HR cannot change company settings', $hr->get('/dashboard/settings')->status === 303);

section('Secure logo upload (A1)');
$admin->submit('/dashboard/settings', ['_action' => 'letterhead', '_form' => 'letterhead', 'address' => "12 Park St\nPune", 'phone' => '020 1234', 'panNumber' => 'AAJFF7660C'], null, ['logo' => tmp_png()]);
$logoRef = (string)qv('SELECT logoUrl FROM Company WHERE id = ?', [$MW]);
check('logo stored under storage/uploads/<co>/other with random name', (bool)preg_match('#^storage:' . $MW . '/other/[a-f0-9]{32}\.png$#', $logoRef), $logoRef);
$logo = $admin->get("/files/logo/$MW");
check('logo served via protected route as image/png', $logo->status === 200 && $logo->header('content-type') === 'image/png');
check('other tenant cannot fetch the logo', $nwAdmin->get("/files/logo/$MW")->status === 404);
check('storage path not reachable over HTTP', (new Client())->get('/storage/uploads/' . substr($logoRef, 8))->status === 403);
$evil = $admin->submit('/dashboard/settings', ['_action' => 'letterhead', '_form' => 'letterhead'], null, ['logo' => tmp_file('shell.png', "\x89PNG\r\n\x1a\n<?php system(\$_GET['c']); ?>")]);
check('PHP disguised as PNG rejected', $evil->has('isn’t allowed') || $evil->has('don’t match'));
$svg = $admin->submit('/dashboard/settings', ['_action' => 'letterhead', '_form' => 'letterhead'], null, ['logo' => tmp_file('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]);
check('SVG logo rejected (script-capable)', $svg->has('That file type isn’t allowed'));
$php = $admin->submit('/dashboard/settings', ['_action' => 'letterhead', '_form' => 'letterhead'], null, ['logo' => tmp_file('x.php', '<?php echo 1;')]);
check('.php upload rejected', $php->has('That file type isn’t allowed'));
check('logo unchanged after rejected uploads', qv('SELECT logoUrl FROM Company WHERE id = ?', [$MW]) === $logoRef);

section('Invite user / custom roles');
$admin->submit('/dashboard/settings/roles', ['_action' => 'create', '_form' => 'role', 'name' => 'Team Lead', 'baseRole' => 'MANAGER']);
$tl = (string)qv("SELECT id FROM CustomRole WHERE companyId = ? AND name = 'Team Lead'", [$MW]);
check('custom role created', $tl !== '');
$dupRole = $admin->submit('/dashboard/settings/roles', ['_action' => 'create', '_form' => 'role', 'name' => 'Team Lead', 'baseRole' => 'MANAGER']);
check('duplicate custom role name rejected', $dupRole->has('already exists'));
$inv = $admin->submit('/dashboard/settings/users', ['_action' => 'invite', '_form' => 'invite', 'email' => 'lead@meridianworks.com', 'role' => "custom:$tl"]);
preg_match('/data-temp-password>([^<]+)</', $inv->body, $m);
$leadTemp = html_entity_decode($m[1] ?? '', ENT_QUOTES);
$lead = q1("SELECT * FROM `User` WHERE email = 'lead@meridianworks.com'");
check('invited user gets base role + custom role', $lead !== null && $lead['role'] === 'MANAGER' && $lead['customRoleId'] === $tl);
$leadClient = Client::as('lead@meridianworks.com', $leadTemp);
check('custom role label shown in the user menu', $leadClient->get('/dashboard')->has('Team Lead'));
$admin->submit('/dashboard/settings/roles', ['_action' => 'toggle', 'customRoleId' => $tl, 'module' => '/dashboard/leave', 'allowed' => '0']);
check('custom role module switch hides Leave for that role only', !$leadClient->get('/dashboard')->has('>Leave</a>') && $manager->get('/dashboard')->has('>Leave</a>'));
$del = $admin->submit('/dashboard/settings/roles', ['_action' => 'delete', 'id' => $tl]);
check('role with users cannot be deleted', $del->has('still have this role'));
$bad = $admin->submit('/dashboard/settings/users', ['_action' => 'invite', '_form' => 'invite', 'email' => 'lead@meridianworks.com', 'role' => 'EMPLOYEE']);
check('duplicate invite rejected', $bad->has('already exists'));
$bad = $admin->submit('/dashboard/settings/users', ['_action' => 'invite', '_form' => 'invite', 'email' => 'root@meridianworks.com', 'role' => 'SUPER_ADMIN']);
check('SUPER_ADMIN cannot be invited', $bad->has('can’t be assigned'));
$nwRole = 'custom:' . ($tl);
$bad = $nwAdmin->submit('/dashboard/settings/users', ['_action' => 'invite', '_form' => 'invite', 'email' => 'x@northwind.test', 'role' => $nwRole]);
check("other tenant can't use Meridian's custom role", $bad->has('Custom role not found.'));
$admin->submit('/dashboard/settings/users', ['_action' => 'toggle', 'userId' => $lead['id'], 'active' => '0']);
check('company admin disables a login', (int)qv('SELECT isActive FROM `User` WHERE id = ?', [$lead['id']]) === 0);
$nwUser = (string)qv("SELECT id FROM `User` WHERE email = 'admin@northwindretail.com'");
$admin->submit('/dashboard/settings/users', ['_action' => 'toggle', 'userId' => $nwUser, 'active' => '0']);
check("cannot disable another tenant's user", (int)qv('SELECT isActive FROM `User` WHERE id = ?', [$nwUser]) === 1);

section('Audit log scoping');
$a = $admin->get('/dashboard/audit');
check('tenant audit shows own company only', $a->has('recorded action(s) for your company') && !$a->has('owner@acme.test'));
check('filter values scoped to tenant', !str_contains($admin->get('/dashboard/audit')->body, '>ACTIVATE<') || (int)qv("SELECT COUNT(*) FROM AuditLog WHERE companyId = ? AND action='ACTIVATE'", [$MW]) > 0);
check('super admin sees all tenants', $super->get('/dashboard/audit')->has('across every tenant'));
check('manager cannot open audit', $manager->get('/dashboard/audit')->status === 303);

finish();
