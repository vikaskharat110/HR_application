<?php
/** /dashboard/profile — port of app/dashboard/profile/page.tsx + ChangePasswordForm. */
declare(strict_types=1);

handle_post([
    'change_password' => function () use ($auth): ?string {
        action_change_own_password($auth, post_raw('currentPassword'), post_raw('newPassword'), post_raw('confirmPassword'));
        flash('success', 'Password updated.', 'password');
        return null;
    },
]);

$employee = $auth['employeeId'] ? db_one(
    'SELECT e.id, e.designation, e.dateOfJoining, d.name AS department, b.name AS branch,
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

app_layout_open($auth, 'My profile');
echo page_header('My profile', 'Your account details and password.');
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 space-y-5">
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
