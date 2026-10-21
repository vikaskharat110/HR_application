<?php
/** /dashboard/attendance — port of app/dashboard/attendance/page.tsx + CheckInOutCard */
declare(strict_types=1);

$managerial = is_managerial($auth);
$today = today_start();
[$in, $ids] = db_in(visible_employee_ids($auth));

$myToday = $auth['employeeId'] ? db_one('SELECT * FROM Attendance WHERE employeeId = ? AND date = ?', [$auth['employeeId'], $today]) : null;
$myHistory = $auth['employeeId'] ? db_all('SELECT * FROM Attendance WHERE employeeId = ? ORDER BY date DESC LIMIT 10', [$auth['employeeId']]) : [];
$teamToday = $managerial ? db_all(
    "SELECT a.*, e.firstName, e.lastName, e.avatarColor FROM Attendance a JOIN Employee e ON e.id = a.employeeId
      WHERE a.employeeId IN ($in) AND a.date = ? ORDER BY e.firstName",
    [...$ids, $today]
) : [];
$pendingRegCount = $managerial ? (int)db_value("SELECT COUNT(*) FROM AttendanceRegularization WHERE employeeId IN ($in) AND status = 'PENDING'", $ids) : 0;
$geofenceEnabled = $auth['companyId'] ? geofence_enabled($auth['companyId']) : false;
$branch = $auth['employeeId'] ? db_one('SELECT b.name, b.latitude, b.longitude FROM Employee e JOIN Branch b ON b.id = e.branchId WHERE e.id = ?', [$auth['employeeId']]) : null;

/** "~120m from Pune HQ" link to the map (LocationNote). */
$locationNote = static function ($lat, $lng) use ($branch): string {
    if ($lat === null || $lng === null) {
        return '';
    }
    $text = 'View location';
    if ($branch && $branch['latitude'] !== null && $branch['longitude'] !== null) {
        $text = '~' . (int)js_round(distance_meters((float)$lat, (float)$lng, (float)$branch['latitude'], (float)$branch['longitude'])) . 'm from ' . $branch['name'];
    }
    return '<a href="https://www.google.com/maps?q=' . e($lat) . ',' . e($lng) . '" target="_blank" rel="noreferrer noopener" class="flex items-center gap-1 text-[11px] text-white/50 hover:text-white/80 mt-0.5" title="View on map">'
        . icon('map-pin', 10) . e($text) . '</a>';
};

app_layout_open($auth, 'Attendance');
echo '<script src="' . e(asset('js/checkin.js')) . '" defer></script>';
echo page_header('Attendance', 'Check in, review history, and manage regularizations.',
        '<div class="flex flex-wrap gap-2"><a href="' . e(url('/dashboard/calendar')) . '" class="btn btn-primary">' . icon('calendar-days', 15) . ' Calendar</a>'
    . '<a href="' . e(url('/dashboard/attendance/regularizations')) . '" class="btn btn-secondary">' . icon('clipboard-list', 15)
    . ' Regularizations' . ($pendingRegCount > 0 ? " ($pendingRegCount)" : '') . '</a></div>');

?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="space-y-5"<?= $managerial ? '' : ' style="display:contents"' ?>>
        <?php if ($auth['employeeId']):
            $checkIn = $myToday['checkIn'] ?? null;
            $checkOut = $myToday['checkOut'] ?? null; ?>
            <div class="card p-5 bg-brand-dark text-white border-0" data-checkin-card data-geofence="<?= $geofenceEnabled ? '1' : '0' ?>">
                <div class="flex items-center gap-2 text-white/70 text-xs font-semibold uppercase tracking-wide mb-3"><?= icon('clock', 14) ?> Today</div>
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="text-xs text-white/50">Check-in</p>
                        <p class="font-display text-lg font-semibold"><?= e($checkIn ? format_time($checkIn) : DASH) ?></p>
                        <?= $locationNote($myToday['checkInLat'] ?? null, $myToday['checkInLng'] ?? null) ?>
                    </div>
                    <div class="w-px h-8 bg-white/15"></div>
                    <div>
                        <p class="text-xs text-white/50">Check-out</p>
                        <p class="font-display text-lg font-semibold"><?= e($checkOut ? format_time($checkOut) : DASH) ?></p>
                        <?= $locationNote($myToday['checkOutLat'] ?? null, $myToday['checkOutLng'] ?? null) ?>
                    </div>
                </div>
                <?php if (!$checkIn): ?>
                    <form method="post" action="<?= e(url('/api/attendance/check-in')) ?>" data-checkin-form>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn w-full justify-center bg-[var(--color-accent)] text-white hover:bg-[var(--color-accent-dark)] disabled:opacity-70"><?= icon('log-in', 15) ?> Check in</button>
                    </form>
                <?php elseif (!$checkOut): ?>
                    <form method="post" action="<?= e(url('/api/attendance/check-out')) ?>" data-checkin-form>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn w-full justify-center bg-white text-[var(--color-brand-dark)] hover:bg-white/90 disabled:opacity-70"><?= icon('log-out', 15) ?> Check out</button>
                    </form>
                <?php else: ?>
                    <p class="text-sm text-white/70 text-center py-1.5">You’re done for today. See you tomorrow!</p>
                <?php endif; ?>
                <?php $errs = flashes('checkin'); ?>
                <div class="flex items-start gap-2 text-xs text-white bg-white/10 rounded-lg px-3 py-2.5 mt-3" data-checkin-error<?= $errs ? '' : ' hidden' ?>>
                    <?= icon('circle-alert', 14, 'mt-0.5 shrink-0') ?> <span><?= e($errs[0]['message'] ?? '') ?></span>
                </div>
                <?php if (!$errs && !$checkIn && $geofenceEnabled): ?>
                    <p class="flex items-center gap-1.5 text-[11px] text-white/40 mt-2.5"><?= icon('map-pin', 11) ?> Your location may be checked against your branch on check-in.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card p-5<?= $managerial ? '' : ' lg:col-span-2' ?>">
            <p class="text-sm font-semibold text-ink mb-3">My recent attendance</p>
            <ul class="space-y-2.5">
                <?php foreach ($myHistory as $a): ?>
                    <li class="flex items-center justify-between text-sm">
                        <span class="text-ink-soft"><?= e(format_date($a['date'])) ?></span>
                        <span class="text-muted"><?= e($a['checkIn'] ? format_time($a['checkIn']) : DASH) ?> – <?= e($a['checkOut'] ? format_time($a['checkOut']) : DASH) ?></span>
                        <?= status_badge($a['status']) ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$myHistory): ?><p class="text-sm text-muted py-4">No records yet.</p><?php endif; ?>
            </ul>
        </div>
    </div>

    <?php if ($managerial): ?>
        <div class="lg:col-span-2 card p-5 overflow-x-auto">
            <p class="text-sm font-semibold text-ink mb-3">Team — today, <?= e(format_date($today)) ?></p>
            <?php if (!$teamToday): ?>
                <?= empty_state('fingerprint', 'No attendance marked yet', 'Check back once your team checks in.') ?>
            <?php else: ?>
                <table class="table-clean">
                    <thead><tr><th>Employee</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($teamToday as $a): ?>
                        <tr>
                            <td class="flex items-center gap-2.5 py-2.5"><a href="<?= e(url('/dashboard/employees/' . $a['employeeId'])) ?>" class="flex items-center gap-2.5 hover:underline"><?= avatar($a['firstName'], $a['lastName'], $a['avatarColor']) ?> <?= e($a['firstName'] . ' ' . $a['lastName']) ?></a></td>
                            <td><?= e($a['checkIn'] ? format_time($a['checkIn']) : DASH) ?></td>
                            <td><?= e($a['checkOut'] ? format_time($a['checkOut']) : DASH) ?></td>
                            <td><?= status_badge($a['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php app_layout_close();
