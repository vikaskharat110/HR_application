<?php
/**
 * Presentation helpers. e() is the single escaping function (A3): every
 * dynamic value printed into HTML goes through it — user/company/document
 * data, comments, ticket text, letter merge fields — so stored input can
 * never become markup or script.
 */
declare(strict_types=1);

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Escaped text with newlines → <br> (for multi-line user text). */
function e_nl(mixed $value): string
{
    return nl2br(e($value), false);
}

/** JSON for a <script type="application/json"> data island (never executed; CSP-safe). */
function json_island(string $id, mixed $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script type="application/json" id="' . e($id) . '">' . $json . '</script>';
}

/** Inline SVG icon from the local Lucide sprite. */
function icon(string $name, int $size = 16, string $class = '', array $attrs = []): string
{
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . e($k) . '="' . e($v) . '"';
    }
    return '<svg class="lucide ' . e($class) . '" width="' . $size . '" height="' . $size . '" aria-hidden="true"' . $extra
        . '><use href="' . e(url('/assets/icons/lucide.svg')) . '#' . e($name) . '"></use></svg>';
}

/** Renders a template from app/views and returns the HTML. */
function view(string $template, array $vars = []): string
{
    $file = VIEWS_PATH . '/' . $template . '.php';
    if (!preg_match('#^[a-z0-9_/-]+$#', $template) || !is_file($file)) {
        throw new LogicException("View not found: $template");
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    try {
        require $file;
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/** "a b" class-name joiner that drops empty parts (replaces clsx). */
function cx(string|false|null ...$parts): string
{
    return implode(' ', array_filter($parts, static fn($p) => is_string($p) && $p !== ''));
}

/** ' selected' / ' checked' helpers for form controls. */
function selected(mixed $a, mixed $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(bool $on): string
{
    return $on ? ' checked' : '';
}
