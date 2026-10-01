<?php
/**
 * Phase 7 — Attendance + Leave. Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase7_attendance_leave.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$NW = company_id('Northwind Retail');
$admin = Client::as('admin@meridianworks.com');
$hr = Client::as('hr@meridianworks.com');
$manager = Client::as('manager@meridianworks.com');
$employee = Client::as('employee@meridianworks.com');
$recruiterUser = Client::as('recruiter@meridianworks.com');
$empId = emp_id('employee@meridianworks.com');
$mgrId = emp_id('manager@meridianworks.com');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
// Seed data has attendance only up to yesterday; clear today just in case.
q('DELETE FROM Attendance WHERE date >= ?', ["$today 00:00:00"]);

section('Check-in / check-out');
$r = $employee->postJson('/api/attendance/check-in', []);
check('check-in returns ok', $r->status === 200 && $r->body === '{"ok":true}', $r->status . ' ' . $r->body);
$row = q1('SELECT * FROM Attendance WHERE employeeId = ? AND date = ?', [$empId, "$today 00:00:00.000"]);
check('row keyed by local midnight', $row !== null, json_encode($row));
$shift = q1('SELECT startTime, graceMinutes FROM Shift WHERE companyId = ? ORDER BY id LIMIT 1', [$MW]);
[$h, $m] = array_map('intval', explode(':', $shift['startTime']));
$cutoff = (new DateTimeImmutable("$today 00:00", new DateTimeZone('Asia/Kolkata')))->setTime($h, 0)->modify('+' . ($m + (int)$shift['graceMinutes']) . ' minutes');
$expected = new DateTimeImmutable($row['checkIn'], new DateTimeZone('Asia/Kolkata')) > $cutoff ? 'LATE' : 'PRESENT';
check("status follows shift start + grace ($expected)", $row['status'] === $expected, $row['status']);
check('shift linked', $row['shiftId'] !== null);
$r = $employee->postJson('/api/attendance/check-in', []);
check('second check-in refused (would overwrite the time)', $r->status === 422 && str_contains($r->body, 'already checked in'));
$r = $employee->postJson('/api/attendance/check-out', []);
$row = q1('SELECT * FROM Attendance WHERE employeeId = ? AND date = ?', [$empId, "$today 00:00:00.000"]);
check('check-out stored with worked minutes', $r->status === 200 && $row['checkOut'] !== null && (int)$row['workedMins'] >= 0);
check('second check-out refused', $employee->postJson('/api/attendance/check-out', [])->status === 422);
$r = $recruiterUser->postJson('/api/attendance/check-out', []);
check('check-out before check-in refused', $r->status === 422 && str_contains($r->body, 'Check in first.'));
$page = $employee->get('/dashboard/attendance');
check('card shows done-for-today', $page->has('done for today'));
$r = (new Client())->postJson('/api/attendance/check-in', []);
check('anonymous check-in refused', in_array($r->status, [401, 403], true));
$noCsrf = $hr->get('/dashboard');
$c = new Client();
check('JSON check-in without CSRF header refused', $c->post('/api/attendance/check-in', [], null, [], false)->status === 403);

section('Geofence');
$hrEmp = emp_id('hr@meridianworks.com');
$branch = (string)qv('SELECT branchId FROM Employee WHERE id = ?', [$hrEmp]);
q('UPDATE CompanyPolicy SET geofenceAttendanceEnabled = 1 WHERE companyId = ?', [$MW]);
q('UPDATE Branch SET latitude = 18.5204, longitude = 73.8567, geofenceRadiusMeters = 100 WHERE id = ?', [$branch]);
$page = $hr->get('/dashboard/attendance');
check('card announces the location check', $page->has('data-geofence="1"') && $page->has('location may be checked'));
$r = $hr->postJson('/api/attendance/check-in', []);
check('no location → refused with explanation', $r->status === 422 && str_contains($r->body, 'Location access is required'));
$r = $hr->postJson('/api/attendance/check-in', ['latitude' => 18.60, 'longitude' => 73.90]);
check('out of range → refused with distance', $r->status === 422 && preg_match('/You.re \d+m from/u', $r->body) === 1, $r->body);
$r = $hr->postJson('/api/attendance/check-in', ['latitude' => 'abc', 'longitude' => 73.8567]);
check('garbage coordinates treated as missing', $r->status === 422 && str_contains($r->body, 'Location access is required'));
$r = $hr->postJson('/api/attendance/check-in', ['latitude' => 18.5205, 'longitude' => 73.8568]);
check('within radius → ok, coordinates stored', $r->status === 200 && (float)qv('SELECT checkInLat FROM Attendance WHERE employeeId = ? AND date = ?', [$hrEmp, "$today 00:00:00.000"]) === 18.5205);
check('distance note shown on the card', str_contains($hr->get('/dashboard/attendance')->body, 'm from '));
q('UPDATE CompanyPolicy SET geofenceAttendanceEnabled = 0 WHERE companyId = ?', [$MW]);
q('UPDATE Branch SET latitude = NULL, longitude = NULL WHERE id = ?', [$branch]);

section('No-JavaScript fallback');
$fin = $admin; // Finance has no Attendance module (original nav), so use the Company Admin
$r = $fin->post('/api/attendance/check-in', [], '/dashboard/attendance');
check('plain form check-in redirects back', $r->status === 303 && str_ends_with($r->location, '/dashboard/attendance'));
$r = $fin->post('/api/attendance/check-in', [], '/dashboard/attendance');
check('…and errors are flashed on the card', $fin->get('/dashboard/attendance')->has('already checked in'));

section('Team view');
$m = $manager->get('/dashboard/attendance');
check('manager sees team today', $m->has('Team — today') && $m->has('Rohan Verma'));
check('employee sees no team table', !$employee->get('/dashboard/attendance')->has('Team — today'));

section('Regularization');
$future = (new DateTimeImmutable('+2 days'))->format('Y-m-d');
$r = $employee->submit('/dashboard/attendance/regularizations', ['_action' => 'request', '_form' => 'request', 'date' => $future, 'checkIn' => '09:30', 'checkOut' => '18:00', 'reason' => 'x']);
check('future date refused', $r->has('future date'));
$r = $employee->submit('/dashboard/attendance/regularizations', ['_action' => 'request', '_form' => 'request', 'date' => '2026-09-01', 'checkIn' => '25:00', 'checkOut' => '', 'reason' => 'x']);
check('invalid time refused', $r->has('valid check-in time'));
$past = (new DateTimeImmutable('-60 days'))->format('Y-m-d');
$employee->submit('/dashboard/attendance/regularizations', ['_action' => 'request', '_form' => 'request', 'date' => $past, 'checkIn' => '09:40', 'checkOut' => '19:05', 'reason' => 'Client call <b>late</b>']);
$reg = q1('SELECT * FROM AttendanceRegularization WHERE employeeId = ? ORDER BY createdAt DESC LIMIT 1', [$empId]);
check('request routed to direct manager', $reg !== null && $reg['approverId'] === $mgrId && $reg['date'] === "$past 00:00:00.000", json_encode($reg));
check('requested times stored on that date', $reg['requestedCheckIn'] === "$past 09:40:00.000");
check('manager notified', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Attendance regularization request'", [$mgrId]) >= 1);
$queue = $manager->get('/dashboard/attendance/regularizations');
check('queue shows it, reason escaped', $queue->has('Client call &lt;b&gt;late&lt;/b&gt;'));
$employee->post('/dashboard/attendance/regularizations', ['_action' => 'decide', 'id' => $reg['id'], 'approve' => '1']);
check('employee cannot approve own', qv('SELECT status FROM AttendanceRegularization WHERE id = ?', [$reg['id']]) === 'PENDING');
$nwAdmin = Client::as('admin@northwindretail.com');
$nwAdmin->post('/dashboard/attendance/regularizations', ['_action' => 'decide', 'id' => $reg['id'], 'approve' => '1'], '/dashboard');
check('other tenant cannot approve', qv('SELECT status FROM AttendanceRegularization WHERE id = ?', [$reg['id']]) === 'PENDING');
$manager->submit('/dashboard/attendance/regularizations', ['_action' => 'decide', 'id' => $reg['id'], 'approve' => '1']);
$att = q1('SELECT * FROM Attendance WHERE employeeId = ? AND date = ?', [$empId, "$past 00:00:00.000"]);
check('approval creates/updates the day as PRESENT with requested times', $att !== null && $att['status'] === 'PRESENT' && $att['checkIn'] === "$past 09:40:00.000" && $att['checkOut'] === "$past 19:05:00.000");
check('approval audited + employee notified', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'APPROVE'", [$reg['id']]) === 1
    && (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Regularization approved'", [$empId]) === 1);
$r = $manager->submit('/dashboard/attendance/regularizations', ['_action' => 'decide', 'id' => $reg['id'], 'approve' => '0']);
check('cannot decide twice', $r->has('already been actioned') && qv('SELECT status FROM AttendanceRegularization WHERE id = ?', [$reg['id']]) === 'APPROVED');

section('Leave — apply (B5) and day count');
$casual = (string)qv("SELECT id FROM LeaveType WHERE companyId = ? AND name = 'Casual Leave'", [$MW]);
$unpaid = (string)qv("SELECT id FROM LeaveType WHERE companyId = ? AND name = 'Unpaid Leave'", [$MW]);
$foreign = (string)qv('SELECT id FROM LeaveType WHERE companyId = ? LIMIT 1', [$NW]);
if ($foreign === '') {
    q("INSERT INTO LeaveType (id, companyId, name, daysPerYear) VALUES ('cforeignleavetype0000001', ?, 'NW Leave', 10)", [$NW]);
    $foreign = 'cforeignleavetype0000001';
}
$before = (int)qv('SELECT COUNT(*) FROM LeaveRequest WHERE employeeId = ?', [$empId]);
$r = $employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $foreign, 'startDate' => '2026-11-02', 'endDate' => '2026-11-03', 'reason' => 'x']);
check("B5: another company's leave type refused", $r->has('Choose a valid leave type.') && (int)qv('SELECT COUNT(*) FROM LeaveRequest WHERE employeeId = ?', [$empId]) === $before);
$r = $employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $casual, 'startDate' => '2026-11-05', 'endDate' => '2026-11-03', 'reason' => 'x']);
check('end before start refused', $r->has('Please fill in all fields correctly.'));
$employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $casual, 'startDate' => '2026-10-09', 'endDate' => '2026-10-12', 'reason' => 'Trip']);
$lr = q1('SELECT * FROM LeaveRequest WHERE employeeId = ? ORDER BY appliedAt DESC LIMIT 1', [$empId]);
check('days = calendar days inclusive (Fri→Mon = 4, original rule)', (float)$lr['days'] === 4.0, (string)$lr['days']);
check('dates stored at local midnight', $lr['startDate'] === '2026-10-09 00:00:00.000');
check('routed to manager + notified', $lr['approverId'] === $mgrId
    && (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'New leave request'", [$mgrId]) >= 1);
check('appears in manager approvals queue', $manager->get('/dashboard/leave/approvals')->has('Rohan Verma'));
check('employee cannot open approvals queue', $employee->get('/dashboard/leave/approvals')->status === 303);

section('Leave — B1 transactional approval');
$bal = q1('SELECT * FROM LeaveBalance WHERE employeeId = ? AND leaveTypeId = ? AND year = 2026', [$empId, $casual]);
$remaining = (float)$bal['allocated'] - (float)$bal['used'];
$employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $casual, 'startDate' => '2026-11-01', 'endDate' => '2026-12-31', 'reason' => 'Too long']);
$big = q1('SELECT * FROM LeaveRequest WHERE employeeId = ? ORDER BY appliedAt DESC LIMIT 1', [$empId]);
$r = $manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $big['id'], 'approve' => '1']);
check('over-balance approval shows the reason', $r->has('would exceed the employee’s remaining Casual Leave balance'), substr(strip_tags($r->body), 0, 0));
check('B1: request stays PENDING after the failed approval', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$big['id']]) === 'PENDING');
check('B1: balance unchanged', (float)qv('SELECT used FROM LeaveBalance WHERE id = ?', [$bal['id']]) === (float)$bal['used']);
check('B1: no APPROVE audit written', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'APPROVE'", [$big['id']]) === 0);
$manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $lr['id'], 'approve' => '1']);
check('within balance → APPROVED', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$lr['id']]) === 'APPROVED');
check('balance used += 4', (float)qv('SELECT used FROM LeaveBalance WHERE id = ?', [$bal['id']]) === (float)$bal['used'] + 4);
check('employee notified', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Leave request approved'", [$empId]) === 1);
$r = $manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $lr['id'], 'approve' => '1']);
check('double approval refused (no double deduction)', (float)qv('SELECT used FROM LeaveBalance WHERE id = ?', [$bal['id']]) === (float)$bal['used'] + 4);
$manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $big['id'], 'approve' => '0']);
check('reject works', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$big['id']]) === 'REJECTED');

section('Leave — unpaid, B3 next year, authority');
$employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $unpaid, 'startDate' => '2026-12-01', 'endDate' => '2026-12-02', 'reason' => 'Personal']);
$up = q1('SELECT * FROM LeaveRequest WHERE employeeId = ? ORDER BY appliedAt DESC LIMIT 1', [$empId]);
$manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $up['id'], 'approve' => '1']);
check('unpaid leave approved without any balance', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$up['id']]) === 'APPROVED'
    && (int)qv('SELECT COUNT(*) FROM LeaveBalance WHERE employeeId = ? AND leaveTypeId = ?', [$empId, $unpaid]) === 0);
$employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $casual, 'startDate' => '2027-01-04', 'endDate' => '2027-01-05', 'reason' => 'New year']);
$ny = q1('SELECT * FROM LeaveRequest WHERE employeeId = ? ORDER BY appliedAt DESC LIMIT 1', [$empId]);
$manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $ny['id'], 'approve' => '1']);
check('B3: 2027 balances created on demand, then deducted', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$ny['id']]) === 'APPROVED'
    && (float)qv('SELECT used FROM LeaveBalance WHERE employeeId = ? AND leaveTypeId = ? AND year = 2027', [$empId, $casual]) === 2.0);
$manager->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $casual, 'startDate' => '2026-11-16', 'endDate' => '2026-11-16', 'reason' => 'Mine']);
$own = q1('SELECT * FROM LeaveRequest WHERE employeeId = ? ORDER BY appliedAt DESC LIMIT 1', [$mgrId]);
$r = $manager->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $own['id'], 'approve' => '1']);
check('nobody approves their own leave', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$own['id']]) === 'PENDING');
check("manager's leave routed to their manager (Company Admin)", $own['approverId'] === emp_id('admin@meridianworks.com'));
$fin2 = emp_id('finance@meridianworks.com');
$other = q1("SELECT r.* FROM LeaveRequest r WHERE r.status = 'PENDING' AND r.employeeId <> ? AND r.approverId <> ? LIMIT 1", [$mgrId, $mgrId]);
if ($other) {
    $manager->post('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $other['id'], 'approve' => '1']);
    check('manager cannot act on a request not assigned to them', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$other['id']]) === 'PENDING');
}
$hr->submit('/dashboard/leave/approvals', ['_action' => 'decide', 'id' => $own['id'], 'approve' => '1']);
check('HR (company-wide override) can approve', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$own['id']]) === 'APPROVED');

section('Leave — cancel');
$employee->submit('/dashboard/leave', ['_action' => 'apply', '_form' => 'apply', 'leaveTypeId' => $casual, 'startDate' => '2026-11-20', 'endDate' => '2026-11-20', 'reason' => 'Cancel me']);
$cx = q1('SELECT * FROM LeaveRequest WHERE employeeId = ? ORDER BY appliedAt DESC LIMIT 1', [$empId]);
$manager->post('/dashboard/leave', ['_action' => 'cancel', 'id' => $cx['id']]);
check("cannot cancel someone else's request", qv('SELECT status FROM LeaveRequest WHERE id = ?', [$cx['id']]) === 'PENDING');
$employee->post('/dashboard/leave', ['_action' => 'cancel', 'id' => $cx['id']]);
check('own pending request cancelled', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$cx['id']]) === 'CANCELLED');
$employee->post('/dashboard/leave', ['_action' => 'cancel', 'id' => $lr['id']]);
check('approved request cannot be cancelled', qv('SELECT status FROM LeaveRequest WHERE id = ?', [$lr['id']]) === 'APPROVED');
$page = $employee->get('/dashboard/leave');
check('leave page shows balances and history', $page->has('days left') && $page->has('Trip') === false && $page->has('Casual Leave'));

finish();
