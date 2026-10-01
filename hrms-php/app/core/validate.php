<?php
/**
 * Small input-validation helpers used by the actions. Each throws UserError
 * with a message that is safe to show next to the form.
 */
declare(strict_types=1);

/** Required trimmed string, max length (column size). */
function v_required(string $value, string $label, int $max = 191): string
{
    if ($value === '') {
        throw new UserError("$label is required.");
    }
    return v_max($value, $label, $max);
}

function v_max(string $value, string $label, int $max = 191): string
{
    if (mb_strlen($value) > $max) {
        throw new UserError("$label must be at most $max characters.");
    }
    return $value;
}

/** Optional string → null when empty. */
function v_optional(string $value, string $label, int $max = 191): ?string
{
    return $value === '' ? null : v_max($value, $label, $max);
}

function v_email(string $value, string $label = 'Email'): string
{
    $value = strtolower(trim($value));
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 191) {
        throw new UserError("Enter a valid $label address.");
    }
    return $value;
}

/** One of an allowed set (ENUM columns). */
function v_enum(string $value, array $allowed, string $label): string
{
    if (!in_array($value, $allowed, true)) {
        throw new UserError("Choose a valid $label.");
    }
    return $value;
}

function v_date(string $value, string $label, bool $required = true): ?DateTimeImmutable
{
    if ($value === '' && !$required) {
        return null;
    }
    $d = parse_date_input($value);
    if ($d === null) {
        throw new UserError("Enter a valid $label (YYYY-MM-DD).");
    }
    return $d;
}

/** Finite number, optional bounds. */
function v_number(string $value, string $label, ?float $min = null, ?float $max = null): float
{
    if ($value === '' || !is_numeric($value)) {
        throw new UserError("$label must be a number.");
    }
    $n = (float)$value;
    if (!is_finite($n) || ($min !== null && $n < $min) || ($max !== null && $n > $max)) {
        throw new UserError("$label is out of range.");
    }
    return $n;
}

function v_int(string $value, string $label, ?int $min = null, ?int $max = null): int
{
    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        throw new UserError("$label must be a whole number.");
    }
    $n = (int)$value;
    if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
        throw new UserError("$label is out of range.");
    }
    return $n;
}

/** Id from a form field (optional → null). */
function v_id(string $value, string $label = 'record', bool $required = true): ?string
{
    if ($value === '' && !$required) {
        return null;
    }
    if (!is_valid_id($value)) {
        throw new UserError("Choose a valid $label.");
    }
    return $value;
}
