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
    'branch_update' => function () use ($auth) {
        action_update_branch($auth, post_id('id'), post('name'), post('city'));
        flash('success', 'Branch updated.', 'branches');
        return '/dashboard/settings#tab-branches';
    },
    'branch_delete' => function () use ($auth) {
        action_delete_branch($auth, post_id('id'));
        flash('success', 'Branch deleted.', 'branches');
        return '/dashboard/settings#tab-branches';
    },
    'department_update' => function () use ($auth) {
        action_update_department($auth, post_id('id'), post('name'));
        flash('success', 'Department updated.', 'departments');
        return '/dashboard/settings#tab-departments';
    },
    'department_delete' => function () use ($auth) {
        action_delete_department($auth, post_id('id'));
        flash('success', 'Department deleted.', 'departments');
        return '/dashboard/settings#tab-departments';
    },
    'leave_type_update' => function () use ($auth) {
        action_update_leave_type($auth, post_id('id'), post('name'), post('daysPerYear'), post('carryForward') === 'on', post('paid') !== '0');
        flash('success', 'Leave type updated.', 'leave-types');
        return '/dashboard/settings#tab-leave-types';
    },
    'leave_type_delete' => function () use ($auth) {
        action_delete_leave_type($auth, post_id('id'));
        flash('success', 'Leave type deleted.', 'leave-types');
        return '/dashboard/settings#tab-leave-types';
    },
    'holiday_update' => function () use ($auth) {
        action_update_holiday($auth, post_id('id'), post('name'), post('date'));
        flash('success', 'Holiday updated.', 'holidays');
        return '/dashboard/settings#tab-holidays';
    },
    'holiday_delete' => function () use ($auth) {
        action_delete_holiday($auth, post_id('id'));
        flash('success', 'Holiday deleted.', 'holidays');
        return '/dashboard/settings#tab-holidays';
    },
    'template_update' => function () use ($auth) {
        action_update_onboarding_template($auth, post_id('id'), post('category'), post('title'));
        flash('success', 'Template updated.', 'templates');
        return '/dashboard/settings#tab-onboarding';
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
        action_add_leave_type($auth, post('name'), post('daysPerYear'), post('carryForward') === 'on', post('paid') !== '0');
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
$branches = db_all('SELECT b.*, (SELECT COUNT(*) FROM Employee e WHERE e.branchId = b.id) AS people FROM Branch b WHERE b.companyId = ? ORDER BY b.name', [$co]);
$departments = db_all('SELECT d.id, d.name, (SELECT COUNT(*) FROM Employee e WHERE e.departmentId = d.id) AS people FROM Department d WHERE d.companyId = ? ORDER BY d.name', [$co]);
$leaveTypes = db_all('SELECT id, name, daysPerYear, carryForward, paid FROM LeaveType WHERE companyId = ? ORDER BY paid DESC, name', [$co]);
$holidays = db_all('SELECT id, name, date FROM Holiday WHERE companyId = ? ORDER BY date', [$co]);
$templates = db_all('SELECT * FROM OnboardingTaskTemplate WHERE companyId = ? ORDER BY category, sortOrder', [$co]);

$cellInput = static fn(string $form, string $name, string $value, string $extra = '') =>
    '<input form="' . e($form) . '" name="' . e($name) . '" value="' . e($value) . '" required maxlength="191" class="input !py-1 text-sm" ' . $extra . '>';

/**
 * Editable table card: every row is its own form (inputs bind to it with the form="" attribute), with Save and
 * Delete buttons, plus an add row at the bottom. $rows = [['id'=>, 'cells'=>fn(string $formId): string[], 'confirm'=>string], ...].
 */
$crudTable = static function (string $title, string $scope, array $headers, array $rows, string $updateAction, string $deleteAction,
                              string $addAction, string $addCells, string $note = ''): string {
    $h = '<div class="card p-5"><p class="text-sm font-semibold text-ink mb-1">' . e($title) . '</p>'
        . ($note !== '' ? '<p class="text-xs text-muted mb-3">' . e($note) . '</p>' : '<div class="mb-3"></div>')
        . flash_alerts($scope) . '<div class="overflow-x-auto"><table class="table-clean"><thead><tr>';
    foreach ($headers as $th) {
        $h .= '<th>' . e($th) . '</th>';
    }
    $h .= '<th class="text-right">Actions</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $f = 'f-' . $scope . '-' . $r['id'];
        $h .= '<tr>';
        foreach (($r['cells'])($f) as $cell) {
            $h .= '<td>' . $cell . '</td>';
        }
        $h .= '<td class="text-right whitespace-nowrap"><form id="' . e($f) . '" method="post" class="inline">' . form_fields($updateAction, $scope)
            . '<input type="hidden" name="id" value="' . e($r['id']) . '"><button type="submit" class="btn btn-secondary text-xs !py-1">Save</button></form> '
            . '<form method="post" class="inline">' . form_fields($deleteAction, $scope) . '<input type="hidden" name="id" value="' . e($r['id']) . '">'
            . confirm_button($r['confirm'], 'Delete', 'text-xs text-danger hover:underline px-1') . '</form></td></tr>';
    }
    if (!$rows) {
        $h .= '<tr><td colspan="' . (count($headers) + 1) . '" class="text-center text-muted py-5">None yet.</td></tr>';
    }
    $f = 'add-' . $scope;
    return $h . '<tr class="bg-brand-softer">' . $addCells . '<td class="text-right"><form id="' . e($f) . '" method="post" class="inline">'
        . form_fields($addAction, $scope) . '<button type="submit" class="btn btn-primary text-xs !py-1">Add</button></form></td></tr>'
        . '</tbody></table></div></div>';
};
$addCell = static fn(string $form, string $name, string $placeholder, string $extra = '') =>
    '<td><input form="' . e($form) . '" name="' . e($name) . '" required maxlength="191" placeholder="' . e($placeholder) . '" class="input !py-1 text-sm" ' . $extra . '></td>';
$numField = static fn(string $name, string $label, int $value) =>
    '<div><label class="field-label">' . e($label) . '</label><input type="number" min="0" name="' . e($name) . '" value="' . $value . '" class="input"></div>';

ob_start(); ?>
<div class="card p-5">
    <p class="text-sm font-semibold text-ink mb-1">Company letterhead</p>
    <p class="text-xs text-muted mb-4">Shown on every generated offer/appointment/relieving letter.</p>
    <form method="post" enctype="multipart/form-data" class="space-y-4" data-pending-form>
        <?= form_fields('letterhead', 'letterhead') ?>
        <div class="overflow-x-auto"><table class="table-clean">
            <thead><tr><th style="width:12rem">Field</th><th>Value</th></tr></thead>
            <tbody>
                <tr><td class="font-medium">Logo</td><td>
                    <?php if ($company['logoUrl'] && $company['logoUrl'] !== '#'): ?>
                        <img src="<?= e(url('/files/logo/' . $co)) ?>" alt="Current logo" class="h-10 mb-2 object-contain">
                    <?php endif; ?>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="input !py-1 text-sm">
                    <p class="text-xs text-muted mt-1">PNG, JPG or WebP, up to 10MB. Leave blank to keep the current logo.</p></td></tr>
                <tr><td class="font-medium">Phone</td><td><input name="phone" maxlength="50" value="<?= e($company['phone'] ?? '') ?>" placeholder="e.g. 72765 45228" class="input !py-1 text-sm"></td></tr>
                <tr><td class="font-medium">Registered address</td><td><textarea name="address" rows="3" maxlength="2000" placeholder="Full postal address for the letterhead" class="input text-sm"><?= e($company['address'] ?? '') ?></textarea></td></tr>
                <tr><td class="font-medium">PAN number</td><td><input name="panNumber" maxlength="50" value="<?= e($company['panNumber'] ?? '') ?>" placeholder="e.g. AAJFF7660C" class="input !py-1 text-sm"></td></tr>
            </tbody>
        </table></div>
        <?= flash_alerts('letterhead') ?>
        <button type="submit" class="btn btn-primary disabled:opacity-70" data-pending-label="Saving…">Save letterhead</button>
    </form>
</div>
<?php $letterheadTab = ob_get_clean();

ob_start(); ?>
<div class="card p-5">
    <p class="text-sm font-semibold text-ink mb-3">Policy</p>
    <?= flash_alerts('policy') ?>
    <form method="post" class="space-y-4">
        <?= form_fields('policy', 'policy') ?>
        <div class="overflow-x-auto"><table class="table-clean">
            <thead><tr><th>Setting</th><th class="w-40">Value</th><th>Notes</th></tr></thead>
            <tbody>
                <tr><td class="font-medium">Work week</td><td><input name="workWeek" maxlength="191" value="<?= e($policy['workWeek'] ?? 'MON-FRI') ?>" class="input !py-1 text-sm"></td><td class="text-xs text-muted">e.g. MON-FRI</td></tr>
                <?php foreach ([
                    ['checkInGraceMins', 'Check-in grace', 'mins', 15, 'Minutes after shift start before a check-in is marked late'],
                    ['probationMonths', 'Probation', 'months', 3, ''],
                    ['casualLeaveDays', 'Casual leave', 'days/yr', 12, ''],
                    ['sickLeaveDays', 'Sick leave', 'days/yr', 8, ''],
                    ['earnedLeaveDays', 'Earned leave', 'days/yr', 15, ''],
                    ['noticePeriodDays', 'Notice period', 'days', 30, ''],
                ] as [$key, $label, $unit, $default, $note]): ?>
                    <tr><td class="font-medium"><?= e($label) ?> <span class="text-xs text-muted font-normal">(<?= e($unit) ?>)</span></td>
                        <td><input type="number" min="0" name="<?= e($key) ?>" value="<?= (int)($policy[$key] ?? $default) ?>" class="input !py-1 text-sm"></td>
                        <td class="text-xs text-muted"><?= e($note) ?></td></tr>
                <?php endforeach; ?>
                <tr><td class="font-medium">Geofenced attendance</td>
                    <td><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="geofenceAttendanceEnabled"<?= checked((bool)($policy['geofenceAttendanceEnabled'] ?? false)) ?> class="w-4 h-4 accent-brand"> Required</label></td>
                    <td class="text-xs text-muted">Employees must be at their branch to check in/out. Checked against the radius set under the Branches tab; nobody is restricted until a branch also has its location set.</td></tr>
            </tbody>
        </table></div>
        <button type="submit" class="btn btn-primary">Save policy</button>
    </form>
</div>
<?php $policyTab = ob_get_clean();

ob_start(); ?>
<div class="card p-5">
    <p class="text-sm font-semibold text-ink mb-3">Branches</p>
    <?= flash_alerts('branches') ?>
    <div class="overflow-x-auto"><table class="table-clean">
        <thead><tr><th>Branch name</th><th>City</th><th>Geofence</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($branches as $b):
            $hasFence = $b['latitude'] !== null; $f = 'f-branches-' . $b['id']; ?>
            <tr>
                <td style="vertical-align:top"><?= $cellInput($f, 'name', $b['name']) ?></td>
                <td style="vertical-align:top"><?= $cellInput($f, 'city', $b['city']) ?></td>
                <td>
                    <p class="text-xs text-muted flex items-center gap-1 mb-1"><?= icon('map-pin', 11) ?>
                        <?= $hasFence ? 'Active — ' . (int)$b['geofenceRadiusMeters'] . 'm radius' : 'Not set' ?></p>
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
                </td>
                <td class="text-right whitespace-nowrap" style="vertical-align:top">
                    <form id="<?= e($f) ?>" method="post" class="inline"><?= form_fields('branch_update', 'branches') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>">
                        <button type="submit" class="btn btn-secondary text-xs !py-1">Save</button></form>
                    <form method="post" class="inline"><?= form_fields('branch_delete', 'branches') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>">
                        <?= confirm_button('Delete the branch "' . $b['name'] . '"?' . ((int)$b['people'] > 0 ? ' ' . (int)$b['people'] . ' employee(s) will be left without a branch.' : ''), 'Delete', 'text-xs text-danger hover:underline px-1') ?></form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$branches): ?><tr><td colspan="4" class="text-center text-muted py-5">None yet.</td></tr><?php endif; ?>
            <tr class="bg-brand-softer">
                <?= $addCell('add-branches', 'name', 'Branch name') ?><?= $addCell('add-branches', 'city', 'City') ?><td class="text-xs text-muted">Set after adding</td>
                <td class="text-right"><form id="add-branches" method="post" class="inline"><?= form_fields('branch', 'branches') ?><button type="submit" class="btn btn-primary text-xs !py-1">Add</button></form></td>
            </tr>
        </tbody>
    </table></div>
</div>
<?php $branchesTab = ob_get_clean();

ob_start(); ?>
<?php
$catSelect = static function (string $form, string $current) {
    $h = '<select form="' . e($form) . '" name="category" class="input !py-1 text-sm !w-auto">';
    foreach (['HR' => 'HR', 'IT' => 'IT', 'MANAGER' => 'Manager'] as $v => $l) {
        $h .= '<option value="' . $v . '"' . selected($v, $current) . '>' . $l . '</option>';
    }
    return $h . '</select>';
};
echo $crudTable('Onboarding task templates', 'templates', ['Category', 'Task title'],
    array_map(static fn($t) => ['id' => $t['id'], 'confirm' => 'Delete the "' . $t['title'] . '" onboarding task template?',
        'cells' => static fn($f) => [$catSelect($f, $t['category']), $cellInput($f, 'title', $t['title'])]], $templates),
    'template_update', 'template_remove', 'template_add',
    '<td>' . $catSelect('add-templates', 'HR') . '</td>' . $addCell('add-templates', 'title', 'Task title'));
?>
<?php $templatesTab = ob_get_clean();

$tabs = [
    ['key' => 'letterhead', 'label' => 'Letterhead', 'content' => $letterheadTab],
    ['key' => 'policy', 'label' => 'Policy', 'content' => $policyTab],
    ['key' => 'branches', 'label' => 'Branches', 'content' => $branchesTab],
    ['key' => 'departments', 'label' => 'Departments', 'content' => $crudTable('Departments', 'departments', ['Department name', 'Employees'],
        array_map(static fn($d) => ['id' => $d['id'], 'confirm' => 'Delete the "' . $d['name'] . '" department?' . ((int)$d['people'] > 0 ? ' ' . (int)$d['people'] . ' employee(s) will be left without a department.' : ''),
            'cells' => static fn($f) => [$cellInput($f, 'name', $d['name']), '<span class="text-muted">' . (int)$d['people'] . '</span>']], $departments),
        'department_update', 'department_delete', 'department',
        $addCell('add-departments', 'name', 'Department name') . '<td></td>')],
    ['key' => 'leave-types', 'label' => 'Leave types', 'content' => $crudTable('Leave types', 'leave-types', ['Leave type', 'Days / year', 'Category', 'Carry forward'],
        array_map(static fn($l) => ['id' => $l['id'], 'confirm' => 'Delete the "' . $l['name'] . '" leave type? This also removes its balances. Types that have leave requests cannot be deleted.',
            'cells' => static fn($f) => [
                $cellInput($f, 'name', $l['name']),
                '<input form="' . e($f) . '" type="number" name="daysPerYear" min="0" max="366" required value="' . (int)$l['daysPerYear'] . '" class="input !py-1 text-sm" style="width:6rem">',
                '<select form="' . e($f) . '" name="paid" class="input !py-1 text-sm !w-auto"><option value="1"' . selected((int)$l['paid'], 1) . '>Paid</option><option value="0"' . selected((int)$l['paid'], 0) . '>Unpaid</option></select>',
                '<input form="' . e($f) . '" type="checkbox" name="carryForward" class="w-4 h-4"' . checked((bool)$l['carryForward']) . '>',
            ]], $leaveTypes),
        'leave_type_update', 'leave_type_delete', 'leave_type',
        $addCell('add-leave-types', 'name', 'Leave type name')
        . '<td><input form="add-leave-types" type="number" name="daysPerYear" min="0" max="366" required placeholder="Days" class="input !py-1 text-sm" style="width:6rem"></td>'
        . '<td><select form="add-leave-types" name="paid" class="input !py-1 text-sm !w-auto"><option value="1">Paid</option><option value="0">Unpaid</option></select></td>'
        . '<td><input form="add-leave-types" type="checkbox" name="carryForward" class="w-4 h-4"></td>',
        'Employees choose Paid or Unpaid when applying; unpaid types have no balance limit.')],
    ['key' => 'holidays', 'label' => 'Holidays', 'content' => $crudTable('Holidays', 'holidays', ['Holiday', 'Date'],
        array_map(static fn($h) => ['id' => $h['id'], 'confirm' => 'Delete the holiday "' . $h['name'] . '"?',
            'cells' => static fn($f) => [$cellInput($f, 'name', $h['name']),
                '<input form="' . e($f) . '" type="date" name="date" required value="' . e(dt($h['date'])->format('Y-m-d')) . '" class="input !py-1 text-sm !w-auto">']], $holidays),
        'holiday_update', 'holiday_delete', 'holiday',
        $addCell('add-holidays', 'name', 'Holiday name') . '<td><input form="add-holidays" type="date" name="date" required class="input !py-1 text-sm !w-auto"></td>')],
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
