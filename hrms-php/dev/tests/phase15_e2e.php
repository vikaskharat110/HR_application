<?php
/**
 * Phase 15 — end-to-end journey across every module on a fresh install.
 * Run through dev/tests/phase15_e2e.sh (it prepares the empty database).
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const SETUP_KEY = 'e2e-setup-key-0123456789abcdef';
// Only log lines written during this run count (earlier suites log deliberate failures).
$logFile = __DIR__ . '/../../storage/logs/app-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d') . '.log';
clearstatcache();
$logStart = is_file($logFile) ? filesize($logFile) : 0;

/** The one-time temporary password shown on $page (data-temp-password). */
function temp_from(Response $page): string
{
    preg_match('/data-temp-password>([^<]+)</', $page->body, $m);
    return html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5);
}

function login_fails(string $email, string $password): bool
{
    try {
        Client::as($email, $password);
        return false;
    } catch (RuntimeException) {
        return true;
    }
}

section('Fresh install');
check('empty database: no users, no companies', (int)qv('SELECT COUNT(*) FROM `User`') === 0 && (int)qv('SELECT COUNT(*) FROM Company') === 0);
$anon = new Client();
check('/install asks for the setup key', $anon->get('/install')->has('name="setupKey"'));
$anon->post('/install', ['_action' => 'install', '_form' => 'install', 'email' => 'owner@platform.test', 'password' => 'Platform#Owner2026',
    'confirmPassword' => 'Platform#Owner2026', 'setupKey' => SETUP_KEY]);
check('Super Admin created; installer locked', qv("SELECT role FROM `User` WHERE email = 'owner@platform.test'") === 'SUPER_ADMIN' && $anon->get('/install')->status === 404);
$super = Client::as('owner@platform.test', 'Platform#Owner2026');

section('Tenant onboarding');
$super->post('/dashboard/companies/new', ['_action' => 'create', '_form' => 'company', 'name' => 'Lotus Foods', 'industry' => 'FMCG', 'adminEmail' => 'admin@lotus.test']);
$adminTemp = temp_from($super->get('/dashboard/companies'));
$lotus = company_id('Lotus Foods');
check('company provisioned with policy + onboarding templates', $lotus !== '' && strlen($adminTemp) >= 12
    && (int)qv('SELECT COUNT(*) FROM CompanyPolicy WHERE companyId = ?', [$lotus]) === 1);
check('temp password not stored in plain text anywhere', (int)qv('SELECT COUNT(*) FROM `User` WHERE passwordHash = ?', [$adminTemp]) === 0
    && (int)qv('SELECT COUNT(*) FROM AuditLog WHERE meta LIKE ?', ['%' . $adminTemp . '%']) === 0
    && (int)qv('SELECT COUNT(*) FROM Notification WHERE message LIKE ?', ['%' . $adminTemp . '%']) === 0);
check('temp password shown only once', temp_from($super->get('/dashboard/companies')) === '');
$admin = Client::as('admin@lotus.test', $adminTemp);
$t = $admin->token('/dashboard/profile');
$admin->post('/dashboard/profile', ['_action' => 'change_password', '_form' => 'password', 'currentPassword' => $adminTemp,
    'newPassword' => 'Lotus#Admin2026', 'confirmPassword' => 'Lotus#Admin2026', '_csrf' => $t]);
check('admin replaces the temp password', login_fails('admin@lotus.test', $adminTemp) && (bool)($admin = Client::as('admin@lotus.test', 'Lotus#Admin2026')));

section('Organisation setup');
$admin->submit('/dashboard/settings', ['_action' => 'department', '_form' => 'departments', 'name' => 'Operations']);
$admin->submit('/dashboard/settings', ['_action' => 'department', '_form' => 'departments', 'name' => 'Finance']);
$admin->submit('/dashboard/settings', ['_action' => 'leave_type', '_form' => 'leave-types', 'name' => 'Casual Leave', 'daysPerYear' => '12']);
$admin->submit('/dashboard/settings', ['_action' => 'branch', '_form' => 'branches', 'name' => 'Pune HQ', 'city' => 'Pune']);
$ops = (string)qv("SELECT id FROM Department WHERE companyId = ? AND name = 'Operations'", [$lotus]);
$fin = (string)qv("SELECT id FROM Department WHERE companyId = ? AND name = 'Finance'", [$lotus]);
$casual = (string)qv("SELECT id FROM LeaveType WHERE companyId = ? AND name = 'Casual Leave'", [$lotus]);
check('departments, leave type and branch created', $ops !== '' && $fin !== '' && $casual !== '' && (int)qv('SELECT COUNT(*) FROM Branch WHERE companyId = ?', [$lotus]) === 1);

$people = [];
$hire = static function (string $first, string $last, string $email, string $dept, ?string $managerId, string $role, string $designation) use ($admin, &$people): string {
    $r = $admin->post('/dashboard/employees/new', ['_action' => 'create', '_form' => 'employee', 'firstName' => $first, 'lastName' => $last, 'email' => $email,
        'designation' => $designation, 'departmentId' => $dept, 'branchId' => '', 'managerId' => $managerId ?? '', 'employmentType' => 'FULL_TIME',
        'dateOfJoining' => '2026-09-01', 'createLogin' => 'on', 'role' => $role]);
    $id = basename($r->location);
    $people[$email] = temp_from($admin->get("/dashboard/employees/$id"));
    return $id;
};
$mgrId = $hire('Kiran', 'Desai', 'kiran@lotus.test', $ops, null, 'MANAGER', 'Operations Lead');
$empId = $hire('Meera', 'Joshi', 'meera@lotus.test', $ops, $mgrId, 'EMPLOYEE', 'Associate');
$finId = $hire('Farhan', 'Ali', 'farhan@lotus.test', $fin, null, 'FINANCE', 'Accountant');
check('three employees hired with logins', (int)qv('SELECT COUNT(*) FROM Employee e JOIN `User` u ON u.id = e.userId WHERE e.companyId = ?', [$lotus]) === 3
    && count(array_filter($people, static fn($p) => strlen($p) >= 12)) === 3);
check('B3: leave balances allocated on hire (12 casual days)', (float)qv('SELECT allocated FROM LeaveBalance WHERE employeeId = ? AND leaveTypeId = ? AND year = 2026', [$empId, $casual]) === 12.0);
check('direct hires get no onboarding checklist (only recruitment hires, as original)', (int)qv('SELECT COUNT(*) FROM Onboarding WHERE employeeId = ?', [$empId]) === 0);
$manager = Client::as('kiran@lotus.test', $people['kiran@lotus.test']);
$employee = Client::as('meera@lotus.test', $people['meera@lotus.test']);
$finance = Client::as('farhan@lotus.test', $people['farhan@lotus.test']);
check('every new user signs in with their temp password', true);

section('Attendance + leave');
$r = $employee->postJson('/api/attendance/check-in', []);
check('employee checks in (JSON API + CSRF header)', $r->status === 200 && (int)qv('SELECT COUNT(*) FROM Attendance WHERE employeeId = ? AND checkIn IS NOT NULL', [$empId]) === 1);
$employee->postJson('/api/attendance/check-out', []);
check('employee checks out', qv('SELECT checkOut FROM Attendance WHERE employeeId = ?', [$empId]) !== null);
$employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $casual, 'startDate' => '2026-10-12', 'endDate' => '2026-10-13', 'reason' => 'Family function']);
$lr = q1('SELECT * FROM LeaveRequest WHERE employeeId = ?', [$empId]);
check('leave applied: 2 working days, PENDING', $lr !== null && (float)$lr['days'] === 2.0 && $lr['status'] === 'PENDING');
check('manager sees it in approvals', $manager->get('/dashboard/leave/approvals')->has('Meera'));
$manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $lr['id'], 'approve' => '1']);
check('manager approves; balance used = 2', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$lr['id']]) === 'APPROVED'
    && (float)qv('SELECT used FROM LeaveBalance WHERE employeeId = ? AND leaveTypeId = ?', [$empId, $casual]) === 2.0);
$empUser = (string)qv("SELECT id FROM `User` WHERE email = 'meera@lotus.test'");
check('employee notified', (int)qv('SELECT COUNT(*) FROM Notification WHERE userId = ?', [$empUser]) >= 1);

section('Payroll');
foreach ([$empId => 30000, $mgrId => 60000, $finId => 45000] as $id => $basic) {
    $admin->post('/dashboard/payroll/salary', ['_action' => 'save', '_form' => 'salary-' . $id, 'employeeId' => $id, 'payPeriod' => 'MONTHLY',
        'basic' => (string)$basic, 'hra' => (string)($basic * 0.4), 'conveyance' => '1600', 'medicalAllowance' => '1250', 'specialAllowance' => '2000']);
}
check('salary structures saved', (int)qv('SELECT COUNT(*) FROM SalaryStructure s JOIN Employee e ON e.id = s.employeeId WHERE e.companyId = ?', [$lotus]) === 3);
$r = $admin->post('/dashboard/payroll', ['_action' => 'run', '_form' => 'run', 'month' => '9', 'year' => '2026', 'payDate' => '2026-09-30']);
$run = q1('SELECT * FROM PayrollRun WHERE companyId = ?', [$lotus]);
check('September run processed with 3 payslips', $run !== null && $run['status'] === 'PROCESSED' && (int)qv('SELECT COUNT(*) FROM Payslip WHERE payrollRunId = ?', [$run['id']]) === 3);
$slip = q1('SELECT * FROM Payslip WHERE payrollRunId = ? AND employeeId = ?', [$run['id'], $empId]);
check('payslip figures are consistent (net = gross − deductions)', abs((float)$slip['netPay'] - ((float)$slip['grossPay'] - (float)$slip['totalDeductions'])) < 0.01, json_encode([$slip['grossPay'] ?? null, $slip['totalDeductions'] ?? null, $slip['netPay'] ?? null]));
check('employee cannot see the payslip before lock', $employee->get('/payslips/' . $slip['id'])->status === 404);
$finance->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'approve']);
$admin->submit('/dashboard/payroll/' . $run['id'], ['_action' => 'lock']);
check('finance approves, admin locks', qv('SELECT status FROM PayrollRun WHERE id = ?', [$run['id']]) === 'LOCKED');
$p = $employee->get('/payslips/' . $slip['id']);
check('employee opens own payslip after lock', $p->status === 200 && $p->has('Meera'));
check('manager cannot open a report’s payslip', $manager->get('/payslips/' . $slip['id'])->status === 404);
$csv = $admin->get('/api/reports/payroll');
check('payroll CSV export', $csv->status === 200 && str_contains($csv->body, 'Meera'));

section('Expenses, helpdesk, documents');
$employee->submit('/dashboard/expenses', ['_action' => 'submit', '_form' => 'claim', 'category' => 'Travel', 'amount' => '850', 'description' => 'Client visit cab']);
$claim = q1('SELECT * FROM ExpenseClaim WHERE employeeId = ?', [$empId]);
$manager->submit('/dashboard/expenses', ['_action' => 'decide', 'id' => $claim['id'], 'approve' => '1']);
check('expense claimed and approved by manager', $claim !== null && qv('SELECT status FROM ExpenseClaim WHERE id = ?', [$claim['id']]) !== 'PENDING');
$r = $employee->post('/dashboard/helpdesk', ['_action' => 'create', '_form' => 'ticket', 'category' => 'HR Policy', 'priority' => 'MEDIUM', 'subject' => 'Payslip query', 'description' => '']);
$ticket = q1('SELECT * FROM Ticket WHERE raisedById = ?', [$empId]);
check('ticket raised (no HR user yet → unassigned)', $ticket !== null && $ticket['assignedToId'] === null);
$admin->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'status', 'status' => 'RESOLVED']);
check('admin resolves it', qv('SELECT status FROM Ticket WHERE id = ?', [$ticket['id']]) === 'RESOLVED');
$pdf = tmp_file('Handbook.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
$admin->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'POLICY', 'scope' => 'company', 'name' => ''], null, ['file' => $pdf]);
$policy = q1("SELECT * FROM Document WHERE companyId = ? AND type = 'POLICY'", [$lotus]);
check('company policy uploaded to private storage', $policy !== null && str_starts_with($policy['fileUrl'], 'storage:' . $lotus . '/'));
check('employee downloads it through /files', $employee->get('/files/' . $policy['id'])->status === 200);
$r = $admin->submit('/dashboard/documents', ['_action' => 'generate', '_form' => 'generate', 'employeeId' => $empId, 'type' => 'OFFER']);
check('letter needs a template first (as original)', $r->has('No template configured for this letter type yet'));
$admin->submit('/dashboard/documents', ['_action' => 'template', '_form' => 'template-OFFER', 'type' => 'OFFER', 'subject' => 'Offer — {{employeeName}}',
    'bodyHtml' => '<p>Dear {{employeeName}}, welcome to {{companyName}} as {{designation}}. Annual CTC ₹{{ctc}}.</p>']);
$admin->submit('/dashboard/documents', ['_action' => 'generate', '_form' => 'generate', 'employeeId' => $empId, 'type' => 'OFFER']);
$offer = q1("SELECT * FROM Document WHERE employeeId = ? AND type = 'OFFER_LETTER'", [$empId]);
$f = $offer ? $employee->get('/files/' . $offer['id']) : null;
check('offer letter generated as a real PDF', $f !== null && $f->status === 200 && str_starts_with($f->body, '%PDF'));

section('Reports, audit, notifications');
$r = $admin->get('/dashboard/reports');
check('reports show 3 active employees and the run', (bool)preg_match('#Active employees</p><p[^>]*>3</p>#', $r->body) && (bool)preg_match('#Payroll runs</p><p[^>]*>1</p>#', $r->body));
check('audit log records the journey', (int)qv('SELECT COUNT(*) FROM AuditLog WHERE companyId = ?', [$lotus]) >= 10 && $admin->get('/dashboard/audit')->status === 200);
$n = $employee->get('/dashboard');
check('notification bell lists leave, payslip and expense updates', $n->has('Leave request approved') && $n->has('Payslip published') && $n->has('Expense claim approved'));

section('Isolation + suspension');
$super->post('/dashboard/companies/new', ['_action' => 'create', '_form' => 'company', 'name' => 'Rival Retail', 'industry' => 'Retail', 'adminEmail' => 'admin@rival.test']);
$rival = Client::as('admin@rival.test', temp_from($super->get('/dashboard/companies')));
foreach (["/dashboard/employees/$empId", '/files/' . $policy['id'], '/payslips/' . $slip['id'], '/dashboard/helpdesk/' . $ticket['id'], '/dashboard/payroll/' . $run['id']] as $path) {
    $st = $rival->get($path)->status;
    check("other tenant can't open $path", $st === 404 || ($st === 303 && !str_contains((string)$rival->last->location, $path)), (string)$st);
}
check('other tenant sees an empty company', !$rival->get('/dashboard/employees')->has('Meera') && !$rival->get('/api/reports/employees')->has('lotus.test'));
$super->submit('/dashboard/companies/' . $lotus, ['_action' => 'status', 'status' => 'SUSPENDED']);
check('suspended tenant is locked out', $employee->get('/dashboard')->status === 403 && $rival->get('/dashboard')->status === 200);
$super->submit('/dashboard/companies/' . $lotus, ['_action' => 'status', 'status' => 'ACTIVE']);
check('reactivated tenant works again', $employee->get('/dashboard')->status === 200);

section('Every page renders for its roles');
$pages = [
    'admin' => ['/dashboard', '/dashboard/employees', "/dashboard/employees/$empId", "/dashboard/employees/$empId/edit", '/dashboard/employees/confirmations',
        '/dashboard/employees/promotions', '/dashboard/employees/import', '/dashboard/attendance', '/dashboard/attendance/regularizations', '/dashboard/leave',
        '/dashboard/leave/approvals', '/dashboard/payroll', '/dashboard/payroll/salary', '/dashboard/payroll/' . $run['id'], '/dashboard/recruitment',
        '/dashboard/recruitment/new', '/dashboard/onboarding', '/dashboard/performance', '/dashboard/expenses', '/dashboard/documents', '/dashboard/documents/company',
        '/dashboard/documents/employees', "/dashboard/documents/$empId", '/dashboard/assets', '/dashboard/helpdesk', '/dashboard/exit', '/dashboard/reports',
        '/dashboard/settings', '/dashboard/settings/users', '/dashboard/settings/roles', '/dashboard/settings/statutory', '/dashboard/audit', '/dashboard/profile'],
    'employee' => ['/dashboard', '/dashboard/attendance', '/dashboard/leave', '/dashboard/payroll', '/dashboard/performance', '/dashboard/expenses', '/dashboard/documents',
        '/dashboard/helpdesk', '/dashboard/profile', '/dashboard/exit/new', "/dashboard/employees/$empId"],
    'super' => ['/dashboard', '/dashboard/companies', '/dashboard/companies/new', "/dashboard/companies/$lotus", '/dashboard/users', '/dashboard/roles', '/dashboard/audit'],
];
foreach ($pages as $who => $list) {
    $bad = [];
    foreach ($list as $path) {
        $r = ${$who}->get($path);
        if ($r->status !== 200 || str_contains($r->body, 'Something went wrong')) {
            $bad[] = "$path ({$r->status})";
        }
    }
    check("$who: " . count($list) . ' pages render (200, no error page)', $bad === [], implode(', ', $bad));
}
clearstatcache();
$content = is_file($logFile) ? (string)file_get_contents($logFile, false, null, $logStart) : '';
check('no PHP errors/warnings logged during the journey', trim($content) === '', substr($content, 0, 400));

finish();
