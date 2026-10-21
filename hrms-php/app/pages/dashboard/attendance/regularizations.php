<?php
/** /dashboard/attendance/regularizations — port of app/dashboard/attendance/regularizations/page.tsx */
declare(strict_types=1);

handle_post([
    'request' => function () use ($auth) {
        action_request_regularization($auth, post('date'), post('checkIn'), post('checkOut'), post_raw('reason'));
        flash('success', 'Correction requested.', 'request');
        return null;
    },
    'decide' => function () use ($auth) {
        action_decide_regularization($auth, post_id('id'), post('approve') === '1');
        return null;
    },
]);

$managerial = is_managerial($auth);
$mine = $auth['employeeId'] ? db_all('SELECT * FROM AttendanceRegularization WHERE employeeId = ? ORDER BY createdAt DESC', [$auth['employeeId']]) : [];
$queue = [];
if ($managerial) {
    [$qSql, $qParams] = approval_queue_where($auth, 'r');
    $queue = db_all(
        "SELECT r.*, e.firstName, e.lastName, e.avatarColor FROM AttendanceRegularization r JOIN Employee e ON e.id = r.employeeId
          WHERE $qSql AND r.status = 'PENDING' ORDER BY r.createdAt ASC",
        $qParams
    );
}

app_layout_open($auth, 'Attendance regularizations');
echo back_link('/dashboard/attendance', 'Back to attendance');
echo page_header('Attendance regularizations', 'Fix a missed punch or request a correction — routed to your manager for approval.');
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="card p-6">
        <p class="text-sm font-semibold text-ink mb-4">Request a correction</p>
        <?php if ($auth['employeeId']): ?>
            <form method="post" class="space-y-4">
                <?= form_fields('request', 'request') ?>
                <div><label class="field-label">Date</label><input type="date" name="date" required max="<?= e(today_start()->format('Y-m-d')) ?>" value="<?= e(old('date', parse_date_input(query('date')) ? query('date') : '')) ?>" class="input"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="field-label">Check-in</label><input type="time" name="checkIn" value="<?= e(old('checkIn')) ?>" class="input"></div>
                    <div><label class="field-label">Check-out</label><input type="time" name="checkOut" value="<?= e(old('checkOut')) ?>" class="input"></div>
                </div>
                <div><label class="field-label">Reason</label><textarea name="reason" required rows="3" maxlength="5000" class="input" placeholder="e.g. Forgot to check out"><?= e(old('reason')) ?></textarea></div>
                <?= flash_alerts('request') ?>
                <button type="submit" class="btn btn-primary w-full justify-center">Submit request</button>
            </form>
        <?php else: ?>
            <p class="text-sm text-muted">This account isn’t linked to an employee record, so it can’t submit attendance corrections.</p>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-2 space-y-5">
        <?php if ($managerial): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Awaiting your decision</p>
                <?php if (!$queue): ?>
                    <?= empty_state('clipboard-list', 'Nothing pending') ?>
                <?php else: ?>
                    <ul class="divide-y divide-border-soft">
                        <?php foreach ($queue as $r): ?>
                            <li class="py-3 flex items-start justify-between gap-3">
                                <div class="flex items-start gap-2.5">
                                    <?= avatar($r['firstName'], $r['lastName'], $r['avatarColor']) ?>
                                    <div>
                                        <p class="text-sm font-medium text-ink"><?= e($r['firstName'] . ' ' . $r['lastName']) ?> · <?= e(format_date($r['date'])) ?></p>
                                        <p class="text-xs text-muted mt-0.5"><?= e($r['requestedCheckIn'] ? format_time($r['requestedCheckIn']) : DASH) ?> – <?= e($r['requestedCheckOut'] ? format_time($r['requestedCheckOut']) : DASH) ?></p>
                                        <p class="text-sm text-ink-soft mt-1"><?= e($r['reason']) ?></p>
                                    </div>
                                </div>
                                <div class="flex gap-1.5 shrink-0">
                                    <form method="post"><?= form_fields('decide') ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="approve" value="1">
                                        <button class="btn btn-secondary !py-1.5 !px-2.5" title="Approve"><?= icon('check', 14) ?></button></form>
                                    <form method="post"><?= form_fields('decide') ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="approve" value="0">
                                        <button class="btn btn-secondary !py-1.5 !px-2.5 text-danger" title="Reject"><?= icon('x', 14) ?></button></form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card p-5">
            <p class="text-sm font-semibold text-ink mb-3">My requests</p>
            <?php if (!$mine): ?>
                <p class="text-sm text-muted py-4">You haven’t requested any corrections.</p>
            <?php else: ?>
                <ul class="divide-y divide-border-soft">
                    <?php foreach ($mine as $r): ?>
                        <li class="py-3 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-ink"><?= e(format_date($r['date'])) ?></p>
                                <p class="text-xs text-muted mt-0.5"><?= e($r['reason']) ?></p>
                            </div>
                            <?= status_badge($r['status']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php app_layout_close();
