<?php
/** /login — port of app/login/page.tsx + components/LoginForm.tsx */
declare(strict_types=1);

handle_post([
    'login' => fn() => action_login(post('email'), post_raw('password'), post('next')),
]);

$next = safe_next(query('next'), '/dashboard');
$demoAvailable = config('app.env') !== 'production';

document_open('Sign in');
?>
<div class="min-h-screen flex bg-[var(--color-surface)]">
    <div class="hidden lg:flex lg:w-[46%] relative flex-col justify-between bg-[var(--color-brand-dark)] text-white px-12 py-12 overflow-hidden">
        <div class="pointer-events-none absolute inset-0 opacity-[0.08] login-dots"></div>
        <div class="relative flex items-center gap-2.5">
            <div class="grid place-items-center w-9 h-9 rounded-lg bg-[var(--color-accent)]"><?= icon('leaf', 19) ?></div>
            <span class="font-display text-lg font-semibold">WorkNest</span>
        </div>
        <div class="relative max-w-md">
            <p class="font-display text-3xl leading-snug">One place for every person process — from offer letter to final settlement.</p>
            <p class="mt-4 text-white/60 text-sm leading-relaxed">
                Attendance, leave, payroll, hiring, performance, and approvals — all in a
                single tenant-isolated workspace built for how HR teams actually work.
            </p>
            <div class="mt-10 space-y-4 text-sm">
                <div class="flex items-center gap-3 text-white/80"><?= icon('shield-check', 17, 'text-[var(--color-accent)]') ?> Role-based access with a full audit trail</div>
                <div class="flex items-center gap-3 text-white/80"><?= icon('users-round', 17, 'text-[var(--color-accent)]') ?> Multi-company, multi-branch from day one</div>
                <div class="flex items-center gap-3 text-white/80"><?= icon('calendar-clock', 17, 'text-[var(--color-accent)]') ?> Approvals that route themselves</div>
            </div>
        </div>
        <p class="relative text-xs text-white/35">© <?= e(now()->format('Y')) ?> WorkNest. Internal HR platform.</p>
    </div>

    <div class="flex-1 flex items-center justify-center px-6 py-16">
        <div class="w-full max-w-sm">
            <div class="lg:hidden flex items-center gap-2.5 mb-8 justify-center">
                <div class="grid place-items-center w-9 h-9 rounded-lg bg-[var(--color-brand)] text-white"><?= icon('leaf', 18) ?></div>
                <span class="font-display text-lg font-semibold text-[var(--color-ink)]">WorkNest</span>
            </div>
            <h1 class="font-display text-2xl font-semibold text-[var(--color-ink)]">Welcome back</h1>
            <p class="mt-1.5 text-sm text-[var(--color-muted)]">Sign in with the account your admin set up for you.</p>

            <div class="mt-7 card p-6">
                <form method="post" action="<?= e(url('/login')) ?>" class="space-y-4" data-pending-form>
                    <?= form_fields('login', 'login') ?>
                    <input type="hidden" name="next" value="<?= e($next) ?>">
                    <div>
                        <label class="field-label" for="email">Work email</label>
                        <input id="email" name="email" type="email" required autofocus autocomplete="username"
                               placeholder="you@company.com" class="input" value="<?= e(old('email')) ?>">
                    </div>
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="field-label" for="password">Password</label>
                            <a href="<?= e(url('/forgot-password')) ?>" class="text-xs text-brand font-medium hover:underline mb-1.5">Forgot password?</a>
                        </div>
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               placeholder="••••••••" class="input">
                    </div>
                    <?= flash_alerts('login') ?>
                    <button type="submit" class="btn btn-primary w-full justify-center py-2.5 disabled:opacity-70"
                            data-pending-label="Signing in…">
                        Sign in <?= icon('arrow-right', 16) ?>
                    </button>
                </form>
            </div>

            <p class="mt-5 text-center text-xs text-[var(--color-muted)]">
                Trouble signing in? Contact your HR admin.
                <?php if ($demoAvailable): ?>
                    See <a href="<?= e(url('/login/demo')) ?>" class="text-[var(--color-brand)] font-medium underline underline-offset-2">demo credentials</a>.
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>
<?php document_close();
