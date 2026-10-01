<?php
/** /dashboard/recruitment/new — port of app/dashboard/recruitment/new/page.tsx */
declare(strict_types=1);

handle_post([
    'create' => fn() => action_create_job_posting($auth, $_POST),
]);

app_layout_open($auth, 'New job posting');
?>
<div class="max-w-xl">
    <?= back_link('/dashboard/recruitment', 'Back to recruitment') ?>
    <?= page_header('New job posting') ?>
    <?= flash_alerts('job') ?>
    <form method="post" class="card p-6 space-y-4">
        <?= form_fields('create', 'job') ?>
        <div><label class="field-label">Job title</label><input name="title" required maxlength="191" value="<?= e(old('title')) ?>" class="input"></div>
        <div class="grid grid-cols-2 gap-4">
            <div><label class="field-label">Department</label><input name="department" required maxlength="191" value="<?= e(old('department')) ?>" class="input"></div>
            <div><label class="field-label">Location</label><input name="location" required maxlength="191" value="<?= e(old('location')) ?>" class="input"></div>
        </div>
        <div><label class="field-label">Openings</label><input name="openings" type="number" min="1" value="<?= e(old('openings', '1')) ?>" class="input max-w-[8rem]"></div>
        <div><label class="field-label">Description</label><textarea name="description" rows="4" maxlength="20000" class="input"><?= e(old('description')) ?></textarea></div>
        <div class="flex justify-end gap-2">
            <a href="<?= e(url('/dashboard/recruitment')) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Publish posting</button>
        </div>
    </form>
</div>
<?php app_layout_close();
