<?php
/** /dashboard/leave/approvals — port of app/dashboard/leave/approvals/page.tsx */
declare(strict_types=1);

handle_post([
    'decide' => function () use ($auth) {
        action_decide_leave($auth, post_id('id'), post('approve') === '1');
        flash('success', post('approve') === '1' ? 'Leave approved.' : 'Leave rejected.');
        return null;
    },
]);

[$qSql, $qParams] = approval_queue_where($auth, 'r');
$requests = db_all(
    "SELECT r.*, t.name AS typeName, e.firstName, e.lastName, e.avatarColor FROM LeaveRequest r
       JOIN Employee e ON e.id = r.employeeId JOIN LeaveType t ON t.id = r.leaveTypeId
      WHERE $qSql AND r.status = 'PENDING' ORDER BY r.appliedAt ASC",
    $qParams
);

app_layout_open($auth, 'Leave approvals');
echo back_link('/dashboard/leave', 'Back to leave');
echo page_header('Leave approvals', 'Requests from your reporting line waiting on a decision.');
?>
<div class="card p-5">
    <?php if (!$requests): ?>
        <?= empty_state('calendar-check-2', 'All caught up', 'No pending leave requests right now.') ?>
    <?php else: ?>
        <ul class="divide-y divide-border-soft">
            <?php foreach ($requests as $r): ?>
                <li class="py-4 flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <?= avatar($r['firstName'], $r['lastName'], $r['avatarColor']) ?>
                        <div>
                            <p class="text-sm font-medium text-ink"><?= e($r['firstName'] . ' ' . $r['lastName']) ?></p>
                            <p class="text-xs text-muted mt-0.5"><?= e($r['typeName']) ?> · <?= e(js_num($r['days'])) ?> day(s) · <?= e(format_date($r['startDate'])) ?> – <?= e(format_date($r['endDate'])) ?></p>
                            <p class="text-sm text-ink-soft mt-1.5 max-w-md"><?= e($r['reason']) ?></p>
                        </div>
                    </div>
                    <div class="flex gap-1.5 shrink-0">
                        <form method="post"><?= form_fields('decide') ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="approve" value="1">
                            <button class="btn btn-secondary !py-1.5 !px-2.5"><?= icon('check', 14) ?> Approve</button></form>
                        <form method="post"><?= form_fields('decide') ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="approve" value="0">
                            <button class="btn btn-secondary !py-1.5 !px-2.5 text-danger"><?= icon('x', 14) ?> Reject</button></form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php app_layout_close();
