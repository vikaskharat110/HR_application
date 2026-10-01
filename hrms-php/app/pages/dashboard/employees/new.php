<?php
/** /dashboard/employees/new — port of app/dashboard/employees/new/page.tsx */
declare(strict_types=1);

handle_post([
    'create' => fn() => action_create_employee($auth, $_POST),
]);

$co = $auth['companyId'];
$departments = db_all('SELECT id, name FROM Department WHERE companyId = ? ORDER BY name', [$co]);
$branches = db_all('SELECT id, name FROM Branch WHERE companyId = ? ORDER BY name', [$co]);
// Only employees whose login role is MANAGER (incl. custom roles built on it) are pickable.
$managers = db_all("SELECT e.id, e.firstName, e.lastName FROM Employee e JOIN `User` u ON u.id = e.userId WHERE e.companyId = ? AND u.role = 'MANAGER' ORDER BY e.firstName", [$co]);
$customRoles = db_all('SELECT id, name FROM CustomRole WHERE companyId = ? ORDER BY name', [$co]);

$candidateId = query('candidateId');
$candidate = is_valid_id($candidateId) ? db_one(
    'SELECT c.id, c.name, c.email, j.title, j.department FROM Candidate c JOIN JobPosting j ON j.id = c.jobPostingId WHERE c.id = ? AND j.companyId = ?',
    [$candidateId, $co]
) : null;
$parts = $candidate ? preg_split('/\s+/', trim($candidate['name'])) : [''];
$prefillDept = '';
if ($candidate) {
    foreach ($departments as $d) {
        if (mb_strtolower($d['name']) === mb_strtolower($candidate['department'])) {
            $prefillDept = $d['id'];
        }
    }
}
$submitted = old('_action') !== '';

app_layout_open($auth, 'Add employee');
?>
<div class="max-w-2xl">
    <?= back_link('/dashboard/employees', 'Back to employees') ?>
    <?= page_header('Add employee', 'Create an employee master record and, optionally, a login.') ?>
    <?php if ($candidate): ?>
        <div class="flex items-center gap-2.5 rounded-lg bg-brand-softer border border-border-soft px-4 py-3 mb-5 text-sm text-ink-soft">
            <?= icon('user-check', 16, 'text-brand shrink-0') ?>
            Onboarding <b class="text-ink"><?= e($candidate['name']) ?></b>, hired for <b class="text-ink"><?= e($candidate['title']) ?></b> — details prefilled below.
        </div>
    <?php endif; ?>
    <?= flash_alerts('employee') ?>
    <form method="post" class="card p-6 space-y-5">
        <?= form_fields('create', 'employee') ?>
        <?php if ($candidate): ?><input type="hidden" name="candidateId" value="<?= e($candidate['id']) ?>"><?php endif; ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="field-label">First name</label><input name="firstName" required maxlength="191" value="<?= e(old('firstName', $parts[0] ?? '')) ?>" class="input"></div>
            <div><label class="field-label">Last name</label><input name="lastName" required maxlength="191" value="<?= e(old('lastName', implode(' ', array_slice($parts, 1)))) ?>" class="input"></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="field-label">Work email</label><input name="email" type="email" required maxlength="191" value="<?= e(old('email', $candidate['email'] ?? '')) ?>" class="input"></div>
            <div><label class="field-label">Designation</label><input name="designation" required maxlength="191" value="<?= e(old('designation', $candidate['title'] ?? '')) ?>" placeholder="e.g. Software Engineer" class="input"></div>
        </div>
        <div class="grid sm:grid-cols-3 gap-4">
            <div>
                <label class="field-label">Department</label>
                <select name="departmentId" class="input"><option value="">— None —</option>
                    <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>"<?= selected($d['id'], old('departmentId', $prefillDept)) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Branch</label>
                <select name="branchId" class="input"><option value="">— None —</option>
                    <?php foreach ($branches as $b): ?><option value="<?= e($b['id']) ?>"<?= selected($b['id'], old('branchId')) ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label">Reporting manager</label>
                <select name="managerId" class="input"><option value="">— None —</option>
                    <?php foreach ($managers as $m): ?><option value="<?= e($m['id']) ?>"<?= selected($m['id'], old('managerId')) ?>><?= e($m['firstName'] . ' ' . $m['lastName']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="field-label">Employment type</label>
                <select name="employmentType" class="input">
                    <?php foreach (['FULL_TIME' => 'Full time', 'PART_TIME' => 'Part time', 'CONTRACT' => 'Contract', 'INTERN' => 'Intern'] as $v => $l): ?>
                        <option value="<?= $v ?>"<?= selected($v, old('employmentType', 'FULL_TIME')) ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="field-label">Date of joining</label><input name="dateOfJoining" type="date" required value="<?= e(old('dateOfJoining')) ?>" class="input"></div>
        </div>
        <div class="rounded-lg border border-border-soft bg-brand-softer p-4">
            <label class="flex items-center gap-2.5 text-sm font-medium text-ink">
                <input type="checkbox" name="createLogin"<?= checked(!$submitted || old('createLogin') === 'on') ?> class="w-4 h-4 accent-[var(--color-brand)]">
                Create a portal login for this employee
            </label>
            <div class="mt-3">
                <label class="field-label">Access role</label>
                <select name="role" class="input max-w-xs">
                    <optgroup label="Standard roles">
                        <?php foreach (ASSIGNABLE_ROLES as $r): ?><option value="<?= e($r) ?>"<?= selected($r, old('role', 'EMPLOYEE')) ?>><?= e(ROLE_LABEL[$r]) ?></option><?php endforeach; ?>
                    </optgroup>
                    <?php if ($customRoles): ?>
                        <optgroup label="Custom roles">
                            <?php foreach ($customRoles as $cr): ?><option value="custom:<?= e($cr['id']) ?>"<?= selected('custom:' . $cr['id'], old('role')) ?>><?= e($cr['name']) ?></option><?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <p class="text-xs text-muted mt-2">A secure temporary password is generated and shown to you once, right after the employee is created.</p>
        </div>
        <div class="flex justify-end gap-2 pt-1">
            <a href="<?= e(url('/dashboard/employees')) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create employee</button>
        </div>
    </form>
</div>
<?php app_layout_close();
