<?php
/**
 * Leave-balance allocation (B3 — new in the PHP version, approved).
 *
 * The original only ever created LeaveBalance rows in the seed script, so an
 * employee added later had no balance and a paid-leave approval always
 * failed with "0 day(s) left". Now every employee gets one row per *paid*
 * company leave type per year, allocated = LeaveType.daysPerYear (the
 * configured annual days — no pro-rating), exactly what the seed created.
 * Unpaid types get no balance: approval never checks one for them (original
 * behaviour). Creation is idempotent
 * (unique key employeeId+leaveTypeId+year, existing rows never touched) and
 * request-driven (no cron): on employee creation/import, when the employee
 * opens Leave, and when a leave request is approved for that year.
 * LeaveType.carryForward remains informational, exactly as in the original.
 */
declare(strict_types=1);

/** Creates any missing balances for $employeeId in $year. Returns the number created. */
function ensure_leave_balances(string $companyId, string $employeeId, int $year): int
{
    $types = db_all('SELECT id, daysPerYear FROM LeaveType WHERE companyId = ? AND paid = 1', [$companyId]);
    if (!$types) {
        return 0;
    }
    $existing = array_flip(db_column('SELECT leaveTypeId FROM LeaveBalance WHERE employeeId = ? AND year = ?', [$employeeId, $year]));
    $created = 0;
    foreach ($types as $t) {
        if (isset($existing[$t['id']])) {
            continue;
        }
        // INSERT IGNORE: a concurrent request creating the same row is not an error.
        db_exec(
            'INSERT IGNORE INTO LeaveBalance (id, employeeId, leaveTypeId, year, allocated, used) VALUES (?, ?, ?, ?, ?, 0)',
            [new_id(), $employeeId, $t['id'], $year, (float)$t['daysPerYear']]
        );
        $created++;
    }
    return $created;
}
