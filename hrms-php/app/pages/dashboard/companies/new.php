<?php
/** /dashboard/companies/new — port of app/dashboard/companies/new/page.tsx + NewCompanyForm */
declare(strict_types=1);

handle_post([
    'create' => fn() => action_create_company($auth, post('name'), post('industry'), post('adminEmail')),
]);

app_layout_open($auth, 'Provision a new company');
?>
<div class="max-w-lg">
    <?= back_link('/dashboard/companies', 'Back to companies') ?>
    <?= page_header('Provision a new company') ?>
    <form method="post" class="card p-6 space-y-4" data-pending-form>
        <?= form_fields('create', 'company') ?>
        <div><label class="field-label">Company name</label><input name="name" required maxlength="191" value="<?= e(old('name')) ?>" class="input"></div>
        <div><label class="field-label">Industry</label><input name="industry" maxlength="191" value="<?= e(old('industry')) ?>" class="input"></div>
        <div>
            <label class="field-label">Company admin email</label>
            <input name="adminEmail" type="email" required maxlength="191" value="<?= e(old('adminEmail')) ?>" class="input">
            <p class="text-xs text-muted mt-1.5">A Company Admin login is created with a secure temporary password, shown to you once after creation.</p>
        </div>
        <?= flash_alerts('company') ?>
        <div class="flex justify-end gap-2">
            <a href="<?= e(url('/dashboard/companies')) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary disabled:opacity-70" data-pending-label="Creating…">Create company</button>
        </div>
    </form>
</div>
<?php app_layout_close();
