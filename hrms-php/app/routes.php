<?php
/**
 * Route table — identical URLs to the original Next.js app (stored
 * notification links and bookmarks keep working). Guards mirror each
 * original page's requireSession/requireRole/requireModuleAccess calls;
 * finer business rules are enforced again inside pages and actions.
 *
 * Static segments are declared before {param} routes of the same prefix.
 */
declare(strict_types=1);

const R_ADMIN_HR = ['COMPANY_ADMIN', 'HR'];
const R_ADMIN_HR_MGR = ['COMPANY_ADMIN', 'HR', 'MANAGER'];

// ── Public ──────────────────────────────────────────────────────────────
route('/', 'home.php', ['auth' => false]);
route('/login', 'login.php', ['auth' => false]);
route('/login/demo', 'login_demo.php', ['auth' => false, 'methods' => ['GET']]);
route('/logout', 'logout.php', ['auth' => false, 'methods' => ['POST']]);
route('/forgot-password', 'forgot_password.php', ['auth' => false]);
route('/reset-password', 'reset_password.php', ['auth' => false]);
route('/install', 'install.php', ['auth' => false]);

// ── Print views (outside the dashboard chrome) ─────────────────────────
route('/payslips/{id}', 'print/payslip.php', ['methods' => ['GET']]);
route('/letters/{id}', 'print/letter.php', ['methods' => ['GET']]);

// ── Protected file downloads (A2) ──────────────────────────────────────
route('/files/logo/{companyId}', 'files/logo.php', ['methods' => ['GET']]);
route('/files/{id}', 'files/document.php', ['methods' => ['GET']]);

// ── CSV / JSON endpoints ───────────────────────────────────────────────
route('/api/reports/employees', 'api/reports_employees.php', ['methods' => ['GET']]);
route('/api/reports/payroll', 'api/reports_payroll.php', ['methods' => ['GET']]);
route('/api/employees/import-template', 'api/import_template.php', ['methods' => ['GET']]);
route('/api/attendance/check-in', 'api/attendance_check.php', ['methods' => ['POST']]);
route('/api/attendance/check-out', 'api/attendance_check.php', ['methods' => ['POST']]);
route('/api/notifications/read', 'api/notifications.php', ['methods' => ['POST']]);
route('/api/notifications/read-all', 'api/notifications.php', ['methods' => ['POST']]);

// ── Dashboard ──────────────────────────────────────────────────────────
route('/dashboard', 'dashboard/index.php');
route('/dashboard/profile', 'dashboard/profile.php');

// Platform (Super Admin)
route('/dashboard/companies', 'dashboard/companies/index.php', ['roles' => ['SUPER_ADMIN']]);
route('/dashboard/companies/new', 'dashboard/companies/new.php', ['roles' => ['SUPER_ADMIN']]);
route('/dashboard/companies/{id}', 'dashboard/companies/show.php', ['roles' => ['SUPER_ADMIN']]);
route('/dashboard/users', 'dashboard/users.php', ['roles' => ['SUPER_ADMIN']]);
route('/dashboard/roles', 'dashboard/roles.php', ['roles' => ['SUPER_ADMIN']]);
route('/dashboard/audit', 'dashboard/audit.php', ['roles' => ['SUPER_ADMIN', 'AUDITOR', 'COMPANY_ADMIN']]);

// Employees
$emp = '/dashboard/employees';
route($emp, 'dashboard/employees/index.php', ['roles' => R_ADMIN_HR_MGR, 'module' => $emp]);
route("$emp/new", 'dashboard/employees/new.php', ['roles' => R_ADMIN_HR, 'module' => $emp]);
route("$emp/import", 'dashboard/employees/import.php', ['roles' => R_ADMIN_HR, 'module' => $emp]);
route("$emp/confirmations", 'dashboard/employees/confirmations.php', ['roles' => R_ADMIN_HR_MGR, 'module' => $emp]);
route("$emp/promotions", 'dashboard/employees/promotions.php', ['roles' => R_ADMIN_HR_MGR, 'module' => $emp]);
route("$emp/{id}/edit", 'dashboard/employees/edit.php', ['roles' => R_ADMIN_HR, 'module' => $emp]);
route("$emp/{id}/promote", 'dashboard/employees/promote.php', ['roles' => R_ADMIN_HR, 'module' => $emp]);
route("$emp/{id}", 'dashboard/employees/show.php'); // visibility checked in the page

// Attendance & leave
route('/dashboard/attendance', 'dashboard/attendance/index.php', ['module' => '/dashboard/attendance']);
route('/dashboard/attendance/regularizations', 'dashboard/attendance/regularizations.php', ['module' => '/dashboard/attendance']);
route('/dashboard/leave', 'dashboard/leave/index.php', ['module' => '/dashboard/leave']);
route('/dashboard/leave/approvals', 'dashboard/leave/approvals.php', ['roles' => R_ADMIN_HR_MGR, 'module' => '/dashboard/leave']);

// Payroll
route('/dashboard/payroll', 'dashboard/payroll/index.php', ['module' => '/dashboard/payroll']);
route('/dashboard/payroll/salary', 'dashboard/payroll/salary.php', ['module' => '/dashboard/payroll']); // + EDIT_SALARY in page
route('/dashboard/payroll/{runId}', 'dashboard/payroll/run.php', ['roles' => ['COMPANY_ADMIN', 'HR', 'PAYROLL_ADMIN', 'FINANCE'], 'module' => '/dashboard/payroll']);

// Recruitment & onboarding
$rec = ['roles' => ['COMPANY_ADMIN', 'HR', 'RECRUITER'], 'module' => '/dashboard/recruitment'];
route('/dashboard/recruitment', 'dashboard/recruitment/index.php', $rec);
route('/dashboard/recruitment/new', 'dashboard/recruitment/new.php', $rec);
route('/dashboard/recruitment/{jobId}', 'dashboard/recruitment/job.php', $rec);
route('/dashboard/onboarding', 'dashboard/onboarding/index.php', ['module' => '/dashboard/onboarding']);
route('/dashboard/onboarding/{id}', 'dashboard/onboarding/show.php', ['module' => '/dashboard/onboarding']);

// Performance, expenses, assets
route('/dashboard/performance', 'dashboard/performance.php', ['module' => '/dashboard/performance']);
route('/dashboard/expenses', 'dashboard/expenses.php', ['module' => '/dashboard/expenses']);
route('/dashboard/assets', 'dashboard/assets.php', ['module' => '/dashboard/assets']);

// Documents
route('/dashboard/documents', 'dashboard/documents/index.php', ['module' => '/dashboard/documents']);
route('/dashboard/documents/company', 'dashboard/documents/company.php', ['module' => '/dashboard/documents']);
route('/dashboard/documents/employees', 'dashboard/documents/employees.php', ['module' => '/dashboard/documents']);
route('/dashboard/documents/letter/{id}', 'dashboard/documents/letter.php', ['methods' => ['GET']]); // visibility checked in the page
route('/dashboard/documents/{employeeId}', 'dashboard/documents/employee.php', ['module' => '/dashboard/documents']);

// Helpdesk & exit
route('/dashboard/helpdesk', 'dashboard/helpdesk/index.php', ['module' => '/dashboard/helpdesk']);
route('/dashboard/helpdesk/{id}', 'dashboard/helpdesk/show.php', ['module' => '/dashboard/helpdesk']);
route('/dashboard/exit', 'dashboard/exit/index.php', ['module' => '/dashboard/exit']);
route('/dashboard/exit/new', 'dashboard/exit/new.php');   // any employee may resign (checked in the action)
route('/dashboard/exit/{id}', 'dashboard/exit/show.php'); // owner or staff (checked in the page)

// Reports & settings
route('/dashboard/reports', 'dashboard/reports.php', [
    'roles' => ['COMPANY_ADMIN', 'HR', 'MANAGER', 'AUDITOR', 'MANAGEMENT', 'PAYROLL_ADMIN', 'RECRUITER'],
    'module' => '/dashboard/reports',
]);
route('/dashboard/settings', 'dashboard/settings/index.php', ['roles' => ['COMPANY_ADMIN'], 'module' => '/dashboard/settings']);
route('/dashboard/settings/users', 'dashboard/settings/users.php', ['roles' => ['COMPANY_ADMIN'], 'module' => '/dashboard/settings']);
route('/dashboard/settings/roles', 'dashboard/settings/roles.php', ['roles' => ['COMPANY_ADMIN']]);
route('/dashboard/settings/statutory', 'dashboard/settings/statutory.php', ['roles' => ['COMPANY_ADMIN', 'PAYROLL_ADMIN'], 'module' => '/dashboard/settings']);

// Development diagnostics live in dev/ — never deployed, and only loaded for APP_ENV=local.
if (config('app.env') === 'local' && is_file(BASE_PATH . '/dev/routes.php')) {
    require BASE_PATH . '/dev/routes.php';
}
