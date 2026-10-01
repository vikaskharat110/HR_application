<?php
/**
 * Unauthorized-absence (LOP) days — port of lib/absence.ts. A weekday
 * (Mon–Fri) in [start, end] with no check-in, that isn't a company holiday,
 * isn't covered by an APPROVED leave request, and isn't before the joining
 * date. Computed on read (no ABSENT rows are ever written).
 *
 * Comparison is by calendar date (Y-m-d in Asia/Kolkata). The original
 * compared local-midnight days against leave dates stored at UTC midnight
 * (05:30 IST), which charged the first day of every approved leave as LOP.
 *
 * @param list<string> $employeeIds
 * @return array<string,int> employeeId => days
 */
function unauthorized_absence_days(string $companyId, array $employeeIds, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $result = array_fill_keys($employeeIds, 0);
    if (!$employeeIds) {
        return $result;
    }
    [$in, $ids] = db_in($employeeIds);
    $holidays = array_flip(array_map(static fn($d) => substr($d, 0, 10),
        db_column('SELECT date FROM Holiday WHERE companyId = ? AND date >= ? AND date <= ?', [$companyId, $start, $end])));
    $present = [];
    foreach (db_all("SELECT employeeId, date FROM Attendance WHERE employeeId IN ($in) AND date >= ? AND date <= ? AND checkIn IS NOT NULL", [...$ids, $start, $end]) as $a) {
        $present[$a['employeeId']][substr($a['date'], 0, 10)] = true;
    }
    $leaves = [];
    foreach (db_all("SELECT employeeId, startDate, endDate FROM LeaveRequest WHERE employeeId IN ($in) AND status = 'APPROVED' AND startDate <= ? AND endDate >= ?", [...$ids, $end, start_of_day($start)]) as $l) {
        $leaves[$l['employeeId']][] = [substr($l['startDate'], 0, 10), substr($l['endDate'], 0, 10)];
    }
    $joined = [];
    foreach (db_all("SELECT id, dateOfJoining FROM Employee WHERE id IN ($in)", $ids) as $e) {
        $joined[$e['id']] = substr($e['dateOfJoining'], 0, 10);
    }

    $last = start_of_day($end)->format('Y-m-d');
    foreach ($employeeIds as $employeeId) {
        $count = 0;
        for ($cursor = start_of_day($start); $cursor->format('Y-m-d') <= $last; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $dow = dow($cursor);
            if ($dow === 0 || $dow === 6 || isset($holidays[$key]) || isset($present[$employeeId][$key])) {
                continue;
            }
            if (isset($joined[$employeeId]) && $key < $joined[$employeeId]) {
                continue;
            }
            foreach ($leaves[$employeeId] ?? [] as [$ls, $le]) {
                if ($key >= $ls && $key <= $le) {
                    continue 2;
                }
            }
            $count++;
        }
        $result[$employeeId] = $count;
    }
    return $result;
}
