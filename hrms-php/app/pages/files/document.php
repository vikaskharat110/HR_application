<?php
/**
 * GET /files/{documentId} — protected document download (A2). Mirrors who
 * can see the document in the Documents module:
 *   - the document must belong to the caller's company;
 *   - company-wide documents: anyone with Documents module access;
 *   - employee documents: Admin/HR, or the employee is in the caller's
 *     visible scope (self, or their reporting line for managers).
 */
declare(strict_types=1);

$doc = db_one('SELECT * FROM Document WHERE id = ? AND companyId = ?', [$params['id'], $auth['companyId'] ?? '']);
if ($doc === null) {
    throw new HttpError(404);
}
if ($doc['employeeId'] === null) {
    $allowed = has_module_access($auth, '/dashboard/documents');
} else {
    $allowed = is_company_admin($auth) || can_see_employee($auth, $doc['employeeId']);
}
if (!$allowed) {
    throw new HttpError(404); // don't reveal that the document exists
}
$path = resolve_stored_file($doc['fileUrl'], $doc['companyId']);
if ($path === null) {
    throw new HttpError(404, 'This document has no file attached.');
}
send_stored_file($path, $doc['name']);
