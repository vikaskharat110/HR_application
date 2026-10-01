<?php
/**
 * Phase 12 — Helpdesk + Exit/Offboarding. Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase12_helpdesk_exit.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$admin = Client::as('admin@meridianworks.com');
$hr = Client::as('hr@meridianworks.com');
$manager = Client::as('manager@meridianworks.com');
$employee = Client::as('employee@meridianworks.com');
// A peer EMPLOYEE login (the seed has only one), attached to an employee outside the manager's line.
$peerEmp = (string)qv("SELECT e.id FROM Employee e WHERE e.companyId = ? AND e.userId IS NULL AND e.managerId <> ? ORDER BY e.firstName LIMIT 1", [$MW, emp_id('manager@meridianworks.com')]);
q("INSERT INTO `User` (id, companyId, email, passwordHash, role) SELECT 'ctestpeeruser00000000001', companyId, 'peer@meridianworks.com', passwordHash, 'EMPLOYEE' FROM `User` WHERE email = 'employee@meridianworks.com'");
q("UPDATE Employee SET userId = 'ctestpeeruser00000000001' WHERE id = ?", [$peerEmp]);
$peer = Client::as('peer@meridianworks.com');
$it = Client::as('it@meridianworks.com');
$finance = Client::as('finance@meridianworks.com');
$nw = Client::as('admin@northwindretail.com');
$empId = emp_id('employee@meridianworks.com');
// Northwind has no employees in the demo data — add one for the cross-tenant checks.
q("INSERT INTO Employee (id, companyId, employeeCode, firstName, lastName, email, designation, dateOfJoining) VALUES ('ctestnwemployee000000001', ?, 'NW-T1', 'Nora', 'West', 'nora@northwindretail.com', 'Clerk', '2025-01-01')", [company_id('Northwind Retail')]);
$nwEmp = 'ctestnwemployee000000001';
$itEmp = emp_id('it@meridianworks.com');
$itUser = (string)qv("SELECT id FROM `User` WHERE email = 'it@meridianworks.com'");
$mgrUser = (string)qv("SELECT id FROM `User` WHERE email = 'manager@meridianworks.com'");
$empUser = (string)qv("SELECT id FROM `User` WHERE email = 'employee@meridianworks.com'");
$notes = static fn(string $uid, string $link) => (int)qv('SELECT COUNT(*) FROM Notification WHERE userId = ? AND link = ?', [$uid, $link]);

// ───────────────────────────── Helpdesk ─────────────────────────────
section('Helpdesk — raise a ticket');
$r = $employee->post('/dashboard/helpdesk', ['_action' => 'create', '_form' => 'ticket', 'category' => 'IT', 'priority' => 'HIGH',
    'subject' => 'Laptop <script>x</script>', 'description' => "Screen flickers\r\nsince Monday"]);
$ticket = q1('SELECT * FROM Ticket WHERE raisedById = ? ORDER BY createdAt DESC LIMIT 1', [$empId]);
check('ticket created and redirected to its page', $ticket !== null && $r->status === 303 && str_ends_with($r->location, '/dashboard/helpdesk/' . $ticket['id']));
check('IT ticket routed to the IT queue (FR-110)', $ticket['assignedToId'] === $itEmp && $ticket['companyId'] === $MW && $ticket['priority'] === 'HIGH' && $ticket['status'] === 'OPEN');
check('line endings normalised', $ticket['description'] === "Screen flickers\nsince Monday");
check('IT owner notified', $notes($itUser, '/dashboard/helpdesk/' . $ticket['id']) === 1);
check('audit CREATE Ticket', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityType = 'Ticket' AND entityId = ? AND action = 'CREATE' AND companyId = ?", [$ticket['id'], $MW]) === 1);
$page = $employee->get('/dashboard/helpdesk/' . $ticket['id']);
check('raiser opens ticket; subject escaped', $page->status === 200 && $page->has('Laptop &lt;script&gt;x&lt;/script&gt;') && !$page->has('<script>x</script>'));
check('raiser sees status as a badge, not a control', !$page->has('data-autosubmit') && $page->has('Assigned to'));
$r = $employee->submit('/dashboard/helpdesk', ['_action' => 'create', '_form' => 'ticket', 'category' => 'Hacking', 'priority' => 'LOW', 'subject' => 'x']);
check('invalid category rejected', $r->has('Choose a valid category.'));
$r = $employee->submit('/dashboard/helpdesk', ['_action' => 'create', '_form' => 'ticket', 'category' => 'Other', 'priority' => 'MEDIUM', 'subject' => '   ']);
check('blank subject rejected', $r->has('Subject is required.'));
$employee->post('/dashboard/helpdesk', ['_action' => 'create', '_form' => 'ticket', 'category' => 'HR Policy', 'priority' => 'LOW', 'subject' => 'Leave policy question']);
$hrTicket = q1('SELECT * FROM Ticket WHERE raisedById = ? AND category = ? ORDER BY createdAt DESC LIMIT 1', [$empId, 'HR Policy']);
check('non-queue category routed to HR', $hrTicket !== null && $hrTicket['assignedToId'] === emp_id('hr@meridianworks.com'));

section('Helpdesk — visibility (A4)');
check('other tenant gets 404 on the ticket (A4)', $nw->get('/dashboard/helpdesk/' . $ticket['id'])->status === 404);
check('other tenant list does not show it', !$nw->get('/dashboard/helpdesk')->has($ticket['id']));
check('colleague redirected away from the ticket', $peer->get('/dashboard/helpdesk/' . $ticket['id'])->location === BASE . '/dashboard/helpdesk');
check('colleague list does not show it', !$peer->get('/dashboard/helpdesk')->has($ticket['id']));
check('employee list shows own tickets without "Raised by"', $employee->get('/dashboard/helpdesk')->has($ticket['id']) && !$employee->last->has('Raised by'));
check('HR sees all company tickets with "Raised by"', $hr->get('/dashboard/helpdesk')->has($ticket['id']) && $hr->last->has('Raised by'));
check('IT queue sees the ticket assigned to it', $it->get('/dashboard/helpdesk')->has($ticket['id']));
check('IT queue does not see the HR-routed ticket', !$it->last->has($hrTicket['id']));
check('Finance (other queue) redirected away from IT ticket', $finance->get('/dashboard/helpdesk/' . $ticket['id'])->location === BASE . '/dashboard/helpdesk');
check('invalid id → 404', $hr->get('/dashboard/helpdesk/..%2f..%2fetc')->status === 404);
$payroll = Client::as('payroll@meridianworks.com');
check('Payroll admin has no Helpdesk module (as original)', $payroll->get('/dashboard/helpdesk')->location === BASE . '/dashboard');

section('Helpdesk — comments and status');
$employee->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'comment', '_form' => 'comment', 'message' => 'Any update? <b>bold</b>']);
check('raiser comments; assignee notified', (int)qv('SELECT COUNT(*) FROM TicketComment WHERE ticketId = ?', [$ticket['id']]) === 1 && $notes($itUser, '/dashboard/helpdesk/' . $ticket['id']) === 2);
check('comment escaped in conversation', $it->get('/dashboard/helpdesk/' . $ticket['id'])->has('Any update? &lt;b&gt;bold&lt;/b&gt;'));
$it->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'comment', '_form' => 'comment', 'message' => 'Replacing the panel.']);
check('assignee replies; raiser notified', (int)qv('SELECT COUNT(*) FROM TicketComment WHERE ticketId = ?', [$ticket['id']]) === 2 && $notes($empUser, '/dashboard/helpdesk/' . $ticket['id']) === 1);
$employee->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'comment', '_form' => 'comment', 'message' => '   ']);
check('blank reply ignored', (int)qv('SELECT COUNT(*) FROM TicketComment WHERE ticketId = ?', [$ticket['id']]) === 2);
$r = $peer->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'comment', '_form' => 'comment', 'message' => 'sneaky'], '/dashboard/helpdesk');
check("colleague can't comment", (int)qv('SELECT COUNT(*) FROM TicketComment WHERE ticketId = ?', [$ticket['id']]) === 2);
$r = $nw->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'comment', '_form' => 'comment', 'message' => 'cross-tenant'], '/dashboard/helpdesk');
check("other tenant can't comment (404)", $r->status === 404 && (int)qv('SELECT COUNT(*) FROM TicketComment WHERE ticketId = ?', [$ticket['id']]) === 2);
$r = $employee->submit('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'status', 'status' => 'CLOSED']);
check("raiser can't change status", $r->has('not authorized to update tickets') && qv('SELECT status FROM Ticket WHERE id = ?', [$ticket['id']]) === 'OPEN');
check('assignee sees the status control', $it->get('/dashboard/helpdesk/' . $ticket['id'])->has('data-autosubmit'));
$it->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'status', 'status' => 'IN_PROGRESS']);
check('assignee moves to IN_PROGRESS (no resolvedAt)', ($t = q1('SELECT * FROM Ticket WHERE id = ?', [$ticket['id']]))['status'] === 'IN_PROGRESS' && $t['resolvedAt'] === null);
$it->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'status', 'status' => 'BOGUS']);
check('invalid status rejected', qv('SELECT status FROM Ticket WHERE id = ?', [$ticket['id']]) === 'IN_PROGRESS');
$it->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'status', 'status' => 'RESOLVED']);
check('RESOLVED sets resolvedAt', ($t = q1('SELECT * FROM Ticket WHERE id = ?', [$ticket['id']]))['status'] === 'RESOLVED' && $t['resolvedAt'] !== null);
check('audit STATUS_CHANGE', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'STATUS_CHANGE'", [$ticket['id']]) === 2);
$hr->post('/dashboard/helpdesk/' . $ticket['id'], ['_action' => 'status', 'status' => 'OPEN']);
check('HR (managerial) can reopen; resolvedAt cleared', ($t = q1('SELECT * FROM Ticket WHERE id = ?', [$ticket['id']]))['status'] === 'OPEN' && $t['resolvedAt'] === null);

// ───────────────────────────── Exit ─────────────────────────────
section('Exit — resignation');
check('employee has no Exit queue', $employee->get('/dashboard/exit')->location === BASE . '/dashboard');
check('employee can open the resignation form', $employee->get('/dashboard/exit/new')->status === 200 && $employee->last->has('Submit resignation'));
check('employee ?employeeId= ignored (own form)', !$employee->get('/dashboard/exit/new?employeeId=' . $peerEmp)->has(q1('SELECT firstName FROM Employee WHERE id = ?', [$peerEmp])['firstName'] . ' '));
$r = $employee->submit('/dashboard/exit/new', ['_action' => 'resign', '_form' => 'resign', 'employeeId' => $peerEmp, 'resignationDate' => '2026-10-01', 'reason' => 'x']);
check("employee can't resign for a colleague", $r->has('only initiate a resignation for yourself') && !qv('SELECT 1 FROM EmployeeExit WHERE employeeId = ?', [$peerEmp]));
$r = $employee->submit('/dashboard/exit/new', ['_action' => 'resign', '_form' => 'resign', 'employeeId' => $empId, 'resignationDate' => '2026-10-01', 'reason' => '   ']);
check('reason required', $r->has('required') && !qv('SELECT 1 FROM EmployeeExit WHERE employeeId = ?', [$empId]));
$r = $employee->post('/dashboard/exit/new', ['_action' => 'resign', '_form' => 'resign', 'employeeId' => $empId, 'resignationDate' => '2026-10-01', 'reason' => 'Higher studies <abroad>']);
$exit = q1('SELECT * FROM EmployeeExit WHERE employeeId = ?', [$empId]);
check('resignation created; redirected to its page', $exit !== null && str_ends_with($r->location, '/dashboard/exit/' . $exit['id']));
check('status PENDING, date midnight, notice from policy', $exit['status'] === 'PENDING' && $exit['resignationDate'] === '2026-10-01 00:00:00.000'
    && (int)$exit['noticePeriodDays'] === (int)(qv('SELECT noticePeriodDays FROM CompanyPolicy WHERE companyId = ?', [$MW]) ?? 30));
check('manager notified', $notes($mgrUser, '/dashboard/exit/' . $exit['id']) === 1);
$own = $employee->get('/dashboard/exit/' . $exit['id']);
check('owner sees "Your resignation", reason escaped, no staff controls', $own->status === 200 && $own->has('Your resignation') && $own->has('Higher studies &lt;abroad&gt;') && !$own->has('value="manager"'));
$r = $employee->submit('/dashboard/exit/new', ['_action' => 'resign', '_form' => 'resign', 'employeeId' => $empId, 'resignationDate' => '2026-10-02', 'reason' => 'again']);
check('second open exit rejected', $r->has('already has an exit in progress') && (int)qv('SELECT COUNT(*) FROM EmployeeExit WHERE employeeId = ?', [$empId]) === 1);

section('Exit — visibility');
check('colleague redirected to dashboard', $peer->get('/dashboard/exit/' . $exit['id'])->location === BASE . '/dashboard');
check('other tenant 404', $nw->get('/dashboard/exit/' . $exit['id'])->status === 404);
check('other tenant list does not show it', !$nw->get('/dashboard/exit')->has($exit['id']));
check('manager list shows the report’s exit', $manager->get('/dashboard/exit')->has($exit['id']));
check('IT list empty before clearance starts', !$it->get('/dashboard/exit')->has($exit['id']));
check('IT detail redirected before clearance starts', $it->get('/dashboard/exit/' . $exit['id'])->location === BASE . '/dashboard/exit');

section('Exit — manager and HR sign-off');
$r = $hr->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'hr', '_form' => 'exit', 'lastWorkingDate' => '2026-10-31']);
check('HR sign-off blocked before manager approval', $r->has('Manager approval is required') && qv('SELECT status FROM EmployeeExit WHERE id = ?', [$exit['id']]) === 'PENDING');
$r = $finance->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'generate', '_form' => 'exit'], '/dashboard/exit');
check('settlement blocked before clearance', !qv('SELECT 1 FROM FullFinalSettlement WHERE exitId = ?', [$exit['id']]));
$r = $peer->post('/dashboard/exit/' . $exit['id'], ['_action' => 'manager', '_form' => 'exit', 'approve' => '1'], '/dashboard');
check("colleague can't approve", qv('SELECT status FROM EmployeeExit WHERE id = ?', [$exit['id']]) === 'PENDING');
check('manager sees review buttons', $manager->get('/dashboard/exit/' . $exit['id'])->has('value="manager"'));
$manager->post('/dashboard/exit/' . $exit['id'], ['_action' => 'manager', '_form' => 'exit', 'approve' => '1']);
check('manager approves → MANAGER_APPROVED', ($x = q1('SELECT * FROM EmployeeExit WHERE id = ?', [$exit['id']]))['status'] === 'MANAGER_APPROVED' && $x['managerApproverId'] === emp_id('manager@meridianworks.com'));
$r = $manager->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'manager', '_form' => 'exit', 'approve' => '0']);
check('second manager decision rejected', $r->has('already moved past manager review'));
$r = $manager->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'hr', '_form' => 'exit', 'lastWorkingDate' => '2026-10-31']);
check("manager can't do HR sign-off", $r->has('Only HR or the Company Admin'));
check('HR sees sign-off form', $hr->get('/dashboard/exit/' . $exit['id'])->has('Approve &amp; start clearance'));
$r = $hr->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'hr', '_form' => 'exit', 'lastWorkingDate' => 'not-a-date']);
check('invalid last working date rejected', qv('SELECT status FROM EmployeeExit WHERE id = ?', [$exit['id']]) === 'MANAGER_APPROVED');
$hr->post('/dashboard/exit/' . $exit['id'], ['_action' => 'hr', '_form' => 'exit', 'lastWorkingDate' => '2026-10-31']);
$x = q1('SELECT * FROM EmployeeExit WHERE id = ?', [$exit['id']]);
check('HR sign-off → CLEARANCE with LWD', $x['status'] === 'CLEARANCE' && $x['lastWorkingDate'] === '2026-10-31 00:00:00.000');
check('employee moved to NOTICE_PERIOD', qv('SELECT status FROM Employee WHERE id = ?', [$empId]) === 'NOTICE_PERIOD');
check('five pending clearances', (int)qv("SELECT COUNT(*) FROM ExitClearance WHERE exitId = ? AND status = 'PENDING'", [$exit['id']]) === 5);
check('IT and Finance notified', $notes($itUser, '/dashboard/exit/' . $exit['id']) === 1
    && $notes((string)qv("SELECT id FROM `User` WHERE email = 'finance@meridianworks.com'"), '/dashboard/exit/' . $exit['id']) === 1);
check('IT list shows it now', $it->get('/dashboard/exit')->has($exit['id']) && $it->last->has('0/5 cleared'));

section('Exit — department clearance');
$cl = static fn(string $d) => (string)qv('SELECT id FROM ExitClearance WHERE exitId = ? AND department = ?', [$exit['id'], $d]);
$r = $it->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('FINANCE')]);
check("IT can't clear FINANCE", $r->has('not authorized to clear this department'));
$r = $employee->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('IT')]);
check("employee can't clear their own exit", $r->has('not authorized to clear') && qv('SELECT status FROM ExitClearance WHERE id = ?', [$cl('IT')]) === 'PENDING');
$nwExitClear = $nw->post('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('HR')], '/dashboard/exit');
check('other tenant cannot clear (404)', $nwExitClear->status === 404);
$it->post('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('IT'), 'remarks' => 'Laptop wiped <ok>']);
check('IT clears IT with remarks', ($c = q1('SELECT * FROM ExitClearance WHERE id = ?', [$cl('IT')]))['status'] === 'CLEARED' && $c['clearedById'] === $itEmp && $c['remarks'] === 'Laptop wiped <ok>');
check('remarks escaped', $hr->get('/dashboard/exit/' . $exit['id'])->has('Laptop wiped &lt;ok&gt;'));
// ASSET clearance needs every assignment returned (FR-101).
q("INSERT INTO Asset (id, companyId, name, category, serialNumber, status) VALUES ('ctestasset00000000000001', ?, 'Test Phone', 'Phone', 'SN-P12', 'ASSIGNED')", [$MW]);
q("INSERT INTO AssetAssignment (id, assetId, employeeId) VALUES ('ctestassign0000000000001', 'ctestasset00000000000001', ?)", [$empId]);
$r = $it->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('ASSET')]);
check('ASSET clearance blocked while assets are out', $r->has('unreturned assets') && qv('SELECT status FROM ExitClearance WHERE id = ?', [$cl('ASSET')]) === 'PENDING');
q('UPDATE AssetAssignment SET returnedDate = NOW(3) WHERE employeeId = ? AND returnedDate IS NULL', [$empId]);
$it->post('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('ASSET')]);
check('ASSET cleared once returned', qv('SELECT status FROM ExitClearance WHERE id = ?', [$cl('ASSET')]) === 'CLEARED');
$manager->post('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('MANAGER')]);
check("employee's manager clears MANAGER", qv('SELECT status FROM ExitClearance WHERE id = ?', [$cl('MANAGER')]) === 'CLEARED');
$hr->post('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('HR')]);
check('HR clears HR', qv('SELECT status FROM ExitClearance WHERE id = ?', [$cl('HR')]) === 'CLEARED');
check('generate button hidden until all cleared', !$finance->get('/dashboard/exit/' . $exit['id'])->has('value="generate"'));
$r = $finance->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'generate', '_form' => 'exit']);
check('generate blocked with FINANCE pending', $r->has('All department clearances must be complete'));
$finance->post('/dashboard/exit/' . $exit['id'], ['_action' => 'clear', '_form' => 'exit', 'clearanceId' => $cl('FINANCE')]);
check('Finance clears FINANCE; all cleared', (int)qv("SELECT COUNT(*) FROM ExitClearance WHERE exitId = ? AND status = 'CLEARED'", [$exit['id']]) === 5);
check('audit CLEAR ×5', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityType = 'ExitClearance' AND action = 'CLEAR'") === 5);

section('Exit — Full & Final');
check('Finance sees generate button', $finance->get('/dashboard/exit/' . $exit['id'])->has('value="generate"'));
$r = $manager->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'generate', '_form' => 'exit']);
check("manager can't generate", $r->has('not authorized to generate'));
$finance->post('/dashboard/exit/' . $exit['id'], ['_action' => 'generate', '_form' => 'exit']);
$s = q1('SELECT * FROM FullFinalSettlement WHERE exitId = ?', [$exit['id']]);
$sal = q1('SELECT basic + hra + conveyance + medicalAllowance + specialAllowance AS g FROM SalaryStructure WHERE employeeId = ?', [$empId]);
$perDay = ($sal['g'] ?? 0) / 30;
$unused = (float)qv('SELECT COALESCE(SUM(GREATEST(0, allocated - used)), 0) FROM LeaveBalance WHERE employeeId = ? AND year = 2026', [$empId]);
$expenses = (float)qv("SELECT COALESCE(SUM(amount), 0) FROM ExpenseClaim WHERE employeeId = ? AND status = 'APPROVED'", [$empId]);
check('settlement generated (DRAFT), exit SETTLED', $s !== null && $s['status'] === 'DRAFT' && qv('SELECT status FROM EmployeeExit WHERE id = ?', [$exit['id']]) === 'SETTLED');
check('leave encashment = round(unused × gross/30)', (float)$s['leaveEncashment'] === floor($unused * $perDay + 0.5), "$unused × $perDay vs {$s['leaveEncashment']}");
check('pending expenses = approved claims', abs((float)$s['pendingExpenses'] - $expenses) < 0.001);
check('net = round(enc + expenses − LOP)', (float)$s['netPayable'] === floor((float)$s['leaveEncashment'] + $expenses - (float)$s['lopDeduction'] + 0.5));
$r = $finance->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'generate', '_form' => 'exit']);
check('second generate rejected', $r->has('All department clearances') || $r->has('already been generated'));
check('settlement shown to the exiting employee', $employee->get('/dashboard/exit/' . $exit['id'])->has('Net payable') && !$employee->last->has('value="approve_settlement"'));
$r = $it->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'approve_settlement', '_form' => 'exit']);
check("IT can't approve the settlement", $r->has('not authorized to approve settlements'));
$letters = static fn() => (int)qv("SELECT COUNT(*) FROM Document WHERE employeeId = ? AND type IN ('RELIEVING_LETTER', 'EXPERIENCE_LETTER')", [$empId]);
$before = $letters();
$finance->post('/dashboard/exit/' . $exit['id'], ['_action' => 'approve_settlement', '_form' => 'exit']);
check('settlement APPROVED; exit COMPLETED', qv('SELECT status FROM FullFinalSettlement WHERE exitId = ?', [$exit['id']]) === 'APPROVED' && qv('SELECT status FROM EmployeeExit WHERE id = ?', [$exit['id']]) === 'COMPLETED');
$e = q1('SELECT status, dateOfExit FROM Employee WHERE id = ?', [$empId]);
check('employee EXITED with dateOfExit = LWD', $e['status'] === 'EXITED' && $e['dateOfExit'] === '2026-10-31 00:00:00.000');
check('login disabled', (int)qv('SELECT isActive FROM `User` WHERE id = ?', [$empUser]) === 0);
check('existing session ends on next request', $employee->get('/dashboard')->status === 303);
$blocked = false;
try { Client::as('employee@meridianworks.com'); } catch (RuntimeException) { $blocked = true; }
check('exited employee cannot log in', $blocked);
check('relieving + experience letters generated', $letters() === $before + 2);
$r = $finance->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'approve_settlement', '_form' => 'exit']);
check('second approval rejected', $r->has('already been approved') || $r->has('No settlement'));
check('Letters note shown when COMPLETED', $hr->get('/dashboard/exit/' . $exit['id'])->has('Relieving and experience letters were generated'));
check('employee can resign again only after completion (no open exit)', !qv("SELECT 1 FROM EmployeeExit WHERE employeeId = ? AND status NOT IN ('COMPLETED','REJECTED')", [$empId]));

section('Exit — interview notes');
$r = $finance->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'interview', '_form' => 'exit', 'notes' => 'x']);
check("Finance can't record interview notes", $r->has('Only HR or the Company Admin') && qv('SELECT exitInterviewNotes FROM EmployeeExit WHERE id = ?', [$exit['id']]) === null);
$r = $hr->submit('/dashboard/exit/' . $exit['id'], ['_action' => 'interview', '_form' => 'interview', 'notes' => "Good team\r\n<script>bad</script>"]);
check('HR saves notes (normalised, escaped)', qv('SELECT exitInterviewNotes FROM EmployeeExit WHERE id = ?', [$exit['id']]) === "Good team\n<script>bad</script>"
    && $r->has('Notes saved.') && $r->has('&lt;script&gt;bad&lt;/script&gt;'));

section('Exit — HR-initiated + rejection');
check('HR form honours ?employeeId=', $hr->get('/dashboard/exit/new?employeeId=' . $peerEmp)->has('value="' . $peerEmp . '"'));
check('HR ?employeeId= for another tenant falls back to exit list', $hr->get('/dashboard/exit/new?employeeId=' . $nwEmp)->location === BASE . '/dashboard/exit');
$r = $hr->post('/dashboard/exit/new', ['_action' => 'resign', '_form' => 'resign', 'employeeId' => $peerEmp, 'resignationDate' => '', 'reason' => 'Relocation']);
$exit2 = q1('SELECT * FROM EmployeeExit WHERE employeeId = ?', [$peerEmp]);
check('HR raises a resignation for an employee (date defaults to today)', $exit2 !== null && $exit2['requestedById'] === emp_id('hr@meridianworks.com')
    && str_starts_with($exit2['resignationDate'], (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d')));
check('peer (owner) can follow it', $peer->get('/dashboard/exit/' . $exit2['id'])->has('Your resignation'));
$r = $hr->submit('/dashboard/exit/new', ['_action' => 'resign', '_form' => 'resign', 'employeeId' => $nwEmp, 'resignationDate' => '2026-10-01', 'reason' => 'x']);
check("HR can't raise for another tenant's employee", $r->has('Employee not found'));
$hr->post('/dashboard/exit/' . $exit2['id'], ['_action' => 'manager', '_form' => 'exit', 'approve' => '0']);
check('HR (company-wide) rejects → REJECTED', qv('SELECT status FROM EmployeeExit WHERE id = ?', [$exit2['id']]) === 'REJECTED');
$peer->post('/dashboard/exit/new', ['_action' => 'resign', '_form' => 'resign', 'employeeId' => $peerEmp, 'resignationDate' => '2026-11-01', 'reason' => 'Changed my mind again']);
check('a new resignation is allowed after rejection', (int)qv('SELECT COUNT(*) FROM EmployeeExit WHERE employeeId = ?', [$peerEmp]) === 2);
check('audit trail for exits', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityType = 'EmployeeExit' AND companyId = ?", [$MW]) >= 6);
check('no exits leaked into Northwind', !qv('SELECT 1 FROM EmployeeExit WHERE employeeId = ?', [$nwEmp]));

finish();
