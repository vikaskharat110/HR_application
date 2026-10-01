<?php
/**
 * Page chrome. document_open/close = the original root layout (fonts, CSS,
 * <body class="min-h-full flex flex-col">). app_layout_open/close = the
 * original app/dashboard/layout.tsx (sidebar, mobile menu, notification bell,
 * user menu) and is added in Phase 5.
 */
declare(strict_types=1);

function document_open(string $title, string $bodyClass = 'min-h-full flex flex-col'): void
{
    $fullTitle = $title === '' ? 'WorkNest' : $title . ' · WorkNest';
    echo '<!doctype html><html lang="en" class="h-full antialiased"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="description" content="People operations platform — attendance, leave, payroll, talent, and more.">'
        . '<meta name="csrf-token" content="' . e(csrf_token()) . '">'
        . '<title>' . e($fullTitle) . '</title>'
        . '<link rel="icon" href="' . e(url('/assets/img/favicon.ico')) . '" sizes="any">'
        . '<link rel="icon" href="' . e(url('/assets/img/favicon-32.png')) . '" type="image/png" sizes="32x32">'
        . '<link rel="apple-touch-icon" href="' . e(url('/assets/img/apple-touch-icon.png')) . '">'
        . '<link rel="preload" href="' . e(url('/assets/fonts/manrope-latin-wght-normal.woff2')) . '" as="font" type="font/woff2" crossorigin>'
        . '<link rel="stylesheet" href="' . e(asset('css/app.css')) . '">'
        . '<script src="' . e(asset('js/app.js')) . '" defer></script>'
        . '</head><body class="' . e($bodyClass) . '">';
}

function document_close(): void
{
    echo '</body></html>';
}

/** Sidebar/mobile nav link list for the current user (role ceiling minus hidden modules). */
function layout_nav_items(array $auth): array
{
    $hidden = hidden_hrefs($auth);
    return array_values(array_filter(nav_for_role($auth['role']), static fn($i) => !in_array($i['href'], $hidden, true)));
}

function nav_is_active(string $href, string $path): bool
{
    return $href === '/dashboard' ? $path === '/dashboard' : ($path === $href || str_starts_with($path, $href . '/'));
}

/**
 * Opens the authenticated dashboard chrome (port of app/dashboard/layout.tsx):
 * sidebar, sticky header with mobile menu, notification bell (latest 15)
 * and user menu, then the <main> container. Unscoped flash messages render
 * at the top of <main>.
 */
function app_layout_open(array $auth, string $title): void
{
    $path = request_path();
    $items = layout_nav_items($auth);
    $companyName = $auth['companyName'] ?? 'Platform-wide';
    $notifications = db_all('SELECT id, title, message, link, isRead, createdAt FROM Notification WHERE userId = ? ORDER BY createdAt DESC LIMIT 15', [$auth['sub']]);
    $unread = count(array_filter($notifications, static fn($n) => !(int)$n['isRead']));
    [$first, $last] = array_pad(explode(' ', $auth['name'], 2), 2, '');

    document_open($title);
    $navLinks = static function (string $padding) use ($items, $path): string {
        $h = '';
        foreach ($items as $item) {
            $active = nav_is_active($item['href'], $path);
            $h .= '<a href="' . e(url($item['href'])) . '" class="flex items-center gap-3 rounded-xl px-3 ' . $padding . ' text-[13.5px] font-medium transition-all '
                . ($active ? 'nav-active text-white' : 'text-indigo-100/65 hover:bg-white/[0.06] hover:text-white') . '"'
                . ($active ? ' aria-current="page"' : '') . '>' . icon($item['icon'], 17) . e($item['label']) . '</a>';
        }
        return $h;
    };
    ?>
<div class="flex min-h-screen bg-[var(--color-surface)]">
    <aside class="hidden lg:flex w-60 shrink-0 flex-col app-sidebar text-white sticky top-0 h-screen">
        <div class="flex items-center gap-2.5 px-5 py-5 border-b border-white/[0.08]">
            <?= brand_mark(34) ?>
            <div class="leading-tight">
                <p class="font-display font-extrabold text-[16px] text-white tracking-tight">WorkNest</p>
                <p class="text-[11px] text-indigo-200/60 truncate max-w-[10.5rem]"><?= e($companyName) ?></p>
            </div>
        </div>
        <nav class="flex-1 min-h-0 overflow-y-auto no-scrollbar py-4 px-3 space-y-0.5"><?= $navLinks('py-2') ?></nav>
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
        <header class="flex items-center justify-between gap-3 px-4 sm:px-6 h-16 border-b border-[var(--color-border)]/70 bg-white/75 backdrop-blur-md sticky top-0 z-40">
            <div class="flex items-center gap-2">
                <div class="lg:hidden">
                    <button type="button" data-mobile-open class="grid place-items-center w-9 h-9 rounded-lg hover:bg-[var(--color-brand-softer)]" aria-label="Open menu"><?= icon('menu', 19) ?></button>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="relative" data-dropdown data-read-url="<?= e(url('/api/notifications/read')) ?>">
                    <button type="button" data-dropdown-toggle class="relative grid place-items-center w-9 h-9 rounded-full hover:bg-[var(--color-surface)]" aria-label="Notifications">
                        <?= icon('bell', 18) ?>
                        <?php if ($unread > 0): ?>
                            <span data-unread-badge class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 rounded-full bg-red-600 text-white text-[10px] leading-4 text-center"><?= $unread > 9 ? '9+' : $unread ?></span>
                        <?php endif; ?>
                    </button>
                    <div data-dropdown-menu hidden class="absolute right-0 mt-2 w-80 max-w-[90vw] card z-50 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-2.5 border-b border-[var(--color-border)]">
                            <p class="text-sm font-semibold">Notifications</p>
                            <?php if ($unread > 0): ?>
                                <form method="post" action="<?= e(url('/api/notifications/read-all')) ?>" data-mark-all-read>
                                    <?= csrf_field() ?><input type="hidden" name="_back" value="<?= e(current_url()) ?>">
                                    <button type="submit" class="text-xs text-[var(--color-brand)] hover:underline">Mark all read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <ul class="max-h-96 overflow-y-auto">
                            <?php if (!$notifications): ?>
                                <li class="px-4 py-6 text-sm text-center text-[var(--color-muted)]">You’re all caught up.</li>
                            <?php endif; ?>
                            <?php foreach ($notifications as $n):
                                $read = (int)$n['isRead'];
                                $body = '<p class="text-sm ' . ($read ? '' : 'font-semibold') . '">' . e($n['title']) . '</p>'
                                    . '<p class="text-xs text-[var(--color-muted)]">' . e($n['message']) . '</p>'
                                    . '<p class="text-[11px] text-[var(--color-muted)] mt-0.5">' . e(format_datetime($n['createdAt'])) . '</p>'; ?>
                                <li class="border-b last:border-0 border-[var(--color-border)]">
                                    <?php if ($n['link']): ?>
                                        <a href="<?= e(url(safe_next($n['link'], '/dashboard'))) ?>" data-notification-id="<?= e($n['id']) ?>" data-read="<?= $read ?>" class="block px-4 py-2.5 hover:bg-[var(--color-surface)]"><?= $body ?></a>
                                    <?php else: ?>
                                        <button type="button" data-notification-id="<?= e($n['id']) ?>" data-read="<?= $read ?>" class="block w-full text-left px-4 py-2.5 hover:bg-[var(--color-surface)]"><?= $body ?></button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <div class="w-px h-6 bg-[var(--color-border)] mx-1"></div>
                <div class="relative" data-dropdown>
                    <button type="button" data-dropdown-toggle class="flex items-center gap-2 rounded-lg pl-1.5 pr-2 py-1.5 hover:bg-[var(--color-brand-softer)]">
                        <span class="grid place-items-center w-8 h-8 rounded-full brand-gradient text-white text-[11px] font-bold ring-2 ring-indigo-100"><?= e(initials($first, $last)) ?></span>
                        <span class="hidden sm:block text-left">
                            <span class="block text-sm font-medium text-[var(--color-ink)] leading-tight"><?= e($auth['name']) ?></span>
                            <span class="block text-[11px] text-[var(--color-muted)] leading-tight"><?= e(role_label($auth)) ?></span>
                        </span>
                        <?= icon('chevron-down', 14, 'text-[var(--color-muted)]') ?>
                    </button>
                    <div data-dropdown-menu hidden class="absolute right-0 mt-2 w-52 card shadow-lg py-1.5 z-50">
                        <div class="px-3.5 py-2 border-b border-[var(--color-border-soft)]">
                            <p class="text-sm font-medium text-[var(--color-ink)] flex items-center gap-1.5"><?= icon('user', 13) ?> <?= e($auth['name']) ?></p>
                            <p class="text-xs text-[var(--color-muted)] mt-0.5 truncate"><?= e($auth['email']) ?></p>
                        </div>
                        <a href="<?= e(url('/dashboard/profile')) ?>" class="flex items-center gap-2 px-3.5 py-2 text-sm text-[var(--color-ink-soft)] hover:bg-[var(--color-brand-softer)]"><?= icon('user', 15) ?> My profile</a>
                        <form method="post" action="<?= e(url('/logout')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="w-full flex items-center gap-2 px-3.5 py-2 text-sm text-[var(--color-danger)] hover:bg-[var(--color-danger-soft)]"><?= icon('log-out', 15) ?> Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6 lg:p-8 max-w-[1400px] w-full mx-auto">
            <?= flash_alerts() ?>
    <?php
    // Mobile drawer (MobileMenu) — rendered once, toggled by app.js.
    $GLOBALS['__mobile_menu'] = '<div id="mobile-menu" hidden class="fixed inset-0 z-50 flex lg:hidden">'
        . '<div class="w-72 h-full app-sidebar text-white flex flex-col">'
        . '<div class="flex items-center justify-between px-5 py-5 border-b border-white/10"><div class="flex items-center gap-2">'
        . brand_mark(34)
        . '<div><p class="font-display font-extrabold text-[16px] tracking-tight">WorkNest</p><p class="text-[11px] text-indigo-200/60">' . e($companyName) . '</p></div></div>'
        . '<button type="button" data-mobile-close class="text-white/60 hover:text-white" aria-label="Close menu">' . icon('x', 20) . '</button></div>'
        . '<nav class="flex-1 min-h-0 overflow-y-auto py-4 px-3 space-y-0.5">' . $navLinks('py-2.5') . '</nav></div>'
        . '<button type="button" data-mobile-close class="flex-1 bg-black/30" aria-label="Close menu overlay"></button></div>';
}

function app_layout_close(): void
{
    echo '</main></div></div>' . ($GLOBALS['__mobile_menu'] ?? '');
    document_close();
}
