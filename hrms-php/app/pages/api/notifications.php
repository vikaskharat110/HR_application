<?php
/**
 * POST /api/notifications/read      {id}  — mark one read
 * POST /api/notifications/read-all        — mark all read
 * JSON for fetch() callers; a plain form post (no JS) is redirected back.
 */
declare(strict_types=1);

$body = json_decode((string)file_get_contents('php://input'), true);
$id = is_array($body) ? (string)($body['id'] ?? '') : post('id');

if (str_ends_with(request_path(), '/read-all')) {
    action_mark_all_notifications_read($auth);
} else {
    action_mark_notification_read($auth, $id);
}

if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    json_response(['ok' => true]);
}
redirect(safe_next(post('_back'), '/dashboard'));
