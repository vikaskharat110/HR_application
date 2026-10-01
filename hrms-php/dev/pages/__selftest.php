<?php
/**
 * Phase 3 infrastructure self-test. Disabled (404) unless APP_ENV=local.
 *
 */
declare(strict_types=1);

if (config('app.env') !== 'local') {
    throw new HttpError(404);
}

handle_post([
    'ok' => function (): ?string {
        flash('success', 'POST accepted, CSRF verified: ' . post('note'));
        return null;
    },
    'user_error' => function (): never {
        throw new UserError('Validation message shown to the user <b>escaped</b>.');
    },
    'duplicate' => function (): never {
        $slug = 'selftest-dup';
        db_tx(function () use ($slug) {
            db_insert('Company', ['name' => 'Dup A', 'slug' => $slug]);
            db_insert('Company', ['name' => 'Dup B', 'slug' => $slug]); // violates unique → rolled back
        });
        throw new LogicException('unreachable');
    },
    'crash' => function (): never {
        db_value('SELECT * FROM `NoSuchTable`');
        throw new LogicException('unreachable');
    },
]);

$checks = [
    'php_version' => PHP_VERSION,
    'db_version' => db_value('SELECT VERSION()'),
    'db_time_zone' => db_value('SELECT @@session.time_zone'),
    'db_sql_mode_strict' => str_contains((string)db_value('SELECT @@session.sql_mode'), 'STRICT_TRANS_TABLES'),
    'db_now' => db_value('SELECT NOW(3)'),
    'php_now' => db_now(),
    'tables' => (int)db_value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
    'dup_company_rows_after_rollback' => (int)db_value('SELECT COUNT(*) FROM Company WHERE slug = ?', ['selftest-dup']),
    'new_id' => new_id(),
    'new_id_valid' => is_valid_id(new_id()),
    'bad_id_rejected' => !is_valid_id("x' OR 1=1 --"),
    'date_input' => db_dt(parse_date_input('2026-10-05')),
    'date_input_invalid' => parse_date_input('2026-02-30') === null,
    'add_months_js(2026-01-31,+1)' => add_months_js(new DateTimeImmutable('2026-01-31', app_tz()), 1)->format('Y-m-d'),
    'safe_next' => [
        '/dashboard/leave' => safe_next('/dashboard/leave'),
        '//evil.com' => safe_next('//evil.com'),
        '/\\evil.com' => safe_next('/\\evil.com'),
        'https://evil.com' => safe_next('https://evil.com'),
        'javascript:alert(1)' => safe_next('javascript:alert(1)'),
        '/javascript:alert(1)' => safe_next('/javascript:alert(1)'),
    ],
    'escape' => e('<script>alert("x")</script>'),
    'base_path' => base_path(),
    'request_path' => request_path(),
];
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Self-test</title></head>
<body>
<?php foreach (flashes() as $f): ?>
    <p class="flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></p>
<?php endforeach; ?>
<pre id="checks"><?= e(json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
<?php foreach (['ok', 'user_error', 'duplicate', 'crash'] as $a): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="<?= e($a) ?>"><input name="note" value="hello"><button><?= e($a) ?></button></form>
<?php endforeach; ?>
</body></html>
