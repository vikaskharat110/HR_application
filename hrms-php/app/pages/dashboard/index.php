<?php
/** /dashboard — port of app/dashboard/page.tsx (role-scoped home + Super Admin platform overview). */
declare(strict_types=1);

if ($auth['role'] === 'SUPER_ADMIN') {
    $companies = (int)db_value('SELECT COUNT(*) FROM Company');
    $totalUsers = (int)db_value('SELECT COUNT(*) FROM `User`');
    $totalEmployees = (int)db_value('SELECT COUNT(*) FROM Employee');
    $recent = db_all(
        'SELECT c.id, c.name, c.industry, c.createdAt,
                (SELECT COUNT(*) FROM `User` u WHERE u.companyId = c.id) AS users,
                (SELECT COUNT(*) FROM Employee e WHERE e.companyId = c.id) AS employees
           FROM Company c ORDER BY c.createdAt DESC LIMIT 6'
    );
    app_layout_open($auth, 'Platform overview');
    echo page_header('Platform overview', 'Every tenant on WorkNest, at a glance.',
        '<a href="' . e(url('/dashboard/companies')) . '" class="btn btn-primary">' . icon('building-2', 15) . ' Manage companies</a>');
    ?>
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
        <?= stat_card('Companies', $companies, 'building-2', 'brand') ?>
        <?= stat_card('Total users', $totalUsers, 'users', 'info') ?>
        <?= stat_card('Total employees', $totalEmployees, 'circle-check', 'accent') ?>
    </div>
    <div class="card overflow-x-auto">
        <table class="table-clean">
            <thead><tr><th>Company</th><th>Industry</th><th>Users</th><th>Employees</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $c): ?>
                <tr>
                    <td class="font-medium text-[var(--color-ink)]"><?= e($c['name']) ?></td>
                    <td><?= e($c['industry'] ?: DASH) ?></td>
                    <td><?= (int)$c['users'] ?></td>
                    <td><?= (int)$c['employees'] ?></td>
                    <td><?= e(format_date($c['createdAt'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    app_layout_close();
    return;
}

$empIds = visible_employee_ids($auth);
$managerial = is_managerial($auth);
$startOfToday = today_start();
$fourteenDaysAgo = now()->modify('-14 days');
[$in, $ids] = db_in($empIds);

$headcount = (int)db_value("SELECT COUNT(*) FROM Employee WHERE id IN ($in) AND status = 'ACTIVE'", $ids);
$presentToday = (int)db_value("SELECT COUNT(*) FROM Attendance WHERE employeeId IN ($in) AND date >= ? AND status IN ('PRESENT','LATE')", [...$ids, $startOfToday]);
$pendingLeave = (int)db_value("SELECT COUNT(*) FROM LeaveRequest WHERE employeeId IN ($in) AND status = 'PENDING'", $ids);
$pendingExpense = (int)db_value("SELECT COUNT(*) FROM ExpenseClaim WHERE employeeId IN ($in) AND status = 'PENDING'", $ids);
$openTickets = (int)db_value("SELECT COUNT(*) FROM Ticket WHERE raisedById IN ($in) AND status IN ('OPEN','IN_PROGRESS')", $ids);
$myBalances = $auth['employeeId'] ? db_all(
    'SELECT b.id, b.allocated, b.used, t.name FROM LeaveBalance b JOIN LeaveType t ON t.id = b.leaveTypeId
      WHERE b.employeeId = ? AND b.year = ? ORDER BY t.name',
    [$auth['employeeId'], (int)now()->format('Y')]
) : [];

// Present/late headcount per day over the last 14 days.
$trendRows = db_all(
    "SELECT DATE(date) AS d, COUNT(*) AS c FROM Attendance
      WHERE employeeId IN ($in) AND date >= ? AND status IN ('PRESENT','LATE')
      GROUP BY DATE(date) ORDER BY d",
    [...$ids, $fourteenDaysAgo]
);
$trendData = array_map(static fn($r) => ['day' => format_day_month($r['d']), 'present' => (int)$r['c']], $trendRows);

$deptData = [];
$approvalsQueue = [];
$birthdays = [];
$anniversaries = [];
if ($managerial) {
    $deptData = db_all(
        'SELECT d.name AS department, (SELECT COUNT(*) FROM Employee e WHERE e.departmentId = d.id) AS headcount
           FROM Department d WHERE d.companyId = ? ORDER BY headcount DESC, d.name',
        [$auth['companyId']]
    );
    $deptData = array_map(static fn($r) => ['department' => $r['department'], 'headcount' => (int)$r['headcount']], $deptData);
    $approvalsQueue = db_all(
        "SELECT r.id, r.status, r.days, r.startDate, t.name AS leaveType, e.firstName, e.lastName, e.avatarColor
           FROM LeaveRequest r JOIN Employee e ON e.id = r.employeeId JOIN LeaveType t ON t.id = r.leaveTypeId
          WHERE r.employeeId IN ($in) AND r.status = 'PENDING' ORDER BY r.appliedAt DESC LIMIT 5",
        $ids
    );
}
// Birthdays / work anniversaries this month — company-wide, shown to everyone (names and day only).
$today = today_start();
$monthEvents = company_month_events($auth['companyId'], (int)$today->format('Y'), (int)$today->format('n'));
$upcomingHolidays = db_all('SELECT id, name, date FROM Holiday WHERE companyId = ? AND date >= ? ORDER BY date ASC LIMIT 5', [$auth['companyId'], $startOfToday]);
/** "Today" / "Tomorrow" / "in 12 days" for a date. */
$inDays = static function (DateTimeImmutable $d) use ($today): string {
    $n = (int)$today->diff($d)->format('%r%a');
    return $n === 0 ? 'Today' : ($n === 1 ? 'Tomorrow' : "in $n days");
};

$quickLink = static fn(string $href, string $iconName, string $label): string =>
    '<a href="' . e(url($href)) . '" class="flex flex-col items-start gap-2 rounded-lg border border-[var(--color-border)] px-3.5 py-3 hover:border-[var(--color-brand-soft)] hover:bg-[var(--color-brand-softer)] transition-colors">'
    . icon($iconName, 17, 'text-[var(--color-brand)]') . '<span class="text-xs font-medium text-[var(--color-ink)]">' . e($label) . '</span></a>';

app_layout_open($auth, 'Dashboard');
echo page_header('Welcome back, ' . explode(' ', $auth['name'])[0], 'Here’s what’s moving across your organisation today.');
?>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <?= stat_card($managerial ? 'Active employees' : 'Team size', $headcount, 'users', 'brand') ?>
    <?= stat_card('Present today', $presentToday, 'user-check', 'info', "of $headcount expected") ?>
    <?= stat_card('Pending leave', $pendingLeave, 'calendar-clock', 'warning') ?>
    <?= stat_card('Pending expenses', $pendingExpense, 'receipt', 'accent') ?>
    <?= stat_card('Open tickets', $openTickets, 'life-buoy', 'danger') ?>
</div>

<div class="grid lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 space-y-5">
        <div class="card p-5">
            <p class="text-sm font-semibold text-[var(--color-ink)] mb-1">Attendance — last 14 days</p>
            <p class="text-xs text-[var(--color-muted)] mb-3">Employees marked present or late, company-wide.</p>
            <?php if ($trendData): ?>
                <?= chart_trend_line($trendData, 'present', 'day') ?>
            <?php else: ?>
                <p class="text-sm text-[var(--color-muted)] py-10 text-center">No attendance recorded yet.</p>
            <?php endif; ?>
        </div>

        <?php if ($managerial && $deptData): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-[var(--color-ink)] mb-1">Headcount by department</p>
                <p class="text-xs text-[var(--color-muted)] mb-3">Active employees per department.</p>
                <?= chart_rank_bar($deptData, 'headcount', 'department', true) ?>
            </div>
        <?php endif; ?>

        <?php if (!$managerial): ?>
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm font-semibold text-[var(--color-ink)]">My leave balance — <?= e(now()->format('Y')) ?></p>
                    <a href="<?= e(url('/dashboard/leave')) ?>" class="text-xs font-medium text-[var(--color-brand)] flex items-center gap-1 hover:underline">Apply for leave <?= icon('arrow-right', 12) ?></a>
                </div>
                <div class="grid sm:grid-cols-3 gap-3">
                    <?php foreach ($myBalances as $b): ?>
                        <div class="rounded-lg border border-[var(--color-border)] px-4 py-3">
                            <p class="text-xs font-medium text-[var(--color-muted)]"><?= e($b['name']) ?></p>
                            <p class="font-display text-xl font-semibold text-[var(--color-ink)] mt-1">
                                <?= e(js_num($b['allocated'] - $b['used'])) ?>
                                <span class="text-sm font-normal text-[var(--color-muted)]"> / <?= e(js_num($b['allocated'])) ?> days</span>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="space-y-5">
        <?php if ($managerial): ?>
            <div class="card p-5">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm font-semibold text-[var(--color-ink)]">Awaiting your approval</p>
                    <a href="<?= e(url('/dashboard/leave/approvals')) ?>" class="text-xs font-medium text-[var(--color-brand)] hover:underline">View all</a>
                </div>
                <?php if (!$approvalsQueue): ?>
                    <p class="text-sm text-[var(--color-muted)] py-4">Nothing pending. Nice and clear.</p>
                <?php else: ?>
                    <ul class="space-y-3">
                        <?php foreach ($approvalsQueue as $r): ?>
                            <li class="flex items-center gap-3">
                                <?= avatar($r['firstName'], $r['lastName'], $r['avatarColor']) ?>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-[var(--color-ink)] truncate"><?= e($r['firstName'] . ' ' . $r['lastName']) ?></p>
                                    <p class="text-xs text-[var(--color-muted)]"><?= e($r['leaveType']) ?> · <?= e(js_num($r['days'])) ?>d from <?= e(format_date($r['startDate'])) ?></p>
                                </div>
                                <?= status_badge($r['status']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card p-5">
            <p class="text-sm font-semibold text-[var(--color-ink)] mb-3">Quick actions</p>
            <div class="grid grid-cols-2 gap-2.5">
                <?= $quickLink('/dashboard/attendance', 'fingerprint', 'Check in/out') ?>
                <?= $quickLink('/dashboard/calendar', 'calendar-days', 'Calendar') ?>
                <?= $quickLink('/dashboard/leave', 'calendar-clock', 'Apply leave') ?>
                <?= $quickLink('/dashboard/payroll', 'wallet', 'Payslips') ?>
                <?= $quickLink('/dashboard/helpdesk', 'life-buoy', 'Raise ticket') ?>
                <?= $quickLink('/dashboard/exit/new', 'log-out', 'Resign') ?>
            </div>
        </div>

        <div class="card p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-semibold text-[var(--color-ink)]">Upcoming holidays</p>
                <a href="<?= e(url('/dashboard/calendar')) ?>" class="text-xs font-medium text-[var(--color-brand)] hover:underline">View calendar</a>
            </div>
            <?php if (!$upcomingHolidays): ?>
                <p class="text-sm text-[var(--color-muted)]">None scheduled.</p>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($upcomingHolidays as $h): $hd = dt($h['date']); ?>
                        <li class="flex items-center gap-3 text-sm">
                            <span class="grid place-items-center w-11 h-11 rounded-xl bg-violet-50 text-violet-700 border border-violet-200 shrink-0 leading-none">
                                <span class="text-[10px] font-bold uppercase"><?= e($hd->format('M')) ?></span>
                                <span class="text-sm font-extrabold -mt-1"><?= e($hd->format('d')) ?></span>
                            </span>
                            <span class="flex-1 min-w-0">
                                <span class="block truncate font-medium text-[var(--color-ink)]"><?= e($h['name']) ?></span>
                                <span class="block text-xs text-[var(--color-muted)]"><?= e($hd->format('l, d M Y')) ?></span>
                            </span>
                            <span class="text-xs font-semibold text-violet-700 shrink-0"><?= e($inDays($hd)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card p-5">
            <p class="text-sm font-semibold text-[var(--color-ink)] mb-3">Birthdays &amp; anniversaries — <?= e($today->format('F')) ?></p>
            <?php if (!$monthEvents['birthdays'] && !$monthEvents['anniversaries']): ?>
                <p class="text-sm text-[var(--color-muted)]">No birthdays or work anniversaries this month.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach (['birthdays' => ['cake', 'Birthdays'], 'anniversaries' => ['award', 'Work anniversaries']] as $kind => [$ico, $heading]): ?>
                        <?php if ($monthEvents[$kind]): ?>
                            <div>
                                <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)] mb-2"><?= icon($ico, 13) ?> <?= $heading ?></p>
                                <ul class="space-y-2.5">
                                    <?php foreach ($monthEvents[$kind] as $dayNo => $list): foreach ($list as $ev):
                                        $isToday = $dayNo === (int)$today->format('j'); ?>
                                        <li class="flex items-center gap-2.5 text-sm">
                                            <?= avatar($ev['firstName'], $ev['lastName'], $ev['avatarColor']) ?>
                                            <span class="flex-1 min-w-0 truncate text-[var(--color-ink)]"><?= e($ev['firstName'] . ' ' . $ev['lastName']) ?></span>
                                            <span class="text-xs shrink-0 <?= $isToday ? 'font-bold text-pink-600' : 'text-[var(--color-muted)]' ?>">
                                                <?= $kind === 'anniversaries' ? (int)$ev['years'] . ' yr' . ($ev['years'] === 1 ? '' : 's') . ' · ' : '' ?><?= $isToday ? 'Today' : e(sprintf('%02d ', $dayNo) . $today->format('M')) ?>
                                            </span>
                                        </li>
                                    <?php endforeach; endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php app_layout_close();
