<?php
/** /dashboard/payroll — port of app/dashboard/payroll/page.tsx + RunPayrollButton */
declare(strict_types=1);

handle_post([
    'run' => fn() => action_run_payroll($auth, post('month'), post('year'), post('payDate')),
]);

const PAYROLL_PAGE_SIZE = 12;
$canView = has_role($auth, ['COMPANY_ADMIN', 'HR', 'PAYROLL_ADMIN', 'FINANCE']);
$canProcess = has_payroll_action($auth, 'PROCESS');
$canEditSalary = has_payroll_action($auth, 'EDIT_SALARY');
$managerial = is_managerial($auth) || has_role($auth, ['PAYROLL_ADMIN', 'FINANCE']);
$page = query_page();
$now = now();

$runTotal = $canView ? (int)db_value('SELECT COUNT(*) FROM PayrollRun WHERE companyId = ?', [$auth['companyId']]) : 0;
$runs = $canView ? db_all(
    'SELECT r.*, (SELECT COUNT(*) FROM Payslip p WHERE p.payrollRunId = r.id) AS slips FROM PayrollRun r
      WHERE r.companyId = ? ORDER BY r.year DESC, r.month DESC LIMIT ' . PAYROLL_PAGE_SIZE . ' OFFSET ' . (($page - 1) * PAYROLL_PAGE_SIZE),
    [$auth['companyId']]
) : [];
// Employees see their payslips only once the run is locked (published).
$myPayslips = $auth['employeeId'] ? db_all(
    "SELECT p.*, r.month, r.year FROM Payslip p JOIN PayrollRun r ON r.id = p.payrollRunId
      WHERE p.employeeId = ? AND r.status = 'LOCKED' ORDER BY p.generatedAt DESC",
    [$auth['employeeId']]
) : [];
$totalPages = max(1, (int)ceil($runTotal / PAYROLL_PAGE_SIZE));

$actions = '';
if ($canView) {
    $actions = '<div class="flex gap-2 items-end flex-wrap">';
    if ($canEditSalary) {
        $actions .= '<a href="' . e(url('/dashboard/payroll/salary')) . '" class="btn btn-secondary">' . icon('settings-2', 15) . ' Salary structures</a>';
    }
    if ($canProcess) {
        $m = (int)$now->format('n');
        $y = (int)$now->format('Y');
        $actions .= '<div><form method="post" class="flex items-end gap-2" data-pending-form>' . form_fields('run', 'run')
            . '<input type="hidden" name="month" value="' . $m . '"><input type="hidden" name="year" value="' . $y . '">'
            . '<div><label class="field-label">Salary date</label><input type="date" name="payDate" required value="' . e($now->modify('last day of this month')->format('Y-m-d')) . '" class="input"></div>'
            . '<button type="submit" class="btn btn-primary disabled:opacity-70" data-pending-label="Running…">' . icon('play', 15) . ' Run payroll — ' . e(month_name($m)) . '</button></form>'
            . flash_alerts('run') . '</div>';
    }
    $actions .= '</div>';
}

app_layout_open($auth, 'Payroll');
echo page_header('Payroll', $canView ? 'Run monthly payroll and manage salary structures.' : 'Your payslip history.', $actions);
?>
<?php if ($canView): ?>
    <div class="mb-6">
        <div class="card overflow-x-auto">
            <?php if (!$runs): ?>
                <?= empty_state('wallet', 'No payroll runs yet', 'Run payroll for the current month to get started.') ?>
            <?php else: ?>
                <table class="table-clean">
                    <thead><tr><th>Period</th><th>Employees paid</th><th>Status</th><th>Salary date</th><th>Processed</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($runs as $r): ?>
                        <tr>
                            <td class="font-medium text-ink"><?= e(month_name((int)$r['month']) . ' ' . $r['year']) ?></td>
                            <td><?= (int)$r['slips'] ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td><?= e($r['payDate'] ? format_date($r['payDate']) : DASH) ?></td>
                            <td><?= e(format_date($r['processedAt'])) ?></td>
                            <td><a href="<?= e(url('/dashboard/payroll/' . $r['id'])) ?>" class="text-brand text-sm font-medium hover:underline">View payslips</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?= pagination($page, $totalPages, '/dashboard/payroll') ?>
    </div>
<?php endif; ?>

<?php if (!$managerial): ?>
    <div class="card overflow-x-auto">
        <p class="px-5 pt-5 text-sm font-semibold text-ink">My payslips</p>
        <?php if (!$myPayslips): ?>
            <?= empty_state('wallet', 'No payslips yet', 'Your payslips will appear here once payroll is processed.') ?>
        <?php else: ?>
            <table class="table-clean mt-2">
                <thead><tr><th>Period</th><th>Gross</th><th>Deductions</th><th>Net pay</th><th>Paid days</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($myPayslips as $p): ?>
                    <tr>
                        <td class="font-medium text-ink"><?= e(month_name((int)$p['month']) . ' ' . $p['year']) ?></td>
                        <td><?= e(format_currency((float)$p['grossPay'])) ?></td>
                        <td><?= e(format_currency((float)$p['totalDeductions'])) ?></td>
                        <td class="font-semibold text-ink"><?= e(format_currency((float)$p['netPay'])) ?><?php if ((int)$p['isHeld']): ?><span class="badge badge-danger ml-2">On hold</span><?php endif; ?></td>
                        <td><?= e(js_num($p['paidDays'])) ?> / <?= (int)$p['workingDays'] ?></td>
                        <td><a href="<?= e(url('/payslips/' . $p['id'])) ?>" target="_blank" rel="noopener" class="text-brand text-sm font-medium hover:underline">Download</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php app_layout_close();
