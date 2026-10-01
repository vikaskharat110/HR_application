<?php
/** /dashboard/employees/import — port of app/dashboard/employees/import/page.tsx + ImportEmployeesForm */
declare(strict_types=1);

handle_post([
    'import' => function () use ($auth): ?string {
        $file = uploaded_file('file') ?? throw new UserError('Choose a CSV file to import.');
        $_SESSION['_import_result'] = action_import_employees($auth, $file);
        return null;
    },
]);
$result = $_SESSION['_import_result'] ?? null;
unset($_SESSION['_import_result']);

app_layout_open($auth, 'Import employees');
?>
<div class="max-w-xl">
    <?= back_link('/dashboard/employees', 'Back to employees') ?>
    <?= page_header('Import employees', 'Bulk create or update employee records from a CSV file.',
        '<a href="' . e(url('/api/employees/import-template')) . '" class="btn btn-secondary">' . icon('download', 15) . ' Download template</a>') ?>
    <div class="card p-4 mb-5 text-sm text-ink-soft bg-[var(--color-brand-softer)] border-none">
        Rows with a Employee Code that already exists <b>update</b> that record; a blank or unrecognized code
        <b> creates</b> a new one. Department and Branch must already exist under Settings — a typo won’t
        auto-create one. Status isn’t imported — use the exit or confirmation workflow to change it.
    </div>
    <div class="space-y-5">
        <form method="post" enctype="multipart/form-data" class="card p-6 space-y-4" data-pending-form>
            <?= form_fields('import', 'import') ?>
            <div>
                <label class="field-label">CSV file</label>
                <input type="file" name="file" accept=".csv,text/csv" required class="input">
                <p class="text-xs text-muted mt-1.5">Columns must match the template — download it below if you haven’t already.</p>
            </div>
            <?= flash_alerts('import') ?>
            <button type="submit" class="btn btn-primary disabled:opacity-70" data-pending-label="Importing…"><?= icon('cloud-upload', 16) ?> Import</button>
        </form>
        <?php if ($result): ?>
            <div class="card p-6">
                <div class="flex items-center gap-2 text-sm font-semibold text-ink mb-3"><?= icon('circle-check', 16, 'text-brand') ?> Import complete</div>
                <div class="flex items-center gap-4 text-sm mb-4">
                    <span class="text-ink-soft"><span class="font-semibold text-ink"><?= (int)$result['created'] ?></span> created</span>
                    <span class="text-ink-soft"><span class="font-semibold text-ink"><?= (int)$result['updated'] ?></span> updated</span>
                    <span class="text-ink-soft"><span class="font-semibold text-danger"><?= count($result['errors']) ?></span> failed</span>
                </div>
                <?php if ($result['errors']): ?>
                    <div class="max-h-64 overflow-y-auto border-t border-border-soft pt-3">
                        <ul class="space-y-1.5">
                            <?php foreach ($result['errors'] as $err): ?>
                                <li class="text-xs text-ink-soft"><span class="font-mono text-danger">Row <?= (int)$err['row'] ?></span> — <?= e($err['message']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php app_layout_close();
