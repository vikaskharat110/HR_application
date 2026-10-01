<?php
/** /dashboard/employees/{id}/promote — port of app/dashboard/employees/[id]/promote/page.tsx */
declare(strict_types=1);

$id = $params['id'];
$co = $auth['companyId'];
$employee = db_one('SELECT e.*, s.basic FROM Employee e LEFT JOIN SalaryStructure s ON s.employeeId = e.id WHERE e.id = ? AND e.companyId = ?', [$id, $co]);
if ($employee === null) {
    throw new HttpError(404);
}
handle_post([
    'request' => fn() => action_request_promotion_transfer($auth, ['employeeId' => $id] + $_POST),
]);
$departments = db_all('SELECT id, name FROM Department WHERE companyId = ? ORDER BY name', [$co]);
$branches = db_all('SELECT id, name FROM Branch WHERE companyId = ? ORDER BY name', [$co]);
$managers = db_all('SELECT id, firstName, lastName FROM Employee WHERE companyId = ? AND id <> ? ORDER BY firstName', [$co, $id]);

app_layout_open($auth, 'Promote / Transfer');
?>
<div class="max-w-2xl">
    <?= back_link("/dashboard/employees/$id", 'Back to profile') ?>
    <?= page_header('Promote / Transfer — ' . $employee['firstName'] . ' ' . $employee['lastName'],
        'Leave a field unchanged to keep the employee\'s current value. Goes to their manager (or HR/Admin) for approval before anything changes.') ?>
    <?= flash_alerts('promote') ?>
    <form method="post" class="card p-6 space-y-5">
        <?= form_fields('request', 'promote') ?>
        <div>
            <label class="field-label">Type</label>
            <select name="type" class="input max-w-xs">
                <?php foreach (['PROMOTION' => 'Promotion', 'TRANSFER' => 'Transfer', 'BOTH' => 'Both'] as $v => $l): ?>
                    <option value="<?= $v ?>"<?= selected($v, old('type', 'PROMOTION')) ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label">New designation <span class="text-muted font-normal">(current: <?= e($employee['designation']) ?>)</span></label>
            <input name="newDesignation" maxlength="191" value="<?= e(old('newDesignation')) ?>" placeholder="Leave blank for no change" class="input">
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
            <div>
                <label class="field-label">New department</label>
                <select name="newDepartmentId" class="input"><option value="">No change</option>
                    <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], old('newDepartmentId')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">New branch</label>
                <select name="newBranchId" class="input"><option value="">No change</option>
                    <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= selected($b['id'], old('newBranchId')) ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">New manager</label>
                <select name="newManagerId" class="input"><option value="">No change</option>
                    <?php foreach ($managers as $m): ?><option value="<?= e($m['id']) ?>"<?= selected($m['id'], old('newManagerId')) ?>><?= e($m['firstName'] . ' ' . $m['lastName']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div>
            <label class="field-label">New basic salary <span class="text-muted font-normal">(current: <?= e($employee['basic'] !== null ? rupees((float)$employee['basic']) : 'not set') ?>)</span></label>
            <input name="newBasic" type="number" min="0" step="any" value="<?= e(old('newBasic')) ?>" placeholder="Leave blank for no change" class="input max-w-xs">
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="field-label">Effective date</label><input name="effectiveDate" type="date" required value="<?= e(old('effectiveDate')) ?>" class="input"></div>
        </div>
        <div><label class="field-label">Reason</label><textarea name="reason" required rows="3" maxlength="5000" class="input"><?= e(old('reason')) ?></textarea></div>
        <div class="flex justify-end gap-2 pt-1">
            <a href="<?= e(url("/dashboard/employees/$id")) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Submit for approval</button>
        </div>
    </form>
</div>
<?php app_layout_close();
