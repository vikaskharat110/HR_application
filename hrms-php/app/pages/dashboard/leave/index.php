<?php
/** /dashboard/leave — port of app/dashboard/leave/page.tsx */
declare(strict_types=1);

handle_post([
    'apply' => function () use ($auth) {
        action_apply_leave($auth, post('leaveTypeId'), post('startDate'), post('endDate'), post_raw('reason'));
        flash('success', 'Leave request submitted.', 'apply');
        return null;
    },
    'cancel' => function () use ($auth) {
        action_cancel_leave($auth, post_id('id'));
        return null;
    },
]);

$managerial = is_managerial($auth);
$year = (int)now()->format('Y');
if ($auth['employeeId'] && $auth['companyId']) {
    ensure_leave_balances($auth['companyId'], $auth['employeeId'], $year); // B3: own balances for this year
}
$leaveTypes = db_all('SELECT id, name FROM LeaveType WHERE companyId = ? ORDER BY name', [$auth['companyId'] ?? '']);
$balances = $auth['employeeId'] ? db_all(
    'SELECT b.id, b.allocated, b.used, t.name FROM LeaveBalance b JOIN LeaveType t ON t.id = b.leaveTypeId WHERE b.employeeId = ? AND b.year = ? ORDER BY t.name',
    [$auth['employeeId'], $year]
) : [];
$myRequests = $auth['employeeId'] ? db_all(
    'SELECT r.*, t.name AS typeName FROM LeaveRequest r JOIN LeaveType t ON t.id = r.leaveTypeId WHERE r.employeeId = ? ORDER BY r.appliedAt DESC',
    [$auth['employeeId']]
) : [];

app_layout_open($auth, 'Leave');
echo page_header('Leave', 'Apply for time off and track your balances.',
    $managerial ? '<a href="' . e(url('/dashboard/leave/approvals')) . '" class="btn btn-secondary">' . icon('calendar-check-2', 15) . ' Approvals queue</a>' : '');
?>
<div class="grid sm:grid-cols-3 gap-4 mb-6">
    <?php foreach ($balances as $b): ?>
        <div class="stat-card">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted"><?= e($b['name']) ?></p>
            <p class="font-display text-2xl font-semibold text-ink mt-1.5"><?= e(js_num($b['allocated'] - $b['used'])) ?><span class="text-sm font-normal text-muted"> / <?= e(js_num($b['allocated'])) ?> days left</span></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-5">
    <div class="card p-6">
        <p class="text-sm font-semibold text-ink mb-4">Apply for leave</p>
        <?php if ($auth['employeeId']): ?>
            <form method="post" class="space-y-4">
                <?= form_fields('apply', 'apply') ?>
                <div>
                    <label class="field-label">Leave type</label>
                    <select name="leaveTypeId" required class="input">
                        <?php foreach ($leaveTypes as $lt): ?><option value="<?= e($lt['id']) ?>"<?= selected($lt['id'], old('leaveTypeId')) ?>><?= e($lt['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="field-label">From</label><input type="date" name="startDate" required value="<?= e(old('startDate')) ?>" class="input"></div>
                    <div><label class="field-label">To</label><input type="date" name="endDate" required value="<?= e(old('endDate')) ?>" class="input"></div>
                </div>
                <div><label class="field-label">Reason</label><textarea name="reason" required rows="3" maxlength="5000" class="input"><?= e(old('reason')) ?></textarea></div>
                <?= flash_alerts('apply') ?>
                <button type="submit" class="btn btn-primary w-full justify-center">Submit request</button>
            </form>
        <?php else: ?>
            <p class="text-sm text-muted">This account isn’t linked to an employee record, so it can’t apply for leave.</p>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-2 card p-5 overflow-x-auto">
        <p class="text-sm font-semibold text-ink mb-3">My requests</p>
        <table class="table-clean">
            <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($myRequests as $r): ?>
                <tr>
                    <td><?= e($r['typeName']) ?></td>
                    <td><?= e(format_date($r['startDate'])) ?> – <?= e(format_date($r['endDate'])) ?></td>
                    <td><?= e(js_num($r['days'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td>
                        <?php if ($r['status'] === 'PENDING'): ?>
                            <form method="post"><?= form_fields('cancel') ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                <button class="text-muted hover:text-danger" title="Cancel"><?= icon('x', 15) ?></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$myRequests): ?><tr><td colspan="5" class="text-center text-muted py-6">No leave requests yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php app_layout_close();
