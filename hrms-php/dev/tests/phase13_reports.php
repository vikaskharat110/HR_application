<?php
/**
 * Phase 13 — Reports (MIS page + CSV exports). Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase13_reports.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$NW = company_id('Northwind Retail');

/** Decoded data-chart configs on a page, in order. */
function charts(Response $r): array
{
    preg_match_all('/data-chart="([^"]+)"/', $r->body, $m);
    return array_map(static fn($c) => json_decode(html_entity_decode($c, ENT_QUOTES | ENT_HTML5), true), $m[1]);
}

section('Access by role');
$allowed = ['admin', 'hr', 'manager', 'auditor', 'management', 'payroll', 'recruiter'];
$denied = ['employee', 'finance', 'it'];
$clients = [];
foreach ([...$allowed, ...$denied] as $who) {
    $clients[$who] = Client::as("$who@meridianworks.com");
}
foreach ($allowed as $who) {
    $r = $clients[$who]->get('/dashboard/reports');
    check("$who can open Reports", $r->status === 200 && $r->has('Company-wide MIS'));
}
foreach ($denied as $who) {
    check("$who redirected to dashboard", $clients[$who]->get('/dashboard/reports')->location === BASE . '/dashboard');
}
check('anonymous redirected to login', str_contains((new Client())->get('/dashboard/reports')->location, '/login'));
$platform = Client::as('business@webpayx.com');
check('platform admin (no company role) redirected', $platform->get('/dashboard/reports')->status === 303);

section('Payroll CSV link by role');
foreach (['admin' => true, 'hr' => true, 'payroll' => true, 'auditor' => true, 'management' => true, 'manager' => false, 'recruiter' => false] as $who => $sees) {
    check("$who " . ($sees ? 'sees' : 'does not see') . ' the payroll CSV link', $clients[$who]->get('/dashboard/reports')->has('/api/reports/payroll') === $sees);
}
check('employees CSV link always shown', $clients['recruiter']->last->has('/api/reports/employees'));

section('Figures (Meridian Works)');
$r = $clients['hr']->get('/dashboard/reports');
$active = (int)qv("SELECT COUNT(*) FROM Employee WHERE companyId = ? AND status = 'ACTIVE'", [$MW]);
$leave = (int)qv('SELECT COUNT(*) FROM LeaveRequest r JOIN Employee e ON e.id = r.employeeId WHERE e.companyId = ?', [$MW]);
$pending = (int)qv("SELECT COUNT(*) FROM LeaveRequest r JOIN Employee e ON e.id = r.employeeId WHERE e.companyId = ? AND r.status = 'PENDING'", [$MW]);
$cands = (int)qv('SELECT COUNT(*) FROM Candidate c JOIN JobPosting j ON j.id = c.jobPostingId WHERE j.companyId = ?', [$MW]);
$stat = static fn(string $label, int $v) => (bool)preg_match('#' . preg_quote($label, '#') . '</p><p[^>]*>' . $v . '</p>#', $r->body);
check("active employees = $active", $stat('Active employees', $active));
check("leave requests = $leave with $pending pending", $stat('Leave requests (all-time)', $leave) && $r->has("$pending pending"));
check('payroll runs = 2', $stat('Payroll runs', 2));
check("candidates in pipeline = $cands", $stat('Candidates in pipeline', $cands));
$c = charts($r);
check('three charts rendered, Chart.js loaded locally', count($c) === 3 && $r->has('/assets/vendor/chart.umd.min.js') && !$r->has('cdn.'));
$days = q("SELECT DATE(a.date) AS d, COUNT(*) AS n FROM Attendance a JOIN Employee e ON e.id = a.employeeId
            WHERE e.companyId = ? AND a.date >= NOW() - INTERVAL 30 DAY AND a.status IN ('PRESENT','LATE') GROUP BY DATE(a.date) ORDER BY d", [$MW]);
check('attendance trend matches present+late per day', $c[0]['type'] === 'line' && $c[0]['values'] === array_map(static fn($d) => (int)$d['n'], $days), json_encode([$c[0]['values'] ?? null]));
check('attendance labels like "01 Oct", oldest first', $days === [] || $c[0]['labels'][0] === (new DateTimeImmutable($days[0]['d']))->format('d M'));
$net = static fn(int $m) => (int)floor((float)qv('SELECT COALESCE(SUM(p.netPay), 0) FROM Payslip p JOIN PayrollRun r ON r.id = p.payrollRunId WHERE r.companyId = ? AND r.month = ? AND r.year = 2026', [$MW, $m]) / 1000 + 0.5);
check("payroll trend: Jul/Aug '26 net in ₹k", $c[1]['labels'] === ["Jul '26", "Aug '26"] && $c[1]['values'] === [$net(7), $net(8)] && $c[1]['unit'] === 'k');
$depts = q('SELECT d.name, (SELECT COUNT(*) FROM Employee e WHERE e.departmentId = d.id) AS n FROM Department d WHERE d.companyId = ? ORDER BY n DESC, d.name', [$MW]);
check('department bar is horizontal, sorted by headcount', $c[2]['horizontal'] === true && $c[2]['labels'] === array_column($depts, 'name') && $c[2]['values'] === array_map('intval', array_column($depts, 'n')));

section('Latest six payroll runs (fix)');
foreach ([[1, 2025], [2, 2025], [3, 2025], [4, 2025], [5, 2025], [6, 2025], [9, 2025]] as $i => [$m, $y]) {
    q("INSERT INTO PayrollRun (id, companyId, month, year, status) VALUES (?, ?, ?, ?, 'LOCKED')", ['ctestpayrollrun00000000' . $i . 'x', $MW, $m, $y]);
}
$c = charts($clients['hr']->get('/dashboard/reports'));
check('chart shows the latest six runs, oldest → newest', $c[1]['labels'] === ["Apr '25", "May '25", "Jun '25", "Sep '25", "Jul '26", "Aug '26"], json_encode($c[1]['labels']));
check('subtitle says six runs; run count is the true total (9)', $clients['hr']->last->has('Across the last 6 processed run(s)') && (bool)preg_match('#Payroll runs</p><p[^>]*>9</p>#', $clients['hr']->last->body));
q("DELETE FROM PayrollRun WHERE id LIKE 'ctestpayrollrun%'");

section('Tenant isolation');
$nw = Client::as('admin@northwindretail.com');
$r = $nw->get('/dashboard/reports');
check('Northwind sees its own (empty) figures', $r->status === 200 && (bool)preg_match('#Active employees</p><p[^>]*>0</p>#', $r->body) && (bool)preg_match('#Payroll runs</p><p[^>]*>0</p>#', $r->body));
check('Northwind gets empty states, no Meridian chart data', $r->has('No data yet.') && $r->has('Run payroll to see this trend.') && !str_contains(json_encode(charts($r)), 'Engineering'));
$csv = $nw->get('/api/reports/employees');
check('Northwind employees CSV has no Meridian rows', $csv->status === 200 && !str_contains($csv->body, 'meridianworks.com'));
check('Northwind payroll CSV → 404 (no runs)', $nw->get('/api/reports/payroll')->status === 404);

section('CSV exports');
$csv = $clients['hr']->get('/api/reports/employees');
check('employees CSV served as attachment', $csv->status === 200 && str_contains((string)$csv->header('content-type'), 'text/csv') && str_contains((string)$csv->header('content-disposition'), 'attachment'));
check('employees CSV lists Meridian staff only', str_contains($csv->body, 'employee@meridianworks.com') && !str_contains($csv->body, 'northwindretail.com'));
$csv = $clients['payroll']->get('/api/reports/payroll');
check('latest payroll CSV for Payroll Admin', $csv->status === 200 && str_contains((string)$csv->header('content-type'), 'text/csv'));
check('payroll CSV denied to Recruiter', $clients['recruiter']->get('/api/reports/payroll')->status === 401);
check('payroll CSV denied to Employee', $clients['employee']->get('/api/reports/payroll')->status === 401);

section('Module override');
q("INSERT INTO RolePermission (id, role, module, action, allowed, companyId) VALUES ('ctestroleperm00000000001', 'MANAGER', '/dashboard/reports', 'VIEW', 0, ?)", [$MW]);
check('company override revokes Reports for Manager', $clients['manager']->get('/dashboard/reports')->location === BASE . '/dashboard');
check('nav no longer shows Reports for Manager', !$clients['manager']->get('/dashboard')->has('href="/dashboard/reports"'));
q("DELETE FROM RolePermission WHERE id = 'ctestroleperm00000000001'");
check('restored after the override is removed', $clients['manager']->get('/dashboard/reports')->status === 200);

finish();
