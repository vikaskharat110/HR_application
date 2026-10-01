<?php
/** /dashboard/helpdesk — port of app/dashboard/helpdesk/page.tsx */
declare(strict_types=1);

handle_post([
    'create' => fn() => action_create_ticket($auth, $_POST),
]);

$managerial = is_managerial($auth);
$isQueueOwner = has_role($auth, TICKET_QUEUE_OWNERS);
$canSeeAll = $managerial || $isQueueOwner;
if ($managerial) {
    $where = 't.companyId = ?';
    $p = [$auth['companyId']];
} elseif ($isQueueOwner) {
    $where = 't.companyId = ? AND t.assignedToId = ?';
    $p = [$auth['companyId'], $auth['employeeId'] ?? '__none__'];
} else {
    $where = 't.companyId = ? AND t.raisedById = ?';
    $p = [$auth['companyId'], $auth['employeeId'] ?? '__none__'];
}
$tickets = db_all("SELECT t.*, e.firstName, e.lastName FROM Ticket t JOIN Employee e ON e.id = t.raisedById WHERE $where ORDER BY t.createdAt DESC", $p);

app_layout_open($auth, 'Helpdesk');
echo page_header('Helpdesk', 'Raise a ticket and track resolution.');
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="card p-6">
        <p class="text-sm font-semibold text-ink mb-4">Raise a ticket</p>
        <?php if ($auth['employeeId']): ?>
            <form method="post" class="space-y-3">
                <?= form_fields('create', 'ticket') ?>
                <div><label class="field-label">Category</label><select name="category" class="input"><?php foreach (TICKET_CATEGORIES as $c): ?><option value="<?= e($c) ?>"<?= selected($c, old('category')) ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Priority</label><select name="priority" class="input">
                    <?php foreach (['LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High', 'URGENT' => 'Urgent'] as $v => $l): ?><option value="<?= $v ?>"<?= selected($v, old('priority', 'MEDIUM')) ?>><?= $l ?></option><?php endforeach; ?>
                </select></div>
                <div><label class="field-label">Subject</label><input name="subject" required maxlength="255" value="<?= e(old('subject')) ?>" class="input"></div>
                <div><label class="field-label">Description</label><textarea name="description" rows="3" maxlength="20000" class="input"><?= e(old('description')) ?></textarea></div>
                <?= flash_alerts('ticket') ?>
                <button type="submit" class="btn btn-primary w-full justify-center">Submit ticket</button>
            </form>
        <?php else: ?>
            <p class="text-sm text-muted">This account isn’t linked to an employee record, so it can’t raise tickets.</p>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2 card overflow-x-auto">
        <?php if (!$tickets): ?>
            <?= empty_state('life-buoy', 'No tickets yet') ?>
        <?php else: ?>
            <table class="table-clean">
                <thead><tr><th>Subject</th><?= $canSeeAll ? '<th>Raised by</th>' : '' ?><th>Category</th><th>Priority</th><th>Status</th><th>Opened</th></tr></thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td><a href="<?= e(url('/dashboard/helpdesk/' . $t['id'])) ?>" class="font-medium text-ink hover:underline"><?= e($t['subject']) ?></a></td>
                        <?php if ($canSeeAll): ?><td><?= e($t['firstName'] . ' ' . $t['lastName']) ?></td><?php endif; ?>
                        <td><?= e($t['category']) ?></td>
                        <td><?= status_badge($t['priority']) ?></td>
                        <td><?= status_badge($t['status']) ?></td>
                        <td><?= e(format_date($t['createdAt'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php app_layout_close();
