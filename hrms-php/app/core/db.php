<?php
/**
 * PDO data layer. Every query is a prepared statement with positional
 * placeholders — user input is never concatenated into SQL. Identifiers
 * (table/column names) passed to the helpers come from code only and are
 * additionally checked against a strict pattern.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    // Same wall-clock zone as PHP, and strict mode so an invalid ENUM/date is
    // an error instead of being silently stored as '' / 0000-00-00 on hosts
    // whose global sql_mode is lax.
    $offset = now()->format('P');
    $pdo->exec("SET time_zone = '$offset'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $value = db_value_of($value); // DateTime → DATETIME(3) string, arrays → JSON
        $type = match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        $stmt->bindValue($i + 1, is_bool($value) ? (int)$value : (is_float($value) ? (string)$value : $value), $type);
    }
    $stmt->execute();
    return $stmt;
}

/** @return list<array<string,mixed>> */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/** @return list<mixed> first column of every row */
function db_column(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

function db_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
        throw new LogicException("Invalid SQL identifier: $name");
    }
    return '`' . $name . '`';
}

/** INSERT a row. Generates `id` (CUID-style) when the caller doesn't supply one. Returns the id. */
function db_insert(string $table, array $data): string
{
    if (!array_key_exists('id', $data)) {
        $data = ['id' => new_id()] + $data;
    }
    $cols = implode(', ', array_map('db_ident', array_keys($data)));
    $marks = implode(', ', array_fill(0, count($data), '?'));
    db_query('INSERT INTO ' . db_ident($table) . " ($cols) VALUES ($marks)", array_map('db_value_of', array_values($data)));
    return (string)$data['id'];
}

/** UPDATE `table` SET data WHERE <where> — returns affected rows. */
function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    if ($data === []) {
        return 0;
    }
    $sets = implode(', ', array_map(static fn($c) => db_ident($c) . ' = ?', array_keys($data)));
    return db_exec('UPDATE ' . db_ident($table) . " SET $sets WHERE $where", [...array_map('db_value_of', array_values($data)), ...$whereParams]);
}

/** Normalises PHP values for binding (DateTime → DATETIME(3), arrays → JSON). */
function db_value_of(mixed $v): mixed
{
    if ($v instanceof DateTimeInterface) {
        return db_dt($v);
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
    return $v;
}

/**
 * Runs $fn inside a transaction; commits on success, rolls back and rethrows
 * on any exception. Nested calls join the outer transaction.
 */
function db_tx(callable $fn): mixed
{
    static $depth = 0;
    $pdo = db();
    if ($depth > 0) {
        $depth++;
        try {
            return $fn();
        } finally {
            $depth--;
        }
    }
    $depth = 1;
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $depth = 0;
    }
}

/**
 * Placeholder list for an IN (...) clause. An empty list yields "NULL" so the
 * clause matches nothing (mirrors Prisma's `in: []`).
 *
 * @return array{0:string,1:list<mixed>}
 */
function db_in(array $values): array
{
    $values = array_values(array_unique($values));
    if ($values === []) {
        return ['NULL', []];
    }
    return [implode(', ', array_fill(0, count($values), '?')), $values];
}

/** True if the exception is a MySQL duplicate-key / FK constraint violation. */
function db_is_constraint_violation(Throwable $e): bool
{
    return $e instanceof PDOException && str_starts_with((string)$e->getCode(), '23');
}

function db_is_duplicate_key(Throwable $e): bool
{
    return $e instanceof PDOException && (($e->errorInfo[1] ?? null) === 1062);
}
