<?php
/** Suspended/archived company screen (from app/dashboard/layout.tsx). @var string $status */
document_open($status === 'SUSPENDED' ? 'Access suspended' : 'Workspace archived');
?>
<div class="min-h-screen grid place-items-center bg-[var(--color-surface)] px-6">
    <div class="card max-w-md w-full p-8 text-center">
        <div class="grid place-items-center w-12 h-12 rounded-full bg-danger-soft text-danger mx-auto mb-4">
            <?= icon('shield-alert', 22) ?>
        </div>
        <h1 class="font-display text-xl font-semibold text-ink">
            <?= $status === 'SUSPENDED' ? 'Access suspended' : 'Workspace archived' ?>
        </h1>
        <p class="text-sm text-muted mt-2">
            <?= $status === 'SUSPENDED'
                ? 'Your company’s access to WorkNest has been suspended by the platform administrator.'
                : 'Your company’s workspace has been archived and is no longer accessible.' ?>
        </p>
        <form method="post" action="<?= e(url('/logout')) ?>" class="mt-6">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary w-full justify-center">Sign out</button>
        </form>
    </div>
</div>
<?php document_close();
