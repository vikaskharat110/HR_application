<?php
/**
 * /login/demo — port of app/login/demo/page.tsx. Lists the accounts in
 * dev/demo/hrms_demo_data.sql. DEV ONLY: registered by dev/routes.php, never deployed.
 */
declare(strict_types=1);

$accounts = [
    ['Super Admin', 'business@webpayx.com', 'Sees every tenant on the platform.'],
    ['Company Admin', 'admin@meridianworks.com', 'Full control of Meridian Works.'],
    ['HR / Recruiter', 'hr@meridianworks.com', 'People ops, hiring, payroll runs.'],
    ['Manager', 'manager@meridianworks.com', 'Approves leave & expenses for their team.'],
    ['Employee', 'employee@meridianworks.com', 'Self-service: attendance, leave, payslips.'],
];
if (config('app.env') === 'production') {
    throw new HttpError(404);
}

document_open('Demo credentials');
?>
<div class="min-h-screen bg-[var(--color-surface)] flex items-center justify-center px-6 py-16">
    <div class="w-full max-w-lg">
        <a href="<?= e(url('/login')) ?>" class="inline-flex items-center gap-1.5 text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)] mb-6">
            <?= icon('arrow-left', 15) ?> Back to sign in
        </a>
        <h1 class="font-display text-2xl font-semibold text-[var(--color-ink)]">Demo credentials</h1>
            <p class="mt-1.5 text-sm text-[var(--color-muted)]">
                Once the optional demo data is imported (<code class="px-1 py-0.5 rounded bg-[var(--color-border-soft)] text-[13px]">dev/demo/hrms_demo_data.sql</code>),
                every account below shares the same password.
            </p>
            <div class="mt-6 card divide-y divide-[var(--color-border-soft)]">
                <?php foreach ($accounts as [$role, $email, $note]): ?>
                    <div class="flex items-center justify-between gap-4 px-5 py-4">
                        <div>
                            <p class="text-sm font-semibold text-[var(--color-ink)]"><?= e($role) ?></p>
                            <p class="text-sm text-[var(--color-brand)] font-mono"><?= e($email) ?></p>
                            <p class="text-xs text-[var(--color-muted)] mt-0.5"><?= e($note) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 flex items-center gap-2 rounded-lg bg-[var(--color-brand-softer)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">
                <?= icon('copy', 15, 'shrink-0') ?>
                Password for every demo account: <span class="font-mono font-semibold text-[var(--color-ink)]">Passw0rd!</span>
            </div>
    </div>
</div>
<?php document_close();
