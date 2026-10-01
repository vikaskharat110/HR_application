<?php
/** /reset-password?token=… — port of app/reset-password/page.tsx + ResetPasswordForm. */
declare(strict_types=1);

handle_post([
    'reset' => function (): string {
        action_reset_password(post('token'), post_raw('password'), post_raw('confirmPassword'));
        $_SESSION['_reset_done'] = true;
        return '/reset-password';
    },
]);

$done = !empty($_SESSION['_reset_done']);
unset($_SESSION['_reset_done']);

$token = query('token');
$isValid = false;
if (!$done && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $row = db_one('SELECT expiresAt, usedAt FROM PasswordResetToken WHERE token = ?', [hash('sha256', $token)]);
    $isValid = $row !== null && $row['usedAt'] === null && dt($row['expiresAt']) > now();
}

document_open('Choose a new password');
?>
<div class="min-h-screen flex items-center justify-center bg-[var(--color-surface)] px-6 py-16">
    <div class="w-full max-w-sm">
        <?= brand_lockup() ?>
        <?php if ($done): ?>
            <div class="card p-6 text-center">
                <?= icon('circle-check', 28, 'mx-auto text-[var(--color-brand)]') ?>
                <p class="mt-3 text-sm text-[var(--color-ink)] font-medium">Password updated</p>
                <p class="mt-1.5 text-sm text-[var(--color-muted)]">You can now sign in with your new password.</p>
                <a href="<?= e(url('/login')) ?>" class="btn btn-primary w-full justify-center mt-5">Go to sign in</a>
            </div>
        <?php elseif ($isValid): ?>
            <h1 class="font-display text-2xl font-semibold text-[var(--color-ink)] text-center">Choose a new password</h1>
            <div class="mt-7">
                <form method="post" action="<?= e(url('/reset-password?token=' . rawurlencode($token))) ?>" class="card p-6 space-y-4" data-pending-form>
                    <?= form_fields('reset', 'reset') ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div>
                        <label class="field-label" for="password">New password</label>
                        <input id="password" name="password" type="password" required autofocus minlength="8" autocomplete="new-password" placeholder="At least 8 characters" class="input">
                    </div>
                    <div>
                        <label class="field-label" for="confirmPassword">Confirm new password</label>
                        <input id="confirmPassword" name="confirmPassword" type="password" required minlength="8" autocomplete="new-password" class="input">
                    </div>
                    <?= flash_alerts('reset') ?>
                    <button type="submit" class="btn btn-primary w-full justify-center py-2.5 disabled:opacity-70" data-pending-label="Updating…">
                        Reset password <?= icon('arrow-right', 16) ?>
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="card p-6 text-center">
                <?= icon('circle-alert', 28, 'mx-auto text-[var(--color-danger)]') ?>
                <p class="mt-3 text-sm text-[var(--color-ink)] font-medium">This link is invalid or has expired</p>
                <p class="mt-1.5 text-sm text-[var(--color-muted)]">Password reset links are only valid for 30 minutes.</p>
                <?= flash_alerts('reset') ?>
                <a href="<?= e(url('/forgot-password')) ?>" class="btn btn-primary w-full justify-center mt-5">Request a new link</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php document_close();
