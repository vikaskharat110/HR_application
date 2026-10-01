<?php
/** @var int $status @var string $title @var string $message @var ?string $ref @var ?string $debug */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · WorkNest</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="min-h-full">
<div class="min-h-screen grid place-items-center bg-[var(--color-surface)] px-6">
    <div class="card max-w-md w-full p-8 text-center">
        <p class="font-display text-4xl font-semibold text-[var(--color-brand)]"><?= (int)$status ?></p>
        <h1 class="font-display text-xl font-semibold text-ink mt-2"><?= e($title) ?></h1>
        <?php if ($message !== $title): ?>
            <p class="text-sm text-muted mt-2"><?= e($message) ?></p>
        <?php endif; ?>
        <?php if (!empty($ref)): ?>
            <p class="text-xs text-muted mt-3">Reference: <span class="font-mono"><?= e($ref) ?></span></p>
        <?php endif; ?>
        <div class="mt-6 flex justify-center gap-2">
            <a href="<?= e(url('/dashboard')) ?>" class="btn btn-primary">Go to dashboard</a>
        </div>
        <?php if (!empty($debug)): ?>
            <pre class="mt-6 text-left text-xs overflow-auto max-h-80 p-3 bg-[var(--color-border-soft)] rounded"><?= e($debug) ?></pre>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
