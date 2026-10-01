<?php
/**
 * /dashboard/helpdesk/{id} — port of app/dashboard/helpdesk/[id]/page.tsx.
 * A4 fix: the ticket is looked up within the caller's company only.
 */
declare(strict_types=1);

$ticket = find_company_ticket($auth, $params['id']) ?? throw new HttpError(404);
$managerial = is_managerial($auth);
$canUpdateStatus = $managerial || (has_role($auth, TICKET_QUEUE_OWNERS) && $ticket['assignedToId'] === $auth['employeeId']);
if (!$canUpdateStatus && $ticket['raisedById'] !== $auth['employeeId']) {
    redirect('/dashboard/helpdesk');
}
handle_post([
    'comment' => function () use ($auth, $ticket) {
        action_add_ticket_comment($auth, $ticket['id'], post('message'));
        return null;
    },
    'status' => function () use ($auth, $ticket) {
        action_update_ticket_status($auth, $ticket['id'], post('status'));
        return null;
    },
]);

$raiser = db_one('SELECT firstName, lastName FROM Employee WHERE id = ?', [$ticket['raisedById']]);
$assignee = $ticket['assignedToId'] ? db_one('SELECT firstName, lastName FROM Employee WHERE id = ?', [$ticket['assignedToId']]) : null;
$comments = db_all('SELECT c.*, e.firstName, e.lastName, e.avatarColor FROM TicketComment c JOIN Employee e ON e.id = c.authorId WHERE c.ticketId = ? ORDER BY c.createdAt ASC', [$ticket['id']]);

$statusControl = $canUpdateStatus
    ? '<form method="post">' . form_fields('status') . auto_submit_select('status', $ticket['status'], TICKET_STATUSES, 'input w-auto! text-sm') . '</form>'
    : status_badge($ticket['status']);

app_layout_open($auth, $ticket['subject']);
?>
<div class="max-w-3xl">
    <?= back_link('/dashboard/helpdesk', 'Back to helpdesk') ?>
    <?= page_header($ticket['subject'], $ticket['category'] . ' · raised by ' . $raiser['firstName'] . ' ' . $raiser['lastName'] . ' on ' . format_datetime($ticket['createdAt']),
        '<div class="flex items-center gap-2">' . status_badge($ticket['priority']) . $statusControl . '</div>') ?>
    <div class="card p-5 mb-5">
        <p class="text-sm text-ink-soft whitespace-pre-line"><?= e($ticket['description'] !== '' ? $ticket['description'] : 'No further description provided.') ?></p>
        <?php if ($assignee): ?><p class="text-xs text-muted mt-3">Assigned to <?= e($assignee['firstName'] . ' ' . $assignee['lastName']) ?></p><?php endif; ?>
    </div>
    <div class="card p-5">
        <p class="text-sm font-semibold text-ink mb-4">Conversation</p>
        <ul class="space-y-4 mb-5">
            <?php foreach ($comments as $c): ?>
                <li class="flex items-start gap-3">
                    <?= avatar($c['firstName'], $c['lastName'], $c['avatarColor']) ?>
                    <div>
                        <p class="text-sm"><span class="font-medium text-ink"><?= e($c['firstName'] . ' ' . $c['lastName']) ?></span> <span class="text-xs text-muted"><?= e(format_datetime($c['createdAt'])) ?></span></p>
                        <p class="text-sm text-ink-soft mt-0.5"><?= e($c['message']) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
            <?php if (!$comments): ?><p class="text-sm text-muted">No comments yet.</p><?php endif; ?>
        </ul>
        <?= flash_alerts('comment') ?>
        <form method="post" class="flex gap-2">
            <?= form_fields('comment', 'comment') ?>
            <input name="message" required maxlength="5000" placeholder="Write a reply…" class="input">
            <button type="submit" class="btn btn-primary shrink-0">Send</button>
        </form>
    </div>
</div>
<?php app_layout_close();
