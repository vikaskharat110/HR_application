<?php
/**
 * /dashboard/reports — port of app/dashboard/reports/page.tsx (company-wide MIS).
 * Fix: the original took the *first* six payroll runs (orderBy asc + take 6), so
 * the "last N runs" chart froze after six months and the run count capped at 6;
 * this shows the latest six (oldest → newest) and the true run count.
 */
declare(strict_types=1);

$companyId = $auth['companyId'];
$canSeePayroll = has_role($auth, ['COMPANY_ADMIN', 'HR', 'PAYROLL_ADMIN', 'FINANCE', 'AUDITOR', 'MANAGEMENT']);
$thirtyDaysAgo = now()->modify('-30 days');

$headcount = (int)db_value("SELECT COUNT(*) FROM Employee WHERE companyId = ? AND status = 'ACTIVE'", [$companyId]);
$leaveByStatus = [];
foreach (db_all('SELECT r.status, COUNT(*) AS c FROM LeaveRequest r JOIN Employee e ON e.id = r.employeeId WHERE e.companyId = ? GROUP BY r.status', [$companyId]) as $row) {
    $leaveByStatus[$row['status']] = (int)$row['c'];
}
$leaveTotal = array_sum($leaveByStatus);
$runCount = (int)db_value('SELECT COUNT(*) FROM PayrollRun WHERE companyId = ?', [$companyId]);
$pipelineTotal = (int)db_value('SELECT COUNT(*) FROM Candidate c JOIN JobPosting j ON j.id = c.jobPostingId WHERE j.companyId = ?', [$companyId]);

$attendanceTrend = array_map(
    static fn($r) => ['day' => format_day_month($r['d']), 'present' => (int)$r['c']],
    db_all(
        "SELECT DATE(a.date) AS d, COUNT(*) AS c FROM Attendance a JOIN Employee e ON e.id = a.employeeId
          WHERE e.companyId = ? AND a.date >= ? AND a.status IN ('PRESENT','LATE')
          GROUP BY DATE(a.date) ORDER BY d",
        [$companyId, $thirtyDaysAgo]
    )
);
$payrollData = array_map(
    static fn($r) => ['period' => substr(month_name((int)$r['month']), 0, 3) . " '" . substr((string)$r['year'], 2), 'net' => js_round((float)$r['net'] / 1000)],
    array_reverse(db_all(
        'SELECT r.month, r.year, COALESCE((SELECT SUM(p.netPay) FROM Payslip p WHERE p.payrollRunId = r.id), 0) AS net
           FROM PayrollRun r WHERE r.companyId = ? ORDER BY r.year DESC, r.month DESC LIMIT 6',
        [$companyId]
    ))
);
// As the original: every employee linked to the department is counted.
$deptData = array_map(
    static fn($r) => ['department' => $r['department'], 'headcount' => (int)$r['headcount']],
    db_all(
        'SELECT d.name AS department, (SELECT COUNT(*) FROM Employee e WHERE e.departmentId = d.id) AS headcount
           FROM Department d WHERE d.companyId = ? ORDER BY headcount DESC, d.name',
        [$companyId]
    )
);

$actions = '<div class="flex gap-2"><a href="' . e(url('/api/reports/employees')) . '" class="btn btn-secondary">' . icon('download', 15) . ' Employees CSV</a>'
    // Only offered once a payroll run exists (otherwise the export has nothing to download).
    . ($canSeePayroll && $runCount > 0 ? '<a href="' . e(url('/api/reports/payroll')) . '" class="btn btn-secondary">' . icon('download', 15) . ' Latest payroll CSV</a>' : '')
    . '</div>';
$noData = static fn(string $msg) => '<p class="text-sm text-muted py-10 text-center">' . e($msg) . '</p>';

app_layout_open($auth, 'Reports');
echo page_header('Reports', 'Company-wide MIS across attendance, leave, payroll and hiring.', $actions);
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?= stat_card('Active employees', $headcount, 'users', 'brand') ?>
    <?= stat_card('Leave requests (all-time)', $leaveTotal, 'calendar-clock', 'warning', ($leaveByStatus['PENDING'] ?? 0) . ' pending') ?>
    <?= stat_card('Payroll runs', $runCount, 'wallet', 'info') ?>
    <?= stat_card('Candidates in pipeline', $pipelineTotal, 'briefcase', 'accent') ?>
</div>

<div class="grid lg:grid-cols-2 gap-5 mb-5">
    <div class="card p-5">
        <p class="text-sm font-semibold text-ink mb-1">Attendance — last 30 days</p>
        <p class="text-xs text-muted mb-3">Present or late headcount per day, company-wide.</p>
        <?= $attendanceTrend ? chart_trend_line($attendanceTrend, 'present', 'day') : $noData('No data yet.') ?>
    </div>
    <div class="card p-5">
        <p class="text-sm font-semibold text-ink mb-1">Net payroll cost (₹ thousands)</p>
        <p class="text-xs text-muted mb-3">Across the last <?= count($payrollData) ?> processed run(s).</p>
        <?= $payrollData ? chart_trend_line($payrollData, 'net', 'period', 'k') : $noData('Run payroll to see this trend.') ?>
    </div>
</div>

<div class="card p-5">
    <p class="text-sm font-semibold text-ink mb-1">Headcount by department</p>
    <p class="text-xs text-muted mb-3">Active employees per department.</p>
    <?= $deptData ? chart_rank_bar($deptData, 'headcount', 'department', true) : $noData('No departments yet.') ?>
</div>
<?php app_layout_close();
