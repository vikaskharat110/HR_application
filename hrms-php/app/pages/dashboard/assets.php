<?php
/** /dashboard/assets — port of app/dashboard/assets/page.tsx */
declare(strict_types=1);

handle_post([
    'create' => function () use ($auth) {
        action_create_asset($auth, post('name'), post('category'), post('serialNumber'));
        return null;
    },
    'assign' => function () use ($auth) {
        action_assign_asset($auth, post_id('assetId'), post('employeeId'));
        return null;
    },
    'return' => function () use ($auth) {
        action_return_asset($auth, post_id('assignmentId'), post_id('assetId'));
        return null;
    },
]);

$co = $auth['companyId'];
$canManage = has_role($auth, ASSET_MANAGER_ROLES);
$assets = db_all(
    'SELECT a.*, aa.id AS assignmentId, e.firstName, e.lastName FROM Asset a
       LEFT JOIN AssetAssignment aa ON aa.assetId = a.id AND aa.returnedDate IS NULL
       LEFT JOIN Employee e ON e.id = aa.employeeId
      WHERE a.companyId = ? ORDER BY a.name',
    [$co]
);
$employees = $canManage ? db_all("SELECT id, firstName, lastName FROM Employee WHERE companyId = ? AND status = 'ACTIVE' ORDER BY firstName", [$co]) : [];

app_layout_open($auth, 'Assets');
echo page_header('Assets', 'Company inventory and who\'s holding what.');
?>
<div class="<?= $canManage ? 'grid lg:grid-cols-3 gap-5' : '' ?>">
    <?php if ($canManage): ?>
        <div class="card p-6 space-y-6">
            <div>
                <p class="text-sm font-semibold text-ink mb-3">Add asset</p>
                <form method="post" class="space-y-3">
                    <?= form_fields('create', 'asset') ?>
                    <input name="name" required maxlength="191" placeholder="Asset name" class="input">
                    <input name="category" required maxlength="191" placeholder="Category (Laptop, Phone...)" class="input">
                    <input name="serialNumber" required maxlength="191" placeholder="Serial number" class="input">
                    <?= flash_alerts('asset') ?>
                    <button type="submit" class="btn btn-primary w-full justify-center">Add to inventory</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <div class="<?= $canManage ? 'lg:col-span-2 ' : '' ?>card overflow-x-auto">
        <?php if (!$assets): ?>
            <?= empty_state('boxes', 'No assets yet') ?>
        <?php else: ?>
            <table class="table-clean">
                <thead><tr><th>Asset</th><th>Category</th><th>Serial</th><th>Status</th><th>Assigned to</th><?= $canManage ? '<th></th>' : '' ?></tr></thead>
                <tbody>
                <?php foreach ($assets as $a): ?>
                    <tr>
                        <td class="font-medium text-ink"><?= e($a['name']) ?></td>
                        <td><?= e($a['category']) ?></td>
                        <td class="font-mono text-xs"><?= e($a['serialNumber']) ?></td>
                        <td><?= status_badge($a['status']) ?></td>
                        <td><?= e($a['assignmentId'] ? $a['firstName'] . ' ' . $a['lastName'] : DASH) ?></td>
                        <?php if ($canManage): ?>
                            <td>
                                <?php if ($a['assignmentId']): ?>
                                    <form method="post"><?= form_fields('return') ?><input type="hidden" name="assignmentId" value="<?= e($a['assignmentId']) ?>"><input type="hidden" name="assetId" value="<?= e($a['id']) ?>">
                                        <button class="btn btn-secondary !py-1 !px-2 text-xs"><?= icon('undo-2', 12) ?> Return</button></form>
                                <?php else: ?>
                                    <form method="post" class="flex gap-1.5"><?= form_fields('assign') ?><input type="hidden" name="assetId" value="<?= e($a['id']) ?>">
                                        <select name="employeeId" required class="input !py-1 text-xs !w-auto"><option value="">Assign to…</option>
                                            <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"><?= e($emp['firstName'] . ' ' . $emp['lastName']) ?></option><?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-secondary !py-1 !px-2 text-xs">Go</button></form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="px-5 py-3 text-xs text-muted border-t border-border-soft">Purchased dates omitted from this view — see individual employee profiles for assignment history.</p>
        <?php endif; ?>
    </div>
</div>
<?php app_layout_close();
