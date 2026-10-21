<?php
/**
 * Employee details + attendance-by-range cards, shared by /dashboard/profile
 * and /dashboard/employees/{id}. Range comes from ?month=YYYY-MM or ?from=&to=
 * (default: the current month).
 */
declare(strict_types=1);

/** @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: bool} inclusive [from, to, explicitly filtered] */
function attendance_range_from_query(): array
{
    $parse = static function (string $v): ?DateTimeImmutable {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, app_tz());
        return $d && $d->format('Y-m-d') === $v ? $d : null;
    };
    $month = query('month');
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $from = new DateTimeImmutable($month . '-01', app_tz());
        return [$from, $from->modify('last day of this month'), true];
    }
    $from = $parse(query('from'));
    $to = $parse(query('to'));
    if ($from && $to) {
        return $from <= $to ? [$from, $to, true] : [$to, $from, true];
    }
    $first = today_start()->modify('first day of this month');
    return [$first, $first->modify('last day of this month'), false];
}

/** @return array{details: string, attendance: string} */
function employee_panel_cards(array $auth, array $employee, bool $showSalary): array
{
    $id = $employee['id'];
    [$from, $to, $filtered] = attendance_range_from_query();
    $toExclusive = $to->modify('+1 day');

    // Stats always cover [from, to]; the list shows the latest records unless a range was chosen.
    $stats = db_all('SELECT status FROM Attendance WHERE employeeId = ? AND date >= ? AND date < ?', [$id, $from, $toExclusive]);
    $where = $filtered ? ' AND date >= ? AND date < ?' : '';
    $args = $filtered ? [$id, $from, $toExclusive] : [$id];
    $perPage = 10;
    $total = (int)db_value('SELECT COUNT(*) FROM Attendance WHERE employeeId = ?' . $where, $args);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min(query_page(), $totalPages);
    $rows = db_all('SELECT * FROM Attendance WHERE employeeId = ?' . $where . ' ORDER BY date DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $args);
    $present = 0.0;
    $absent = 0;
    $onLeave = 0;
    $working = 0;
    foreach ($stats as $a) {
        if (in_array($a['status'], ['WEEK_OFF', 'HOLIDAY'], true)) {
            continue;
        }
        $working++;
        match ($a['status']) {
            'PRESENT', 'LATE' => $present += 1,
            'HALF_DAY' => $present += 0.5,
            'ON_LEAVE' => $onLeave++,
            default => $absent++,
        };
    }
    $leaves = db_all(
        'SELECT status, days FROM LeaveRequest WHERE employeeId = ? AND startDate < ? AND endDate >= ?',
        [$id, $toExclusive, $from]
    );
    $leaveApplied = count($leaves);
    $leaveApprovedDays = array_sum(array_map(static fn($l) => $l['status'] === 'APPROVED' ? (float)$l['days'] : 0.0, $leaves));
    $salary = $showSalary ? db_one('SELECT * FROM SalaryStructure WHERE employeeId = ?', [$id]) : null;
    $gross = $salary ? (float)$salary['basic'] + (float)$salary['hra'] + (float)$salary['conveyance'] + (float)$salary['medicalAllowance'] + (float)$salary['specialAllowance'] : null;

    $item = static fn(string $l, string $v) => '<div><p class="text-xs text-muted">' . e($l) . '</p><p class="font-medium text-ink mt-0.5">' . e($v) . '</p></div>';
    $name = $employee['firstName'] . ' ' . $employee['lastName'];
    $periodLabel = format_date($from) . ' – ' . format_date($to);
    $phone = trim((string)($employee['phone'] ?? ''));
    $address = trim((string)($employee['address'] ?? ''));

    $details = '<div class="card p-5"><p class="text-sm font-semibold text-ink mb-3">Employee details</p>'
        . '<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">'
        . $item('Name', $name)
        . $item('Joining date', format_date($employee['dateOfJoining']))
        . $item('Contact', $phone !== '' ? $phone : DASH)
        . $item('Address', $address !== '' ? $address : DASH)
        . ($showSalary ? $item('Salary per month', $gross !== null ? rupees($gross) : DASH) : '')
        . $item('Leaves applied', $leaveApplied . ' (' . js_num($leaveApprovedDays) . ' day(s) approved)')
        . $item('Working days', (string)$working)
        . $item('Days present', js_num($present))
        . $item('Absent / on leave', $absent . ' / ' . $onLeave)
        . '</div><p class="text-xs text-muted mt-3">Leave and attendance figures are for ' . e($periodLabel) . '.</p></div>';

    $isFullMonth = $from->format('d') === '01' && $to->format('Y-m-d') === $from->modify('last day of this month')->format('Y-m-d');
    $monthVal = $isFullMonth ? $from->format('Y-m') : '';
    $att = '<div class="card p-5 overflow-x-auto"><p class="text-sm font-semibold text-ink mb-3">Attendance</p>'
        . '<div class="flex flex-wrap items-end gap-4 mb-4">'
        . '<form method="get" class="flex items-end gap-2"><div><label class="field-label">Month</label><input type="month" name="month" value="' . e($monthVal) . '" class="input !py-1.5 text-sm"></div>'
        . '<button type="submit" class="btn btn-secondary text-sm">View</button></form>'
        . '<form method="get" class="flex items-end gap-2"><div><label class="field-label">From</label><input type="date" name="from" value="' . e($from->format('Y-m-d')) . '" class="input !py-1.5 text-sm"></div>'
        . '<div><label class="field-label">To</label><input type="date" name="to" value="' . e($to->format('Y-m-d')) . '" class="input !py-1.5 text-sm"></div>'
        . '<button type="submit" class="btn btn-secondary text-sm">Apply</button></form></div>'
        . '<table class="table-clean"><thead><tr><th>Date</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $a) {
        $att .= '<tr><td>' . e(format_date($a['date'])) . '</td><td>' . e($a['checkIn'] ? format_time($a['checkIn']) : DASH) . '</td><td>'
            . e($a['checkOut'] ? format_time($a['checkOut']) : DASH) . '</td><td>' . status_badge($a['status']) . '</td></tr>';
    }
    if (!$rows) {
        $att .= '<tr><td colspan="4" class="text-center text-muted py-6">' . ($filtered ? 'No attendance recorded for ' . e($periodLabel) . '.' : 'No attendance recorded yet.') . '</td></tr>';
    }
    $att .= '</tbody></table>' . pagination($page, $totalPages, request_path(), $filtered ? array_filter(['month' => query('month'), 'from' => query('from'), 'to' => query('to')]) : []) . '</div>';

    return ['details' => $details, 'attendance' => $att];
}
