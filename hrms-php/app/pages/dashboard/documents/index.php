<?php
/** /dashboard/documents — port of app/dashboard/documents/page.tsx (+ upload forms, letter generator, template editor). */
declare(strict_types=1);

handle_post([
    'upload' => function () use ($auth) {
        action_upload_document($auth, uploaded_file('file'), $_POST);
        flash('success', 'Uploaded.', 'upload');
        return '/dashboard/documents#tab-upload';
    },
    'upload_own' => function () use ($auth) {
        action_upload_own_document($auth, uploaded_file('file'), $_POST);
        flash('success', 'Uploaded.', 'upload-own');
        return '/dashboard/documents#tab-upload-own';
    },
    'generate' => function () use ($auth) {
        action_generate_letter($auth, post('employeeId'), post('type'));
        flash('success', 'Letter generated — find it under “All documents”.', 'generate');
        return '/dashboard/documents#tab-generate-letter';
    },
    'template' => function () use ($auth) {
        action_upsert_letter_template($auth, post('type'), post_raw('subject'), post_raw('bodyHtml'), uploaded_file('templateFile'), post('removeTemplateFile') === '1');
        flash('success', 'Saved', 'template-' . post('type'));
        return '/dashboard/documents#tab-tpl-' . strtolower(post('type'));
    },
]);

$co = $auth['companyId'];
$canUpload = is_company_admin($auth);
$canSelfUpload = !$canUpload && $auth['employeeId'] !== null;
$companyDocCount = (int)db_value('SELECT COUNT(*) FROM Document WHERE companyId = ? AND employeeId IS NULL', [$co]);
[$in, $ids] = db_in(visible_employee_ids($auth));
$employeeFolderCount = (int)db_value("SELECT COUNT(DISTINCT employeeId) FROM Document WHERE companyId = ? AND employeeId IN ($in)", [$co, ...$ids]);
$employees = $canUpload ? db_all('SELECT e.id, e.firstName, e.lastName, e.departmentId, d.name AS department FROM Employee e LEFT JOIN Department d ON d.id = e.departmentId WHERE e.companyId = ? ORDER BY e.firstName', [$co]) : [];
$templates = [];
if ($canUpload) {
    foreach (db_all('SELECT * FROM LetterTemplate WHERE companyId = ?', [$co]) as $t) {
        $templates[$t['type']] = $t;
    }
}
$departments = [];
foreach ($employees as $emp) {
    if ($emp['departmentId']) {
        $departments[$emp['departmentId']] = $emp['department'];
    }
}
asort($departments);
$fileInput = '<div><label class="field-label">File</label><input type="file" name="file" required class="input" data-max-bytes="' . (int)config('uploads.max_bytes') . '">'
    . '<p class="text-xs text-muted mt-1">Up to 10MB · PDF, images, Word, Excel, PowerPoint, text or CSV.</p></div>'
    . '<div><label class="field-label">Display name</label><input name="name" maxlength="255" class="input" placeholder="Defaults to file name"></div>';

$tabs = [];
if ($canUpload) {
    ob_start(); ?>
    <div class="card p-6">
        <form method="post" enctype="multipart/form-data" class="space-y-3" data-pending-form data-scope-form>
            <?= form_fields('upload', 'upload') ?>
            <div class="grid sm:grid-cols-2 gap-4"><?= $fileInput ?></div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="field-label">Type</label>
                    <select name="type" class="input">
                        <?php foreach ((['POLICY' => 'Policy', 'OFFER_LETTER' => 'Offer letter', 'CONTRACT' => 'Contract'] + SELF_UPLOAD_TYPE_LABELS) as $v => $l): ?>
                            <option value="<?= $v ?>"><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Applies to</label>
                    <div class="flex gap-3 mb-2">
                        <label class="flex items-center gap-1.5 text-sm text-ink-soft"><input type="radio" name="scope" value="company" checked class="accent-brand"> Whole company</label>
                        <label class="flex items-center gap-1.5 text-sm text-ink-soft"><input type="radio" name="scope" value="employee" class="accent-brand"> One employee</label>
                    </div>
                    <div class="grid grid-cols-2 gap-2" data-scope-employee>
                        <select class="input" aria-label="Filter by department" data-dept-filter>
                            <option value="">All departments</option>
                            <?php foreach ($departments as $id => $name): ?><option value="<?= e($id) ?>"><?= e($name) ?></option><?php endforeach; ?>
                        </select>
                        <select name="employeeId" class="input" data-employee-select>
                            <option value="">Select employee</option>
                            <?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>" data-dept="<?= e($emp['departmentId'] ?? '') ?>"><?= e($emp['firstName'] . ' ' . $emp['lastName']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="sm:w-1/2 sm:pr-2"><label class="field-label">Expiry date (optional)</label><input type="date" name="expiryDate" class="input"></div>
            <?= flash_alerts('upload') ?>
            <button type="submit" class="btn btn-primary w-full justify-center disabled:opacity-70" data-pending-label="Uploading…">Upload</button>
        </form>
    </div>
    <?php $tabs[] = ['key' => 'upload', 'label' => 'Upload document', 'content' => ob_get_clean()];
}
if ($canSelfUpload) {
    ob_start(); ?>
    <div class="card p-6">
        <form method="post" enctype="multipart/form-data" class="space-y-3" data-pending-form>
            <?= form_fields('upload_own', 'upload-own') ?>
            <div class="grid sm:grid-cols-2 gap-4"><?= $fileInput ?></div>
            <div class="sm:w-1/2 sm:pr-2">
                <label class="field-label">Type</label>
                <select name="type" class="input" required>
                    <option value="" selected disabled>Select document</option>
                    <?php foreach (SELF_UPLOAD_TYPE_LABELS as $v => $l): ?><option value="<?= $v ?>"><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?= flash_alerts('upload-own') ?>
            <button type="submit" class="btn btn-primary w-full justify-center disabled:opacity-70" data-pending-label="Uploading…">Upload</button>
        </form>
        <p class="text-xs text-muted mt-3">Only visible to you and HR/Admin.</p>
    </div>
    <?php $tabs[] = ['key' => 'upload-own', 'label' => 'Upload your document', 'content' => ob_get_clean()];
}
if ($canUpload) {
    $tplTabs = [];
    foreach (LETTER_TYPES as $type) {
        $tpl = $templates[$type] ?? null;
        ob_start(); ?>
        <form method="post" enctype="multipart/form-data" class="space-y-3">
            <?= form_fields('template', 'template-' . $type) ?>
            <input type="hidden" name="type" value="<?= $type ?>">
            <div class="rounded-lg border border-dashed border-border p-3.5 bg-brand-softer/40">
                <label class="field-label">Word template (optional)</label>
                <p class="text-xs text-muted mb-2">Upload your own branded .docx letter with
                    <code class="font-mono">{{employeeName}}</code>, <code class="font-mono">{{designation}}</code>, <code class="font-mono">{{department}}</code>,
                    <code class="font-mono">{{dateOfJoining}}</code>, <code class="font-mono">{{ctc}}</code>, <code class="font-mono">{{manager}}</code>,
                    <code class="font-mono">{{companyName}}</code> or <code class="font-mono">{{today}}</code> typed in as placeholders. Generating a letter fills these in and
                    produces a completed .docx. Leave this empty to auto-generate a default PDF from the Subject/Body below instead.</p>
                <?php if ($tpl && $tpl['templateFileName']): ?>
                    <div class="flex items-center justify-between gap-3 rounded-lg bg-card border border-border px-3 py-2">
                        <span class="flex items-center gap-2 text-sm text-ink min-w-0"><?= icon('file-text', 15, 'text-brand shrink-0') ?><span class="truncate"><?= e($tpl['templateFileName']) ?></span></span>
                        <label class="flex items-center gap-1 text-xs text-danger shrink-0"><input type="checkbox" name="removeTemplateFile" value="1"> Remove on save</label>
                    </div>
                    <p class="text-xs text-muted mt-1.5">Choose a new file to replace it:</p>
                <?php endif; ?>
                <input type="file" name="templateFile" accept=".docx" class="input text-sm mt-1">
            </div>
            <div><label class="field-label">Subject</label><input name="subject" maxlength="2000" required placeholder="Subject — supports {{employeeName}}" value="<?= e($tpl['subject'] ?? '') ?>" class="input text-sm"></div>
            <div>
                <label class="field-label">Body <?= $tpl && $tpl['templateFileName'] ? '(used only if the Word template above is removed)' : '' ?></label>
                <textarea name="bodyHtml" rows="10" required placeholder="Body — {{designation}}, {{companyName}}, {{dateOfJoining}}, {{ctc}}, ..." class="input text-sm font-mono min-h-[10rem]"><?= e($tpl['bodyHtml'] ?? '') ?></textarea>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-secondary text-sm">Save template</button>
                <?php foreach (flashes('template-' . $type) as $f): ?>
                    <span class="flex items-center gap-1 text-xs <?= $f['type'] === 'success' ? 'text-success' : 'text-danger' ?>"><?= e($f['message']) ?></span>
                <?php endforeach; ?>
            </div>
        </form>
        <?php $tplTabs[] = ['key' => 'tpl-' . strtolower($type), 'label' => LETTER_LABEL[$type], 'content' => ob_get_clean()];
    }
    ob_start(); ?>
    <div class="card p-6">
        <form method="post" class="space-y-3" data-pending-form>
            <?= form_fields('generate', 'generate') ?>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="field-label">Employee</label><select name="employeeId" required class="input"><?php foreach ($employees as $emp): ?><option value="<?= e($emp['id']) ?>"><?= e($emp['firstName'] . ' ' . $emp['lastName']) ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label">Letter type</label><select name="type" required class="input"><?php foreach (LETTER_TYPES as $t): ?><option value="<?= $t ?>"><?= e(LETTER_LABEL[$t]) ?></option><?php endforeach; ?></select></div>
            </div>
            <?= flash_alerts('generate') ?>
            <button type="submit" class="btn btn-primary w-full justify-center disabled:opacity-70" data-pending-label="Generating…">Generate</button>
        </form>
        <details class="mt-5 pt-5 border-t border-border-soft">
            <summary class="cursor-pointer text-sm text-brand font-medium">Edit letter templates</summary>
            <div class="mt-3">
                <p class="text-xs font-semibold uppercase text-muted mb-2">Choose a template to edit</p>
                <?= tabbed_panels($tplTabs, 'letter-templates') ?>
            </div>
        </details>
    </div>
    <?php $tabs[] = ['key' => 'generate-letter', 'label' => 'Generate letter', 'content' => ob_get_clean()];
}
ob_start(); ?>
<div class="grid sm:grid-cols-2 gap-5">
    <a href="<?= e(url('/dashboard/documents/company')) ?>" class="card p-6 hover:border-[var(--color-brand)] transition-colors">
        <?= icon('folder-open', 28, 'text-brand mb-3') ?>
        <p class="text-sm font-semibold text-ink">Company documents</p>
        <p class="text-xs text-muted mt-1 flex items-center gap-1"><?= $companyDocCount ?> file<?= $companyDocCount === 1 ? '' : 's' ?> <?= icon('chevron-right', 13) ?></p>
    </a>
    <a href="<?= e(url('/dashboard/documents/employees')) ?>" class="card p-6 hover:border-[var(--color-brand)] transition-colors">
        <?= icon('folder', 28, 'text-brand mb-3') ?>
        <p class="text-sm font-semibold text-ink">Employee files</p>
        <p class="text-xs text-muted mt-1 flex items-center gap-1"><?= $employeeFolderCount ?> employee<?= $employeeFolderCount === 1 ? '' : 's' ?> <?= icon('chevron-right', 13) ?></p>
    </a>
</div>
<?php $tabs[] = ['key' => 'all-documents', 'label' => 'All documents', 'content' => ob_get_clean()];

app_layout_open($auth, 'Documents');
echo page_header('Documents', 'Policies, letters, and employee records.');
echo tabbed_panels($tabs, 'documents-tabs');
app_layout_close();
