<?php
/**
 * Documents & letters — port of lib/actions/documents.ts. Files go through
 * the secure upload pipeline (lib/upload.php) into
 * storage/uploads/<companyId>/{employee-documents|company-documents}/.
 */
declare(strict_types=1);

const DOCUMENT_TYPES = ['ID_PROOF', 'OFFER_LETTER', 'APPOINTMENT_LETTER', 'CONFIRMATION_LETTER', 'PROMOTION_LETTER', 'RELIEVING_LETTER',
    'EXPERIENCE_LETTER', 'CONTRACT', 'PAYSLIP', 'POLICY', 'CERTIFICATE', 'AADHAAR', 'PAN_CARD', 'PREV_OFFER_LETTER',
    'PREV_RELIEVING_LETTER', 'PREV_EXPERIENCE_LETTER', 'PREV_SALARY_SLIP', 'OTHER'];
/** What an employee may label their own uploads (offer letters, contracts, policies stay HR-issued). */
const SELF_UPLOAD_TYPE_LABELS = [
    'AADHAAR' => 'Aadhaar card', 'PAN_CARD' => 'PAN card', 'ID_PROOF' => 'Other ID proof',
    'PREV_OFFER_LETTER' => 'Previous company - Offer letter', 'PREV_RELIEVING_LETTER' => 'Previous company - Relieving letter',
    'PREV_EXPERIENCE_LETTER' => 'Previous company - Experience letter', 'PREV_SALARY_SLIP' => 'Previous company - Salary slip',
    'CERTIFICATE' => 'Certificate', 'OTHER' => 'Other',
];
const SELF_UPLOAD_TYPES = ['AADHAAR', 'PAN_CARD', 'ID_PROOF', 'PREV_OFFER_LETTER', 'PREV_RELIEVING_LETTER', 'PREV_EXPERIENCE_LETTER',
    'PREV_SALARY_SLIP', 'CERTIFICATE', 'OTHER'];

/** Display name: given name, else the uploaded file's base name (as the original). */
function document_display_name(string $name, ?array $file): string
{
    $name = trim($name);
    if ($name === '' && $file !== null) {
        $name = (string)$file['name'];
    }
    return v_max($name !== '' ? $name : 'Untitled document', 'Display name', 255);
}

/** Admin/HR manage any document; an employee-specific one also opens up to that employee. */
function assert_can_manage_document(array $auth, string $documentId): array
{
    $doc = db_one('SELECT * FROM Document WHERE id = ? AND companyId = ?', [$documentId, $auth['companyId'] ?? '']);
    if ($doc === null) {
        throw new UserError('Document not found.');
    }
    $isOwner = $doc['employeeId'] !== null && $doc['employeeId'] === $auth['employeeId'];
    if (!is_company_admin($auth) && !$isOwner) {
        throw new UserError('You are not authorized to manage this document.');
    }
    return $doc;
}

function action_upload_document(array $auth, ?array $file, array $in): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can upload company documents.');
    }
    $companyId = require_company($auth);
    $file ?? throw new UserError('Choose a file to upload.');
    $type = v_enum((string)($in['type'] ?? 'OTHER'), DOCUMENT_TYPES, 'document type');
    $employeeId = ($in['scope'] ?? 'company') === 'employee' ? v_id(trim((string)($in['employeeId'] ?? '')), 'employee') : null;
    if ($employeeId !== null && !db_value('SELECT 1 FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId])) {
        throw new UserError('Employee not found.');
    }
    $expiry = v_date(trim((string)($in['expiryDate'] ?? '')), 'expiry date', false);
    $name = document_display_name((string)($in['name'] ?? ''), $file);
    $ref = store_upload($file, $companyId, $employeeId ? 'employee-documents' : 'company-documents', 'document');
    db_tx(function () use ($auth, $companyId, $employeeId, $name, $type, $ref, $expiry) {
        $id = db_insert('Document', ['companyId' => $companyId, 'employeeId' => $employeeId, 'name' => $name, 'type' => $type, 'fileUrl' => $ref, 'expiryDate' => $expiry]);
        audit($auth, 'CREATE', 'Document', $id);
    });
}

/** Self-service (BRD FR-070): own record only, ID proof / certificate / other. */
function action_upload_own_document(array $auth, ?array $file, array $in): void
{
    if (!$auth['employeeId'] || !$auth['companyId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $file ?? throw new UserError('Choose a file to upload.');
    $type = (string)($in['type'] ?? '');
    if (!in_array($type, SELF_UPLOAD_TYPES, true)) {
        throw new UserError('Select the document type.');
    }
    $name = document_display_name((string)($in['name'] ?? ''), $file);
    $ref = store_upload($file, $auth['companyId'], 'employee-documents', 'document');
    db_tx(function () use ($auth, $name, $type, $ref) {
        $id = db_insert('Document', ['companyId' => $auth['companyId'], 'employeeId' => $auth['employeeId'], 'name' => $name, 'type' => $type, 'fileUrl' => $ref]);
        audit($auth, 'CREATE', 'Document', $id, ['selfUploaded' => true]);
    });
}

function action_generate_letter(array $auth, string $employeeId, string $type): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can generate letters.');
    }
    $companyId = require_company($auth);
    $type = v_enum($type, LETTER_TYPES, 'letter type');
    if (!is_valid_id($employeeId) || !db_value('SELECT 1 FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId])) {
        throw new UserError('Employee not found.');
    }
    db_tx(function () use ($auth, $companyId, $employeeId, $type) {
        $docId = generate_letter_for_employee($companyId, $employeeId, $type)
            ?? throw new UserError('No template configured for this letter type yet — add one under "Edit letter templates" below.');
        audit($auth, 'GENERATE_LETTER', 'Document', $docId, ['type' => $type, 'employeeId' => $employeeId]);
    });
}

function action_update_document(array $auth, string $documentId, ?array $file, array $in): void
{
    $doc = assert_can_manage_document($auth, $documentId);
    $name = trim((string)($in['name'] ?? ''));
    $name = $name !== '' ? v_max($name, 'Display name', 255) : $doc['name'];
    $requested = (string)($in['type'] ?? $doc['type']);
    $type = (is_company_admin($auth) || in_array($requested, SELF_UPLOAD_TYPES, true)) && in_array($requested, DOCUMENT_TYPES, true) ? $requested : $doc['type'];
    $expiry = v_date(trim((string)($in['expiryDate'] ?? '')), 'expiry date', false);
    $newRef = $file !== null
        ? store_upload($file, $doc['companyId'], $doc['employeeId'] ? 'employee-documents' : 'company-documents', 'document')
        : null;
    db_tx(function () use ($auth, $doc, $name, $type, $expiry, $newRef) {
        $data = ['name' => $name, 'type' => $type, 'expiryDate' => $expiry];
        if ($newRef !== null) {
            $data['fileUrl'] = $newRef;
        }
        db_update('Document', $data, 'id = ?', [$doc['id']]);
        audit($auth, 'UPDATE', 'Document', $doc['id']);
    });
    if ($newRef !== null) {
        delete_stored_file($doc['fileUrl'], $doc['companyId']);
    }
}

function action_delete_document(array $auth, string $documentId): void
{
    $doc = assert_can_manage_document($auth, $documentId);
    db_tx(function () use ($auth, $doc) {
        db_exec('DELETE FROM Document WHERE id = ?', [$doc['id']]);
        audit($auth, 'DELETE', 'Document', $doc['id'], ['name' => $doc['name']]);
    });
    delete_stored_file($doc['fileUrl'], $doc['companyId']);
}
