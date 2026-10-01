<?php
/**
 * /letters/{templateId}?employeeId=… and /dashboard/documents/letter/{templateId}?employeeId=…
 * — ports of app/letters/[id]/page.tsx and app/dashboard/documents/letter/[id]/page.tsx.
 *
 * A3 fix: the original injected the rendered body with dangerouslySetInnerHTML,
 * so employee/company/template data could inject markup or script. Here the
 * subject and body are plain text, escaped, with line breaks preserved.
 * Also fixed: the employee must belong to the caller's company (the original
 * rendered any employee id for HR/Admin).
 */
declare(strict_types=1);

$employeeId = query('employeeId');
if (!is_valid_id($employeeId)) {
    throw new HttpError(404);
}
$canManage = is_company_admin($auth);
if (!$canManage && !can_see_employee($auth, $employeeId)) {
    throw new HttpError(404);
}
if (!db_value('SELECT 1 FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $auth['companyId'] ?? ''])) {
    throw new HttpError(404);
}
$template = db_one('SELECT * FROM LetterTemplate WHERE id = ? AND companyId = ?', [$params['id'], $auth['companyId'] ?? '']) ?? throw new HttpError(404);
$letter = render_letter($template, $employeeId);
$withLetterhead = str_starts_with(request_path(), '/letters/');
$company = db_one('SELECT id, name, logoUrl, address, phone, panNumber FROM Company WHERE id = ?', [$auth['companyId']]);

document_open($letter['subject'], '');
?>
<div style="background:#fff;color:#1a1a1a;font-family:Georgia,serif;min-height:100vh">
    <div style="max-width:720px;margin:0 auto;padding:48px 56px">
        <?php if ($withLetterhead && $company && ($company['logoUrl'] || $company['address'] || $company['phone'] || $company['panNumber'])): ?>
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:24px;padding-bottom:16px;margin-bottom:24px;border-bottom:3px solid #1f4d3a">
                <div>
                    <?php if ($company['logoUrl'] && $company['logoUrl'] !== '#'): ?>
                        <img src="<?= e(url('/files/logo/' . $company['id'])) ?>" alt="<?= e($company['name']) ?>" style="height:48px;object-fit:contain">
                    <?php else: ?>
                        <p style="font-size:18px;font-weight:700;margin:0"><?= e($company['name']) ?></p>
                    <?php endif; ?>
                </div>
                <div style="font-size:12px;color:#444;text-align:right;line-height:1.5">
                    <?php if ($company['address']): ?><p style="margin:0;white-space:pre-line"><?= e($company['address']) ?></p><?php endif; ?>
                    <?php if ($company['panNumber']): ?><p style="margin:0">PAN No: <?= e($company['panNumber']) ?></p><?php endif; ?>
                    <?php if ($company['phone']): ?><p style="margin:0">Phone No: <?= e($company['phone']) ?></p><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <h1 style="font-size:20px;margin-bottom:24px;font-family:Georgia,serif"><?= e($letter['subject']) ?></h1>
        <div style="font-size:15px;line-height:1.7;white-space:pre-line"><?= e(normalize_line_endings($letter['body'])) ?></div>
        <p style="margin-top:40px;font-size:13px;color:#666">Use your browser's Print (Ctrl/Cmd+P) → Save as PDF to keep a copy of this letter.</p>
    </div>
</div>
<?php document_close();
