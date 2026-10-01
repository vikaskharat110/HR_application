<?php
/** /dashboard/employees/promotions — port of app/dashboard/employees/promotions/page.tsx */
declare(strict_types=1);

handle_post([
    'decide' => function () use ($auth) {
        action_decide_promotion_transfer($auth, post_id('id'), post('approve') === '1');
        return null;
    },
]);

[$qSql, $qParams] = approval_queue_where($auth, 'p');
$pending = db_all(
    "SELECT p.*, e.firstName, e.lastName, e.avatarColor FROM PromotionTransfer p JOIN Employee e ON e.id = p.employeeId
      WHERE $qSql AND p.status = 'PENDING' ORDER BY p.createdAt ASC", $qParams);
$recent = db_all(
    "SELECT p.*, e.firstName, e.lastName FROM PromotionTransfer p JOIN Employee e ON e.id = p.employeeId
      WHERE $qSql AND p.status <> 'PENDING' ORDER BY p.actedAt DESC LIMIT 10", $qParams);

app_layout_open($auth, 'Promotions & Transfers');
?>
<?= back_link('/dashboard/employees', 'Back to employees') ?>
<?= page_header('Promotions & Transfers', 'Requests awaiting your decision, and recent history.') ?>
<div class="card p-5 mb-5">
    <p class="text-sm font-semibold text-ink mb-3">Awaiting your decision</p>
    <?php if (!$pending): ?>
        <?= empty_state('trending-up', 'Nothing pending') ?>
    <?php else: ?>
        <ul class="divide-y divide-border-soft">
            <?php foreach ($pending as $r): ?>
                <li class="py-4 flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <?= avatar($r['firstName'], $r['lastName'], $r['avatarColor']) ?>
                        <div>
                            <p class="text-sm font-medium text-ink"><?= e($r['firstName'] . ' ' . $r['lastName']) ?> <?= status_badge($r['type']) ?></p>
                            <p class="text-xs text-muted mt-0.5">Effective <?= e(format_date($r['effectiveDate'])) ?></p>
                            <ul class="text-sm text-ink-soft mt-1.5 space-y-0.5">
                                <?php if ($r['newDesignation']): ?><li>New designation: <?= e($r['newDesignation']) ?></li><?php endif; ?>
                                <?php if ($r['newDepartmentId']): ?><li>New department assigned</li><?php endif; ?>
                                <?php if ($r['newBranchId']): ?><li>New branch assigned</li><?php endif; ?>
                                <?php if ($r['newManagerId']): ?><li>New manager assigned</li><?php endif; ?>
                                <?php if ($r['newBasic'] !== null): ?><li>New basic salary: <?= e(format_currency((float)$r['newBasic'])) ?></li><?php endif; ?>
                            </ul>
                            <p class="text-sm text-ink-soft mt-1.5"><?= e($r['reason']) ?></p>
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
<div class="card p-5 overflow-x-auto">
    <p class="text-sm font-semibold text-ink mb-3">Recent decisions</p>
    <?php if (!$recent): ?>
        <p class="text-sm text-muted py-4">No decided requests yet.</p>
    <?php else: ?>
        <table class="table-clean">
            <thead><tr><th>Employee</th><th>Type</th><th>Effective</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr><td><?= e($r['firstName'] . ' ' . $r['lastName']) ?></td><td><?= e($r['type']) ?></td><td><?= e(format_date($r['effectiveDate'])) ?></td><td><?= status_badge($r['status']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php app_layout_close();
