<?php
/**
 * Attendance — port of lib/actions/attendance.ts.
 * Dates are local (Asia/Kolkata) midnight; late = after shift start + grace.
 */
declare(strict_types=1);

/**
 * Geofence check. Both switches must be on for a location to be required:
 * the company-wide policy toggle AND the employee's branch having
 * coordinates. Only the raw lat/lng from the browser is trusted, checked here.
 */
function enforce_geofence(string $employeeId, ?float $lat, ?float $lng): ?string
{
    $row = db_one(
        'SELECT p.geofenceAttendanceEnabled AS enabled, b.name, b.latitude, b.longitude, b.geofenceRadiusMeters AS radius
           FROM Employee e
           LEFT JOIN CompanyPolicy p ON p.companyId = e.companyId
           LEFT JOIN Branch b ON b.id = e.branchId
          WHERE e.id = ?',
        [$employeeId]
    );
    if ($row === null || !(int)$row['enabled'] || $row['latitude'] === null || $row['longitude'] === null) {
        return null;
    }
    if ($lat === null || $lng === null) {
        return 'Location access is required to check in/out at this branch — please allow it and try again.';
    }
    $distance = distance_meters($lat, $lng, (float)$row['latitude'], (float)$row['longitude']);
    if ($distance > (int)$row['radius']) {
        return 'You’re ' . (int)js_round($distance) . "m from {$row['name']} — you must be within {$row['radius']}m to do this.";
    }
    return null;
}

function geofence_enabled(string $companyId): bool
{
    return (bool)db_value('SELECT geofenceAttendanceEnabled FROM CompanyPolicy WHERE companyId = ?', [$companyId]);
}

/** Validates optional browser coordinates. */
function parse_coords(mixed $lat, mixed $lng): array
{
    if ($lat === null || $lat === '' || $lng === null || $lng === '') {
        return [null, null];
    }
    if (!is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) {
        return [null, null];
    }
    return [(float)$lat, (float)$lng];
}

/** @return string|null error message for the user, null on success */
function action_check_in(array $auth, ?float $lat, ?float $lng): ?string
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    if ($err = enforce_geofence($auth['employeeId'], $lat, $lng)) {
        return $err;
    }
    $shift = db_one('SELECT id, startTime, graceMinutes FROM Shift WHERE companyId = ? ORDER BY id LIMIT 1', [$auth['companyId']]);
    $now = now();
    $grace = (int)($shift['graceMinutes'] ?? 15);
    [$h, $m] = array_map('intval', explode(':', $shift['startTime'] ?? '09:30') + [0, 0]);
    $cutoff = $now->setTime($h, 0)->modify('+' . ($m + $grace) . ' minutes');
    $status = $now > $cutoff ? 'LATE' : 'PRESENT';
    $today = today_start();

    return db_tx(function () use ($auth, $today, $now, $lat, $lng, $status, $shift) {
        $existing = db_one('SELECT id, checkIn FROM Attendance WHERE employeeId = ? AND date = ? FOR UPDATE', [$auth['employeeId'], $today]);
        if ($existing !== null && $existing['checkIn'] !== null) {
            return 'You’ve already checked in today.';
        }
        if ($existing !== null) {
            db_update('Attendance', ['checkIn' => $now, 'checkInLat' => $lat, 'checkInLng' => $lng, 'status' => $status], 'id = ?', [$existing['id']]);
        } else {
            db_insert('Attendance', [
                'employeeId' => $auth['employeeId'], 'date' => $today, 'checkIn' => $now,
                'checkInLat' => $lat, 'checkInLng' => $lng, 'status' => $status, 'shiftId' => $shift['id'] ?? null,
            ]);
        }
        return null;
    });
}

/** @return string|null error message for the user, null on success */
function action_check_out(array $auth, ?float $lat, ?float $lng): ?string
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    if ($err = enforce_geofence($auth['employeeId'], $lat, $lng)) {
        return $err;
    }
    return db_tx(function () use ($auth, $lat, $lng) {
        $record = db_one('SELECT id, checkIn, checkOut FROM Attendance WHERE employeeId = ? AND date = ? FOR UPDATE', [$auth['employeeId'], today_start()]);
        if ($record === null || $record['checkIn'] === null) {
            return 'Check in first.';
        }
        if ($record['checkOut'] !== null) {
            return 'You’ve already checked out today.';
        }
        $now = now();
        $workedMins = (int)js_round(((float)$now->format('U.u') - (float)dt($record['checkIn'])->format('U.u')) / 60);
        db_update('Attendance', ['checkOut' => $now, 'checkOutLat' => $lat, 'checkOutLng' => $lng, 'workedMins' => $workedMins], 'id = ?', [$record['id']]);
        return null;
    });
}

function action_request_regularization(array $auth, string $dateRaw, string $inRaw, string $outRaw, string $reason): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $date = v_date(trim($dateRaw), 'date');
    if ($date > today_start()) {
        throw new UserError('You can’t request a correction for a future date.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new UserError('Reason is required.');
    }
    v_max($reason, 'Reason', 5000);
    $in = trim($inRaw) === '' ? null : (parse_time_on($date, $inRaw) ?? throw new UserError('Enter a valid check-in time.'));
    $out = trim($outRaw) === '' ? null : (parse_time_on($date, $outRaw) ?? throw new UserError('Enter a valid check-out time.'));

    db_tx(function () use ($auth, $date, $in, $out, $reason) {
        $existing = db_value('SELECT id FROM Attendance WHERE employeeId = ? AND date = ?', [$auth['employeeId'], $date]);
        $approver = resolve_approver($auth['employeeId']);
        $id = db_insert('AttendanceRegularization', [
            'employeeId' => $auth['employeeId'], 'attendanceId' => $existing ?: null, 'date' => $date,
            'requestedCheckIn' => $in, 'requestedCheckOut' => $out, 'reason' => $reason, 'approverId' => $approver['id'] ?? null,
        ]);
        if (!empty($approver['userId'])) {
            notify($approver['userId'], 'Attendance regularization request',
                "{$auth['name']} requested a correction for " . $date->format('D M d Y') . '.', '/dashboard/attendance/regularizations');
        }
        audit($auth, 'CREATE', 'AttendanceRegularization', $id);
    });
}

function action_decide_regularization(array $auth, string $id, bool $approve): void
{
    $reg = db_one(
        'SELECT r.* FROM AttendanceRegularization r JOIN Employee e ON e.id = r.employeeId WHERE r.id = ? AND e.companyId = ?',
        [$id, $auth['companyId'] ?? '']
    );
    if ($reg === null) {
        throw new UserError('Regularization request not found.');
    }
    assert_can_act_on($auth, $reg['employeeId'], $reg['approverId']);

    db_tx(function () use ($auth, $id, $approve) {
        $reg = db_one('SELECT * FROM AttendanceRegularization WHERE id = ? FOR UPDATE', [$id]);
        if ($reg['status'] !== 'PENDING') {
            throw new UserError('This request has already been actioned.');
        }
        db_update('AttendanceRegularization', ['status' => $approve ? 'APPROVED' : 'REJECTED'], 'id = ?', [$id]);
        if ($approve) {
            $att = db_one('SELECT id FROM Attendance WHERE employeeId = ? AND date = ? FOR UPDATE', [$reg['employeeId'], $reg['date']]);
            if ($att) {
                $patch = ['status' => 'PRESENT'];
                if ($reg['requestedCheckIn'] !== null) {
                    $patch['checkIn'] = $reg['requestedCheckIn'];
                }
                if ($reg['requestedCheckOut'] !== null) {
                    $patch['checkOut'] = $reg['requestedCheckOut'];
                }
                db_update('Attendance', $patch, 'id = ?', [$att['id']]);
            } else {
                db_insert('Attendance', ['employeeId' => $reg['employeeId'], 'date' => $reg['date'], 'checkIn' => $reg['requestedCheckIn'],
                    'checkOut' => $reg['requestedCheckOut'], 'status' => 'PRESENT']);
            }
        }
        if ($uid = employee_user_id($reg['employeeId'])) {
            $d = dt($reg['date'])->format('D M d Y');
            notify($uid, 'Regularization ' . ($approve ? 'approved' : 'rejected'),
                "Your attendance correction for $d was " . ($approve ? 'approved' : 'rejected') . '.', '/dashboard/attendance');
        }
        audit($auth, $approve ? 'APPROVE' : 'REJECT', 'AttendanceRegularization', $id);
    });
}
