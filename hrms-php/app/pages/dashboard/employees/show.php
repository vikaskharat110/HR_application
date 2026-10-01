<?php
/** /dashboard/employees/{id} — port of app/dashboard/employees/[id]/page.tsx */
declare(strict_types=1);

$id = $params['id'];
// Visibility: Admin/HR the whole company, Manager the reporting line, others themselves.
if (!can_see_employee($auth, $id)) {
    redirect('/dashboard/employees');
}
$canManage = is_company_admin($auth);
$canHoldSalary = has_role($auth, SALARY_HOLD_ROLES);
$isOwnProfile = $auth['employeeId'] === $id;

handle_post([
    'status' => function () use ($auth, $id, $canManage) {
        $canManage || throw new UserError('You are not authorized to change status.');
        action_update_employee_status($auth, $id, post('status'));
        flash('success', 'Status updated.');
        return null;
    },
    'phone' => function () use ($auth, $isOwnProfile) {
        $isOwnProfile || throw new UserError('You can only edit your own phone number.');
        action_update_own_phone($auth, post('phone'));
        return null;
    },
    'hold' => function () use ($auth, $id) {
        action_set_salary_hold($auth, $id, post('hold') === '1', post('reason'));
        return null;
    },
    'start_onboarding' => function () use ($auth, $id, $canManage) {
        $canManage || throw new UserError('You are not authorized to start onboarding.');
        action_start_onboarding($auth, $id);
        return null;
    },
]);

$employee = db_one(
    'SELECT e.*, d.name AS departmentName, b.name AS branchName, m.id AS mId, m.firstName AS mFirst, m.lastName AS mLast
       FROM Employee e
       LEFT JOIN Department d ON d.id = e.departmentId
       LEFT JOIN Branch b ON b.id = e.branchId
       LEFT JOIN Employee m ON m.id = e.managerId
      WHERE e.id = ? AND e.companyId = ?',
    [$id, $auth['companyId']]
);
if ($employee === null) {
    throw new HttpError(404);
}
$year = (int)now()->format('Y');
$reports = db_all('SELECT id, firstName, lastName, avatarColor, designation FROM Employee WHERE managerId = ? ORDER BY firstName', [$id]);
$salary = db_one('SELECT * FROM SalaryStructure WHERE employeeId = ?', [$id]);
$documents = db_all('SELECT id, name FROM Document WHERE employeeId = ? AND companyId = ? ORDER BY uploadedAt', [$id, $auth['companyId']]);
$assets = db_all('SELECT a.name, a.serialNumber FROM AssetAssignment aa JOIN Asset a ON a.id = aa.assetId WHERE aa.employeeId = ? AND aa.returnedDate IS NULL', [$id]);
$balances = db_all('SELECT b.id, b.allocated, b.used, t.name FROM LeaveBalance b JOIN LeaveType t ON t.id = b.leaveTypeId WHERE b.employeeId = ? AND b.year = ? ORDER BY t.name', [$id, $year]);
$attendance = db_all('SELECT * FROM Attendance WHERE employeeId = ? ORDER BY date DESC LIMIT 8', [$id]);
$onboarding = db_one('SELECT * FROM Onboarding WHERE employeeId = ?', [$id]);
$onboardingDone = $onboarding ? (int)db_value("SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ? AND status = 'DONE'", [$onboarding['id']]) : 0;
$onboardingTotal = $onboarding ? (int)db_value('SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ?', [$onboarding['id']]) : 0;
$confirmation = db_one('SELECT * FROM Confirmation WHERE employeeId = ? ORDER BY createdAt DESC LIMIT 1', [$id]);
$history = $canManage ? db_all(
    "SELECT l.*, u.email AS actorEmail FROM AuditLog l LEFT JOIN `User` u ON u.id = l.actorId
      WHERE l.companyId = ? AND ((l.entityType = 'Employee' AND l.entityId = ?) OR (l.entityType = 'SalaryStructure' AND l.entityId = ?))
      ORDER BY l.createdAt DESC LIMIT 30",
    [$auth['companyId'], $id, $id]
) : [];

const FIELD_LABELS = [
    'firstName' => 'First name', 'lastName' => 'Last name', 'email' => 'Email', 'phone' => 'Phone', 'designation' => 'Designation',
    'departmentId' => 'Department', 'branchId' => 'Branch', 'managerId' => 'Manager', 'employmentType' => 'Employment type',
];
/** Human-readable lines for one audit entry (describeAuditEntry). */
$describe = static function (string $action, ?string $metaJson): array {
    $m = json_decode((string)$metaJson, true) ?: [];
    if ($action === 'CREATE') {
        return ['Employee profile created'];
    }
    if ($action === 'STATUS_CHANGE') {
        return ['Status changed to ' . humanize((string)($m['status'] ?? DASH))];
    }
    if (!empty($m['changes']) && is_array($m['changes'])) {
        $lines = [];
        foreach ($m['changes'] as $field => $c) {
            $lines[] = (FIELD_LABELS[$field] ?? $field) . ' changed from "' . ($c['from'] ?? DASH) . '" to "' . ($c['to'] ?? DASH) . '"';
        }
        return $lines;
    }
    if ($action === 'SALARY_REVISION') {
        $from = isset($m['from']) ? rupees((float)$m['from']) : DASH;
        $to = isset($m['to']) ? rupees((float)$m['to']) : DASH;
        return ["Basic salary revised from $from to $to"];
    }
    return [humanize($action)];
};
$field = static fn(string $label, float $value) => '<div><p class="text-xs text-muted">' . e($label) . '</p><p class="font-medium text-ink mt-0.5">' . e(rupees($value)) . '</p></div>';

app_layout_open($auth, $employee['firstName'] . ' ' . $employee['lastName']);
?>
<?= back_link('/dashboard/employees', 'Back to employees') ?>
<?= temp_password_notice() ?>
<div class="card p-6 mb-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <?= avatar($employee['firstName'], $employee['lastName'], $employee['avatarColor'], 'w-14 h-14 text-lg') ?>
            <div>
                <h1 class="font-display text-xl font-semibold text-ink"><?= e($employee['firstName'] . ' ' . $employee['lastName']) ?></h1>
                <p class="text-sm text-muted"><?= e($employee['designation']) ?> · <?= e($employee['employeeCode']) ?></p>
                <div class="mt-1.5"><?= status_badge($employee['status']) ?></div>
            </div>
        </div>
        <?php if ($canManage): ?>
            <div class="flex flex-wrap items-center gap-2">
                <a href="<?= e(url("/dashboard/employees/$id/edit")) ?>" class="btn btn-secondary text-sm"><?= icon('pencil', 14) ?> Edit</a>
                <a href="<?= e(url("/dashboard/employees/$id/promote")) ?>" class="btn btn-secondary text-sm"><?= icon('trending-up', 14) ?> Promote / Transfer</a>
                <?php if ($employee['status'] !== 'EXITED'): ?>
                    <a href="<?= e(url('/dashboard/exit/new?employeeId=' . $id)) ?>" class="btn btn-secondary text-sm"><?= icon('log-out', 14) ?> Initiate exit</a>
                    <form method="post" class="flex items-center gap-2">
                        <?= form_fields('status') ?>
                        <select name="status" class="input !w-auto text-sm">
                            <?php foreach (['ACTIVE' => 'Active', 'ON_LEAVE' => 'On leave', 'NOTICE_PERIOD' => 'Notice period'] as $v => $l): ?>
                                <option value="<?= $v ?>"<?= selected($v, $employee['status']) ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-secondary text-sm"><?= icon('user-cog', 14) ?> Update status</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6 pt-6 border-t border-border-soft text-sm">
        <div class="flex items-center gap-2 text-ink-soft min-w-0"><?= icon('mail', 15) ?> <span class="truncate"><?= e($employee['email']) ?></span></div>
        <div class="flex items-center gap-2 text-ink-soft">
            <?= icon('phone', 15, 'shrink-0') ?>
            <?php if ($isOwnProfile): ?>
                <details class="group">
                    <summary class="cursor-pointer list-none flex items-center gap-1.5 hover:text-ink">
                        <?= e($employee['phone'] ?? 'Add phone number') ?> <?= icon('pencil', 11, 'text-muted') ?>
                    </summary>
                    <form method="post" class="mt-2 flex items-center gap-1.5">
                        <?= form_fields('phone') ?>
                        <input name="phone" maxlength="50" value="<?= e($employee['phone'] ?? '') ?>" placeholder="Phone number" class="input !w-36 !py-1 text-xs">
                        <button type="submit" class="btn btn-secondary !py-1 !px-2 text-xs">Save</button>
                    </form>
                </details>
            <?php else: ?>
                <?= e($employee['phone'] ?? DASH) ?>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2 text-ink-soft"><?= icon('building-2', 15) ?> <?= e($employee['departmentName'] ?? DASH) ?></div>
        <div class="flex items-center gap-2 text-ink-soft"><?= icon('map-pin', 15) ?> <?= e($employee['branchName'] ?? DASH) ?></div>
    </div>
</div>

<div class="grid lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 space-y-5">
        <div class="card p-5 overflow-x-auto">
            <p class="text-sm font-semibold text-ink mb-3">Recent attendance</p>
            <table class="table-clean">
                <thead><tr><th>Date</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($attendance as $a): ?>
                    <tr>
                        <td><?= e(format_date($a['date'])) ?></td>
                        <td><?= e($a['checkIn'] ? format_time($a['checkIn']) : DASH) ?></td>
                        <td><?= e($a['checkOut'] ? format_time($a['checkOut']) : DASH) ?></td>
                        <td><?= status_badge($a['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$attendance): ?><tr><td colspan="4" class="text-center text-muted py-6">No attendance recorded yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canManage && $salary): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Salary structure</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                    <?= $field('Basic', (float)$salary['basic']) ?>
                    <?= $field('HRA', (float)$salary['hra']) ?>
                    <?= $field('Conveyance', (float)$salary['conveyance']) ?>
                    <?= $field('Medical', (float)$salary['medicalAllowance']) ?>
                    <?= $field('Special allowance', (float)$salary['specialAllowance']) ?>
                    <?= $field('PF (employee)', (float)$salary['pfEmployee']) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canHoldSalary): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Salary hold</p>
                <?= flash_alerts('hold') ?>
                <?php if ((int)$employee['salaryOnHold']): ?>
                    <p class="text-sm text-danger flex items-center gap-1.5 mb-1"><?= icon('circle-pause', 15) ?> On hold</p>
                    <?php if ($employee['salaryHoldReason']): ?><p class="text-sm text-muted mb-3">Reason: <?= e($employee['salaryHoldReason']) ?></p><?php endif; ?>
                    <form method="post">
                        <?= form_fields('hold', 'hold') ?><input type="hidden" name="hold" value="0">
                        <button class="btn btn-secondary text-sm"><?= icon('circle-play', 14) ?> Release hold</button>
                    </form>
                <?php else: ?>
                    <p class="text-sm text-muted mb-3">Future payroll runs will pay this employee normally.</p>
                    <form method="post" class="space-y-2">
                        <?= form_fields('hold', 'hold') ?><input type="hidden" name="hold" value="1">
                        <input name="reason" required maxlength="1000" placeholder="Reason for hold" class="input text-sm">
                        <button class="btn btn-secondary text-sm w-full justify-center"><?= icon('circle-pause', 14) ?> Hold salary</button>
                    </form>
                <?php endif; ?>
                <p class="text-xs text-muted mt-3">Applies from the next payroll run onward — it doesn’t change any payslip already generated.</p>
            </div>
        <?php endif; ?>

        <?php if ($reports): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Direct reports</p>
                <ul class="space-y-2.5">
                    <?php foreach ($reports as $r): ?>
                        <li>
                            <a href="<?= e(url('/dashboard/employees/' . $r['id'])) ?>" class="flex items-center gap-2.5 text-sm hover:underline">
                                <?= avatar($r['firstName'], $r['lastName'], $r['avatarColor']) ?>
                                <?= e($r['firstName'] . ' ' . $r['lastName']) ?> <span class="text-muted">— <?= e($r['designation']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><?= icon('history', 15) ?> Change history</p>
                <?php if (!$history): ?>
                    <p class="text-sm text-muted py-2">No recorded changes yet.</p>
                <?php else: ?>
                    <ul class="divide-y divide-border-soft">
                        <?php foreach ($history as $log): ?>
                            <li class="py-2.5">
                                <?php foreach ($describe($log['action'], $log['meta']) as $line): ?>
                                    <p class="text-sm text-ink-soft"><?= e($line) ?></p>
                                <?php endforeach; ?>
                                <p class="text-xs text-muted mt-1"><?= e($log['actorEmail'] ?? 'system') ?> · <?= e(format_datetime($log['createdAt'])) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="space-y-5">
        <?php if ($confirmation): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-2">Confirmation</p>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-muted">Due <?= e(format_date($confirmation['dueDate'])) ?></span>
                    <?= status_badge($confirmation['status']) ?>
                </div>
                <?php if ($canManage): ?>
                    <a href="<?= e(url('/dashboard/employees/confirmations')) ?>" class="text-sm text-brand hover:underline mt-2 inline-block">Manage confirmations</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($onboarding && $onboarding['status'] === 'IN_PROGRESS'): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-1">Onboarding checklist</p>
                <p class="text-xs text-muted mb-3"><?= $onboardingDone ?>/<?= $onboardingTotal ?> tasks done</p>
                <a href="<?= e(url('/dashboard/onboarding/' . $onboarding['id'])) ?>" class="text-sm text-brand hover:underline">View checklist</a>
            </div>
        <?php endif; ?>
        <?php if ($canManage && !$onboarding && $employee['status'] !== 'EXITED'): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-1">Onboarding checklist</p>
                <p class="text-xs text-muted mb-3">No checklist yet — this can happen if they were hired before any task templates existed.</p>
                <form method="post"><?= form_fields('start_onboarding') ?><button type="submit" class="btn btn-secondary text-sm">Start onboarding checklist</button></form>
            </div>
        <?php endif; ?>

        <div class="card p-5">
            <p class="text-sm font-semibold text-ink mb-3">Leave balance — <?= $year ?></p>
            <ul class="space-y-2.5">
                <?php foreach ($balances as $b): ?>
                    <li class="flex items-center justify-between text-sm">
                        <span class="text-ink-soft"><?= e($b['name']) ?></span>
                        <span class="font-medium text-ink"><?= e(js_num($b['allocated'] - $b['used'])) ?> / <?= e(js_num($b['allocated'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($employee['mId']): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Reports to</p>
                <a href="<?= e(url('/dashboard/employees/' . $employee['mId'])) ?>" class="flex items-center gap-2.5 text-sm hover:underline">
                    <?= avatar($employee['mFirst'], $employee['mLast']) ?> <?= e($employee['mFirst'] . ' ' . $employee['mLast']) ?>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($assets): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Assigned assets</p>
                <ul class="space-y-2 text-sm">
                    <?php foreach ($assets as $a): ?>
                        <li class="flex items-center justify-between"><span class="text-ink-soft"><?= e($a['name']) ?></span><span class="text-muted text-xs"><?= e($a['serialNumber']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($documents): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Documents</p>
                <ul class="space-y-2 text-sm">
                    <?php foreach ($documents as $d): ?><li class="text-ink-soft"><?= e($d['name']) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php app_layout_close();
