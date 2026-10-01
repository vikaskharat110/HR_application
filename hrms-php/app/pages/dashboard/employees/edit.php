<?php
/** /dashboard/employees/{id}/edit — port of app/dashboard/employees/[id]/edit/page.tsx */
declare(strict_types=1);

$id = $params['id'];
$co = $auth['companyId'];
$employee = db_one('SELECT * FROM Employee WHERE id = ? AND companyId = ?', [$id, $co]);
if ($employee === null) {
    throw new HttpError(404);
}
handle_post([
    'update' => fn() => action_update_employee($auth, $id, $_POST),
]);

$departments = db_all('SELECT id, name FROM Department WHERE companyId = ? ORDER BY name', [$co]);
$branches = db_all('SELECT id, name FROM Branch WHERE companyId = ? ORDER BY name', [$co]);
$managers = db_all("SELECT e.id, e.firstName, e.lastName FROM Employee e JOIN `User` u ON u.id = e.userId WHERE e.companyId = ? AND e.id <> ? AND u.role = 'MANAGER' ORDER BY e.firstName", [$co, $id]);
// Keep the current manager selectable even if they aren't a MANAGER-role login,
// so re-saving without touching the field never silently reassigns them.
if ($employee['managerId'] && !in_array($employee['managerId'], array_column($managers, 'id'), true)) {
    if ($cur = db_one('SELECT id, firstName, lastName FROM Employee WHERE id = ?', [$employee['managerId']])) {
        $managers[] = $cur;
        usort($managers, static fn($a, $b) => strcmp($a['firstName'], $b['firstName']));
    }
}
$v = static fn(string $k) => old($k, $employee[$k] ?? '');

app_layout_open($auth, 'Edit employee');
?>
<div class="max-w-2xl">
    <?= back_link("/dashboard/employees/$id", 'Back to profile') ?>
    <?= page_header('Edit ' . $employee['firstName'] . ' ' . $employee['lastName'], $employee['employeeCode']) ?>
    <?= flash_alerts('employee') ?>
    <form method="post" class="card p-6 space-y-5">
        <?= form_fields('update', 'employee') ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="field-label">First name</label><input name="firstName" required maxlength="191" value="<?= e($v('firstName')) ?>" class="input"></div>
            <div><label class="field-label">Last name</label><input name="lastName" required maxlength="191" value="<?= e($v('lastName')) ?>" class="input"></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="field-label">Work email</label><input name="email" type="email" required maxlength="191" value="<?= e($v('email')) ?>" class="input"></div>
            <div><label class="field-label">Phone</label><input name="phone" maxlength="50" value="<?= e($v('phone')) ?>" class="input"></div>
        </div>
        <div><label class="field-label">Designation</label><input name="designation" required maxlength="191" value="<?= e($v('designation')) ?>" class="input"></div>
        <div class="grid sm:grid-cols-3 gap-4">
            <div>
                <label class="field-label">Department</label>
                <select name="departmentId" class="input"><option value="">— None —</option>
                    <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], $v('departmentId')) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Branch</label>
                <select name="branchId" class="input"><option value="">— None —</option>
                    <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= selected($b['id'], $v('branchId')) ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Reporting manager</label>
                <select name="managerId" class="input"><option value="">— None —</option>
                    <?php foreach ($managers as $m): ?><option value="<?= e($m['id']) ?>"<?= selected($m['id'], $v('managerId')) ?>><?= e($m['firstName'] . ' ' . $m['lastName']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div>
            <label class="field-label">Employment type</label>
            <select name="employmentType" class="input max-w-xs">
                <?php foreach (['FULL_TIME' => 'Full time', 'PART_TIME' => 'Part time', 'CONTRACT' => 'Contract', 'INTERN' => 'Intern'] as $val => $l): ?>
                    <option value="<?= $val ?>"<?= selected($val, $v('employmentType')) ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="text-xs text-muted">Employee code, date of joining, and status aren’t edited here — status changes from the profile page.</p>
        <div class="flex justify-end gap-2 pt-1">
            <a href="<?= e(url("/dashboard/employees/$id")) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</div>
<?php app_layout_close();
