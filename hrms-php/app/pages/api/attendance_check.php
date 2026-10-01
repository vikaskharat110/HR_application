<?php
/**
 * POST /api/attendance/check-in | /api/attendance/check-out
 * Body: JSON {latitude?, longitude?} (from the browser's geolocation) — or a
 * plain form post (no JavaScript), which is redirected back to Attendance.
 */
declare(strict_types=1);

$json = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($json) ? $json : $_POST;
[$lat, $lng] = parse_coords($in['latitude'] ?? null, $in['longitude'] ?? null);
$isCheckIn = str_ends_with(request_path(), '/check-in');

try {
    $error = $isCheckIn ? action_check_in($auth, $lat, $lng) : action_check_out($auth, $lat, $lng);
} catch (UserError $e) {
    $error = $e->getMessage();
}

if (is_array($json)) {
    json_response($error === null ? ['ok' => true] : ['error' => $error], $error === null ? 200 : 422);
}
if ($error !== null) {
    flash('error', $error, 'checkin');
}
redirect('/dashboard/attendance');
