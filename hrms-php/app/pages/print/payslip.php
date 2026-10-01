<?php
/**
 * /payslips/{id} — port of app/payslips/[id]/page.tsx. Print-friendly
 * payslip (browser Print → Save as PDF, as the original). An employee sees
 * only their own, and only once the run is LOCKED; payroll staff see any
 * payslip in their company.
 */
declare(strict_types=1);

const PAYSLIP_STAFF = ['COMPANY_ADMIN', 'HR', 'PAYROLL_ADMIN', 'FINANCE'];

$p = db_one(
    'SELECT p.*, r.month, r.year, r.status AS runStatus, e.firstName, e.lastName, e.employeeCode, e.designation,
            c.name AS companyName, d.name AS departmentName
       FROM Payslip p JOIN PayrollRun r ON r.id = p.payrollRunId JOIN Employee e ON e.id = p.employeeId
       JOIN Company c ON c.id = e.companyId LEFT JOIN Department d ON d.id = e.departmentId
      WHERE p.id = ? AND e.companyId = ?',
    [$params['id'], $auth['companyId'] ?? '']
);
if ($p === null) {
    throw new HttpError(404);
}
$isSelf = $auth['employeeId'] === $p['employeeId'];
$isStaff = has_role($auth, PAYSLIP_STAFF);
if ((!$isSelf && !$isStaff) || ($isSelf && !$isStaff && $p['runStatus'] !== 'LOCKED')) {
    throw new HttpError(404);
}
$b = (json_decode((string)$p['breakdown'], true) ?: []) + ['basic' => 0, 'hra' => 0, 'conveyance' => 0, 'medicalAllowance' => 0, 'specialAllowance' => 0,
    'pfEmployee' => 0, 'esi' => 0, 'professionalTax' => 0, 'tds' => 0, 'lopDays' => 0, 'lopDeduction' => 0];
$period = month_name((int)$p['month']) . ' ' . $p['year'];

$row = static fn(string $l, string $v) => '<tr><td style="padding:3px 0;color:#666;width:140px">' . e($l) . '</td><td style="padding:3px 0">' . e($v) . '</td></tr>';
$line = static fn(string $l, float $v, bool $bold = false) =>
    '<div style="display:flex;justify-content:space-between;font-size:14px;padding:4px 0;font-weight:' . ($bold ? 700 : 400) . ';'
    . ($bold ? 'border-top:1px solid #ccc;margin-top:4px' : '') . '"><span>' . e($l) . '</span><span>' . e(rupees($v)) . '</span></div>';

document_open('Payslip — ' . $period, '');
?>
<div style="background:#fff;color:#1a1a1a;font-family:Georgia,serif;min-height:100vh">
    <div style="max-width:640px;margin:0 auto;padding:48px 56px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1a1a1a;padding-bottom:16px;margin-bottom:24px">
            <div>
                <h1 style="font-size:20px;margin:0;font-family:Georgia,serif"><?= e($p['companyName']) ?></h1>
                <p style="font-size:13px;color:#666;margin:4px 0 0">Payslip — <?= e($period) ?></p>
            </div>
            <p style="font-size:12px;color:#666;margin:0">Generated <?= e(now()->format('d M Y')) ?></p>
        </div>
        <table style="width:100%;font-size:14px;margin-bottom:28px;border-collapse:collapse"><tbody>
            <?= $row('Employee', $p['firstName'] . ' ' . $p['lastName']) ?>
            <?= $row('Employee code', $p['employeeCode']) ?>
            <?= $row('Designation', $p['designation']) ?>
            <?= $row('Department', $p['departmentName'] ?? DASH) ?>
            <?= $row('Paid days', js_num($p['paidDays']) . ' / ' . $p['workingDays']) ?>
            <?php if ((float)$p['lopDays'] > 0): ?><?= $row('Loss of pay', js_num($p['lopDays']) . ' day(s)') ?><?php endif; ?>
        </tbody></table>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px">
            <div>
                <p style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#666;margin-bottom:8px">Earnings</p>
                <?= $line('Basic', (float)$b['basic']) ?><?= $line('HRA', (float)$b['hra']) ?><?= $line('Conveyance', (float)$b['conveyance']) ?>
                <?= $line('Medical allowance', (float)$b['medicalAllowance']) ?><?= $line('Special allowance', (float)$b['specialAllowance']) ?>
                <?= $line('Gross pay', (float)$p['grossPay'], true) ?>
            </div>
            <div>
                <p style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#666;margin-bottom:8px">Deductions</p>
                <?= $line('Provident Fund', (float)$b['pfEmployee']) ?><?= $line('ESI', (float)$b['esi']) ?><?= $line('Professional Tax', (float)$b['professionalTax']) ?><?= $line('TDS', (float)$b['tds']) ?>
                <?php if ((float)$b['lopDeduction'] > 0): ?><?= $line('Loss of pay', (float)$b['lopDeduction']) ?><?php endif; ?>
                <?= $line('Total deductions', (float)$p['totalDeductions'], true) ?>
            </div>
        </div>
        <div style="margin-top:28px;padding-top:16px;border-top:2px solid #1a1a1a;display:flex;justify-content:space-between;align-items:baseline">
            <span style="font-size:15px;font-weight:700">Net pay</span>
            <span style="font-size:22px;font-weight:700"><?= e(rupees((float)$p['netPay'])) ?></span>
        </div>
        <?php if ((int)$p['isHeld']): ?>
            <div style="margin-top:16px;padding:12px 16px;background:#fdecea;border:1px solid #d9534f;border-radius:6px;font-size:13px;color:#a02a20">
                <strong>Payment on hold.</strong> This amount has been calculated but is not being disbursed<?= $p['holdReason'] ? ' — ' . e($p['holdReason']) : '.' ?> Contact HR for details.
            </div>
        <?php endif; ?>
        <p style="margin-top:40px;font-size:13px;color:#666">Use your browser's Print (Ctrl/Cmd+P) → Save as PDF to keep a copy of this payslip.</p>
    </div>
</div>
<?php document_close();
