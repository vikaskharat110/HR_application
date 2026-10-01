<?php
/** Assets — port of lib/actions/assets.ts (Company Admin, HR, IT Admin manage). */
declare(strict_types=1);

const ASSET_MANAGER_ROLES = ['COMPANY_ADMIN', 'HR', 'IT_ADMIN'];

function assert_asset_manager(array $auth): string
{
    if (!has_role($auth, ASSET_MANAGER_ROLES)) {
        throw new UserError('You are not authorized to manage assets.');
    }
    return require_company($auth);
}

function action_create_asset(array $auth, string $name, string $category, string $serial): void
{
    $companyId = assert_asset_manager($auth);
    $data = ['companyId' => $companyId, 'name' => v_required(trim($name), 'Asset name'),
        'category' => v_required(trim($category), 'Category'), 'serialNumber' => v_required(trim($serial), 'Serial number')];
    db_tx(function () use ($auth, $data) {
        $id = db_insert('Asset', $data);
        audit($auth, 'CREATE', 'Asset', $id);
    });
}

function action_assign_asset(array $auth, string $assetId, string $employeeId): void
{
    $companyId = assert_asset_manager($auth);
    $employee = is_valid_id($employeeId) ? db_one('SELECT id, userId FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]) : null;
    if ($employee === null || !db_value('SELECT 1 FROM Asset WHERE id = ? AND companyId = ?', [$assetId, $companyId])) {
        throw new UserError('Asset or employee not found.');
    }
    db_tx(function () use ($auth, $assetId, $employee) {
        db_value('SELECT id FROM Asset WHERE id = ? FOR UPDATE', [$assetId]);
        // Hardening: the original never checked, so a crafted request could double-assign.
        if (db_value('SELECT 1 FROM AssetAssignment WHERE assetId = ? AND returnedDate IS NULL', [$assetId])) {
            throw new UserError('This asset is already assigned — return it first.');
        }
        db_insert('AssetAssignment', ['assetId' => $assetId, 'employeeId' => $employee['id']]);
        db_update('Asset', ['status' => 'ASSIGNED'], 'id = ?', [$assetId]);
        if ($employee['userId']) {
            notify($employee['userId'], 'Asset assigned', 'A new asset has been assigned to you.', '/dashboard/assets');
        }
        audit($auth, 'ASSIGN', 'Asset', $assetId);
    });
}

function action_return_asset(array $auth, string $assignmentId, string $assetId): void
{
    $companyId = assert_asset_manager($auth);
    $ok = db_value(
        'SELECT 1 FROM AssetAssignment aa JOIN Asset a ON a.id = aa.assetId WHERE aa.id = ? AND aa.assetId = ? AND a.companyId = ? AND aa.returnedDate IS NULL',
        [$assignmentId, $assetId, $companyId]
    );
    if (!$ok) {
        throw new UserError('Asset assignment not found.');
    }
    db_tx(function () use ($auth, $assignmentId, $assetId) {
        db_update('AssetAssignment', ['returnedDate' => now()], 'id = ?', [$assignmentId]);
        db_update('Asset', ['status' => 'AVAILABLE'], 'id = ?', [$assetId]);
        audit($auth, 'RETURN', 'Asset', $assetId);
    });
}
