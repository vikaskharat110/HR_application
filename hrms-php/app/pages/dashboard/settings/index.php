<?php
/** /dashboard/settings — port of app/dashboard/settings/page.tsx (+ CompanyLetterheadForm, BranchLocationForm). */
declare(strict_types=1);

handle_post([
    'letterhead' => function () use ($auth) {
        action_update_letterhead($auth, uploaded_file('logo'), post_raw('address'), post('phone'), post('panNumber'));
        flash('success', 'Letterhead saved.', 'letterhead');
        return '/dashboard/settings#tab-letterhead';
    },
    'policy' => function () use ($auth) {
        action_update_policy($auth, $_POST);
        flash('success', 'Policy saved.', 'policy');
        return '/dashboard/settings#tab-policy';
    },
    'branch' => function () use ($auth) {
        action_add_branch($auth, post('name'), post('city'));
        return '/dashboard/settings#tab-branches';
    },
    'branch_location' => function () use ($auth) {
        action_set_branch_location($auth, post_id('branchId'), post('latitude'), post('longitude'), post('radius'));
        flash('success', 'Saved', 'branch-' . post('branchId'));
        return '/dashboard/settings#tab-branches';
    },
    'department' => function () use ($auth) {
        action_add_department($auth, post('name'));
        return '/dashboard/settings#tab-departments';
    },
    'leave_type' => function () use ($auth) {
        action_add_leave_type($auth, post('name'), post('daysPerYear'), post('carryForward') === 'on');
        return '/dashboard/settings#tab-leave-types';
    },
    'holiday' => function () use ($auth) {
        action_add_holiday($auth, post('name'), post('date'));
        return '/dashboard/settings#tab-holidays';
    },
    'template_add' => function () use ($auth) {
        action_add_onboarding_template($auth, post('category'), post('title'));
        return '/dashboard/settings#tab-onboarding';
    },
    'template_remove' => function () use ($auth) {
        action_delete_onboarding_template($auth, post_id('id'));
        return '/dashboard/settings#tab-onboarding';
    },
]);

$co = $auth['companyId'];
$company = db_one('SELECT * FROM Company WHERE id = ?', [$co]);
$policy = db_one('SELECT * FROM CompanyPolicy WHERE companyId = ?', [$co]);
$branches = db_all('SELECT * FROM Branch WHERE companyId = ? ORDER BY name', [$co]);
$departments = db_all('SELECT name FROM Department WHERE companyId = ? ORDER BY name', [$co]);
$leaveTypes = db_all('SELECT name, daysPerYear FROM LeaveType WHERE companyId = ? ORDER BY name', [$co]);
$holidays = db_all('SELECT name, date FROM Holiday WHERE companyId = ? ORDER BY date', [$co]);
$templates = db_all('SELECT * FROM OnboardingTaskTemplate WHERE companyId = ? ORDER BY category, sortOrder', [$co]);

/** Simple list + "+ Add" card (SettingsCard). */
$settingsCard = static function (string $title, array $items, string $action, string $fields, string $scope): string {
    $h = '<div class="card p-5 max-w-2xl"><p class="text-sm font-semibold text-ink mb-3">' . e($title) . '</p>' . flash_alerts($scope)
        . '<ul class="space-y-1.5 mb-3 text-sm text-ink-soft max-h-32 overflow-y-auto">';
    foreach ($items as $it) {
        $h .= '<li>' . e($it) . '</li>';
    }
    if (!$items) {
        $h .= '<li class="text-muted">None yet.</li>';
    }
    return $h . '</ul><details><summary class="cursor-pointer text-sm text-brand font-medium">+ Add</summary>'
        . '<form method="post" class="space-y-2 mt-3">' . form_fields($action, $scope) . $fields
        . '<button type="submit" class="btn btn-secondary w-full justify-center text-sm">Add</button></form></details></div>';
};
$numField = static fn(string $name, string $label, int $value) =>
    '<div><label class="field-label">' . e($label) . '</label><input type="number" min="0" name="' . e($name) . '" value="' . $value . '" class="input"></div>';

ob_start(); ?>
<div class="card p-6 max-w-2xl">
    <p class="text-sm font-semibold text-ink mb-1">Company letterhead</p>
    <p class="text-xs text-muted mb-4">Shown on every generated offer/appointment/relieving letter.</p>
    <form method="post" enctype="multipart/form-data" class="space-y-4" data-pending-form>
        <?= form_fields('letterhead', 'letterhead') ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="field-label">Logo</label>
                <?php if ($company['logoUrl'] && $company['logoUrl'] !== '#'): ?>
                    <img src="<?= e(url('/files/logo/' . $co)) ?>" alt="Current logo" class="h-10 mb-2 object-contain">
                <?php endif; ?>
                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="input">
                <p class="text-xs text-muted mt-1">PNG, JPG or WebP, up to 10MB. Leave blank to keep the current logo.</p>
            </div>
            <div><label class="field-label">Phone</label><input name="phone" maxlength="50" value="<?= e($company['phone'] ?? '') ?>" placeholder="e.g. 72765 45228" class="input"></div>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="field-label">Registered address</label><textarea name="address" rows="3" maxlength="2000" placeholder="Full postal address for the letterhead" class="input"><?= e($company['address'] ?? '') ?></textarea></div>
            <div><label class="field-label">PAN number</label><input name="panNumber" maxlength="50" value="<?= e($company['panNumber'] ?? '') ?>" placeholder="e.g. AAJFF7660C" class="input"></div>
        </div>
        <?= flash_alerts('letterhead') ?>
        <button type="submit" class="btn btn-primary disabled:opacity-70" data-pending-label="Saving…">Save letterhead</button>
    </form>
</div>
<?php $letterheadTab = ob_get_clean();

ob_start(); ?>
<div class="card p-6 max-w-2xl">
    <p class="text-sm font-semibold text-ink mb-4">Policy</p>
    <?= flash_alerts('policy') ?>
    <form method="post" class="space-y-4">
        <?= form_fields('policy', 'policy') ?>
        <div><label class="field-label">Work week</label><input name="workWeek" maxlength="191" value="<?= e($policy['workWeek'] ?? 'MON-FRI') ?>" class="input"></div>
        <div class="grid grid-cols-2 gap-3">
            <?= $numField('checkInGraceMins', 'Check-in grace (mins)', (int)($policy['checkInGraceMins'] ?? 15)) ?>
            <?= $numField('probationMonths', 'Probation (months)', (int)($policy['probationMonths'] ?? 3)) ?>
            <?= $numField('casualLeaveDays', 'Casual leave/yr', (int)($policy['casualLeaveDays'] ?? 12)) ?>
            <?= $numField('sickLeaveDays', 'Sick leave/yr', (int)($policy['sickLeaveDays'] ?? 8)) ?>
            <?= $numField('earnedLeaveDays', 'Earned leave/yr', (int)($policy['earnedLeaveDays'] ?? 15)) ?>
            <?= $numField('noticePeriodDays', 'Notice period (days)', (int)($policy['noticePeriodDays'] ?? 30)) ?>
        </div>
        <div class="rounded-lg border border-border-soft bg-brand-softer p-4">
            <label class="flex items-center gap-2.5 text-sm font-medium text-ink">
                <input type="checkbox" name="geofenceAttendanceEnabled"<?= checked((bool)($policy['geofenceAttendanceEnabled'] ?? false)) ?> class="w-4 h-4 accent-brand">
                Require employees to be at their branch to check in/out
            </label>
            <p class="text-xs text-muted mt-2">When on, employees are asked for their location on check-in/out, and it's checked against the radius set for their branch under the Branches tab. Off by default — turning it on doesn’t restrict anyone until a branch also has its location set.</p>
        </div>
        <button type="submit" class="btn btn-primary w-full justify-center">Save policy</button>
    </form>
</div>
<?php $policyTab = ob_get_clean();

ob_start(); ?>
<div class="card p-5 max-w-2xl">
    <p class="text-sm font-semibold text-ink mb-3">Branches</p>
    <?= flash_alerts('branches') ?>
    <ul class="divide-y divide-border-soft mb-3">
        <?php foreach ($branches as $b):
            $hasFence = $b['latitude'] !== null; ?>
            <li class="py-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm text-ink"><?= e($b['name']) ?> — <?= e($b['city']) ?></p>
                        <p class="text-xs text-muted flex items-center gap-1 mt-0.5"><?= icon('map-pin', 11) ?>
                            <?= $hasFence ? 'Geofence active — ' . (int)$b['geofenceRadiusMeters'] . 'm radius' : 'No geofence — check-in isn\'t location-restricted' ?></p>
                    </div>
                    <details>
                        <summary class="text-xs text-brand font-medium cursor-pointer list-none whitespace-nowrap"><?= $hasFence ? 'Edit location' : 'Set location' ?></summary>
                        <div class="mt-2 space-y-2 w-64">
                            <form method="post" class="space-y-2" data-geo-form>
                                <?= form_fields('branch_location', 'branch-' . $b['id']) ?>
                                <input type="hidden" name="branchId" value="<?= e($b['id']) ?>">
                                <input type="hidden" name="latitude" value="<?= e($b['latitude'] ?? '') ?>" data-geo-lat>
                                <input type="hidden" name="longitude" value="<?= e($b['longitude'] ?? '') ?>" data-geo-lng>
                                <button type="button" data-geo-capture class="btn btn-secondary w-full justify-center text-sm disabled:opacity-70"><?= icon('map-pin', 14) ?> Use my current location</button>
                                <p class="text-[11px] text-muted flex items-center gap-1" data-geo-status<?= $hasFence ? '' : ' hidden' ?>>
                                    <?= icon('circle-check', 11, 'text-success shrink-0') ?> <span data-geo-text><?= $hasFence ? 'Captured: ' . e(number_format((float)$b['latitude'], 5, '.', '')) . ', ' . e(number_format((float)$b['longitude'], 5, '.', '')) : '' ?></span>
                                </p>
                                <p class="text-[11px] text-danger flex items-center gap-1" data-geo-error hidden><?= icon('circle-alert', 11, 'shrink-0') ?> <span></span></p>
                                <input name="radius" type="number" min="1" value="<?= (int)$b['geofenceRadiusMeters'] ?>" placeholder="Radius (meters)" class="input text-sm">
                                <button type="submit" data-geo-submit<?= $hasFence ? '' : ' disabled' ?> class="btn btn-primary w-full justify-center text-sm disabled:opacity-50" title="Capture a location first">Save</button>
                                <?php foreach (flashes('branch-' . $b['id']) as $f): ?>
                                    <p class="text-[11px] <?= $f['type'] === 'success' ? 'text-success' : 'text-danger' ?>"><?= e($f['message']) ?></p>
                                <?php endforeach; ?>
                            </form>
                            <?php if ($hasFence): ?>
                                <form method="post">
                                    <?= form_fields('branch_location') ?>
                                    <input type="hidden" name="branchId" value="<?= e($b['id']) ?>"><input type="hidden" name="latitude" value=""><input type="hidden" name="longitude" value="">
                                    <?= confirm_button('Remove the geofence for ' . $b['name'] . '? Check-in will no longer be location-restricted here.', 'Remove geofence', 'text-[11px] text-danger hover:underline w-full text-center block') ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
            </li>
        <?php endforeach; ?>
        <?php if (!$branches): ?><li class="text-sm text-muted py-2">None yet.</li><?php endif; ?>
    </ul>
    <details>
        <summary class="cursor-pointer text-sm text-brand font-medium">+ Add</summary>
        <form method="post" class="space-y-2 mt-3">
            <?= form_fields('branch', 'branches') ?>
            <input name="name" required maxlength="191" placeholder="Branch name" class="input">
            <input name="city" required maxlength="191" placeholder="City" class="input">
            <button type="submit" class="btn btn-secondary w-full justify-center text-sm">Add</button>
        </form>
    </details>
</div>
<?php $branchesTab = ob_get_clean();

ob_start(); ?>
<div class="card p-5 max-w-2xl">
    <p class="text-sm font-semibold text-ink mb-3">Onboarding task templates</p>
    <?= flash_alerts('templates') ?>
    <ul class="space-y-1.5 mb-3 text-sm text-ink-soft max-h-40 overflow-y-auto">
        <?php foreach ($templates as $t): ?>
            <li class="flex items-center justify-between gap-2">
                <span><span class="text-xs text-muted uppercase"><?= e($t['category']) ?></span> — <?= e($t['title']) ?></span>
                <form method="post"><?= form_fields('template_remove', 'templates') ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
                    <?= confirm_button('Remove the "' . $t['title'] . '" onboarding task template?', 'Remove', 'text-xs text-danger hover:underline') ?></form>
            </li>
        <?php endforeach; ?>
        <?php if (!$templates): ?><li class="text-muted">None yet.</li><?php endif; ?>
    </ul>
    <details>
        <summary class="cursor-pointer text-sm text-brand font-medium">+ Add</summary>
        <form method="post" class="space-y-2 mt-3">
            <?= form_fields('template_add', 'templates') ?>
            <select name="category" class="input"><option value="HR">HR</option><option value="IT">IT</option><option value="MANAGER">Manager</option></select>
            <input name="title" required maxlength="191" placeholder="Task title" class="input">
            <button type="submit" class="btn btn-secondary w-full justify-center text-sm">Add</button>
        </form>
    </details>
</div>
<?php $templatesTab = ob_get_clean();

$tabs = [
    ['key' => 'letterhead', 'label' => 'Letterhead', 'content' => $letterheadTab],
    ['key' => 'policy', 'label' => 'Policy', 'content' => $policyTab],
    ['key' => 'branches', 'label' => 'Branches', 'content' => $branchesTab],
    ['key' => 'departments', 'label' => 'Departments', 'content' => $settingsCard('Departments', array_column($departments, 'name'), 'department',
        '<input name="name" required maxlength="191" placeholder="Department name" class="input">', 'departments')],
    ['key' => 'leave-types', 'label' => 'Leave types', 'content' => $settingsCard('Leave types',
        array_map(static fn($l) => $l['name'] . ' — ' . $l['daysPerYear'] . ' days/yr', $leaveTypes), 'leave_type',
        '<input name="name" required maxlength="191" placeholder="Leave type name" class="input"><input name="daysPerYear" type="number" min="0" max="366" required placeholder="Days per year" class="input">'
        . '<label class="flex items-center gap-2 text-sm text-ink-soft"><input type="checkbox" name="carryForward" class="w-4 h-4"> Allow carry-forward</label>', 'leave-types')],
    ['key' => 'holidays', 'label' => 'Holidays', 'content' => $settingsCard('Holidays',
        array_map(static fn($h) => $h['name'] . ' — ' . format_date($h['date']), $holidays), 'holiday',
        '<input name="name" required maxlength="191" placeholder="Holiday name" class="input"><input name="date" type="date" required class="input">', 'holidays')],
    ['key' => 'onboarding', 'label' => 'Onboarding templates', 'content' => $templatesTab],
];

app_layout_open($auth, 'Company settings');
echo page_header('Company settings', 'Org structure and policy configuration.',
    '<div class="flex gap-2">'
    . '<a href="' . e(url('/dashboard/settings/statutory')) . '" class="btn btn-secondary">' . icon('landmark', 15) . ' Statutory config</a>'
    . '<a href="' . e(url('/dashboard/settings/users')) . '" class="btn btn-secondary">' . icon('users-round', 15) . ' Manage users</a>'
    . '<a href="' . e(url('/dashboard/settings/roles')) . '" class="btn btn-secondary">' . icon('shield-check', 15) . ' Custom roles</a></div>');
echo tabbed_panels($tabs, 'settings-tabs');
app_layout_close();
