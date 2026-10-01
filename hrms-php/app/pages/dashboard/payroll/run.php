<?php
/** /dashboard/payroll/{runId} — port of app/dashboard/payroll/[runId]/page.tsx */
declare(strict_types=1);

$runId = $params['runId'];
$run = db_one('SELECT * FROM PayrollRun WHERE id = ? AND companyId = ?', [$runId, $auth['companyId']]);
if ($run === null) {
    throw new HttpError(404);
}
handle_post([
    'approve' => function () use ($auth, $runId) {
        action_approve_payroll_run($auth, $runId);
        return null;
    },
    'lock' => function () use ($auth, $runId) {
        action_lock_payroll_run($auth, $runId);
        return null;
    },
    'reopen' => function () use ($auth, $runId) {
        action_reopen_payroll_run($auth, $runId, post_raw('reason'));
        return null;
    },
    'hold' => function () use ($auth) {
        action_set_payslip_hold($auth, post_id('payslipId'), post('held') === '1', post('reason'));
        return null;
    },
]);

$payslips = db_all(
    'SELECT p.*, e.firstName, e.lastName, e.avatarColor FROM Payslip p JOIN Employee e ON e.id = p.employeeId
      WHERE p.payrollRunId = ? ORDER BY e.firstName, e.lastName',
    [$runId]
);
$canApprove = has_payroll_action($auth, 'APPROVE');
$canLock = has_payroll_action($auth, 'LOCK');
$isCompanyAdmin = $auth['role'] === 'COMPANY_ADMIN';
$canHold = has_role($auth, SALARY_HOLD_ROLES);
$totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'held' => 0.0];
foreach ($payslips as $p) {
    $totals['gross'] += $p['grossPay'];
    $totals['deductions'] += $p['totalDeductions'];
    $totals[(int)$p['isHeld'] ? 'held' : 'net'] += $p['netPay'];
}
$showHold = $canHold && $run['status'] !== 'LOCKED';

$actions = '<div class="flex items-center gap-2">' . status_badge($run['status']);
if ($run['status'] === 'PROCESSED' && $canApprove) {
    $actions .= '<form method="post">' . form_fields('approve') . '<button class="btn btn-primary">' . icon('circle-check', 15) . ' Approve</button></form>';
}
if ($run['status'] === 'APPROVED' && $canLock) {
    $actions .= '<form method="post">' . form_fields('lock') . '<button class="btn btn-primary">' . icon('lock', 15) . ' Lock &amp; publish</button></form>';
}
if ($run['status'] === 'LOCKED') {
    $actions .= '<a href="' . e(url('/api/reports/payroll?runId=' . $runId . '&format=bank-file')) . '" class="btn btn-secondary">' . icon('download', 15) . ' Bank file</a>';
}
$actions .= '</div>';

app_layout_open($auth, month_name((int)$run['month']) . ' ' . $run['year'] . ' payroll');
echo back_link('/dashboard/payroll', 'Back to payroll');
echo page_header(month_name((int)$run['month']) . ' ' . $run['year'] . ' payroll',
    count($payslips) . ' employees · Preview → Approval → Lock' . ($run['payDate'] ? ' · Salary date ' . format_date($run['payDate']) : ''), $actions);
?>
<?php if ($run['reopenReason']): ?>
    <div class="card p-4 mb-5 text-sm text-warning bg-[var(--color-warning-soft)] border-none">Reopened — reason on record: “<?= e($run['reopenReason']) ?>”</div>
<?php endif; ?>
<div class="grid sm:grid-cols-4 gap-4 mb-6">
    <?php foreach (['Gross' => 'gross', 'Deductions' => 'deductions', 'Net paid' => 'net'] as $label => $k): ?>
        <div class="stat-card"><p class="text-xs font-semibold uppercase text-muted"><?= $label ?></p><p class="font-display text-xl font-semibold text-ink mt-1"><?= e(format_currency($totals[$k])) ?></p></div>
    <?php endforeach; ?>
    <div class="stat-card"><p class="text-xs font-semibold uppercase text-muted">On hold</p><p class="font-display text-xl font-semibold text-danger mt-1"><?= e(format_currency($totals['held'])) ?></p></div>
</div>
<div class="card overflow-x-auto mb-6">
    <table class="table-clean">
        <thead><tr><th>Employee</th><th>Gross</th><th>Deductions</th><th>LOP days</th><th>Net pay</th><th></th><?= $showHold ? '<th></th>' : '' ?></tr></thead>
        <tbody>
        <?php foreach ($payslips as $p): ?>
            <tr>
                <td class="flex items-center gap-2.5 py-2.5">
                    <?= avatar($p['firstName'], $p['lastName'], $p['avatarColor']) ?> <?= e($p['firstName'] . ' ' . $p['lastName']) ?>
                    <?php if ((int)$p['isHeld']): ?><span class="badge badge-danger" title="<?= e($p['holdReason'] ?? '') ?>">On hold</span><?php endif; ?>
                </td>
                <td><?= e(format_currency((float)$p['grossPay'])) ?></td>
                <td><?= e(format_currency((float)$p['totalDeductions'])) ?></td>
                <td><?= e(js_num($p['lopDays'])) ?></td>
                <td class="font-semibold text-ink"><?= e(format_currency((float)$p['netPay'])) ?></td>
                <td><a href="<?= e(url('/payslips/' . $p['id'])) ?>" target="_blank" rel="noopener" class="text-brand text-sm font-medium hover:underline">Download</a></td>
                <?php if ($showHold): ?>
                    <td>
                        <?php if ((int)$p['isHeld']): ?>
                            <form method="post"><?= form_fields('hold') ?><input type="hidden" name="payslipId" value="<?= e($p['id']) ?>"><input type="hidden" name="held" value="0">
                                <button class="btn btn-secondary !py-1.5 !px-2.5 text-sm" title="Release hold"><?= icon('circle-play', 14) ?> Release</button></form>
                        <?php else: ?>
                            <details>
                                <summary class="btn btn-secondary !py-1.5 !px-2.5 text-sm cursor-pointer list-none inline-flex whitespace-nowrap"><?= icon('circle-pause', 14) ?> Hold</summary>
                                <form method="post" class="mt-2 w-56 space-y-2"><?= form_fields('hold') ?><input type="hidden" name="payslipId" value="<?= e($p['id']) ?>"><input type="hidden" name="held" value="1">
                                    <input name="reason" required maxlength="1000" placeholder="Reason for hold" class="input text-sm">
                                    <button type="submit" class="btn btn-primary w-full justify-center text-sm">Confirm hold</button></form>
                            </details>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if ($isCompanyAdmin && $run['status'] === 'LOCKED'): ?>
    <div class="card p-5">
        <p class="text-sm font-semibold text-ink mb-1">Reopen this run</p>
        <p class="text-xs text-muted mb-3">A locked run can only be changed after it's reopened — the reason is recorded in the audit trail.</p>
        <form method="post" class="flex flex-wrap items-end gap-3">
            <?= form_fields('reopen') ?>
            <div class="flex-1 min-w-[240px]"><label class="field-label">Reason</label><input name="reason" required maxlength="2000" class="input" placeholder="e.g. LOP correction for Rohan Verma"></div>
            <button type="submit" class="btn btn-secondary"><?= icon('rotate-ccw', 15) ?> Reopen</button>
        </form>
    </div>
<?php endif; ?>
<p class="text-xs text-muted mt-4">Processed <?= e(format_date($run['processedAt'])) ?><?= $run['approvedAt'] ? ' · Approved ' . e(format_date($run['approvedAt'])) : '' ?><?= $run['lockedAt'] ? ' · Locked ' . e(format_date($run['lockedAt'])) : '' ?></p>
<?php app_layout_close();
