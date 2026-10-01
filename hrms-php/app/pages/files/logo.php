<?php
/** GET /files/logo/{companyId} — the company logo, only for users of that company (or the Super Admin). */
declare(strict_types=1);

$companyId = $params['companyId'];
if ($auth['companyId'] !== $companyId && $auth['role'] !== 'SUPER_ADMIN') {
    throw new HttpError(404);
}
$ref = db_value('SELECT logoUrl FROM Company WHERE id = ?', [$companyId]);
$path = resolve_stored_file(is_string($ref) ? $ref : null, $companyId);
if ($path === null) {
    throw new HttpError(404);
}
send_stored_file($path, 'logo');
