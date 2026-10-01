<?php
/**
 * Minimal .env loader (KEY=VALUE per line, # comments, optional quotes).
 * Values already present in the real environment win, so hosting-panel
 * environment variables can override the file.
 */
declare(strict_types=1);

function env_load(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }
        $quoted = strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0];
        if ($quoted) {
            $doubleQuoted = $value[0] === '"';
            $value = substr($value, 1, -1);
            if ($doubleQuoted) {
                $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
            }
        } else {
            $hash = strpos($value, ' #');
            if ($hash !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }
        }
        if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return match (strtolower((string)$value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $value,
    };
}
