<?php
/**
 * /dashboard/calendar — one month of attendance, leave, holidays, birthdays and anniversaries.
 * Day cells carry their details as JSON; public_html/assets/js/calendar.js shows the tooltip and popup.
 * Company Admin / HR can add or remove holidays from the day popup.
 */
declare(strict_types=1);

/** Month ("YYYY-MM") from a raw value, falling back to the current month. */
$monthFrom = static function (string $raw): DateTimeImmutable {
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $raw, $m) && (int)$m[1] >= 1970 && (int)$m[1] <= 2100) {
        return today_start()->setDate((int)$m[1], (int)$m[2], 1);
    }
    return today_start()->modify('first day of this month');
};
$calendarUrl = static fn(DateTimeImmutable $month, string $employeeId, string $selfId): string =>
    '/dashboard/calendar?month=' . $month->format('Y-m') . ($employeeId !== '' && $employeeId !== $selfId ? '&employee=' . $employeeId : '');

handle_post([
    'holiday_add' => function () use ($auth, $monthFrom, $calendarUrl) {
        action_add_calendar_holiday($auth, post('date'), post('name'));
        return $calendarUrl($monthFrom(post('month')), post('employee'), (string)$auth['employeeId']);
    },
    'holiday_remove' => function () use ($auth, $monthFrom, $calendarUrl) {
        action_delete_holiday($auth, post_id('holidayId'));
        return $calendarUrl($monthFrom(post('month')), post('employee'), (string)$auth['employeeId']);
    },
]);

$companyId = $auth['companyId'] ?? null;
$monthStart = $monthFrom(query('month'));
$prev = $monthStart->modify('-1 month');
$next = $monthStart->modify('+1 month');
$thisMonth = today_start()->modify('first day of this month');
$selfId = (string)($auth['employeeId'] ?? '');
$canManageHolidays = is_company_admin($auth);

// Whose attendance is shown: yourself, or (managers / HR / admin) anyone in your scope.
$people = [];
$employee = null;
$days = [];
if ($companyId) {
    $visible = visible_employee_ids($auth);
    if (count($visible) > 1) {
        [$in, $ids] = db_in($visible);
        $people = db_all("SELECT id, firstName, lastName FROM Employee WHERE id IN ($in) ORDER BY firstName, lastName", $ids);
    }
    $wanted = query('employee');
    $pickId = ($wanted !== '' && in_array($wanted, $visible, true)) ? $wanted : $selfId;
    if ($pickId !== '') {
        $employee = db_one('SELECT id, firstName, lastName, branchId, dateOfJoining FROM Employee WHERE id = ? AND companyId = ?', [$pickId, $companyId]);
    }
    $days = attendance_calendar_month($companyId, $employee, $monthStart, $canManageHolidays);
}
$viewingId = $employee['id'] ?? '';
$isSelf = $employee !== null && $viewingId === $selfId;

$tally = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0];
$occasions = [];
foreach ($days as $d) {
    if (isset($tally[$d['tone']])) {
        $tally[$d['tone']]++;
    }
    foreach (['birthdays' => 'Birthday', 'anniversaries' => 'Work anniversary'] as $key => $label) {
        if (isset($d['details'][$key])) {
            $occasions[] = [$d['day'], $label, $d['details'][$key]];
        }
    }
}

app_layout_open($auth, 'Calendar');
echo page_header('Calendar', $employee && !$isSelf
    ? 'Attendance for ' . $employee['firstName'] . ' ' . $employee['lastName'] . ', with company holidays, birthdays and anniversaries.'
    : 'Your attendance, leave, company holidays, birthdays and work anniversaries.');
?>
<style>
    .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
    .cal-head { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--color-muted); text-align: center; padding: 4px 0; }
    .cal-cell { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-height: 5.25rem; padding: 6px 8px; text-align: left; cursor: pointer;
        border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; font: inherit; color: inherit; }
    .cal-cell:hover, .cal-cell:focus-visible { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, .15); outline: none; }
    .cal-blank { min-height: 5.25rem; }
    .cal-num { font-size: 13px; font-weight: 700; }
    .cal-today .cal-num { display: inline-grid; place-items: center; min-width: 1.5rem; height: 1.5rem; border-radius: 999px; background: #6d4aff; color: #fff; }
    .cal-tag { font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 999px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cal-mark { font-size: 12px; line-height: 1; }
    .tone-present { background: #ecfdf5; border-color: #a7f3d0; } .tone-present .cal-tag { background: #d1fae5; color: #047857; }
    .tone-late    { background: #fffbeb; border-color: #fde68a; } .tone-late .cal-tag    { background: #fef3c7; color: #b45309; }
    .tone-absent  { background: #fff1f2; border-color: #fecdd3; } .tone-absent .cal-tag  { background: #ffe4e6; color: #be123c; }
    .tone-leave   { background: #f0f9ff; border-color: #bae6fd; } .tone-leave .cal-tag   { background: #e0f2fe; color: #0369a1; }
    .tone-holiday { background: #f5f3ff; border-color: #ddd6fe; } .tone-holiday .cal-tag { background: #ede9fe; color: #6d28d9; }
    .tone-off     { background: #f8fafc; }                        .tone-off .cal-tag     { background: #e2e8f0; color: #475569; }
    .tone-pending { background: #eef2ff; border-color: #c7d2fe; } .tone-pending .cal-tag { background: #e0e7ff; color: #4338ca; }
    .tone-none .cal-num { color: #94a3b8; }
    #day-dialog { border: 0; border-radius: 16px; padding: 0; width: calc(100% - 2rem); max-width: 26rem; box-shadow: 0 24px 60px -12px rgba(15, 23, 42, .4); }
    #day-dialog::backdrop { background: rgba(15, 23, 42, .45); }
    @media (max-width: 640px) { .cal-cell, .cal-blank { min-height: 3.75rem; padding: 4px 5px; } .cal-tag { display: none; } }
</style>

<?php if (!$companyId): ?>
    <div class="card p-5"><?= empty_state('calendar-days', 'No company calendar', 'This account isn’t part of a company, so there’s no calendar to show.') ?></div>
<?php else: ?>
<div class="card p-5 mb-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <a href="<?= e(url($calendarUrl($prev, $viewingId, $selfId))) ?>" class="btn btn-secondary !px-2.5" aria-label="Previous month"><?= icon('chevron-left', 16) ?></a>
            <p class="font-display text-lg font-semibold text-ink w-44 text-center"><?= e($monthStart->format('F Y')) ?></p>
            <a href="<?= e(url($calendarUrl($next, $viewingId, $selfId))) ?>" class="btn btn-secondary !px-2.5" aria-label="Next month"><?= icon('chevron-right', 16) ?></a>
            <?php if ($monthStart != $thisMonth): ?>
                <a href="<?= e(url($calendarUrl($thisMonth, $viewingId, $selfId))) ?>" class="btn btn-secondary text-sm">Today</a>
            <?php endif; ?>
        </div>
        <?php if ($people): ?>
            <form method="get" class="flex items-center gap-2">
                <input type="hidden" name="month" value="<?= e($monthStart->format('Y-m')) ?>">
                <label class="text-xs font-semibold uppercase tracking-wide text-muted" for="cal-employee">Employee</label>
                <select id="cal-employee" name="employee" data-autosubmit class="input !w-auto !py-1.5 text-sm">
                    <?php foreach ($people as $p): ?>
                        <option value="<?= e($p['id']) ?>"<?= selected($p['id'], $viewingId) ?>><?= e($p['firstName'] . ' ' . $p['lastName']) ?><?= $p['id'] === $selfId ? ' (me)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($employee): ?>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
            <?php foreach (['present' => ['Present', '#047857'], 'late' => ['Late / half day', '#b45309'], 'absent' => ['Absent', '#be123c'], 'leave' => ['On leave', '#0369a1']] as $k => [$label, $color]): ?>
                <div class="rounded-xl border border-border-soft px-3 py-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted"><?= e($label) ?></p>
                    <p class="font-display text-xl font-semibold" style="color:<?= $color ?>"><?= (int)$tally[$k] ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card p-4 sm:p-5">
    <?= flash_alerts('calendar') ?>
    <div class="cal-grid mb-1.5">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dn): ?><div class="cal-head"><?= $dn ?></div><?php endforeach; ?>
    </div>
    <div class="cal-grid">
        <?php for ($i = 0, $lead = $days ? $days[0]['dow'] : 0; $i < $lead; $i++): ?><div class="cal-blank"></div><?php endfor; ?>
        <?php foreach ($days as $d): ?>
            <button type="button" class="cal-cell tone-<?= e($d['tone']) ?><?= $d['isToday'] ? ' cal-today' : '' ?>"
                    data-date="<?= e($d['date']) ?>" data-day="<?= e(json_encode($d['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                    aria-label="<?= e($d['details']['date'] . ($d['details']['status'] !== '' ? ', ' . $d['details']['status'] : '')) ?>">
                <span class="cal-num"><?= (int)$d['day'] ?></span>
                <?php if (isset($d['details']['holiday'])): ?>
                    <span class="cal-tag" style="background:#ede9fe;color:#6d28d9"><?= e($d['details']['holiday']) ?></span>
                <?php elseif ($d['label'] !== ''): ?>
                    <span class="cal-tag"><?= e($d['label']) ?></span>
                <?php endif; ?>
                <?php if (isset($d['details']['birthdays']) || isset($d['details']['anniversaries'])): ?>
                    <span class="cal-mark"><?= isset($d['details']['birthdays']) ? '🎂' : '' ?><?= isset($d['details']['anniversaries']) ? '🏅' : '' ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-wrap gap-x-4 gap-y-1.5 mt-4 text-xs text-muted">
        <?php foreach ([['Present', '#34d399'], ['Late / half day', '#fbbf24'], ['Absent', '#fb7185'], ['Leave', '#38bdf8'], ['Holiday', '#a78bfa'], ['Weekly off', '#94a3b8']] as [$label, $color]): ?>
            <span class="inline-flex items-center gap-1.5"><span style="width:10px;height:10px;border-radius:3px;background:<?= $color ?>"></span><?= e($label) ?></span>
        <?php endforeach; ?>
        <span>🎂 Birthday</span><span>🏅 Work anniversary</span>
        <?php if ($canManageHolidays): ?><span>Click a day to add or remove a holiday.</span><?php endif; ?>
    </div>
</div>

<?php if ($occasions): ?>
    <div class="card p-5 mt-5">
        <p class="text-sm font-semibold text-ink mb-3">Birthdays &amp; anniversaries in <?= e($monthStart->format('F')) ?></p>
        <ul class="divide-y divide-border-soft text-sm">
            <?php foreach ($occasions as [$dayNo, $label, $names]): ?>
                <li class="py-2 flex gap-3"><span class="w-16 shrink-0 text-muted"><?= e($monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('n'), $dayNo)->format('d M')) ?></span>
                    <span class="w-36 shrink-0 text-muted"><?= e($label) ?></span><span class="text-ink"><?= e($names) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<dialog id="day-dialog" aria-labelledby="day-dialog-date">
    <div class="p-5">
        <div class="flex items-start justify-between gap-3">
            <div><p id="day-dialog-date" data-f="date" class="font-display text-lg font-semibold text-ink"></p><span data-f="status" class="badge mt-2" hidden></span></div>
            <button type="button" data-dialog-close class="text-muted hover:text-ink" aria-label="Close"><?= icon('x', 18) ?></button>
        </div>
        <dl data-f="rows" class="mt-4 space-y-3 text-sm"></dl>
        <a data-f="map" href="#" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-sm text-brand hover:underline mt-3" hidden><?= icon('map-pin', 14) ?> <span></span></a>
        <?php if ($isSelf): ?>
            <div><a data-f="regularize" data-base="<?= e(url('/dashboard/attendance/regularizations')) ?>" href="#" class="btn btn-secondary text-sm mt-3" hidden>Request a correction</a></div>
        <?php endif; ?>
        <?php if ($canManageHolidays): ?>
            <form method="post" data-holiday-add hidden>
                <div class="mt-4 pt-4 border-t border-border-soft space-y-2">
                    <?= form_fields('holiday_add', 'calendar') ?>
                    <input type="hidden" name="date" value=""><input type="hidden" name="month" value="<?= e($monthStart->format('Y-m')) ?>"><input type="hidden" name="employee" value="<?= e($viewingId) ?>">
                    <label class="field-label">Mark as a company holiday</label>
                    <input name="name" required maxlength="191" placeholder="Holiday name" class="input">
                    <button type="submit" class="btn btn-primary w-full justify-center text-sm">Add holiday</button>
                </div>
            </form>
            <form method="post" data-holiday-remove hidden>
                <div class="mt-4 pt-4 border-t border-border-soft">
                    <?= form_fields('holiday_remove', 'calendar') ?>
                    <input type="hidden" name="holidayId" value=""><input type="hidden" name="month" value="<?= e($monthStart->format('Y-m')) ?>"><input type="hidden" name="employee" value="<?= e($viewingId) ?>">
                    <?= confirm_button('Remove this holiday from the company calendar?', 'Remove holiday', 'btn btn-secondary w-full justify-center text-sm text-danger') ?>
                    <p class="text-[11px] text-muted mt-1.5 text-center"><span data-f="holidayName"></span></p>
                </div>
            </form>
        <?php endif; ?>
    </div>
</dialog>
<script src="<?= e(asset('js/calendar.js')) ?>" defer></script>
<?php endif; ?>
<?php app_layout_close();
