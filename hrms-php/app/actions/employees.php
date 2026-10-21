<?php
/**
 * Employee master actions — port of lib/actions/employees.ts.
 * Every lookup is scoped to the caller's company; ids from the form are
 * validated before use.
 */
declare(strict_types=1);

const EMPLOYMENT_TYPES = ['FULL_TIME', 'PART_TIME', 'CONTRACT', 'INTERN'];
const GENDERS = ['MALE', 'FEMALE', 'OTHER'];
const AVATAR_COLORS = ['#1f4d3a', '#c1712f', '#2c6a8c', '#7a5a2f', '#5a3a7a'];

function require_company(array $auth): string
{
    if (!$auth['companyId']) {
        throw new UserError('No company context.');
    }
    return $auth['companyId'];
}

/** "custom:<id>" or a built-in role → [baseRole, customRoleId]. */
function resolve_role_choice(string $companyId, string $raw): array
{
    if (str_starts_with($raw, 'custom:')) {
        $customId = substr($raw, 7);
        $cr = is_valid_id($customId) ? db_one('SELECT id, baseRole FROM CustomRole WHERE id = ? AND companyId = ?', [$customId, $companyId]) : null;
        if ($cr === null) {
            throw new UserError('Custom role not found.');
        }
        return [$cr['baseRole'], $cr['id']];
    }
    return [$raw, null];
}

/** Checks optional department/branch/manager ids belong to the company. */
function assert_org_refs(string $companyId, ?string $departmentId, ?string $branchId, ?string $managerId): void
{
    if ($departmentId !== null && !db_value('SELECT 1 FROM Department WHERE id = ? AND companyId = ?', [$departmentId, $companyId])) {
        throw new UserError('Department not found.');
    }
    if ($branchId !== null && !db_value('SELECT 1 FROM Branch WHERE id = ? AND companyId = ?', [$branchId, $companyId])) {
        throw new UserError('Branch not found.');
    }
    if ($managerId !== null && !db_value('SELECT 1 FROM Employee WHERE id = ? AND companyId = ?', [$managerId, $companyId])) {
        throw new UserError('Manager not found.');
    }
}

/**
 * Employee-code prefix from the company name: its capital letters ("WebPayX" → "WPX",
 * "Meridian Works" → "MW"), else the first three letters ("acme" → "ACM").
 */
function employee_code_prefix(string $companyId): string
{
    $name = (string)db_value('SELECT name FROM Company WHERE id = ?', [$companyId]);
    preg_match_all('/\p{Lu}/u', $name, $m);
    $caps = implode('', $m[0]);
    if (mb_strlen($caps) < 2) {
        $caps = mb_strtoupper(mb_substr((string)preg_replace('/[^\p{L}\p{N}]/u', '', $name), 0, 3));
    }
    return mb_substr($caps !== '' ? $caps : 'EMP', 0, 4);
}

/** Next free "<PREFIX>-####" code (original formula 1001 + count, skipping any code already taken). */
function next_employee_code(string $companyId): string
{
    $prefix = employee_code_prefix($companyId);
    $n = 1001 + (int)db_value('SELECT COUNT(*) FROM Employee WHERE companyId = ?', [$companyId]);
    while (db_value('SELECT 1 FROM Employee WHERE companyId = ? AND employeeCode = ?', [$companyId, "$prefix-$n"])) {
        $n++;
    }
    return "$prefix-$n";
}

/** Optional date of birth (used for the birthday calendar): a real past date, or null. */
function v_birth_date(string $raw): ?DateTimeImmutable
{
    $dob = v_date(trim($raw), 'date of birth', false);
    if ($dob !== null && ($dob >= today_start() || (int)$dob->format('Y') < 1900)) {
        throw new UserError('Enter a valid date of birth.');
    }
    return $dob;
}

/** @return string redirect path */
function action_create_employee(array $auth, array $in): string
{
    $companyId = require_company($auth);
    $firstName = v_required(trim((string)($in['firstName'] ?? '')), 'First name');
    $lastName = v_required(trim((string)($in['lastName'] ?? '')), 'Last name');
    $email = v_email((string)($in['email'] ?? ''), 'work email');
    $designation = v_required(trim((string)($in['designation'] ?? '')), 'Designation');
    $departmentId = v_id(trim((string)($in['departmentId'] ?? '')), 'department', false);
    $branchId = v_id(trim((string)($in['branchId'] ?? '')), 'branch', false);
    $managerId = v_id(trim((string)($in['managerId'] ?? '')), 'manager', false);
    $employmentType = v_enum((string)($in['employmentType'] ?? 'FULL_TIME'), EMPLOYMENT_TYPES, 'employment type');
    $doj = v_date(trim((string)($in['dateOfJoining'] ?? '')), 'date of joining');
    $dob = v_birth_date((string)($in['dateOfBirth'] ?? ''));
    $createLogin = ($in['createLogin'] ?? '') === 'on';
    $candidateId = v_id(trim((string)($in['candidateId'] ?? '')), 'candidate', false);

    [$role, $customRoleId] = resolve_role_choice($companyId, (string)($in['role'] ?? 'EMPLOYEE'));
    if ($createLogin && !in_array($role, ASSIGNABLE_ROLES, true)) {
        throw new UserError('That role can’t be assigned from here.');
    }
    if ($createLogin && $role === 'COMPANY_ADMIN' && $auth['role'] !== 'COMPANY_ADMIN') {
        throw new UserError('Only a Company Admin can grant Company Admin access.');
    }
    assert_org_refs($companyId, $departmentId, $branchId, $managerId);

    $employeeId = db_tx(function () use ($auth, $companyId, $firstName, $lastName, $email, $designation, $departmentId, $branchId, $managerId, $employmentType, $doj, $createLogin, $candidateId, $role, $customRoleId) {
        $candidate = null;
        if ($candidateId !== null) {
            $candidate = db_one('SELECT c.id FROM Candidate c JOIN JobPosting j ON j.id = c.jobPostingId WHERE c.id = ? AND j.companyId = ?', [$candidateId, $companyId]);
            if ($candidate === null) {
                throw new UserError('Candidate not found.');
            }
            if (db_value('SELECT 1 FROM Employee WHERE candidateId = ?', [$candidateId])) {
                throw new UserError('This candidate has already been onboarded as an employee.');
            }
        }

        $count = (int)db_value('SELECT COUNT(*) FROM Employee WHERE companyId = ?', [$companyId]);
        $userId = null;
        $linkedExisting = false;
        $tempPassword = null;
        if ($createLogin) {
            // The email may already belong to a login with no employee behind it
            // (e.g. the Company Admin created with the company) — link to it.
            $existing = db_one('SELECT id, companyId, role, customRoleId FROM `User` WHERE email = ? FOR UPDATE', [$email]);
            if ($existing !== null) {
                if ($existing['companyId'] !== $companyId) {
                    throw new UserError('A login with this email already exists on a different company.');
                }
                if (db_value('SELECT 1 FROM Employee WHERE userId = ?', [$existing['id']])) {
                    throw new UserError('This login is already linked to another employee.');
                }
                if ($existing['role'] !== $role || $existing['customRoleId'] !== $customRoleId) {
                    db_update('User', ['role' => $role, 'customRoleId' => $customRoleId], 'id = ?', [$existing['id']]);
                }
                $userId = $existing['id'];
                $linkedExisting = true;
            } else {
                $tempPassword = generate_temp_password();
                $userId = db_insert('User', [
                    'email' => $email, 'passwordHash' => hash_password($tempPassword),
                    'role' => $role, 'customRoleId' => $customRoleId, 'companyId' => $companyId,
                ]);
            }
        }

        $employeeId = db_insert('Employee', [
            'companyId' => $companyId,
            'userId' => $userId,
            'candidateId' => $candidate['id'] ?? null,
            'employeeCode' => next_employee_code($companyId),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'designation' => $designation,
            'departmentId' => $departmentId,
            'branchId' => $branchId,
            'managerId' => $managerId,
            'employmentType' => $employmentType,
            'dateOfJoining' => $doj,
            'dateOfBirth' => $dob,
            'avatarColor' => AVATAR_COLORS[$count % 5],
        ]);

        audit($auth, 'CREATE', 'Employee', $employeeId, $candidate
            ? ['name' => "$firstName $lastName", 'onboardedFromCandidateId' => $candidate['id']]
            : ['name' => "$firstName $lastName"]);

        // A hire from recruitment gets an onboarding checklist cloned from the
        // company's task templates (created even with zero templates, as the original).
        if ($candidate !== null) {
            create_onboarding_checklist($companyId, $employeeId);
        }

        // B3: annual leave balances for the current year.
        ensure_leave_balances($companyId, $employeeId, (int)now()->format('Y'));

        if ($userId !== null && !$linkedExisting) {
            notify($userId, 'Welcome to WorkNest',
                'Your account is ready. Sign in with the temporary password your administrator gave you, then change it under My profile → Change password.',
                '/dashboard');
            flash_temp_password($email, $tempPassword);
        } elseif ($userId !== null) {
            notify($userId, 'Your employee profile is ready',
                "$firstName $lastName’s employee record has been added to your existing login — no password change needed.",
                "/dashboard/employees/$employeeId");
        }
        return $employeeId;
    });

    flash('success', 'Employee created.');
    return "/dashboard/employees/$employeeId";
}

/**
 * True if $candidateManagerId is the employee or anyone who (directly or
 * transitively, up to 10 levels) reports to the employee — setting them as
 * manager would create a reporting cycle.
 */
function would_create_manager_cycle(string $employeeId, string $candidateManagerId): bool
{
    if ($candidateManagerId === $employeeId) {
        return true;
    }
    $frontier = [$employeeId];
    for ($depth = 0; $depth < 10 && $frontier; $depth++) {
        [$in, $p] = db_in($frontier);
        $ids = db_column("SELECT id FROM Employee WHERE managerId IN ($in)", $p);
        if (in_array($candidateManagerId, $ids, true)) {
            return true;
        }
        $frontier = $ids;
    }
    return false;
}

/**
 * Applies designation/department/branch/manager changes and writes ONE audit
 * entry with a field-level diff (relation ids resolved to names so history
 * stays readable). Shared by employee edit and promotion/transfer approval.
 */
function apply_org_field_changes(array $auth, string $companyId, string $employeeId, array $existing, array $patch, string $auditAction = 'UPDATE', array $auditMeta = [], array $extraChanges = []): array
{
    $keys = ['designation', 'departmentId', 'branchId', 'managerId'];
    $cur = array_intersect_key($existing, array_flip($keys));
    $next = array_merge($cur, array_intersect_key($patch, array_flip($keys)));

    $deptName = static fn(?string $id) => $id ? db_value('SELECT name FROM Department WHERE id = ?', [$id]) : null;
    $branchName = static fn(?string $id) => $id ? db_value('SELECT name FROM Branch WHERE id = ?', [$id]) : null;
    $empName = static function (?string $id): ?string {
        if (!$id) {
            return null;
        }
        $r = db_one('SELECT firstName, lastName FROM Employee WHERE id = ?', [$id]);
        return $r ? $r['firstName'] . ' ' . $r['lastName'] : null;
    };
    $display = static fn(array $v) => [
        'designation' => $v['designation'],
        'departmentId' => $deptName($v['departmentId']),
        'branchId' => $branchName($v['branchId']),
        'managerId' => $empName($v['managerId']),
    ];
    $prev = $display($cur);
    $new = $display($next);

    $changes = $extraChanges;
    foreach ($keys as $k) {
        if ($cur[$k] !== $next[$k]) {
            $changes[$k] = ['from' => $prev[$k], 'to' => $new[$k]];
        }
    }
    db_update('Employee', $next, 'id = ? AND companyId = ?', [$employeeId, $companyId]);
    if ($changes) {
        record_audit([
            'companyId' => $companyId, 'actorId' => $auth['sub'], 'action' => $auditAction,
            'entityType' => 'Employee', 'entityId' => $employeeId, 'meta' => ['changes' => $changes] + $auditMeta,
        ]);
    }
    return $changes;
}

function action_update_employee(array $auth, string $employeeId, array $in): string
{
    $companyId = require_company($auth);
    $existing = db_one('SELECT * FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]);
    if ($existing === null) {
        throw new UserError('Employee not found.');
    }
    $firstName = v_required(trim((string)($in['firstName'] ?? '')), 'First name');
    $lastName = v_required(trim((string)($in['lastName'] ?? '')), 'Last name');
    $email = v_email((string)($in['email'] ?? ''), 'work email');
    $phone = v_optional(trim((string)($in['phone'] ?? '')), 'Phone', 50);
    $address = v_optional(trim((string)($in['address'] ?? '')), 'Address', 500);
    $designation = v_required(trim((string)($in['designation'] ?? '')), 'Designation');
    $departmentId = v_id(trim((string)($in['departmentId'] ?? '')), 'department', false);
    $branchId = v_id(trim((string)($in['branchId'] ?? '')), 'branch', false);
    $managerId = v_id(trim((string)($in['managerId'] ?? '')), 'manager', false);
    $employmentType = v_enum((string)($in['employmentType'] ?? 'FULL_TIME'), EMPLOYMENT_TYPES, 'employment type');


    // The birthday is only touched when the form sends the field.
    $hasDob = array_key_exists('dateOfBirth', $in);
    $dob = $hasDob ? v_birth_date((string)$in['dateOfBirth']) : null;
    assert_org_refs($companyId, $departmentId, $branchId, $managerId);
    if ($managerId !== null && would_create_manager_cycle($employeeId, $managerId)) {
        throw new UserError('That would create a reporting cycle — this person already reports, directly or indirectly, to the employee you’re editing.');
    }

     db_tx(function () use ($auth, $companyId, $employeeId, $existing, $firstName, $lastName, $email, $phone, $address, $employmentType, $designation, $departmentId, $branchId, $managerId, $hasDob, $dob) {
        $prev = ['firstName' => $existing['firstName'], 'lastName' => $existing['lastName'], 'email' => $existing['email'], 'phone' => $existing['phone'], 'address' => $existing['address'] ?? null, 'employmentType' => $existing['employmentType']];
        $next = ['firstName' => $firstName, 'lastName' => $lastName, 'email' => $email, 'phone' => $phone, 'address' => $address, 'employmentType' => $employmentType];
        $contactChanges = [];
        foreach ($next as $k => $v) {
            if ($prev[$k] !== $v) {
                $contactChanges[$k] = ['from' => $prev[$k], 'to' => $v];
            }
        }
                if ($hasDob) {
            $oldDob = $existing['dateOfBirth'] ? dt($existing['dateOfBirth'])->format('Y-m-d') : null;
            $newDob = $dob?->format('Y-m-d');
            if ($oldDob !== $newDob) {
                $next['dateOfBirth'] = $dob;
                $contactChanges['dateOfBirth'] = ['from' => $oldDob, 'to' => $newDob];
            }
        }
        
        
        db_update('Employee', $next, 'id = ?', [$employeeId]);
        apply_org_field_changes($auth, $companyId, $employeeId, $existing,
            ['designation' => $designation, 'departmentId' => $departmentId, 'branchId' => $branchId, 'managerId' => $managerId],
            'UPDATE', [], $contactChanges);
    });
    flash('success', 'Changes saved.');
    return "/dashboard/employees/$employeeId";
}

/** Self-service phone edit (BRD FR-012) — own record only, phone only. */
function action_update_own_phone(array $auth, string $phoneRaw): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $phone = v_optional(trim($phoneRaw), 'Phone', 50);
    $existing = db_value('SELECT phone FROM Employee WHERE id = ?', [$auth['employeeId']]);
    if ($existing === $phone) {
        return;
    }
    db_tx(function () use ($auth, $phone, $existing) {
        db_update('Employee', ['phone' => $phone], 'id = ?', [$auth['employeeId']]);
        audit($auth, 'UPDATE', 'Employee', $auth['employeeId'], ['changes' => ['phone' => ['from' => $existing, 'to' => $phone]], 'selfService' => true]);
    });
}

/** ACTIVE / ON_LEAVE / NOTICE_PERIOD only — EXITED is reached solely through the exit workflow. */
function action_update_employee_status(array $auth, string $employeeId, string $status): void
{
    $companyId = require_company($auth);
    $status = v_enum($status, ['ACTIVE', 'ON_LEAVE', 'NOTICE_PERIOD'], 'status');
    $existing = db_one('SELECT id FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]);
    if ($existing === null) {
        throw new UserError('Employee not found.');
    }
    db_tx(function () use ($auth, $employeeId, $status) {
        db_update('Employee', ['status' => $status], 'id = ?', [$employeeId]);
        audit($auth, 'STATUS_CHANGE', 'Employee', $employeeId, ['status' => $status]);
    });
}

/**
 * Bulk CSV import (BRD FR-014). Row by row (one bad row doesn't sink the
 * batch); an existing Employee Code updates, a blank/unknown code creates;
 * managers linked in a second pass; Status is never imported. New employees
 * also get leave balances (B3). Imports never create logins (as the original).
 *
 * @return array{created:int,updated:int,errors:list<array{row:int,message:string}>}
 */
function action_import_employees(array $auth, array $file): array
{
    $companyId = require_company($auth);
    validate_upload($file, 'csv', 5 * 1024 * 1024);
    $allRows = parse_csv((string)file_get_contents($file['tmp_name']));
    if (count($allRows) < 2) {
        throw new UserError('The file has no data rows below the header.');
    }
    $dataRows = array_slice($allRows, 1);

    $deptByName = [];
    foreach (db_all('SELECT id, name FROM Department WHERE companyId = ?', [$companyId]) as $d) {
        $deptByName[mb_strtolower($d['name'])] = $d['id'];
    }
    $branchByName = [];
    foreach (db_all('SELECT id, name FROM Branch WHERE companyId = ?', [$companyId]) as $b) {
        $branchByName[mb_strtolower($b['name'])] = $b['id'];
    }
    $codeToId = [];
    foreach (db_all('SELECT id, employeeCode FROM Employee WHERE companyId = ?', [$companyId]) as $e) {
        $codeToId[$e['employeeCode']] = $e['id'];
    }
    $codeCounter = 1001 + count($codeToId);
    $seen = [];
    $results = [];
    $rowEmployee = [];
    $rowManagerCode = [];
    $year = (int)now()->format('Y');

    foreach ($dataRows as $i => $cols) {
        $rowNum = $i + 2;
        try {
            [$code, $firstName, $lastName, $email, $phone, $genderCell, $dobCell, $designation, $deptCell, $branchCell,
                $managerCode, $empTypeCell, $dojCell, $bankAcc, $bankIfsc, $bankName] = array_map(
                static fn($k) => trim((string)($cols[$k] ?? '')), range(0, 15));

            if ($firstName === '' || $lastName === '' || $email === '' || $designation === '' || $dojCell === '') {
                throw new UserError('First name, last name, email, designation, and date of joining are required.');
            }
            if ($code !== '' && isset($seen[$code])) {
                throw new UserError("Employee Code \"$code\" appears more than once in this file.");
            }
            if ($code !== '') {
                $seen[$code] = true;
            }
            $doj = parse_date_input($dojCell) ?? throw new UserError("Invalid date of joining \"$dojCell\" — use YYYY-MM-DD.");
            $dob = null;
            if ($dobCell !== '') {
                $dob = parse_date_input($dobCell) ?? throw new UserError("Invalid date of birth \"$dobCell\" — use YYYY-MM-DD.");
            }
            $gender = strtoupper($genderCell);
            if ($genderCell !== '' && !in_array($gender, GENDERS, true)) {
                throw new UserError("Invalid gender \"$genderCell\" — use MALE, FEMALE, or OTHER.");
            }
            $empType = strtoupper($empTypeCell !== '' ? $empTypeCell : 'FULL_TIME');
            if (!in_array($empType, EMPLOYMENT_TYPES, true)) {
                throw new UserError("Invalid employment type \"$empTypeCell\".");
            }
            $departmentId = null;
            if ($deptCell !== '') {
                $departmentId = $deptByName[mb_strtolower($deptCell)] ?? throw new UserError("Department \"$deptCell\" not found — create it in Settings first.");
            }
            $branchId = null;
            if ($branchCell !== '') {
                $branchId = $branchByName[mb_strtolower($branchCell)] ?? throw new UserError("Branch \"$branchCell\" not found — create it in Settings first.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new UserError("Invalid email \"$email\".");
            }
            foreach ([[$firstName, 'First name'], [$lastName, 'Last name'], [$designation, 'Designation'], [$code, 'Employee Code']] as [$v, $label]) {
                v_max($v, $label);
            }

            $data = [
                'firstName' => $firstName, 'lastName' => $lastName, 'email' => strtolower($email),
                'phone' => $phone !== '' ? v_max($phone, 'Phone', 50) : null,
                'gender' => $genderCell !== '' ? $gender : null, 'dateOfBirth' => $dob, 'designation' => $designation,
                'departmentId' => $departmentId, 'branchId' => $branchId, 'employmentType' => $empType, 'dateOfJoining' => $doj,
                'bankAccountNumber' => $bankAcc !== '' ? v_max($bankAcc, 'Bank account number') : null,
                'bankIfsc' => $bankIfsc !== '' ? v_max($bankIfsc, 'Bank IFSC') : null,
                'bankName' => $bankName !== '' ? v_max($bankName, 'Bank name') : null,
            ];
            if ($code !== '' && isset($codeToId[$code])) {
                db_update('Employee', $data, 'id = ? AND companyId = ?', [$codeToId[$code], $companyId]);
                $rowEmployee[$i] = $codeToId[$code];
                $results[$i] = ['row' => $rowNum, 'status' => 'updated'];
            } else {
                $newCode = $code !== '' ? $code : employee_code_prefix($companyId) . '-' . $codeCounter++;
                $id = db_tx(function () use ($data, $companyId, $newCode, $i, $year) {
                    $id = db_insert('Employee', $data + ['companyId' => $companyId, 'employeeCode' => $newCode, 'avatarColor' => AVATAR_COLORS[$i % 5]]);
                    ensure_leave_balances($companyId, $id, $year);
                    return $id;
                });
                $codeToId[$newCode] = $id;
                $rowEmployee[$i] = $id;
                $results[$i] = ['row' => $rowNum, 'status' => 'created'];
            }
            if ($managerCode !== '') {
                $rowManagerCode[$i] = $managerCode;
            }
        } catch (UserError $e) {
            $results[$i] = ['row' => $rowNum, 'status' => 'error', 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            if (!db_is_constraint_violation($e)) {
                throw $e;
            }
            $results[$i] = ['row' => $rowNum, 'status' => 'error', 'message' => 'Conflicts with an existing record (duplicate Employee Code?).'];
        }
    }

    // Second pass: manager links, once every row's employee exists.
    foreach ($rowManagerCode as $i => $managerCode) {
        $employeeId = $rowEmployee[$i] ?? null;
        if ($employeeId === null || $results[$i]['status'] === 'error') {
            continue;
        }
        $managerId = $codeToId[$managerCode] ?? null;
        if ($managerId === null) {
            $results[$i] = ['row' => $results[$i]['row'], 'status' => 'error', 'message' => "Manager Employee Code \"$managerCode\" not found."];
        } elseif ($managerId === $employeeId) {
            $results[$i] = ['row' => $results[$i]['row'], 'status' => 'error', 'message' => 'An employee cannot be their own manager.'];
        } elseif (would_create_manager_cycle($employeeId, $managerId)) {
            $results[$i] = ['row' => $results[$i]['row'], 'status' => 'error', 'message' => "Setting \"$managerCode\" as manager would create a reporting cycle."];
        } else {
            db_update('Employee', ['managerId' => $managerId], 'id = ? AND companyId = ?', [$employeeId, $companyId]);
        }
    }

    ksort($results);
    $created = count(array_filter($results, static fn($r) => $r['status'] === 'created'));
    $updated = count(array_filter($results, static fn($r) => $r['status'] === 'updated'));
    $errors = array_values(array_filter($results, static fn($r) => $r['status'] === 'error'));
    audit($auth, 'BULK_IMPORT', 'Employee', $companyId, ['created' => $created, 'updated' => $updated, 'errorCount' => count($errors), 'totalRows' => count($dataRows)]);
    return ['created' => $created, 'updated' => $updated, 'errors' => array_map(static fn($r) => ['row' => $r['row'], 'message' => $r['message']], $errors)];
}

/**
 * Self-service profile edit — any user with an employee record may change their own personal details.
 * Work fields (email, designation, department, branch, manager, pay) stay with HR.
 */
function action_update_own_profile(array $auth, array $in): void
{
    $employeeId = $auth['employeeId'] ?? null;
    if (!$employeeId || !$auth['companyId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $existing = db_one('SELECT firstName, lastName, phone, address, gender, dateOfBirth FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $auth['companyId']]);
    if ($existing === null) {
        throw new UserError('Employee not found.');
    }
    $gender = trim((string)($in['gender'] ?? ''));
    $data = [
        'firstName' => v_required(trim((string)($in['firstName'] ?? '')), 'First name'),
        'lastName' => v_required(trim((string)($in['lastName'] ?? '')), 'Last name'),
        'phone' => v_optional(trim((string)($in['phone'] ?? '')), 'Phone', 50),
        'address' => v_optional(trim((string)($in['address'] ?? '')), 'Address', 500),
        'gender' => $gender === '' ? null : v_enum($gender, ['MALE', 'FEMALE', 'OTHER'], 'gender'),
        'dateOfBirth' => v_birth_date((string)($in['dateOfBirth'] ?? '')),
    ];
    db_tx(function () use ($auth, $employeeId, $data) {
        db_update('Employee', $data, 'id = ?', [$employeeId]);
        audit($auth, 'UPDATE', 'Employee', $employeeId, ['self' => true, 'fields' => array_keys($data)]);
    });
}
