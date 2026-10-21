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
            c.name AS companyName, c.address AS companyAddress, c.logoUrl, d.name AS departmentName,
            e.bankName, e.bankAccountNumber, br.city AS branchCity
       FROM Payslip p JOIN PayrollRun r ON r.id = p.payrollRunId JOIN Employee e ON e.id = p.employeeId
       JOIN Company c ON c.id = e.companyId LEFT JOIN Department d ON d.id = e.departmentId
       LEFT JOIN Branch br ON br.id = e.branchId
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


$na = static fn($v) => ($v === null || trim((string)$v) === '') ? 'N/A' : (string)$v;
$bank = trim(($p['bankName'] ?? '') . ' ' . ($p['bankAccountNumber'] ? '- ' . $p['bankAccountNumber'] : ''), ' -');
$info = static fn(string $l, string $v) => '<tr><td class="l">' . e($l) . '</td><td class="c">:</td><td>' . e($v) . '</td></tr>';

// Earnings / deductions rows; zero-value optional components are skipped like the sample slip.
$earn = [['BASIC', 'basic', true], ['House Rent Allowance', 'hra', false], ['Conveyance Allowance', 'conveyance', false],
    ['Medical Allowance', 'medicalAllowance', false], ['Special Allowance', 'specialAllowance', false]];
$ded = [['Provident Fund', 'pfEmployee', true], ['ESI', 'esi', false], ['Profession Tax', 'professionalTax', true],
    ['TDS', 'tds', false], ['Loss of Pay', 'lopDeduction', false]];
$rows = static function (array $defs, array $b): array {
    $out = [];
    foreach ($defs as [$label, $key, $always]) {
        if ($always || (float)$b[$key] > 0) {
            $out[] = [$label, (float)$b[$key]];
        }
    }
    return $out;
};
$E = $rows($earn, $b);
$D = $rows($ded, $b);
$n = max(count($E), count($D));
$amt = static fn(float $v) => number_format($v, 2, '.', '');

document_open('Payslip — ' . $period, '');
?>
<style>
    .ps{max-width:760px;margin:0 auto;padding:32px 24px;font-family:Verdana,Arial,sans-serif;font-size:13px;color:#000;background:#fff}
    .ps table{width:100%;border-collapse:collapse}
    .ps .box{border:2px solid #222}
    .ps .info td{padding:2px 6px;vertical-align:top}
    .ps .info .l{width:110px}.ps .info .c{width:14px}
    .ps .tbl{margin-top:18px;border:2px solid #222}
    .ps .tbl th{text-align:left;padding:6px;border-bottom:2px solid #222}
    .ps .tbl td{padding:2px 6px}
    .ps .r{text-align:right}
    .ps .half{width:25%}.ps .name{width:25%}
    .ps .vsep{border-left:2px solid #222}
    .ps .tot td{border-top:2px solid #222;font-weight:700;padding:6px}
    .ps .net{border:2px solid #222;border-top:0;font-weight:700;font-size:16px;padding:8px 6px;display:flex;justify-content:flex-end;gap:12px}
    .ps .net .v{width:20%;text-align:right}
    .ps .net .k{width:30%}
    @media print{.noprint{display:none}}
</style>
<div class="ps">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:22px">
        <div>
            <div style="font-size:17px;font-weight:700"><?= e($p['companyName']) ?></div>
            <?php if (!empty($p['companyAddress'])): ?><div style="max-width:420px"><?= e($p['companyAddress']) ?></div><?php endif; ?>
            <div style="margin-top:2px">Payslip for the month of : <?= e(substr(month_name((int)$p['month']), 0, 3) . '-' . $p['year']) ?></div>
        </div>
        <?php if (!empty($p['logoUrl'])): ?><img src="<?= e($p['logoUrl']) ?>" alt="" style="max-height:48px;max-width:160px"><?php endif; ?>
    </div>
    <div class="box"><table class="info"><tr>
        <td style="width:50%;border-right:2px solid #222"><table class="info">
            <?= $info('Employee Code', $p['employeeCode']) ?>
            <?= $info('Name', $p['firstName'] . ' ' . $p['lastName']) ?>
            <?= $info('Designation', $p['designation']) ?>
            <?= $info('Department', (string)($p['departmentName'] ?? '')) ?>
            <?= $info('Location', (string)($p['branchCity'] ?? '')) ?>
            <?= $info('Paid Days', js_num($p['paidDays']) . ' / ' . $p['workingDays']) ?>
            <?php if ((float)$p['lopDays'] > 0): ?><?= $info('Loss of Pay', js_num($p['lopDays']) . ' day(s)') ?><?php endif; ?>
        </table></td>
        <td style="width:50%"><table class="info">
            <?= $info('PF Account Number', '') ?>
            <?= $info('UAN', '') ?>
            <?= $info('PAN Number', '') ?>
            <?= $info('Bank Detail', $na($bank)) ?>
            <?= $info('ESI Number', 'N/A') ?>
        </table></td>
    </tr></table></div>

    <div class="tbl"><table>
        <tr><th style="width:30%">Earnings</th><th class="r" style="width:20%">Amount(Rs)</th>
            <th class="vsep" style="width:30%">Deductions</th><th class="r" style="width:20%">Amount(Rs)</th></tr>
        <?php for ($i = 0; $i < $n; $i++): ?>
        <tr>
            <td><?= e($E[$i][0] ?? '') ?></td><td class="r"><?= isset($E[$i]) ? e($amt($E[$i][1])) : '' ?></td>
            <td class="vsep"><?= e($D[$i][0] ?? '') ?></td><td class="r"><?= isset($D[$i]) ? e($amt($D[$i][1])) : '' ?></td>
        </tr>
        <?php endfor; ?>
        <tr class="tot"><td>Total(Rs.)</td><td class="r"><?= e($amt((float)$p['grossPay'])) ?></td>
            <td class="vsep">Total(Rs.)</td><td class="r"><?= e($amt((float)$p['totalDeductions'])) ?></td></tr>
    </table></div>
    <div class="net"><span class="k" style="margin-right:auto;width:auto;padding-left:50%">Net Amount :</span><span class="v"><?= e($amt((float)$p['netPay'])) ?></span></div>

    <?php if ((int)$p['isHeld']): ?>
        <div style="margin-top:16px;padding:12px 16px;background:#fdecea;border:1px solid #d9534f;border-radius:6px;color:#a02a20">
            <strong>Payment on hold.</strong> This amount has been calculated but is not being disbursed<?= $p['holdReason'] ? ' — ' . e($p['holdReason']) : '.' ?> Contact HR for details.
        </div>
    <?php endif; ?>
    <p style="margin-top:22px;font-size:12px"><strong>Note :</strong><br>"This payslip is computer generated, hence no signature is required"</p>
    <p class="noprint" style="margin-top:28px;font-size:12px;color:#666">Use your browser's Print (Ctrl/Cmd+P) → Save as PDF to keep a copy of this payslip.</p>
</div>
<?php document_close();
