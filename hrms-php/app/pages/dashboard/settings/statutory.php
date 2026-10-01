<?php
/** /dashboard/settings/statutory — port of app/dashboard/settings/statutory/page.tsx */
declare(strict_types=1);

handle_post([
    'save' => function () use ($auth) {
        action_update_statutory_config($auth, $_POST);
        flash('success', 'Statutory configuration saved.', 'statutory');
        return null;
    },
]);

$cfg = get_statutory_config($auth['companyId']);
$pretty = static fn($json) => json_encode(json_decode((string)$json, true) ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$field = static fn(string $name, string $label, string $step) =>
    '<div><label class="field-label">' . e($label) . '</label><input type="number" name="' . $name . '" step="' . $step . '" min="0" value="' . e(old($name, js_num($cfg[$name]))) . '" class="input"></div>';

app_layout_open($auth, 'Statutory configuration');
echo back_link('/dashboard/settings', 'Back to settings');
echo page_header('Statutory configuration', 'PF, ESI, Professional Tax and TDS rules used to auto-calculate salary structures — configurable per company, not fixed.');
?>
<form method="post" class="card p-6 space-y-6 max-w-3xl">
    <?= form_fields('save', 'statutory') ?>
    <div>
        <p class="text-sm font-semibold text-ink mb-3">Provident Fund (PF)</p>
        <div class="grid sm:grid-cols-3 gap-3">
            <?= $field('pfEmployeeRate', 'Employee rate', '0.001') ?><?= $field('pfEmployerRate', 'Employer rate', '0.001') ?><?= $field('pfWageCeiling', 'Wage ceiling (₹)', '1') ?>
        </div>
    </div>
    <div>
        <p class="text-sm font-semibold text-ink mb-3">Employee State Insurance (ESI)</p>
        <div class="grid sm:grid-cols-3 gap-3">
            <?= $field('esiEmployeeRate', 'Employee rate', '0.0001') ?><?= $field('esiEmployerRate', 'Employer rate', '0.0001') ?><?= $field('esiWageCeiling', 'Wage ceiling (₹)', '1') ?>
        </div>
    </div>
    <div>
        <label class="field-label">Professional Tax slabs (JSON — monthly basic → amount)</label>
        <textarea name="ptSlabs" rows="4" class="input font-mono text-xs"><?= e(old('ptSlabs', $pretty($cfg['ptSlabs']))) ?></textarea>
    </div>
    <div>
        <label class="field-label">TDS slabs (JSON — annualized basic → monthly deduction)</label>
        <textarea name="tdsSlabs" rows="4" class="input font-mono text-xs"><?= e(old('tdsSlabs', $pretty($cfg['tdsSlabs']))) ?></textarea>
    </div>
    <?= flash_alerts('statutory') ?>
    <button type="submit" class="btn btn-primary">Save statutory configuration</button>
</form>
<?php app_layout_close();
