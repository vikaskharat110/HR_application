<?php
/**
 * Display formatting — port of lib/format.ts (date-fns + Intl) without the
 * intl extension, so it works on any shared host.
 */
declare(strict_types=1);

const DASH = '—';

function to_dt(DateTimeInterface|string|null $d): ?DateTimeImmutable
{
    if ($d === null || $d === '') {
        return null;
    }
    return $d instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($d)->setTimezone(app_tz()) : dt($d);
}

/** "dd MMM yyyy" → 05 Oct 2026 */
function format_date(DateTimeInterface|string|null $d): string
{
    return ($x = to_dt($d)) ? $x->format('d M Y') : DASH;
}

/** "hh:mm a" → 09:35 AM */
function format_time(DateTimeInterface|string|null $d): string
{
    return ($x = to_dt($d)) ? $x->format('h:i A') : DASH;
}

/** "dd MMM yyyy, hh:mm a" */
function format_datetime(DateTimeInterface|string|null $d): string
{
    return ($x = to_dt($d)) ? $x->format('d M Y, h:i A') : DASH;
}

/** en-IN short day+month, e.g. "05 Oct" (toLocaleDateString en-IN {day:'2-digit',month:'short'}) */
function format_day_month(DateTimeInterface|string|null $d): string
{
    return ($x = to_dt($d)) ? $x->format('d M') : DASH;
}

/** en-IN long date "05 October 2026" (used on letters). */
function format_date_long(DateTimeInterface|string|null $d): string
{
    return ($x = to_dt($d)) ? $x->format('d F Y') : DASH;
}

/** Indian digit grouping: 1234567.5 → "12,34,567.5" (toLocaleString("en-IN"), max 3 decimals). */
function format_in(float|int $n, int $maxDecimals = 3, int $minDecimals = 0): string
{
    $neg = $n < 0;
    $n = abs($n);
    $fixed = number_format($n, $maxDecimals, '.', '');
    [$int, $frac] = array_pad(explode('.', $fixed), 2, '');
    $frac = rtrim($frac, '0');
    if (strlen($frac) < $minDecimals) {
        $frac = str_pad($frac, $minDecimals, '0');
    }
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $int . ($frac !== '' ? '.' . $frac : '');
}

/** Intl INR currency: "₹1,23,456.00". */
function format_currency(float|int $n): string
{
    $s = format_in($n, 2, 2);
    return str_starts_with($s, '-') ? '-₹' . substr($s, 1) : '₹' . $s;
}

/** "₹1,23,456" (the "₹" + toLocaleString("en-IN") pattern used inline in the original). */
function rupees(float|int $n): string
{
    return '₹' . format_in($n);
}

function month_name(int $month): string
{
    return DateTimeImmutable::createFromFormat('!n', (string)$month)->format('F');
}

/** Enum → readable lower-case label ("NOTICE_PERIOD" → "notice period"). */
function humanize(string $value): string
{
    return strtolower(str_replace('_', ' ', $value));
}

/** JS Math.round semantics (half rounds toward +∞), used by payroll to stay byte-identical. */
function js_round(float $n): float
{
    return floor($n + 0.5);
}

/** Number rendering like JS String(n): 12 → "12", 1.5 → "1.5". */
function js_num(float|int|null $n): string
{
    if ($n === null) {
        return '';
    }
    if (is_int($n) || floor((float)$n) === (float)$n) {
        return (string)(int)$n;
    }
    return rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
}
