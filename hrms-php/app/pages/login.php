<?php
/** /login — port of app/login/page.tsx + components/LoginForm.tsx */
declare(strict_types=1);

handle_post([
    'login' => fn() => action_login(post('email'), post_raw('password'), post('next')),
]);

$next = safe_next(query('next'), '/dashboard');

document_open('Sign in');
?>
<div class="min-h-screen flex bg-[var(--color-surface)]">
    <div class="hidden lg:flex lg:w-[48%] relative flex-col justify-between auth-hero text-white px-12 py-12 overflow-hidden">
        <div class="pointer-events-none absolute inset-0 opacity-[0.10] login-dots"></div>
        <div class="pointer-events-none absolute -top-24 -right-24 w-80 h-80 rounded-full bg-fuchsia-400/30 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-20 w-96 h-96 rounded-full bg-sky-400/20 blur-3xl"></div>
        <div class="relative flex items-center gap-3">
            <?= brand_mark(40) ?>
            <span class="font-display text-xl font-extrabold tracking-tight">WorkNest</span>
        </div>
        <div class="relative max-w-lg">
            <span class="inline-flex items-center gap-2 rounded-full glass px-3 py-1 text-xs font-semibold text-indigo-50"><span class="w-1.5 h-1.5 rounded-full bg-emerald-300 animate-pulse"></span> All-in-one HR workspace</span>
            <p class="mt-5 font-display text-[2.6rem] font-extrabold leading-[1.1] tracking-tight">Where your people <span class="bg-linear-to-r from-amber-200 via-pink-200 to-sky-200 bg-clip-text text-transparent">settle in</span> and grow.</p>
            <p class="mt-5 text-indigo-100/80 text-[15px] leading-relaxed">
                Attendance, leave, payroll, hiring, performance and approvals in one
                secure, multi-company workspace — from offer letter to final settlement.
            </p>
            <div class="mt-9 grid grid-cols-1 gap-3 text-sm">
                <div class="glass rounded-2xl px-4 py-3 flex items-center gap-3"><span class="grid place-items-center w-8 h-8 rounded-lg bg-white/15"><?= icon('shield-check', 16) ?></span> Role-based access with a full audit trail</div>
                <div class="glass rounded-2xl px-4 py-3 flex items-center gap-3"><span class="grid place-items-center w-8 h-8 rounded-lg bg-white/15"><?= icon('users-round', 16) ?></span> Multi-company, multi-branch from day one</div>
                <div class="glass rounded-2xl px-4 py-3 flex items-center gap-3"><span class="grid place-items-center w-8 h-8 rounded-lg bg-white/15"><?= icon('calendar-clock', 16) ?></span> Approvals that route themselves</div>
            </div>
        </div>
        <p class="relative text-xs text-indigo-100/50">© <?= e(now()->format('Y')) ?> WorkNest. Internal HR platform.</p>
    </div>

    <div class="flex-1 flex items-center justify-center px-6 py-16">
        <div class="w-full max-w-sm">
            <?= brand_lockup('flex items-center mb-9 justify-center lg:justify-start', 56) ?>
            <h1 class="font-display text-3xl font-extrabold text-[var(--color-ink)]">Welcome back <span class="inline-block">👋</span></h1>
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
                Trouble signing in? Contact <a href="mailto:<?= e(config('app.support_email')) ?>" class="text-[var(--color-brand)] font-medium hover:underline"><?= e(config('app.support_email')) ?></a>.
            </p>
        </div>
    </div>
</div>
<?php document_close();
