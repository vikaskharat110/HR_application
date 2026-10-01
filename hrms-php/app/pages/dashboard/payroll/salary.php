<?php
/** /dashboard/payroll/salary — port of app/dashboard/payroll/salary/page.tsx + SalaryStructureForm */
declare(strict_types=1);

if (!has_payroll_action($auth, 'EDIT_SALARY')) {
    redirect('/dashboard/payroll');
}
handle_post([
    'save' => function () use ($auth) {
        action_upsert_salary_structure($auth, $_POST);
        flash('success', 'Salary structure saved.', 'salary-' . post('employeeId'));
        return '/dashboard/payroll/salary#emp-' . post('employeeId');
    },
]);

$employees = db_all(
    "SELECT e.id, e.firstName, e.lastName, e.designation, s.basic, s.hra, s.conveyance, s.medicalAllowance, s.specialAllowance,
            s.pfEmployee, s.esi, s.professionalTax, s.tds, s.id AS salaryId
       FROM Employee e LEFT JOIN SalaryStructure s ON s.employeeId = e.id
      WHERE e.companyId = ? AND e.status = 'ACTIVE' ORDER BY e.firstName",
    [$auth['companyId']]
);
const SALARY_FIELDS = ['basic' => 'Basic', 'hra' => 'HRA', 'conveyance' => 'Conveyance', 'medicalAllowance' => 'Medical allowance', 'specialAllowance' => 'Special allowance'];

app_layout_open($auth, 'Salary structures');
echo back_link('/dashboard/payroll', 'Back to payroll');
echo page_header('Salary structures', 'Enter the CTC components below — PF, ESI, Professional Tax and TDS are calculated automatically from the company\'s statutory configuration (Settings → Statutory).');
?>
<div class="card divide-y divide-border-soft">
    <?php foreach ($employees as $e):
        $scope = 'salary-' . $e['id'];
        $alerts = flash_alerts($scope); ?>
        <details id="emp-<?= e($e['id']) ?>" class="group px-5 py-3.5"<?= $alerts !== '' ? ' open' : '' ?>>
            <summary class="flex items-center justify-between cursor-pointer list-none">
                <span class="text-sm font-medium text-ink"><?= e($e['firstName'] . ' ' . $e['lastName']) ?> <span class="text-muted font-normal">— <?= e($e['designation']) ?></span></span>
                <span class="text-sm text-brand"><?= $e['salaryId'] ? e(rupees((float)$e['basic'])) . ' basic' : 'Not set' ?></span>
            </summary>
            <form method="post" class="mt-4" data-salary-form>
                <?= form_fields('save', $scope) ?>
                <input type="hidden" name="employeeId" value="<?= e($e['id']) ?>">
                <div class="flex items-center gap-2 mb-3">
                    <label class="field-label mb-0">Enter figures as</label>
                    <select name="payPeriod" class="input w-auto! text-sm" data-pay-period>
                        <option value="MONTHLY">Monthly</option>
                        <option value="YEARLY">Yearly</option>
                    </select>
                </div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <?php foreach (SALARY_FIELDS as $name => $label): ?>
                        <div>
                            <label class="field-label"><?= e($label) ?> <span class="text-muted font-normal" data-period-label>(per month)</span></label>
                            <input type="number" name="<?= $name ?>" min="0" step="1" value="<?= $e['salaryId'] ? e(js_num($e[$name])) : '' ?>"
                                   data-monthly="<?= $e['salaryId'] ? e(js_num($e[$name])) : '' ?>" class="input">
                        </div>
                    <?php endforeach; ?>
                    <div class="flex items-end"><button type="submit" class="btn btn-primary w-full justify-center">Save &amp; recalculate</button></div>
                </div>
                <?= $alerts ?>
            </form>
            <?php if ($e['salaryId']): ?>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 border-t border-border-soft text-xs text-muted">
                    <span>PF (employee): <span class="text-ink font-medium"><?= e(format_currency((float)$e['pfEmployee'])) ?></span></span>
                    <span>ESI: <span class="text-ink font-medium"><?= e(format_currency((float)$e['esi'])) ?></span></span>
                    <span>Professional tax: <span class="text-ink font-medium"><?= e(format_currency((float)$e['professionalTax'])) ?></span></span>
                    <span>TDS: <span class="text-ink font-medium"><?= e(format_currency((float)$e['tds'])) ?></span></span>
                </div>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>
</div>
<?php app_layout_close();
