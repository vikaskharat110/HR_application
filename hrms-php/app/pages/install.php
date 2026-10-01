<?php
/**
 * /install — one-time creation of the first Super Admin (no default
 * password exists anywhere). Locks itself permanently once a Super Admin
 * exists (storage/install.lock + a database check); afterwards this URL is 404.
 * Requires SETUP_KEY from .env, so only someone with file access to the
 * server can claim a freshly deployed site.
 */
declare(strict_types=1);

$schemaReady = true;
try {
    db_value('SELECT 1 FROM `User` LIMIT 1');
} catch (PDOException) {
    $schemaReady = false;
}
if ($schemaReady && install_is_locked()) {
    throw new HttpError(404);
}

handle_post([
    'install' => function (): string {
        install_create_super_admin(post('email'), post_raw('password'), post_raw('confirmPassword'), post_raw('setupKey'));
        flash('success', 'Super Admin created. Setup is now locked — sign in to continue.', 'login');
        return '/login';
    },
]);

document_open('Set up WorkNest');
?>
<div class="min-h-screen flex items-center justify-center bg-[var(--color-surface)] px-6 py-16">
    <div class="w-full max-w-sm">
        <?= brand_lockup() ?>
        <h1 class="font-display text-2xl font-semibold text-[var(--color-ink)] text-center">Create the Super Admin</h1>
        <p class="mt-1.5 text-sm text-[var(--color-muted)] text-center">One-time setup. This page disables itself as soon as the account exists.</p>
        <div class="mt-7">
            <?php if (!$schemaReady): ?>
                <div class="card p-6 text-sm text-ink-soft">
                    <p class="font-medium text-ink">Database not ready</p>
                    <p class="mt-1.5">Import <code>database/hrms.sql</code> into your database with phpMyAdmin, check the DB_* values in <code>.env</code>, then reload this page.</p>
                </div>
            <?php elseif (install_setup_key() === null): ?>
                <div class="card p-6 text-sm text-ink-soft">
                    <p class="font-medium text-ink">Setup key required</p>
                    <p class="mt-1.5">Add a line <code>SETUP_KEY=</code> followed by a long random value (at least 16 characters) to <code>.env</code>, then reload this page. You’ll be asked for the same value below. Remove it once setup is complete.</p>
                </div>
            <?php else: ?>
                <form method="post" class="card p-6 space-y-4">
                    <?= form_fields('install', 'install') ?>
                    <div>
                        <label class="field-label" for="setupKey">Setup key <span class="font-normal text-[var(--color-muted)]">(SETUP_KEY from .env)</span></label>
                        <input id="setupKey" name="setupKey" type="password" required autocomplete="off" class="input">
                    </div>
                    <div>
                        <label class="field-label" for="email">Super Admin email</label>
                        <input id="email" name="email" type="email" required autofocus class="input" value="<?= e(old('email', config('app.support_email'))) ?>">
                    </div>
                    <div>
                        <label class="field-label" for="password">Password</label>
                        <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters" class="input">
                    </div>
                    <div>
                        <label class="field-label" for="confirmPassword">Confirm password</label>
                        <input id="confirmPassword" name="confirmPassword" type="password" required minlength="12" autocomplete="new-password" class="input">
                    </div>
                    <?= flash_alerts('install') ?>
                    <button type="submit" class="btn btn-primary w-full justify-center py-2.5">Create Super Admin <?= icon('arrow-right', 16) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php document_close();
