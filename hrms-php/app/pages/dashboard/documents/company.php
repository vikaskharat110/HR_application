<?php
/** /dashboard/documents/company — port of app/dashboard/documents/company/page.tsx */
declare(strict_types=1);

handle_post(document_row_handlers($auth));
$documents = db_all('SELECT * FROM Document WHERE companyId = ? AND employeeId IS NULL ORDER BY uploadedAt DESC', [$auth['companyId']]);

app_layout_open($auth, 'Company documents');
echo back_link('/dashboard/documents', 'Back to documents');
echo page_header('Company documents', 'Policies and other files visible to everyone in the company.');
?>
<div class="card p-5 max-w-2xl">
    <?php if (!$documents): ?>
        <p class="text-sm text-muted py-2">No company-wide documents yet.</p>
    <?php else: ?>
        <ul class="divide-y divide-border-soft"><?php foreach ($documents as $d) { echo document_row($d, $auth, '/dashboard/documents/company'); } ?></ul>
    <?php endif; ?>
</div>
<?php app_layout_close();
