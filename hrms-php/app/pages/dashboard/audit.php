<?php
/**
 * /dashboard/audit — port of app/dashboard/audit/page.tsx. Everyone except
 * the Super Admin is scoped to their own company (including the filter
 * dropdown values, which the original read platform-wide).
 */
declare(strict_types=1);

const AUDIT_PAGE_SIZE = 50;
$isTenantScoped = $auth['role'] !== 'SUPER_ADMIN';
$companyParam = query('companyId');
$companyId = $isTenantScoped ? ($auth['companyId'] ?? '__none__') : $companyParam;
$action = query('action');
$entityType = query('entityType');
$page = query_page();

$where = ['1=1'];
$p = [];
if ($companyId === '__platform__') {
    $where[] = 'l.companyId IS NULL';
} elseif ($companyId !== '') {
    $where[] = 'l.companyId = ?';
    $p[] = $companyId;
}
$scopeSql = implode(' AND ', $where);
$scopeParams = $p;
if ($action !== '') {
    $where[] = 'l.action = ?';
    $p[] = $action;
}
if ($entityType !== '') {
    $where[] = 'l.entityType = ?';
    $p[] = $entityType;
}
$w = implode(' AND ', $where);

$companies = $isTenantScoped ? [] : db_all('SELECT id, name FROM Company ORDER BY name');
$actions = db_column("SELECT DISTINCT l.action FROM AuditLog l WHERE " . ($isTenantScoped ? $scopeSql : '1=1') . ' ORDER BY l.action', $isTenantScoped ? $scopeParams : []);
$entityTypes = db_column("SELECT DISTINCT l.entityType FROM AuditLog l WHERE " . ($isTenantScoped ? $scopeSql : '1=1') . ' ORDER BY l.entityType', $isTenantScoped ? $scopeParams : []);
$total = (int)db_value("SELECT COUNT(*) FROM AuditLog l WHERE $w", $p);
$logs = db_all(
    "SELECT l.*, u.email AS actorEmail, c.name AS companyName FROM AuditLog l
       LEFT JOIN `User` u ON u.id = l.actorId LEFT JOIN Company c ON c.id = l.companyId
      WHERE $w ORDER BY l.createdAt DESC LIMIT " . AUDIT_PAGE_SIZE . ' OFFSET ' . (($page - 1) * AUDIT_PAGE_SIZE),
    $p
);
$totalPages = max(1, (int)ceil($total / AUDIT_PAGE_SIZE));

app_layout_open($auth, 'Audit Logs');
echo page_header('Audit Logs', $isTenantScoped ? "$total recorded action(s) for your company." : "$total recorded action(s) across every tenant on the platform.");
?>
<form class="card p-4 mb-5 flex flex-wrap items-end gap-3" method="get">
    <?php if (!$isTenantScoped): ?>
        <div>
            <label class="field-label">Company</label>
            <select name="companyId" class="input">
                <option value="">All companies</option>
                <option value="__platform__"<?= selected('__platform__', $companyId) ?>>Platform (no company)</option>
                <?php foreach ($companies as $c): ?><option value="<?= e($c['id']) ?>"<?= selected($c['id'], $companyId) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div>
        <label class="field-label">Action</label>
        <select name="action" class="input"><option value="">All actions</option>
            <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= selected($a, $action) ?>><?= e($a) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="field-label">Entity</label>
        <select name="entityType" class="input"><option value="">All entities</option>
            <?php foreach ($entityTypes as $t): ?><option value="<?= e($t) ?>"<?= selected($t, $entityType) ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ((!$isTenantScoped && $companyParam !== '') || $action !== '' || $entityType !== ''): ?>
        <a href="<?= e(url('/dashboard/audit')) ?>" class="text-sm text-muted hover:text-ink">Clear</a>
    <?php endif; ?>
</form>
<div class="card overflow-x-auto">
    <?php if (!$logs): ?>
        <?= empty_state('history', 'No audit entries match these filters') ?>
    <?php else: ?>
        <table class="table-clean">
            <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Company</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="whitespace-nowrap"><?= e(format_datetime($log['createdAt'])) ?></td>
                    <td class="text-ink"><?= e($log['actorEmail'] ?? 'system') ?></td>
                    <td><?= e($log['action']) ?></td>
                    <td><?= e($log['entityType']) ?> <span class="text-muted text-xs font-mono"><?= e(substr($log['entityId'], 0, 8)) ?></span></td>
                    <td>
                        <?php if ($log['companyName'] && !$isTenantScoped): ?>
                            <a href="<?= e(url('/dashboard/companies/' . $log['companyId'])) ?>" class="text-brand hover:underline"><?= e($log['companyName']) ?></a>
                        <?php elseif ($log['companyName']): ?>
                            <?= e($log['companyName']) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?= pagination($page, $totalPages, '/dashboard/audit', ['companyId' => $isTenantScoped ? '' : $companyParam, 'action' => $action, 'entityType' => $entityType]) ?>
<?php app_layout_close();
