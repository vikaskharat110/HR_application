<?php
/**
 * Development-time schema validator (never deployed).
 *
 * Usage:  php validate_schema.php <host> <port> <db> <user> <password>
 *
 * Expects database/hrms.sql to have been imported into an EMPTY database.
 * Checks structure (tables, FKs, unique keys, ENUMs, JSON) and runs
 * representative CRUD / constraint / transaction tests inside a scratch
 * company that is deleted (cascade) at the end.
 */
declare(strict_types=1);

[$_, $host, $port, $db, $user, $pass] = $argv + array_fill(0, 6, null);
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '+05:30'");
// Same session settings the application uses (strict mode: invalid ENUM/dates are errors, not silent '').
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  PASS " : "  FAIL ") . $label . ($detail !== '' ? "  [$detail]" : '') . PHP_EOL;
}
function expectError(PDO $pdo, string $label, callable $fn, string $sqlstatePrefix = '23'): void {
    try { $fn(); check($label, false, 'no error raised'); }
    catch (PDOException $e) { check($label, str_starts_with((string)$e->getCode(), $sqlstatePrefix), $e->getCode()); }
}
function id(): string { return 'c' . bin2hex(random_bytes(12)); }

$version = $pdo->query('SELECT VERSION() v')->fetch()['v'];
echo "Server: $version\n\n== Structure ==\n";

$tables = $pdo->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll();
check('43 tables created', count($tables) === 43, (string)count($tables));
check('all tables InnoDB', count(array_filter($tables, fn($t) => $t['ENGINE'] === 'InnoDB')) === 43);
check('all tables utf8mb4_unicode_ci', count(array_filter($tables, fn($t) => $t['TABLE_COLLATION'] === 'utf8mb4_unicode_ci')) === 43);

$fks = $pdo->query("SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()")->fetchAll();
check('78 foreign keys created', count($fks) === 78, (string)count($fks));
// MariaDB reports RESTRICT as "NO ACTION"; in InnoDB both are identical (checked immediately).
$rules = array_count_values(array_map(fn($f) => $f['DELETE_RULE'] === 'NO ACTION' ? 'RESTRICT' : $f['DELETE_RULE'], $fks));
ksort($rules);
check('FK delete rules = CASCADE 46 / RESTRICT 6 / SET NULL 26',
    ($rules['CASCADE'] ?? 0) === 46 && ($rules['RESTRICT'] ?? 0) === 6 && ($rules['SET NULL'] ?? 0) === 26, json_encode($rules));
check('every FK is ON UPDATE CASCADE', count(array_filter($fks, fn($f) => $f['UPDATE_RULE'] === 'CASCADE')) === 78);

$uniques = $pdo->query("SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'")->fetch()['c'];
check('22 unique constraints', (int)$uniques === 22, (string)$uniques);
$pks = $pdo->query("SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'PRIMARY KEY'")->fetch()['c'];
check('43 primary keys', (int)$pks === 43, (string)$pks);
$idx = $pdo->query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'PasswordResetToken_userId_idx'")->fetch()['c'];
check('PasswordResetToken_userId_idx exists', (int)$idx === 1);

$enumCols = $pdo->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'enum'")->fetch()['c'];
check('ENUM columns created', (int)$enumCols === 31, (string)$enumCols);
$roleEnum = $pdo->query("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='User' AND COLUMN_NAME='role'")->fetch()['t'];
check('User.role ENUM has 11 roles', substr_count($roleEnum, "'") === 22, $roleEnum);

// MariaDB implements JSON as LONGTEXT + json_valid() CHECK; MySQL has a native type.
$jsonCols = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('breakdown','meta','ptSlabs','tdsSlabs')")->fetchAll();
check('4 JSON columns present', count($jsonCols) === 4, implode(',', array_map(fn($c) => $c['TABLE_NAME'] . '.' . $c['COLUMN_NAME'] . ':' . $c['DATA_TYPE'], $jsonCols)));
$long = $pdo->query("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('text','mediumtext')")->fetch()['c'];
check('long free-text columns are TEXT/MEDIUMTEXT', (int)$long === 34, (string)$long);

echo "\n== CRUD, constraints and transactions ==\n";
$co = id(); $co2 = id();
$pdo->prepare("INSERT INTO Company (id, name, slug) VALUES (?,?,?), (?,?,?)")->execute([$co, 'Test Co ₹', 'test-co-' . $co, $co2, 'Other Co', 'other-co-' . $co2]);
$row = $pdo->query("SELECT * FROM Company WHERE id = '$co'")->fetch();
check('CREATE company, defaults applied (status ACTIVE, createdAt set)', $row['status'] === 'ACTIVE' && $row['createdAt'] !== null);
check('utf8mb4 round-trip (₹)', $row['name'] === 'Test Co ₹');
check('DATETIME(3) keeps milliseconds', (bool)preg_match('/\.\d{3}$/', $row['createdAt']), $row['createdAt']);

expectError($pdo, 'UNIQUE Company.slug rejects duplicate', fn() =>
    $pdo->prepare("INSERT INTO Company (id, name, slug) VALUES (?,?,?)")->execute([id(), 'Dup', 'test-co-' . $co]));
expectError($pdo, 'ENUM rejects invalid Company.status', fn() =>
    $pdo->prepare("UPDATE Company SET status = 'BOGUS' WHERE id = ?")->execute([$co]), '');

$pdo->prepare("INSERT INTO CompanyPolicy (id, companyId) VALUES (?,?)")->execute([id(), $co]);
$pol = $pdo->query("SELECT * FROM CompanyPolicy WHERE companyId = '$co'")->fetch();
check('CompanyPolicy defaults (MON-FRI, grace 15, probation 3, geofence off)',
    $pol['workWeek'] === 'MON-FRI' && (int)$pol['checkInGraceMins'] === 15 && (int)$pol['probationMonths'] === 3 && (int)$pol['geofenceAttendanceEnabled'] === 0);

$user = id();
$pdo->prepare("INSERT INTO User (id, companyId, email, passwordHash, role) VALUES (?,?,?,?,?)")
    ->execute([$user, $co, "t-$co@example.com", password_hash('x', PASSWORD_BCRYPT), 'HR']);
$u = $pdo->query("SELECT isActive FROM User WHERE id='$user'")->fetch();
check('User.isActive defaults to 1', (int)$u['isActive'] === 1);
expectError($pdo, 'UNIQUE User.email is case-insensitive (app lowercases anyway)', fn() =>
    $pdo->prepare("INSERT INTO User (id, email, passwordHash, role) VALUES (?,?,?,?)")->execute([id(), strtoupper("t-$co@example.com"), 'x', 'HR']));

$mgr = id(); $emp = id();
$ins = $pdo->prepare("INSERT INTO Employee (id, companyId, userId, employeeCode, firstName, lastName, email, designation, dateOfJoining, managerId) VALUES (?,?,?,?,?,?,?,?,?,?)");
$ins->execute([$mgr, $co, $user, 'MW-1001', 'Asha', 'Rao', 'a@x.com', 'Manager', '2024-01-01 00:00:00', null]);
$ins->execute([$emp, $co, null, 'MW-1002', 'Ravi', 'Kumar', 'r@x.com', 'Engineer', '2025-06-01 00:00:00', $mgr]);
$e = $pdo->query("SELECT status, employmentType, avatarColor, salaryOnHold FROM Employee WHERE id='$emp'")->fetch();
check('Employee defaults (ACTIVE, FULL_TIME, #2f6f4f, not on hold)',
    $e['status'] === 'ACTIVE' && $e['employmentType'] === 'FULL_TIME' && $e['avatarColor'] === '#2f6f4f' && (int)$e['salaryOnHold'] === 0);
expectError($pdo, 'UNIQUE (companyId, employeeCode)', fn() =>
    $ins->execute([id(), $co, null, 'MW-1001', 'X', 'Y', 'x@x.com', 'D', '2025-01-01', null]));
$ins->execute([id(), $co2, null, 'MW-1001', 'Same', 'Code', 's@x.com', 'D', '2025-01-01', null]);
check('same employeeCode allowed in a different company', true);
expectError($pdo, 'FK rejects Employee with unknown companyId', fn() =>
    $ins->execute([id(), 'nope', null, 'MW-9', 'X', 'Y', 'x@x.com', 'D', '2025-01-01', null]));

// READ with join
$r = $pdo->prepare("SELECT e.firstName, m.firstName AS mgr FROM Employee e LEFT JOIN Employee m ON m.id = e.managerId WHERE e.id = ?");
$r->execute([$emp]);
check('READ self-join employee → manager', $r->fetch()['mgr'] === 'Asha');

// UPDATE
$pdo->prepare("UPDATE Employee SET designation = ?, status = 'NOTICE_PERIOD' WHERE id = ?")->execute(['Senior Engineer', $emp]);
check('UPDATE employee', $pdo->query("SELECT designation FROM Employee WHERE id='$emp'")->fetch()['designation'] === 'Senior Engineer');

// Leave: type, balance, request
$lt = id();
$pdo->prepare("INSERT INTO LeaveType (id, companyId, name, daysPerYear) VALUES (?,?,?,?)")->execute([$lt, $co, 'Casual Leave', 12]);
$lb = id();
$pdo->prepare("INSERT INTO LeaveBalance (id, employeeId, leaveTypeId, year, allocated) VALUES (?,?,?,?,?)")->execute([$lb, $emp, $lt, 2026, 12]);
expectError($pdo, 'UNIQUE LeaveBalance (employee, type, year) blocks duplicates', fn() =>
    $pdo->prepare("INSERT INTO LeaveBalance (id, employeeId, leaveTypeId, year, allocated) VALUES (?,?,?,?,?)")->execute([id(), $emp, $lt, 2026, 12]));
$lr = id();
$pdo->prepare("INSERT INTO LeaveRequest (id, employeeId, leaveTypeId, startDate, endDate, days, reason, approverId) VALUES (?,?,?,?,?,?,?,?)")
    ->execute([$lr, $emp, $lt, '2026-10-05 00:00:00', '2026-10-06 00:00:00', 2, str_repeat('Long reason ', 50), $mgr]);
check('TEXT column stores > 191 chars', strlen($pdo->query("SELECT reason FROM LeaveRequest WHERE id='$lr'")->fetch()['reason']) === 600);

// Transaction rollback (B1 pattern)
$pdo->beginTransaction();
$pdo->prepare("UPDATE LeaveRequest SET status='APPROVED' WHERE id=?")->execute([$lr]);
$pdo->prepare("UPDATE LeaveBalance SET used = used + 2 WHERE id=?")->execute([$lb]);
$pdo->rollBack();
$st = $pdo->query("SELECT r.status, b.used FROM LeaveRequest r, LeaveBalance b WHERE r.id='$lr' AND b.id='$lb'")->fetch();
check('ROLLBACK restores request status and balance', $st['status'] === 'PENDING' && (float)$st['used'] === 0.0);

// FK RESTRICT: LeaveType referenced by a request cannot be deleted
expectError($pdo, 'FK RESTRICT: LeaveType with requests cannot be deleted', fn() =>
    $pdo->prepare("DELETE FROM LeaveType WHERE id=?")->execute([$lt]));

// Payroll + JSON
$run = id();
$pdo->prepare("INSERT INTO PayrollRun (id, companyId, month, year, status) VALUES (?,?,?,?,?)")->execute([$run, $co, 9, 2026, 'PROCESSED']);
expectError($pdo, 'UNIQUE PayrollRun (company, month, year)', fn() =>
    $pdo->prepare("INSERT INTO PayrollRun (id, companyId, month, year) VALUES (?,?,?,?)")->execute([id(), $co, 9, 2026]));
$breakdown = json_encode(['basic' => 50000, 'hra' => 20000, 'lopDays' => 1.5, 'lopDeduction' => 3226]);
$pdo->prepare("INSERT INTO Payslip (id, payrollRunId, employeeId, grossPay, totalDeductions, netPay, workingDays, paidDays, breakdown) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([id(), $run, $emp, 70000.0, 9426.0, 60574.0, 22, 20.5, $breakdown]);
$b = $pdo->query("SELECT JSON_EXTRACT(breakdown, '$.lopDays') v FROM Payslip WHERE payrollRunId='$run'")->fetch()['v'];
check('JSON stored and queryable (JSON_EXTRACT)', (float)$b === 1.5, (string)$b);
expectError($pdo, 'JSON column rejects invalid JSON', fn() =>
    $pdo->prepare("UPDATE Payslip SET breakdown = ? WHERE payrollRunId = ?")->execute(['{not json', $run]), '');
$pdo->prepare("INSERT INTO StatutoryConfig (id, companyId, ptSlabs, tdsSlabs) VALUES (?,?,?,?)")
    ->execute([id(), $co, '[{"upto":15000,"amount":0},{"upto":null,"amount":200}]', '[]']);
$sc = $pdo->query("SELECT pfEmployeeRate, esiWageCeiling FROM StatutoryConfig WHERE companyId='$co'")->fetch();
check('StatutoryConfig DOUBLE defaults (0.12, 21000)', (float)$sc['pfEmployeeRate'] === 0.12 && (float)$sc['esiWageCeiling'] === 21000.0);

// Nullable unique: RolePermission platform default (companyId NULL)
$rp = $pdo->prepare("INSERT INTO RolePermission (id, role, module, action, allowed, companyId) VALUES (?,?,?,?,?,?)");
$rp->execute([id(), 'HR', '/dashboard/payroll', 'LOCK', 0, $co]);
expectError($pdo, 'UNIQUE RolePermission (role, module, action, companyId)', fn() =>
    $rp->execute([id(), 'HR', '/dashboard/payroll', 'LOCK', 1, $co]));
$upd = $pdo->query("SELECT updatedAt FROM RolePermission WHERE companyId='$co'")->fetch()['updatedAt'];
check('@updatedAt auto-populated', $upd !== null);

// SET NULL: deleting manager nulls managerId (FK SET NULL)
$tmpMgr = id();
$ins->execute([$tmpMgr, $co, null, 'MW-1003', 'Tmp', 'Mgr', 't@x.com', 'M', '2025-01-01', null]);
$pdo->prepare("UPDATE Employee SET managerId=? WHERE id=?")->execute([$tmpMgr, $emp]);
$pdo->prepare("DELETE FROM Employee WHERE id=?")->execute([$tmpMgr]);
check('FK SET NULL: deleting a manager clears managerId',
    $pdo->query("SELECT managerId FROM Employee WHERE id='$emp'")->fetch()['managerId'] === null);

// DELETE with CASCADE: removing the company removes every dependent row
$pdo->prepare("DELETE FROM LeaveRequest WHERE employeeId=?")->execute([$emp]); // RESTRICT on LeaveType path
$pdo->prepare("DELETE FROM Company WHERE id IN (?, ?)")->execute([$co, $co2]);
$left = 0;
foreach (['Employee', 'User', 'LeaveType', 'LeaveBalance', 'PayrollRun', 'Payslip', 'CompanyPolicy', 'StatutoryConfig', 'RolePermission'] as $t) {
    $left += (int)$pdo->query("SELECT COUNT(*) c FROM `$t`")->fetch()['c'];
}
check('DELETE company cascades to all dependent rows', $left === 0, "$left rows left");

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
