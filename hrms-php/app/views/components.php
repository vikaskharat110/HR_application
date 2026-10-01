<?php
/**
 * Shared UI components — ports of components/ui.tsx and the small client
 * components (BackLink, TabbedPanels, ConfirmSubmitButton, AutoSubmitSelect).
 * Same Tailwind classes as the original so the compiled app.css matches.
 * Behaviour is attached by assets/js/app.js through data-* attributes.
 * Every dynamic value is escaped with e().
 */
declare(strict_types=1);

function page_header(string $title, ?string $subtitle = null, string $actionHtml = ''): string
{
    $h = '<div class="flex flex-wrap items-start justify-between gap-3 mb-6"><div>'
        . '<h1 class="font-display text-2xl font-semibold text-[var(--color-ink)]">' . e($title) . '</h1>';
    if ($subtitle !== null && $subtitle !== '') {
        $h .= '<p class="text-sm text-[var(--color-muted)] mt-1">' . e($subtitle) . '</p>';
    }
    $h .= '</div>';
    if ($actionHtml !== '') {
        $h .= '<div class="flex items-center gap-2">' . $actionHtml . '</div>';
    }
    return $h . '</div>';
}

function stat_card(string $label, string|int|float $value, string $icon, string $tone = 'brand', ?string $hint = null): string
{
    $toneMap = [
        'brand' => 'bg-[var(--color-brand-soft)] text-[var(--color-brand)]',
        'accent' => 'bg-[var(--color-accent-soft)] text-[var(--color-accent-dark)]',
        'info' => 'bg-[var(--color-info-soft)] text-[var(--color-info)]',
        'warning' => 'bg-[var(--color-warning-soft)] text-[var(--color-warning)]',
        'danger' => 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]',
    ];
    return '<div class="stat-card flex items-start justify-between"><div>'
        . '<p class="text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]">' . e($label) . '</p>'
        . '<p class="font-display text-2xl font-semibold text-[var(--color-ink)] mt-1.5">' . e($value) . '</p>'
        . ($hint !== null ? '<p class="text-xs text-[var(--color-muted)] mt-1">' . e($hint) . '</p>' : '')
        . '</div><div class="grid place-items-center w-10 h-10 rounded-lg shrink-0 ' . ($toneMap[$tone] ?? $toneMap['brand']) . '">'
        . icon($icon, 18) . '</div></div>';
}

function empty_state(string $icon, string $title, ?string $message = null): string
{
    return '<div class="flex flex-col items-center justify-center text-center py-14 px-6">'
        . '<div class="grid place-items-center w-12 h-12 rounded-full bg-[var(--color-brand-softer)] text-[var(--color-brand)] mb-3">' . icon($icon, 20) . '</div>'
        . '<p class="text-sm font-semibold text-[var(--color-ink)]">' . e($title) . '</p>'
        . ($message !== null ? '<p class="text-sm text-[var(--color-muted)] mt-1 max-w-sm">' . e($message) . '</p>' : '')
        . '</div>';
}

const STATUS_VARIANTS = [
    'APPROVED' => 'badge-success', 'PAID' => 'badge-success', 'PROCESSED' => 'badge-success', 'PRESENT' => 'badge-success',
    'RESOLVED' => 'badge-success', 'CLOSED' => 'badge-neutral', 'HIRED' => 'badge-success', 'SELECTED' => 'badge-success',
    'OFFERED' => 'badge-info', 'PENDING' => 'badge-warning', 'LATE' => 'badge-warning', 'IN_PROGRESS' => 'badge-info',
    'SCREENING' => 'badge-info', 'INTERVIEW' => 'badge-info', 'APPLIED' => 'badge-neutral', 'DRAFT' => 'badge-neutral',
    'ON_LEAVE' => 'badge-info', 'HALF_DAY' => 'badge-warning', 'WEEK_OFF' => 'badge-neutral', 'HOLIDAY' => 'badge-neutral',
    'REJECTED' => 'badge-danger', 'ABSENT' => 'badge-danger', 'CANCELLED' => 'badge-neutral', 'OPEN' => 'badge-warning',
    'AVAILABLE' => 'badge-success', 'ASSIGNED' => 'badge-info', 'MAINTENANCE' => 'badge-warning', 'RETIRED' => 'badge-neutral',
    'ACTIVE' => 'badge-success', 'SUSPENDED' => 'badge-danger', 'ARCHIVED' => 'badge-neutral', 'ON_HOLD' => 'badge-warning',
    'UPCOMING' => 'badge-info', 'NOTICE_PERIOD' => 'badge-warning', 'EXITED' => 'badge-neutral', 'URGENT' => 'badge-danger',
    'HIGH' => 'badge-warning', 'MEDIUM' => 'badge-info', 'LOW' => 'badge-neutral', 'LOCKED' => 'badge-success',
    'DONE' => 'badge-success', 'PENDING_CLEARANCE' => 'badge-warning', 'CLEARED' => 'badge-success',
    'MANAGER_APPROVED' => 'badge-info', 'HR_APPROVED' => 'badge-info', 'CLEARANCE' => 'badge-warning',
    'SETTLED' => 'badge-success', 'COMPLETED' => 'badge-success', 'CONFIRMED' => 'badge-success', 'EXTENDED' => 'badge-warning',
];

function status_badge(string $status): string
{
    $variant = STATUS_VARIANTS[$status] ?? 'badge-neutral';
    return '<span class="badge ' . $variant . '">' . e(strtolower(str_replace('_', ' ', $status))) . '</span>';
}

/** Initials avatar; the colour is validated so a stored value can't inject CSS. */
function avatar(?string $firstName, ?string $lastName, ?string $color = null, string $size = 'w-8 h-8 text-[11px]'): string
{
    $bg = safe_color($color) ?? 'var(--color-brand)';
    return '<span class="grid place-items-center ' . $size . ' rounded-full text-white font-semibold shrink-0" style="background: ' . e($bg) . '">'
        . e(initials((string)$firstName, (string)$lastName)) . '</span>';
}

function safe_color(?string $c): ?string
{
    return ($c !== null && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) ? $c : null;
}

function initials(string $first, string $last = ''): string
{
    return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}

/** Prev/Next pager (original Pagination component). */
function pagination(int $page, int $totalPages, string $basePath, array $query = []): string
{
    if ($totalPages <= 1) {
        return '';
    }
    $href = static function (int $p) use ($basePath, $query): string {
        $params = array_filter($query, static fn($v) => $v !== null && $v !== '');
        $params['page'] = (string)$p;
        return url($basePath) . '?' . http_build_query($params);
    };
    $prevOff = $page <= 1 ? ' pointer-events-none opacity-40' : '';
    $nextOff = $page >= $totalPages ? ' pointer-events-none opacity-40' : '';
    return '<div class="flex items-center justify-between mt-4 text-sm">'
        . '<a href="' . e($href($page - 1)) . '" aria-disabled="' . ($page <= 1 ? 'true' : 'false') . '" class="btn btn-secondary' . $prevOff . '">' . icon('chevron-left', 14) . ' Previous</a>'
        . '<span class="text-muted">Page ' . $page . ' of ' . $totalPages . '</span>'
        . '<a href="' . e($href($page + 1)) . '" aria-disabled="' . ($page >= $totalPages ? 'true' : 'false') . '" class="btn btn-secondary' . $nextOff . '">Next ' . icon('chevron-right', 14) . '</a>'
        . '</div>';
}

/** "Back to X" — uses browser history when available, else the fallback URL. */
function back_link(string $fallbackHref, string $label): string
{
    return '<a href="' . e(url($fallbackHref)) . '" data-back-link class="inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink mb-4">'
        . icon('arrow-left', 15) . ' ' . e($label) . '</a>';
}

/**
 * Tabs (TabbedPanels). $tabs = [['key'=>..,'label'=>..,'content'=>html], ...].
 * Works without JS (all panels visible); JS shows one at a time and remembers the tab in the URL hash.
 */
function tabbed_panels(array $tabs, string $id = 'tabs'): string
{
    if ($tabs === []) {
        return '';
    }
    $h = '<div data-tabs id="' . e($id) . '"><div class="inline-flex flex-wrap rounded-lg border border-border bg-card p-1 mb-4" role="tablist">';
    foreach ($tabs as $i => $t) {
        $cls = $i === 0 ? 'bg-brand text-white' : 'text-ink-soft hover:text-ink';
        $h .= '<button type="button" role="tab" data-tab="' . e($t['key']) . '" class="px-3.5 py-1.5 text-sm font-medium rounded-md transition-colors ' . $cls . '">' . e($t['label']) . '</button>';
    }
    $h .= '</div>';
    foreach ($tabs as $i => $t) {
        $h .= '<div data-tab-panel="' . e($t['key']) . '"' . ($i === 0 ? '' : ' hidden') . '>' . $t['content'] . '</div>';
    }
    return $h . '</div>';
}

/** Hidden fields every mutating form needs: CSRF token, action name, optional form scope. */
function form_fields(string $action, string $scope = ''): string
{
    return csrf_field()
        . '<input type="hidden" name="_action" value="' . e($action) . '">'
        . ($scope !== '' ? '<input type="hidden" name="_form" value="' . e($scope) . '">' : '');
}

/** A submit button that asks for confirmation first (ConfirmSubmitButton). */
function confirm_button(string $confirmMessage, string $innerHtml, string $class = '', string $title = '', bool $disabled = false): string
{
    return '<button type="submit" data-confirm="' . e($confirmMessage) . '" class="' . e($class) . '"'
        . ($title !== '' ? ' title="' . e($title) . '"' : '') . ($disabled ? ' disabled' : '') . '>' . $innerHtml . '</button>';
}

/** <select> that submits its form on change (AutoSubmitSelect). */
function auto_submit_select(string $name, string $current, array $options, string $class = 'input !w-auto !py-1 text-xs'): string
{
    $h = '<select name="' . e($name) . '" data-autosubmit class="' . e($class) . '">';
    foreach ($options as $o) {
        $h .= '<option value="' . e($o) . '"' . selected($o, $current) . '>' . e($o) . '</option>';
    }
    return $h . '</select>';
}

/** Error/success alerts for a flash scope (inline form feedback). */
function flash_alerts(string $scope = ''): string
{
    $h = '';
    foreach (flashes($scope) as $f) {
        $h .= alert($f['type'], $f['message']);
    }
    return $h;
}

function alert(string $type, string $message): string
{
    if ($type === 'success') {
        return '<div class="flex items-start gap-2 rounded-lg bg-[var(--color-success-soft)] text-[var(--color-success)] text-sm px-3 py-2.5 mb-4" role="status">'
            . icon('circle-check', 16, 'mt-0.5 shrink-0') . '<span>' . e($message) . '</span></div>';
    }
    return '<div class="flex items-start gap-2 rounded-lg bg-[var(--color-danger-soft)] text-[var(--color-danger)] text-sm px-3 py-2.5 mb-4" role="alert">'
        . icon('circle-alert', 16, 'mt-0.5 shrink-0') . '<span>' . e($message) . '</span></div>';
}

/** One-time display of a generated temporary password to the admin who created the account. */
function temp_password_notice(): string
{
    $t = take_temp_password();
    if ($t === null) {
        return '';
    }
    return '<div class="card p-4 mb-5 border-[var(--color-warning)] bg-[var(--color-warning-soft)]" role="alert">'
        . '<p class="text-sm font-semibold text-ink flex items-center gap-2">' . icon('key-round', 15) . ' Temporary password for ' . e($t['email']) . '</p>'
        . '<p class="mt-2 font-mono text-base text-ink select-all" data-temp-password>' . e($t['password']) . '</p>'
        . '<p class="text-xs text-ink-soft mt-2">Shown only once — copy it now and share it securely. The user should change it after first sign-in (My profile → Change password). It is not stored anywhere in plain text.</p>'
        . '</div>';
}

/** Leaf logo + wordmark used on the auth screens. */
function brand_lockup(string $wrapperClass = 'flex items-center gap-2.5 mb-8 justify-center'): string
{
    return '<div class="' . e($wrapperClass) . '"><div class="grid place-items-center w-9 h-9 rounded-lg bg-[var(--color-brand)] text-white">'
        . icon('leaf', 18) . '</div><span class="font-display text-lg font-semibold text-[var(--color-ink)]">WorkNest</span></div>';
}

/**
 * Charts (TrendLine / RankBar ports). Data travels in a data-attribute
 * (escaped JSON) and assets/js/charts.js draws it with the local Chart.js.
 */
function chart_trend_line(array $data, string $dataKey, string $xKey, string $unit = ''): string
{
    chart_assets();
    $cfg = ['type' => 'line', 'labels' => array_column($data, $xKey), 'values' => array_column($data, $dataKey), 'unit' => $unit];
    return '<div class="h-56 w-full relative"><canvas data-chart="' . e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '" role="img" aria-label="Trend chart"></canvas></div>';
}

function chart_rank_bar(array $data, string $dataKey, string $xKey, bool $horizontal = false): string
{
    chart_assets();
    $height = $horizontal ? max(180, count($data) * 36) : 224;
    $cfg = ['type' => 'bar', 'horizontal' => $horizontal, 'labels' => array_column($data, $xKey), 'values' => array_column($data, $dataKey)];
    return '<div class="w-full relative" style="height: ' . (int)$height . 'px"><canvas data-chart="' . e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '" role="img" aria-label="Bar chart"></canvas></div>';
}

/** Emits the Chart.js + charts.js tags once per page, only on pages that draw charts. */
function chart_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<script src="' . e(asset('vendor/chart.umd.min.js')) . '" defer></script><script src="' . e(asset('js/charts.js')) . '" defer></script>';
}

/**
 * One document line (DocumentRow): name, dates, protected download via
 * /files/{id}, and — for Admin/HR or the document's own employee — an edit
 * panel (rename, type, expiry, replace file) and delete.
 */
function document_row(array $d, array $auth, string $back): string
{
    $isAdminOrHr = is_company_admin($auth);
    $canManage = $isAdminOrHr || ($d['employeeId'] !== null && $d['employeeId'] === $auth['employeeId']);
    $types = ['POLICY' => 'Policy', 'OFFER_LETTER' => 'Offer letter', 'CONTRACT' => 'Contract', 'ID_PROOF' => 'ID proof', 'CERTIFICATE' => 'Certificate', 'OTHER' => 'Other'];
    if (!$isAdminOrHr) {
        $types = array_intersect_key($types, array_flip(SELF_UPLOAD_TYPES));
    } elseif (!isset($types[$d['type']])) {
        $types[$d['type']] = ucfirst(humanize($d['type'])); // e.g. a generated CONFIRMATION_LETTER keeps its type
    }
    $scope = 'doc-' . $d['id'];
    $alerts = flash_alerts($scope);
    $h = '<li class="py-2.5"><div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2.5 min-w-0">'
        . icon('file-text', 16, 'text-brand shrink-0') . '<div class="min-w-0"><p class="text-sm font-medium text-ink truncate">' . e($d['name']) . '</p>'
        . '<p class="text-xs text-muted">' . e(format_date($d['uploadedAt'])) . ($d['expiryDate'] ? ' · expires ' . e(format_date($d['expiryDate'])) : '') . '</p></div></div>'
        . '<div class="flex items-center gap-3 shrink-0">';
    if ($d['fileUrl'] !== '#' && $d['fileUrl'] !== '') {
        $h .= '<a href="' . e(url('/files/' . $d['id'])) . '" target="_blank" rel="noopener" class="text-brand" title="Download">' . icon('download', 16) . '</a>';
    }
    if ($canManage) {
        $opts = '';
        foreach ($types as $v => $l) {
            $opts .= '<option value="' . e($v) . '"' . selected($v, $d['type']) . '>' . e($l) . '</option>';
        }
        $h .= '<details class="relative"' . ($alerts !== '' ? ' open' : '') . '><summary class="text-muted hover:text-ink cursor-pointer list-none" title="Edit">' . icon('pencil', 15) . '</summary>'
            . '<form method="post" enctype="multipart/form-data" class="mt-2 w-64 card p-3 space-y-2 shadow-lg absolute right-0 z-10">'
            . form_fields('doc_update', $scope) . '<input type="hidden" name="documentId" value="' . e($d['id']) . '"><input type="hidden" name="_back" value="' . e($back) . '">'
            . '<input name="name" maxlength="255" value="' . e($d['name']) . '" placeholder="Display name" class="input text-sm">'
            . '<select name="type" class="input text-sm">' . $opts . '</select>'
            . '<div><label class="field-label">Expiry date</label><input type="date" name="expiryDate" value="' . e($d['expiryDate'] ? substr($d['expiryDate'], 0, 10) : '') . '" class="input text-sm"></div>'
            . '<div><label class="field-label">Replace file (optional)</label><input type="file" name="file" class="input text-sm"></div>'
            . $alerts . '<button type="submit" class="btn btn-primary w-full justify-center text-sm">Save changes</button></form></details>'
            . '<form method="post">' . form_fields('doc_delete') . '<input type="hidden" name="documentId" value="' . e($d['id']) . '"><input type="hidden" name="_back" value="' . e($back) . '">'
            . confirm_button('Delete "' . $d['name'] . '"? This can\'t be undone.', icon('trash-2', 15), 'text-danger hover:opacity-70', 'Delete') . '</form>';
    }
    return $h . '</div></div></li>';
}

/** POST handlers shared by every page that lists documents. */
function document_row_handlers(array $auth): array
{
    return [
        'doc_update' => function () use ($auth) {
            action_update_document($auth, post_id('documentId'), uploaded_file('file'), $_POST);
            flash('success', 'Saved.', 'doc-' . post('documentId'));
            return null;
        },
        'doc_delete' => function () use ($auth) {
            action_delete_document($auth, post_id('documentId'));
            return null;
        },
    ];
}
