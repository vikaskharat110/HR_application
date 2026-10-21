<?php
/** /dashboard/documents/{employeeId} — port of app/dashboard/documents/[employeeId]/page.tsx */
declare(strict_types=1);

$employeeId = $params['employeeId'];
if (!can_see_employee($auth, $employeeId)) {
    throw new HttpError(404);
}
$employee = db_one('SELECT * FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $auth['companyId']]) ?? throw new HttpError(404);
handle_post(document_row_handlers($auth));
$docs = db_all('SELECT * FROM Document WHERE companyId = ? AND employeeId = ? ORDER BY uploadedAt DESC', [$auth['companyId'], $employeeId]);

app_layout_open($auth, $employee['firstName'] . ' ' . $employee['lastName'] . ' — files');
echo back_link('/dashboard/documents', 'Back to documents');
echo page_header($employee['firstName'] . ' ' . $employee['lastName'], $employee['designation'], avatar($employee['firstName'], $employee['lastName'], $employee['avatarColor']));
?>
<div class="card p-5">
    <p class="text-sm font-semibold text-ink mb-3 flex items-center gap-2"><?= icon('folder', 16, 'text-brand') ?> <?= e($employee['firstName']) ?>’s files</p>
    <?php if (!$docs): ?>
        <p class="text-sm text-muted py-2">No documents for this employee yet.</p>
    <?php else: ?>
        <ul class="divide-y divide-border-soft"><?php foreach ($docs as $d) { echo document_row($d, $auth, '/dashboard/documents/' . $employeeId); } ?></ul>
    <?php endif; ?>
</div>
<?php app_layout_close();
