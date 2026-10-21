<?php
/**
 * Attendance calendar: one month of an employee's days (check-in/out,
 * late, absent, leave, holiday, weekly off) and holiday management for
 * Company Admin / HR from the same calendar.
 */
declare(strict_types=1);

/**
 * Weekly off days (0 = Sunday … 6 = Saturday) from the company policy's work
 * week: "MON-SAT" → Sunday only; anything else (default "MON-FRI") → Sat + Sun.
 */
function company_weekly_offs(string $companyId): array
{
    $week = strtoupper((string)db_value('SELECT workWeek FROM CompanyPolicy WHERE companyId = ?', [$companyId]));
    return str_contains($week, 'SAT') ? [0] : [0, 6];
}

/**
 * Birthdays and work anniversaries of the company's active employees that
 * fall in the given month, keyed by day of month. A 29 Feb date is shown on
 * 28 Feb in non-leap years. Only names are exposed (never the birth year).
 *
 * @return array{birthdays: array<int, list<array>>, anniversaries: array<int, list<array>>}
 */
function company_month_events(string $companyId, int $year, int $month): array
{
    $out = ['birthdays' => [], 'anniversaries' => []];
    $lastDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $rows = db_all("SELECT id, firstName, lastName, avatarColor, dateOfBirth, dateOfJoining FROM Employee WHERE companyId = ? AND status <> 'EXITED' ORDER BY firstName, lastName", [$companyId]);
    foreach ($rows as $e) {
        foreach (['birthdays' => $e['dateOfBirth'], 'anniversaries' => $e['dateOfJoining']] as $kind => $raw) {
            $d = $raw ? dt($raw) : null;
            if ($d === null || (int)$d->format('Y') < 1900 || (int)$d->format('n') !== $month) {
                continue;
            }
            $years = $year - (int)$d->format('Y');
            if ($kind === 'anniversaries' && $years < 1) {
                continue;
            }
            $out[$kind][min((int)$d->format('j'), $lastDay)][] = $e + ['years' => $years];
        }
    }
    ksort($out['birthdays']);
    ksort($out['anniversaries']);
    return $out;
}

/** "8h 25m" from minutes. */
function format_minutes(int $mins): string
{
    return $mins <= 0 ? DASH : intdiv($mins, 60) . 'h ' . str_pad((string)($mins % 60), 2, '0', STR_PAD_LEFT) . 'm';
}

/**
 * Every day of the month starting at $monthStart for one employee (or, with
 * $employee null, holidays and weekly offs only). Each day:
 *   [date, day, dow, status, label, tone, details[]] — details feed the popup.
 */
function attendance_calendar_month(string $companyId, ?array $employee, DateTimeImmutable $monthStart, bool $withHolidayIds): array
{
    $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);
    $today = today_start()->format('Y-m-d');
    $offs = company_weekly_offs($companyId);

    $holidays = [];
    foreach (db_all('SELECT id, name, date FROM Holiday WHERE companyId = ? AND date BETWEEN ? AND ? ORDER BY date', [$companyId, $monthStart, $monthEnd]) as $h) {
        $holidays[dt($h['date'])->format('Y-m-d')] = $h;
    }

    $records = [];
    $leaves = [];
    $branch = null;
    if ($employee !== null) {
        foreach (db_all('SELECT * FROM Attendance WHERE employeeId = ? AND date BETWEEN ? AND ?', [$employee['id'], $monthStart, $monthEnd]) as $a) {
            $records[dt($a['date'])->format('Y-m-d')] = $a;
        }
        $leaves = db_all(
            "SELECT r.startDate, r.endDate, r.status, r.days, t.name AS type FROM LeaveRequest r JOIN LeaveType t ON t.id = r.leaveTypeId
              WHERE r.employeeId = ? AND r.status IN ('APPROVED','PENDING') AND r.startDate <= ? AND r.endDate >= ?",
            [$employee['id'], $monthEnd, $monthStart]
        );
        $branch = $employee['branchId'] ? db_one('SELECT name, latitude, longitude FROM Branch WHERE id = ?', [$employee['branchId']]) : null;
    }
    $joined = $employee !== null ? dt($employee['dateOfJoining'])->format('Y-m-d') : null;
    $events = company_month_events($companyId, (int)$monthStart->format('Y'), (int)$monthStart->format('n'));
    $names = static fn(array $list, bool $withYears = false) => implode(', ', array_map(
        static fn($p) => trim($p['firstName'] . ' ' . $p['lastName']) . ($withYears ? ' (' . $p['years'] . ($p['years'] === 1 ? ' year' : ' years') . ')' : ''), $list));

    $days = [];
    for ($d = $monthStart; $d <= $monthEnd; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $dow = dow($d);
        $holiday = $holidays[$key] ?? null;
        $record = $records[$key] ?? null;
        $leave = null;
        foreach ($leaves as $l) {
            if ($key >= dt($l['startDate'])->format('Y-m-d') && $key <= dt($l['endDate'])->format('Y-m-d')) {
                if ($leave === null || $l['status'] === 'APPROVED') {
                    $leave = $l;
                }
            }
        }

        $details = ['date' => $d->format('l, d F Y')];
        if ($holiday) {
            $details['holiday'] = $holiday['name'];
            if ($withHolidayIds) {
                $details['holidayId'] = $holiday['id'];
            }
        }
        if ($leave) {
            $details['leave'] = $leave['type'] . ($leave['status'] === 'PENDING' ? ' (awaiting approval)' : ' (approved)');
        }
        $dayNo = (int)$d->format('j');
        if (isset($events['birthdays'][$dayNo])) {
            $details['birthdays'] = $names($events['birthdays'][$dayNo]);
        }
        if (isset($events['anniversaries'][$dayNo])) {
            $details['anniversaries'] = $names($events['anniversaries'][$dayNo], true);
        }

        if ($employee === null) {
            [$status, $label, $tone] = $holiday ? ['HOLIDAY', 'Holiday', 'holiday']
                : (in_array($dow, $offs, true) ? ['WEEK_OFF', 'Off', 'off'] : ['', '', 'none']);
        } elseif ($record && ($record['checkIn'] || $record['status'] !== 'PRESENT')) {
            $status = $record['status'];
            $label = $record['checkIn'] ? format_time($record['checkIn']) : ucfirst(strtolower(str_replace('_', ' ', $status)));
            $tone = match ($status) {
                'PRESENT' => 'present', 'LATE', 'HALF_DAY' => 'late', 'ABSENT' => 'absent',
                'ON_LEAVE' => 'leave', 'HOLIDAY' => 'holiday', default => 'off',
            };
            $details['checkIn'] = $record['checkIn'] ? format_time($record['checkIn']) : DASH;
            $details['checkOut'] = $record['checkOut'] ? format_time($record['checkOut']) : ($key === $today ? 'Not yet' : 'Not recorded');
            $mins = (int)$record['workedMins'];
            if ($mins === 0 && $record['checkIn'] && $record['checkOut']) {
                $mins = (int)round((dt($record['checkOut'])->getTimestamp() - dt($record['checkIn'])->getTimestamp()) / 60);
            }
            $details['worked'] = format_minutes($mins);
            if ($record['checkInLat'] !== null && $record['checkInLng'] !== null) {
                $lat = (float)$record['checkInLat'];
                $lng = (float)$record['checkInLng'];
                $details['mapUrl'] = 'https://www.google.com/maps?q=' . number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '');
                $details['location'] = $branch && $branch['latitude'] !== null
                    ? '~' . (int)round(distance_meters($lat, $lng, (float)$branch['latitude'], (float)$branch['longitude'])) . ' m from ' . $branch['name']
                    : 'View on map';
            }
        } elseif ($holiday) {
            [$status, $label, $tone] = ['HOLIDAY', 'Holiday', 'holiday'];
        } elseif ($leave && $leave['status'] === 'APPROVED') {
            [$status, $label, $tone] = ['ON_LEAVE', 'Leave', 'leave'];
        } elseif (in_array($dow, $offs, true)) {
            [$status, $label, $tone] = ['WEEK_OFF', 'Off', 'off'];
        } elseif ($joined !== null && $key < $joined) {
            [$status, $label, $tone] = ['NOT_JOINED', '', 'none'];
        } elseif ($key > $today) {
            [$status, $label, $tone] = $leave ? ['PENDING_LEAVE', 'Leave?', 'pending'] : ['UPCOMING', '', 'none'];
        } elseif ($key === $today) {
            [$status, $label, $tone] = ['NOT_CHECKED_IN', 'Not in', 'pending'];
        } else {
            [$status, $label, $tone] = ['ABSENT', 'Absent', 'absent'];
        }

        $details['status'] = match ($status) {
            'NOT_JOINED' => 'Before joining date', 'UPCOMING' => 'Upcoming', 'NOT_CHECKED_IN' => 'Not checked in yet',
            'PENDING_LEAVE' => 'Leave requested', 'WEEK_OFF' => 'Weekly off', 'ON_LEAVE' => 'On leave',
            '' => '', default => ucfirst(strtolower(str_replace('_', ' ', $status))),
        };
        $details['tone'] = $tone;
        $days[] = ['date' => $key, 'day' => (int)$d->format('j'), 'dow' => $dow, 'status' => $status, 'label' => $label,
            'tone' => $tone, 'isToday' => $key === $today, 'details' => $details];
    }
    return $days;
}

/** Company Admin / HR: add a holiday on a date (one per date). */
function action_add_calendar_holiday(array $auth, string $dateRaw, string $name): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only the Company Admin or HR can manage holidays.');
    }
    $companyId = require_company($auth);
    $date = v_date(trim($dateRaw), 'date');
    $name = v_required(trim($name), 'Holiday name');
    if (db_value('SELECT 1 FROM Holiday WHERE companyId = ? AND date = ?', [$companyId, $date])) {
        throw new UserError('There is already a holiday on ' . format_date($date) . '.');
    }
    $id = db_insert('Holiday', ['companyId' => $companyId, 'name' => $name, 'date' => $date]);
    audit($auth, 'CREATE', 'Holiday', $id, ['name' => $name, 'date' => $date->format('Y-m-d')]);
    flash('success', "Holiday “{$name}” added on " . format_date($date) . '.', 'calendar');
}

/** Company Admin / HR: remove a holiday of their own company. */
function action_delete_holiday(array $auth, string $holidayId): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only the Company Admin or HR can manage holidays.');
    }
    $companyId = require_company($auth);
    $h = is_valid_id($holidayId) ? db_one('SELECT id, name, date FROM Holiday WHERE id = ? AND companyId = ?', [$holidayId, $companyId]) : null;
    if ($h === null) {
        throw new UserError('Holiday not found.');
    }
    db_exec('DELETE FROM Holiday WHERE id = ?', [$h['id']]);
    audit($auth, 'DELETE', 'Holiday', $h['id'], ['name' => $h['name'], 'date' => dt($h['date'])->format('Y-m-d')]);
    flash('success', "Holiday “{$h['name']}” removed.", 'calendar');
}
