<?php
/**
 * Phase 11 — Documents + Letters + Assets. Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase11_documents_assets.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$STORAGE = realpath(__DIR__ . '/../../storage/uploads');
$admin = Client::as('admin@meridianworks.com');
$hr = Client::as('hr@meridianworks.com');
$manager = Client::as('manager@meridianworks.com');
$employee = Client::as('employee@meridianworks.com');
// A peer EMPLOYEE login (the seed has only one), attached to an employee outside the manager's line.
$peerEmp = (string)qv("SELECT e.id FROM Employee e WHERE e.companyId = ? AND e.userId IS NULL AND e.managerId <> ? ORDER BY e.firstName LIMIT 1", [$MW, emp_id('manager@meridianworks.com')]);
q("INSERT INTO `User` (id, companyId, email, passwordHash, role) SELECT 'ctestpeeruser00000000001', companyId, 'peer@meridianworks.com', passwordHash, 'EMPLOYEE' FROM `User` WHERE email = 'employee@meridianworks.com'");
q("UPDATE Employee SET userId = 'ctestpeeruser00000000001' WHERE id = ?", [$peerEmp]);
$colleague = Client::as('peer@meridianworks.com');
$it = Client::as('it@meridianworks.com');
$nw = Client::as('admin@northwindretail.com');
$empId = emp_id('employee@meridianworks.com');
$path = static fn(string $ref) => $STORAGE . '/' . substr($ref, 8);

/** Minimal .docx whose {{employeeName}} placeholder Word split over three runs. */
function make_docx(string $file): string
{
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . '<w:p><w:r><w:t xml:space="preserve">Dear {{</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>employee</w:t></w:r><w:r><w:t xml:space="preserve">Name}}, welcome to {{companyName}}.</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>CTC: {{ctc}} · Unknown: [{{nope}}] · Date {{ today }}</w:t></w:r></w:p>'
        . '</w:body></w:document>');
    $zip->close();
    return $file;
}

section('Upload (A1) + protected download (A2)');
$pdf = tmp_file('Leave Policy.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
$hr->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'POLICY', 'scope' => 'company', 'name' => '', 'expiryDate' => '2027-03-31'], null, ['file' => $pdf]);
$policy = q1("SELECT * FROM Document WHERE companyId = ? AND type = 'POLICY' ORDER BY uploadedAt DESC LIMIT 1", [$MW]);
check('company policy stored; name defaults to file name', $policy !== null && $policy['name'] === 'Leave Policy.pdf' && $policy['employeeId'] === null && $policy['expiryDate'] === '2027-03-31 00:00:00.000');
check('stored under company-documents with a random name', (bool)preg_match('#^storage:' . $MW . '/company-documents/[a-f0-9]{32}\.pdf$#', $policy['fileUrl']) && is_file($path($policy['fileUrl'])));
check('company docs listed for an employee with download via /files', $employee->get('/dashboard/documents/company')->has('/files/' . $policy['id']));
check('every employee with Documents access can download a company doc', $employee->get('/files/' . $policy['id'])->status === 200);
check('IT admin (no Documents module) cannot download it', $it->get('/files/' . $policy['id'])->status === 404);
check('other tenant cannot download it', $nw->get('/files/' . $policy['id'])->status === 404);
check('anonymous request redirected to login', (new Client())->get('/files/' . $policy['id'])->status === 303);
$r = $hr->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'POLICY', 'scope' => 'company'], null, ['file' => tmp_file('run.php', '<?php echo 1;')]);
check('.php rejected', $r->has('That file type isn’t allowed'));
$r = $hr->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'POLICY', 'scope' => 'company'], null, ['file' => tmp_file('fake.pdf', '<html><script>alert(1)</script></html>')]);
check('HTML disguised as PDF rejected', $r->has('don’t match') || $r->has('damaged'));
$r = $hr->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'POLICY', 'scope' => 'company'], null, ['file' => tmp_file('evil.phtml', 'x')]);
check('.phtml rejected', $r->has('That file type isn’t allowed'));
$r = $hr->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'BOGUS', 'scope' => 'company'], null, ['file' => $pdf]);
check('invalid document type rejected', $r->has('Choose a valid document type.'));
$r = $hr->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'CONTRACT', 'scope' => 'employee', 'employeeId' => emp_id('admin@northwindretail.com') ?: 'cxxxxxxxxxxxxxxxxxxxxxxx'], null, ['file' => $pdf]);
check("can't attach to another company's employee", $r->has('Employee not found.'));
$hr->submit('/dashboard/documents', ['_action' => 'upload', '_form' => 'upload', 'type' => 'CONTRACT', 'scope' => 'employee', 'employeeId' => $empId, 'name' => 'Contract <2026>'], null, ['file' => $pdf]);
$contract = q1("SELECT * FROM Document WHERE employeeId = ? AND type = 'CONTRACT' ORDER BY uploadedAt DESC LIMIT 1", [$empId]);
check('employee document stored under employee-documents', $contract !== null && str_contains($contract['fileUrl'], '/employee-documents/'));
check('name escaped in the list', $employee->get('/dashboard/documents/' . $empId)->has('Contract &lt;2026&gt;'));
check('owner downloads; manager (their line) downloads', $employee->get('/files/' . $contract['id'])->status === 200 && $manager->get('/files/' . $contract['id'])->status === 200);
check('colleague cannot download (404)', $colleague->get('/files/' . $contract['id'])->status === 404);
check('employee lands straight on their own folder', $employee->get('/dashboard/documents/employees')->location === BASE . '/dashboard/documents/' . $empId);
check("colleague can't open someone else's folder", $colleague->get('/dashboard/documents/' . $empId)->status === 404);
check('employee upload form offered, admin upload form not', $employee->get('/dashboard/documents')->has('Upload your document') && !$employee->last->has('value="upload"'));

section('Self-upload + edit/replace/delete');
$employee->submit('/dashboard/documents', ['_action' => 'upload_own', '_form' => 'upload-own', 'type' => 'CONTRACT', 'name' => 'My PAN'], null, ['file' => tmp_png()]);
$own = q1("SELECT * FROM Document WHERE employeeId = ? AND name = 'My PAN'", [$empId]);
check('self-upload restricted to ID/certificate/other (CONTRACT → OTHER)', $own !== null && $own['type'] === 'OTHER');
check('self-upload audited as selfUploaded', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND meta LIKE '%selfUploaded%'", [$own['id']]) === 1);
$oldFile = $path($own['fileUrl']);
$employee->submit('/dashboard/documents/' . $empId, ['_action' => 'doc_update', '_form' => 'doc-' . $own['id'], 'documentId' => $own['id'], 'name' => 'PAN card', 'type' => 'POLICY', 'expiryDate' => ''], null, ['file' => $pdf]);
$own2 = q1('SELECT * FROM Document WHERE id = ?', [$own['id']]);
check('owner renames + replaces file; cannot set an HR-only type', $own2['name'] === 'PAN card' && $own2['type'] === 'OTHER' && str_ends_with($own2['fileUrl'], '.pdf'));
check('replaced file removed from storage', !is_file($oldFile) && is_file($path($own2['fileUrl'])));
$colleague->post('/dashboard/documents', ['_action' => 'doc_delete', 'documentId' => $own['id']]);
check("colleague cannot delete someone's document", qv('SELECT id FROM Document WHERE id = ?', [$own['id']]) !== null);
$manager->post('/dashboard/documents/' . $empId, ['_action' => 'doc_delete', 'documentId' => $own['id']]);
check('manager cannot delete a report’s document (original rule)', qv('SELECT id FROM Document WHERE id = ?', [$own['id']]) !== null);
$employee->post('/dashboard/documents/' . $empId, ['_action' => 'doc_delete', 'documentId' => $own['id']]);
clearstatcache();
check('owner deletes; file removed; DELETE audited', qv('SELECT id FROM Document WHERE id = ?', [$own['id']]) === null && !is_file($path($own2['fileUrl']))
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'DELETE'", [$own['id']]) === 1);
$employee->post('/dashboard/documents/company', ['_action' => 'doc_delete', 'documentId' => $policy['id']]);
check('employee cannot delete a company document', qv('SELECT id FROM Document WHERE id = ?', [$policy['id']]) !== null);

section('Letters — built-in PDF (₹) and Word template');
q("UPDATE Employee SET firstName = 'Rohan <b>' WHERE id = ?", [$empId]);
$hr->submit('/dashboard/documents', ['_action' => 'generate', '_form' => 'generate', 'employeeId' => $empId, 'type' => 'OFFER']);
$offer = q1("SELECT * FROM Document WHERE employeeId = ? AND type = 'OFFER_LETTER' ORDER BY uploadedAt DESC LIMIT 1", [$empId]);
check('offer letter generated as PDF document', $offer !== null && str_ends_with($offer['fileUrl'], '.pdf') && $offer['name'] === 'Offer of Employment — Rohan <b> Verma');
$bytes = (string)file_get_contents($path($offer['fileUrl']));
$text = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $mm)) {
    foreach ($mm[1] as $raw) {
        $text .= (@gzuncompress($raw) ?: $raw) . "\n";
    }
}
check('PDF embeds DejaVu Sans (Unicode font)', str_contains($bytes, 'DejaVuSans'));
// tFPDF writes text as UTF-16BE with an identity CID map (CID = code point), so the ₹
// shows up as the bytes 20 B9 in the page content stream; DejaVu Sans has that glyph.
check('₹ (U+20B9) written with the embedded Unicode font', str_contains($text, "\x20\xB9") && str_contains($bytes, '/ToUnicode'));
check('generation audited', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'GENERATE_LETTER'", [$offer['id']]) === 1);
$docx = make_docx(sys_get_temp_dir() . '/offer-template-' . getmypid() . '.docx');
$tplBody = (string)qv("SELECT bodyHtml FROM LetterTemplate WHERE companyId = ? AND type = 'OFFER'", [$MW]);
$hr->submit('/dashboard/documents', ['_action' => 'template', '_form' => 'template-OFFER', 'type' => 'OFFER', 'subject' => 'Offer — {{employeeName}}', 'bodyHtml' => $tplBody], null, ['templateFile' => $docx]);
$tpl = q1("SELECT * FROM LetterTemplate WHERE companyId = ? AND type = 'OFFER'", [$MW]);
check('Word template stored (name kept, file under other/)', str_starts_with((string)$tpl['templateFileName'], 'offer-template') && str_contains((string)$tpl['templateFileUrl'], '/other/'));
$hr->submit('/dashboard/documents', ['_action' => 'generate', '_form' => 'generate', 'employeeId' => $empId, 'type' => 'OFFER']);
$wordDoc = q1("SELECT * FROM Document WHERE employeeId = ? AND type = 'OFFER_LETTER' ORDER BY uploadedAt DESC LIMIT 1", [$empId]);
check('generated from the Word template as .docx', str_ends_with($wordDoc['fileUrl'], '.docx'));
$z = new ZipArchive();
$z->open($path($wordDoc['fileUrl']));
$xml = (string)$z->getFromName('word/document.xml');
$z->close();
$plain = strip_tags(str_replace('</w:p>', "\n", $xml));
check('placeholder split across runs filled', str_contains($plain, 'Dear Rohan &lt;b&gt; Verma,') || str_contains(html_entity_decode($plain), 'Dear Rohan <b> Verma,'), $plain);
check('XML-escaped value (no broken markup)', !str_contains($xml, '<b> Verma'));
check('other fields filled, unknown → empty, spaced tag filled', str_contains($plain, 'Meridian Works') && str_contains($plain, 'CTC: ₹') && str_contains($plain, '[]') && !str_contains($plain, '{{'));
check('formatting of the first run kept (bold run intact)', str_contains($xml, '<w:b/>'));
$hr->submit('/dashboard/documents', ['_action' => 'template', '_form' => 'template-OFFER', 'type' => 'OFFER', 'subject' => 'Offer — {{employeeName}}', 'bodyHtml' => $tplBody, 'removeTemplateFile' => '1']);
check('template file removed (back to PDF) and deleted from storage', qv("SELECT templateFileUrl FROM LetterTemplate WHERE companyId = ? AND type = 'OFFER'", [$MW]) === null && !is_file($path($tpl['templateFileUrl'])));
$r = $hr->submit('/dashboard/documents', ['_action' => 'template', '_form' => 'template-OFFER', 'type' => 'OFFER', 'subject' => 'x', 'bodyHtml' => 'y'], null, ['templateFile' => $pdf]);
check('only .docx accepted as a template', $r->has('That file type isn’t allowed'));
q("DELETE FROM LetterTemplate WHERE companyId = ? AND type = 'PROMOTION'", [$MW]);
$r = $hr->submit('/dashboard/documents', ['_action' => 'generate', '_form' => 'generate', 'employeeId' => $empId, 'type' => 'PROMOTION']);
check('no template → friendly message', $r->has('No template configured for this letter type yet'));
$offers = (int)qv("SELECT COUNT(*) FROM Document WHERE employeeId = ? AND type = 'OFFER_LETTER'", [$empId]);
check('employee cannot generate letters', $employee->post('/dashboard/documents', ['_action' => 'generate', 'employeeId' => $empId, 'type' => 'OFFER'])->status === 303
    && (int)qv("SELECT COUNT(*) FROM Document WHERE employeeId = ? AND type = 'OFFER_LETTER'", [$empId]) === $offers);

section('Letter preview (A3 XSS fix)');
q("UPDATE LetterTemplate SET bodyHtml = 'Hello {{employeeName}} <script>alert(1)</script><img src=x onerror=alert(2)>' WHERE companyId = ? AND type = 'APPOINTMENT'", [$MW]);
$apptId = (string)qv("SELECT id FROM LetterTemplate WHERE companyId = ? AND type = 'APPOINTMENT'", [$MW]);
$p = $hr->get("/letters/$apptId?employeeId=$empId");
check('preview renders', $p->status === 200 && $p->has('Appointment Letter'));
check('template script escaped', $p->has('&lt;script&gt;alert(1)&lt;/script&gt;') && !$p->has('<script>alert(1)'));
check('img/onerror escaped', !$p->has('<img src=x'));
check('employee name escaped', $p->has('Rohan &lt;b&gt; Verma'));
check('dashboard variant renders too', $hr->get("/dashboard/documents/letter/$apptId?employeeId=$empId")->status === 200);
check('employee can preview their own', $employee->get("/letters/$apptId?employeeId=$empId")->status === 200);
check("colleague cannot preview someone else's", $colleague->get("/letters/$apptId?employeeId=$empId")->status === 404);
$nwEmp = (string)qv('SELECT id FROM Employee WHERE companyId = ? LIMIT 1', [company_id('Northwind Retail')]);
if ($nwEmp === '') {
    q("INSERT INTO Employee (id, companyId, employeeCode, firstName, lastName, email, designation, dateOfJoining) VALUES ('cnwemployee0000000000001', ?, 'NW-1', 'North', 'Wind', 'nw@x.com', 'Clerk', NOW())", [company_id('Northwind Retail')]);
    $nwEmp = 'cnwemployee0000000000001';
}
check("HR cannot render a letter for another company's employee (original could)", $hr->get("/letters/$apptId?employeeId=$nwEmp")->status === 404);
check('other tenant cannot use the template', $nw->get("/letters/$apptId?employeeId=$nwEmp")->status === 404);
q("UPDATE Employee SET firstName = 'Rohan' WHERE id = ?", [$empId]);

section('Assets');
$it->submit('/dashboard/assets', ['_action' => 'create', '_form' => 'asset', 'name' => 'ThinkPad X1', 'category' => 'Laptop', 'serialNumber' => 'TP-001']);
$asset = q1("SELECT * FROM Asset WHERE companyId = ? AND serialNumber = 'TP-001'", [$MW]);
check('IT admin adds an asset (AVAILABLE)', $asset !== null && $asset['status'] === 'AVAILABLE');
$employee->post('/dashboard/assets', ['_action' => 'create', 'name' => 'x', 'category' => 'y', 'serialNumber' => 'z']);
check('employee cannot add assets', (int)qv("SELECT COUNT(*) FROM Asset WHERE serialNumber = 'z'") === 0);
$it->submit('/dashboard/assets', ['_action' => 'assign', 'assetId' => $asset['id'], 'employeeId' => $empId]);
check('assigned → ASSIGNED, employee notified', qv('SELECT status FROM Asset WHERE id = ?', [$asset['id']]) === 'ASSIGNED'
    && (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Asset assigned'", [$empId]) >= 1);
$r = $it->submit('/dashboard/assets', ['_action' => 'assign', 'assetId' => $asset['id'], 'employeeId' => emp_id('hr@meridianworks.com')]);
check('double assignment refused (hardening)', $r->has('already assigned') && (int)qv('SELECT COUNT(*) FROM AssetAssignment WHERE assetId = ? AND returnedDate IS NULL', [$asset['id']]) === 1);
check('employee sees the asset list (read-only)', $employee->get('/dashboard/assets')->has('ThinkPad X1') && !$employee->last->has('Add to inventory'));
check('employee profile lists the assigned asset', $hr->get('/dashboard/employees/' . $empId)->has('TP-001'));
$assignment = (string)qv('SELECT id FROM AssetAssignment WHERE assetId = ? AND returnedDate IS NULL', [$asset['id']]);
$nw->post('/dashboard/assets', ['_action' => 'return', 'assignmentId' => $assignment, 'assetId' => $asset['id']]);
check('other tenant cannot return it', qv('SELECT status FROM Asset WHERE id = ?', [$asset['id']]) === 'ASSIGNED');
$it->submit('/dashboard/assets', ['_action' => 'return', 'assignmentId' => $assignment, 'assetId' => $asset['id']]);
check('returned → AVAILABLE, returnedDate set, audited', qv('SELECT status FROM Asset WHERE id = ?', [$asset['id']]) === 'AVAILABLE'
    && qv('SELECT returnedDate FROM AssetAssignment WHERE id = ?', [$assignment]) !== null
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'RETURN'", [$asset['id']]) === 1);

finish();
