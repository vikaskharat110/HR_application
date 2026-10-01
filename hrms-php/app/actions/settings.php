<?php
/**
 * Company settings (Company Admin) — port of lib/actions/settings.ts.
 * Statutory config and letter templates live here too (used from Phase 8/11 pages).
 */
declare(strict_types=1);

function assert_company_admin(array $auth): string
{
    if ($auth['role'] !== 'COMPANY_ADMIN') {
        throw new UserError('Only the Company Admin can change company settings.');
    }
    return require_company($auth);
}

function action_update_letterhead(array $auth, ?array $logo, string $address, string $phone, string $pan): void
{
    $companyId = assert_company_admin($auth);
    $data = [
        'address' => v_optional(trim($address), 'Address', 2000),
        'phone' => v_optional(trim($phone), 'Phone', 50),
        'panNumber' => v_optional(trim($pan), 'PAN number', 50),
    ];
    $oldLogo = null;
    if ($logo !== null) {
        $oldLogo = db_value('SELECT logoUrl FROM Company WHERE id = ?', [$companyId]);
        $data['logoUrl'] = store_upload($logo, $companyId, 'other', 'image');
    }
    db_tx(function () use ($auth, $companyId, $data) {
        db_update('Company', $data, 'id = ?', [$companyId]);
        audit($auth, 'CONFIGURE', 'Company', $companyId, ['letterhead' => true]);
    });
    if ($oldLogo) {
        delete_stored_file($oldLogo, $companyId);
    }
}

function action_update_policy(array $auth, array $in): void
{
    $companyId = assert_company_admin($auth);
    $int = static fn(string $k, string $label, int $max) => v_int(trim((string)($in[$k] ?? '')), $label, 0, $max);
    $data = [
        'workWeek' => v_required(trim((string)($in['workWeek'] ?? '')), 'Work week', 191),
        'checkInGraceMins' => $int('checkInGraceMins', 'Check-in grace', 1440),
        'casualLeaveDays' => $int('casualLeaveDays', 'Casual leave', 366),
        'sickLeaveDays' => $int('sickLeaveDays', 'Sick leave', 366),
        'earnedLeaveDays' => $int('earnedLeaveDays', 'Earned leave', 366),
        'probationMonths' => $int('probationMonths', 'Probation', 120),
        'noticePeriodDays' => $int('noticePeriodDays', 'Notice period', 3650),
        'geofenceAttendanceEnabled' => ($in['geofenceAttendanceEnabled'] ?? '') === 'on',
    ];
    db_tx(function () use ($companyId, $data) {
        if (db_value('SELECT id FROM CompanyPolicy WHERE companyId = ?', [$companyId])) {
            db_update('CompanyPolicy', $data, 'companyId = ?', [$companyId]);
        } else {
            db_insert('CompanyPolicy', ['companyId' => $companyId] + $data);
        }
    });
}

function action_add_branch(array $auth, string $name, string $city): void
{
    $companyId = assert_company_admin($auth);
    db_insert('Branch', ['companyId' => $companyId, 'name' => v_required(trim($name), 'Branch name'), 'city' => v_required(trim($city), 'City')]);
}

/** Sets (or, with blank coordinates, clears) a branch geofence. Coordinates come from the admin's browser. */
function action_set_branch_location(array $auth, string $branchId, string $latRaw, string $lngRaw, string $radiusRaw): void
{
    $companyId = assert_company_admin($auth);
    if (!db_value('SELECT 1 FROM Branch WHERE id = ? AND companyId = ?', [$branchId, $companyId])) {
        throw new UserError('Branch not found.');
    }
    if (trim($latRaw) === '' || trim($lngRaw) === '') {
        db_update('Branch', ['latitude' => null, 'longitude' => null], 'id = ?', [$branchId]);
        return;
    }
    $bad = 'That doesn’t look like a valid location — use the "Use my current location" button instead of typing coordinates.';
    if (!is_numeric($latRaw) || abs((float)$latRaw) > 90 || !is_numeric($lngRaw) || abs((float)$lngRaw) > 180) {
        throw new UserError($bad);
    }
    $latitude = (float)$latRaw;
    $longitude = (float)$lngRaw;
    $radius = 50;
    if (trim($radiusRaw) !== '') {
        if (!is_numeric($radiusRaw)) {
            throw new UserError('Radius must be a number.');
        }
        $radius = max(1, (int)js_round((float)$radiusRaw));
    }
    db_tx(function () use ($auth, $branchId, $latitude, $longitude, $radius) {
        db_update('Branch', ['latitude' => $latitude, 'longitude' => $longitude, 'geofenceRadiusMeters' => $radius], 'id = ?', [$branchId]);
        audit($auth, 'CONFIGURE', 'Branch', $branchId, ['latitude' => $latitude, 'longitude' => $longitude, 'radius' => $radius]);
    });
}

function action_add_department(array $auth, string $name): void
{
    $companyId = assert_company_admin($auth);
    db_insert('Department', ['companyId' => $companyId, 'name' => v_required(trim($name), 'Department name')]);
}

function action_add_leave_type(array $auth, string $name, string $days, bool $carryForward): void
{
    $companyId = assert_company_admin($auth);
    db_insert('LeaveType', [
        'companyId' => $companyId,
        'name' => v_required(trim($name), 'Leave type name'),
        'daysPerYear' => v_int(trim($days), 'Days per year', 0, 366),
        'carryForward' => $carryForward,
    ]);
}

function action_add_holiday(array $auth, string $name, string $date): void
{
    $companyId = assert_company_admin($auth);
    db_insert('Holiday', ['companyId' => $companyId, 'name' => v_required(trim($name), 'Holiday name'), 'date' => v_date(trim($date), 'date')]);
}

/** Creates a tenant login with a random temporary password (shown once to the admin). */
function action_invite_user(array $auth, string $emailRaw, string $roleRaw): void
{
    $companyId = assert_company_admin($auth);
    $email = v_email($emailRaw);
    [$role, $customRoleId] = resolve_role_choice($companyId, $roleRaw);
    if (!in_array($role, ASSIGNABLE_ROLES, true)) {
        throw new UserError('That role can’t be assigned from here.');
    }
    db_tx(function () use ($auth, $companyId, $email, $role, $customRoleId) {
        if (db_value('SELECT 1 FROM `User` WHERE email = ?', [$email])) {
            throw new UserError('A login with this email already exists.');
        }
        $temp = generate_temp_password();
        $userId = db_insert('User', ['email' => $email, 'role' => $role, 'customRoleId' => $customRoleId, 'passwordHash' => hash_password($temp), 'companyId' => $companyId]);
        audit($auth, 'CREATE', 'User', $userId);
        notify($userId, 'Welcome to WorkNest', 'Your account was created. Sign in with the temporary password your administrator gave you, then change it under My profile → Change password.', '/dashboard');
        flash_temp_password($email, $temp);
    });
}

function action_set_user_active(array $auth, string $userId, bool $isActive): void
{
    $companyId = assert_company_admin($auth);
    if (!db_value('SELECT 1 FROM `User` WHERE id = ? AND companyId = ?', [$userId, $companyId])) {
        throw new UserError('User not found.');
    }
    if ($userId === $auth['sub']) {
        throw new UserError('You cannot change your own account status.');
    }
    db_tx(function () use ($auth, $userId, $isActive) {
        db_update('User', ['isActive' => $isActive], 'id = ?', [$userId]);
        audit($auth, $isActive ? 'ACTIVATE' : 'DEACTIVATE', 'User', $userId);
    });
}

function action_add_onboarding_template(array $auth, string $category, string $title): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can manage onboarding templates.');
    }
    $companyId = require_company($auth);
    db_insert('OnboardingTaskTemplate', [
        'companyId' => $companyId,
        'category' => v_enum($category, ['HR', 'IT', 'MANAGER'], 'category'),
        'title' => v_required(trim($title), 'Task title'),
    ]);
}

function action_delete_onboarding_template(array $auth, string $id): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can manage onboarding templates.');
    }
    db_exec('DELETE FROM OnboardingTaskTemplate WHERE id = ? AND companyId = ?', [$id, require_company($auth)]);
}

// ───────────────────────────────────────── Statutory config (Phase 8 page) ───

function action_update_statutory_config(array $auth, array $in): void
{
    if (!has_role($auth, ['COMPANY_ADMIN', 'PAYROLL_ADMIN'])) {
        throw new UserError('You are not authorized to change statutory settings.');
    }
    $companyId = require_company($auth);
    $slabs = static function (string $raw): string {
        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || !array_is_list($parsed)) {
            throw new UserError('Slab tables must be valid JSON arrays, e.g. [{"upto":15000,"amount":0}].');
        }
        return json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    };
    $num = static fn(string $k, string $label) => v_number(trim((string)($in[$k] ?? '')), $label, 0);
    $data = [
        'pfEmployeeRate' => $num('pfEmployeeRate', 'PF employee rate'),
        'pfEmployerRate' => $num('pfEmployerRate', 'PF employer rate'),
        'pfWageCeiling' => $num('pfWageCeiling', 'PF wage ceiling'),
        'esiEmployeeRate' => $num('esiEmployeeRate', 'ESI employee rate'),
        'esiEmployerRate' => $num('esiEmployerRate', 'ESI employer rate'),
        'esiWageCeiling' => $num('esiWageCeiling', 'ESI wage ceiling'),
        'ptSlabs' => $slabs((string)($in['ptSlabs'] ?? '')),
        'tdsSlabs' => $slabs((string)($in['tdsSlabs'] ?? '')),
    ];
    db_tx(function () use ($auth, $companyId, $data) {
        get_statutory_config($companyId); // ensure a row exists
        db_update('StatutoryConfig', $data, 'companyId = ?', [$companyId]);
        audit($auth, 'CONFIGURE', 'StatutoryConfig', $companyId);
    });
}

// ───────────────────────────────────────── Letter templates (Phase 11 page) ───

function action_upsert_letter_template(array $auth, string $type, string $subject, string $body, ?array $file, bool $removeFile): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can edit letter templates.');
    }
    $companyId = require_company($auth);
    $type = v_enum($type, LETTER_TYPES, 'letter type');
    $subject = v_required(normalize_line_endings($subject), 'Subject', 2000);
    $body = v_required(normalize_line_endings($body), 'Body', 200000);

    $existing = db_one('SELECT * FROM LetterTemplate WHERE companyId = ? AND type = ?', [$companyId, $type]);
    $fileUrl = $existing['templateFileUrl'] ?? null;
    $fileName = $existing['templateFileName'] ?? null;
    $replaced = null;
    if ($file !== null) {
        $newRef = store_upload($file, $companyId, 'other', 'docx');
        $replaced = $fileUrl;
        $fileUrl = $newRef;
        $fileName = mb_substr(basename((string)$file['name']), 0, 191);
    } elseif ($removeFile) {
        $replaced = $fileUrl;
        $fileUrl = null;
        $fileName = null;
    }
    db_tx(function () use ($auth, $companyId, $type, $subject, $body, $fileUrl, $fileName, $existing) {
        $data = ['subject' => $subject, 'bodyHtml' => $body, 'templateFileUrl' => $fileUrl, 'templateFileName' => $fileName];
        if ($existing) {
            db_update('LetterTemplate', $data, 'id = ?', [$existing['id']]);
        } else {
            db_insert('LetterTemplate', ['companyId' => $companyId, 'type' => $type] + $data);
        }
        audit($auth, 'CONFIGURE', 'LetterTemplate', $type);
    });
    if ($replaced) {
        delete_stored_file($replaced, $companyId);
    }
}
