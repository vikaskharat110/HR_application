<?php
/**
 * Primary keys. Existing rows use Prisma CUIDs ("c" + 24 lowercase base-36
 * chars); new rows get the same shape so both coexist in VARCHAR(191)
 * columns, stay roughly time-ordered, and remain unguessable in URLs.
 */
declare(strict_types=1);

function new_id(): string
{
    $time = str_pad(base_convert((string)(int)(microtime(true) * 1000), 10, 36), 8, '0', STR_PAD_LEFT);
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    $random = '';
    foreach (str_split(random_bytes(16)) as $byte) {
        $random .= $alphabet[ord($byte) % 36];
    }
    return 'c' . substr($time, -8) . $random;
}

/** Shape check applied to every id arriving from a URL or form before it reaches SQL. */
function is_valid_id(mixed $value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{6,64}$/', $value) === 1;
}
