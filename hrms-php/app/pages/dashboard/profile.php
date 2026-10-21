<?php
/** /dashboard/profile — port of app/dashboard/profile/page.tsx + ChangePasswordForm. */
declare(strict_types=1);

handle_post([
    'update_profile' => function () use ($auth): ?string {
        action_update_own_profile($auth, $_POST);
        flash('success', 'Profile updated.', 'profile');
        return '/dashboard/profile';
    },
    'change_password' => function () use ($auth): ?string {
        action_change_own_password($auth, post_raw('currentPassword'), post_raw('newPassword'), post_raw('confirmPassword'));
        flash('success', 'Password updated.', 'password');
        return null;
    },
]);

$employee = $auth['employeeId'] ? db_one(
    'SELECT e.id, e.firstName, e.lastName, e.phone, e.address, e.gender, e.dateOfBirth, e.designation, e.dateOfJoining, d.name AS department, b.name AS branch,
            m.firstName AS mFirst, m.lastName AS mLast
       FROM Employee e
       LEFT JOIN Department d ON d.id = e.departmentId
       LEFT JOIN Branch b ON b.id = e.branchId
       LEFT JOIN Employee m ON m.id = e.managerId
      WHERE e.id = ? AND e.companyId = ?',
    [$auth['employeeId'], $auth['companyId']]
) : null;

$row = static fn(string $label, string $value): string =>
    '<li class="flex justify-between"><span class="text-muted">' . e($label) . '</span><span class="text-ink">' . e($value) . '</span></li>';

$panel = $employee ? employee_panel_cards($auth, $employee, true) : null;

app_layout_open($auth, 'My profile');
echo page_header('My profile', 'Update your personal details and password.');
?>
<div class="space-y-5">
    <div class="space-y-5">
        <div class="grid sm:grid-cols-2 gap-5">
        <div class="card p-5">
            <p class="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><?= icon('user', 15) ?> Account</p>
            <ul class="space-y-2 text-sm">
                <?= $row('Name', $auth['name']) ?>
                <?= $row('Email', $auth['email']) ?>
                <?= $row('Role', role_label($auth)) ?>
            </ul>
        </div>
        <?php if ($employee): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><?= icon('building-2', 15) ?> Employment</p>
                <ul class="space-y-2 text-sm">
                    <?= $row('Designation', $employee['designation']) ?>
                    <?= $row('Department', $employee['department'] ?? DASH) ?>
                    <?= $row('Branch', $employee['branch'] ?? DASH) ?>
                    <?= $row('Manager', $employee['mFirst'] ? $employee['mFirst'] . ' ' . $employee['mLast'] : DASH) ?>
                    <?= $row('Date of joining', format_date($employee['dateOfJoining'])) ?>
                </ul>
                <a href="<?= e(url('/dashboard/employees/' . $employee['id'])) ?>" class="inline-flex items-center gap-1.5 text-sm text-brand hover:underline mt-3">
                    View full employee record <?= icon('arrow-right', 14) ?>
                </a>
            </div>
        <?php endif; ?>
        </div>
        <div class="card p-5">
            <p class="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><?= icon('pencil', 15) ?> Personal details</p>
            <?php if ($employee): ?>
                <form method="post" class="space-y-3" data-pending-form>
                    <?= form_fields('update_profile', 'profile') ?>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div><label class="field-label">First name</label><input name="firstName" required maxlength="191" value="<?= e(old('firstName') ?: $employee['firstName']) ?>" class="input"></div>
                        <div><label class="field-label">Last name</label><input name="lastName" required maxlength="191" value="<?= e(old('lastName') ?: $employee['lastName']) ?>" class="input"></div>
                        <div><label class="field-label">Phone</label><input name="phone" maxlength="50" value="<?= e(old('phone') ?: ($employee['phone'] ?? '')) ?>" class="input"></div>
                        <div><label class="field-label">Date of birth</label><input type="date" name="dateOfBirth" max="<?= e(now()->format('Y-m-d')) ?>" value="<?= e(old('dateOfBirth') ?: ($employee['dateOfBirth'] ? dt($employee['dateOfBirth'])->format('Y-m-d') : '')) ?>" class="input"></div>
                        <div><label class="field-label">Gender</label>
                            <select name="gender" class="input">
                                <option value="">Prefer not to say</option>
                                <?php foreach (['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other'] as $v => $l): ?>
                                    <option value="<?= $v ?>"<?= selected($v, old('gender') ?: ($employee['gender'] ?? '')) ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div style="grid-column:1/-1"><label class="field-label">Address</label><textarea name="address" rows="2" maxlength="500" class="input"><?= e(old('address') ?: ($employee['address'] ?? '')) ?></textarea></div>
                    </div>
                    <p class="text-xs text-muted">Email, designation, department, branch and manager are managed by HR.</p>
                    <?= flash_alerts('profile') ?>
                    <button type="submit" class="btn btn-primary disabled:opacity-70" data-pending-label="Saving…">Save changes</button>
                </form>
            <?php else: ?>
                <p class="text-sm text-muted">This account isn’t linked to an employee record, so there are no personal details to edit. You can still change your password below.</p>
            <?php endif; ?>
        </div>
        <?php if ($panel): ?><?= $panel['details'] ?><?= $panel['attendance'] ?><?php endif; ?>
        <div class="card p-5">
            <p class="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><?= icon('key-round', 15) ?> Change password</p>
            <form method="post" class="space-y-3 max-w-sm" data-pending-form>
                <?= form_fields('change_password', 'password') ?>
                <div>
                    <label class="field-label">Current password</label>
                    <input name="currentPassword" type="password" required autocomplete="current-password" class="input">
                </div>
                <div>
                    <label class="field-label">New password</label>
                    <input name="newPassword" type="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters" class="input">
                </div>
                <div>
                    <label class="field-label">Confirm new password</label>
                    <input name="confirmPassword" type="password" required minlength="8" autocomplete="new-password" class="input">
                </div>
                <?= flash_alerts('password') ?>
                <button type="submit" class="btn btn-primary disabled:opacity-70" data-pending-label="Updating…">Update password</button>
            </form>
        </div>
    </div>
</div>
<?php app_layout_close();
