<?php
/**
 * Date/time helpers. Every date is handled in APP_TIMEZONE (Asia/Kolkata)
 * and stored in MySQL as that zone's wall-clock time (DATETIME(3)); the DB
 * connection's time_zone is set to the same offset so CURRENT_TIMESTAMP
 * defaults agree with PHP.
 *
 * The original JS parsed "YYYY-MM-DD" form inputs as UTC midnight (05:30
 * IST) in some actions and used local midnight in others (attendance). Here
 * every date-only input is local midnight, consistently.
 */
declare(strict_types=1);

const DB_DATETIME = 'Y-m-d H:i:s.v';

function app_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(config('app.timezone'));
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', app_tz());
}

function today_start(): DateTimeImmutable
{
    return now()->setTime(0, 0, 0, 0);
}

/** DateTime → value for a DATETIME(3) column (null passes through). */
function db_dt(?DateTimeInterface $d): ?string
{
    return $d?->setTimezone(app_tz())->format(DB_DATETIME);
}

function db_now(): string
{
    return db_dt(now());
}

/** DATETIME column string → DateTimeImmutable in app time zone (null/'' → null). */
function dt(?string $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }
    return new DateTimeImmutable($value, app_tz());
}

/** Parses a strict "YYYY-MM-DD" form value to local midnight, or null if invalid. */
function parse_date_input(?string $value): ?DateTimeImmutable
{
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, app_tz());
    if ($d === false || $d->format('Y-m-d') !== $value) {
        return null;
    }
    return $d;
}

/** Parses "HH:MM" onto a given day, or null. */
function parse_time_on(DateTimeImmutable $day, ?string $time): ?DateTimeImmutable
{
    $time = trim((string)$time);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
        return null;
    }
    return $day->setTime((int)$m[1], (int)$m[2], 0, 0);
}

function start_of_day(DateTimeInterface $d): DateTimeImmutable
{
    return DateTimeImmutable::createFromInterface($d)->setTimezone(app_tz())->setTime(0, 0, 0, 0);
}

/** Day-of-week, 0 = Sunday … 6 = Saturday (same as JS getDay()). */
function dow(DateTimeInterface $d): int
{
    return (int)$d->format('w');
}

/** JS-style "add months" (Date.setMonth overflow semantics: Jan 31 + 1 month → Mar 3/2). */
function add_months_js(DateTimeImmutable $d, int $months): DateTimeImmutable
{
    $y = (int)$d->format('Y');
    $m = (int)$d->format('n') - 1 + $months;
    $y += intdiv($m, 12);
    $m %= 12;
    if ($m < 0) {
        $m += 12;
        $y -= 1;
    }
    return $d->setDate($y, $m + 1, 1)->modify('+' . ((int)$d->format('j') - 1) . ' days');
}
