<?php
/** /forgot-password — port of app/forgot-password/page.tsx + ForgotPasswordForm. */
declare(strict_types=1);

handle_post([
    'request' => function (): string {
        action_request_password_reset(post('email'));
        $_SESSION['_reset_sent'] = true;
        return '/forgot-password';
    },
]);

$sent = !empty($_SESSION['_reset_sent']);
unset($_SESSION['_reset_sent']);

document_open('Reset your password');
?>
<div class="min-h-screen flex items-center justify-center bg-[var(--color-surface)] px-6 py-16">
    <div class="w-full max-w-sm">
        <?= brand_lockup() ?>
        <h1 class="font-display text-2xl font-semibold text-[var(--color-ink)] text-center">Reset your password</h1>
        <p class="mt-1.5 text-sm text-[var(--color-muted)] text-center">Enter your work email and we’ll send you a link to reset it.</p>
        <div class="mt-7">
            <?php if ($sent): ?>
                <div class="card p-6 text-center">
                    <?= icon('circle-check', 28, 'mx-auto text-[var(--color-brand)]') ?>
                    <p class="mt-3 text-sm text-[var(--color-ink)] font-medium">Check your email</p>
                    <p class="mt-1.5 text-sm text-[var(--color-muted)]">If an account exists for that email, we’ve sent a link to reset your password. It expires in 30 minutes.</p>
                    <a href="<?= e(url('/login')) ?>" class="inline-flex items-center gap-1.5 text-sm text-[var(--color-brand)] font-medium mt-5 hover:underline"><?= icon('arrow-left', 14) ?> Back to sign in</a>
                </div>
            <?php else: ?>
                <form method="post" class="card p-6 space-y-4" data-pending-form>
                    <?= form_fields('request', 'forgot') ?>
                    <div>
                        <label class="field-label" for="email">Work email</label>
                        <input id="email" name="email" type="email" required autofocus placeholder="you@company.com" class="input" value="<?= e(old('email')) ?>">
                    </div>
                    <?= flash_alerts('forgot') ?>
                    <button type="submit" class="btn btn-primary w-full justify-center py-2.5 disabled:opacity-70" data-pending-label="Sending…">
                        Send reset link <?= icon('arrow-right', 16) ?>
                    </button>
                    <a href="<?= e(url('/login')) ?>" class="flex items-center justify-center gap-1.5 text-sm text-[var(--color-muted)] hover:text-[var(--color-ink)]"><?= icon('arrow-left', 14) ?> Back to sign in</a>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php document_close();
