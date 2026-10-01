<?php
/** Port of lib/actions/notifications.ts — always scoped to the signed-in user's own notifications. */
declare(strict_types=1);

function action_mark_notification_read(array $auth, string $id): void
{
    if (!is_valid_id($id)) {
        throw new UserError('Unknown notification.');
    }
    db_exec('UPDATE Notification SET isRead = 1 WHERE id = ? AND userId = ?', [$id, $auth['sub']]);
}

function action_mark_all_notifications_read(array $auth): void
{
    db_exec('UPDATE Notification SET isRead = 1 WHERE userId = ? AND isRead = 0', [$auth['sub']]);
}
