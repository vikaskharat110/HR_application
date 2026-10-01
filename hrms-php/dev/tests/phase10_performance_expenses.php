<?php
/**
 * Phase 10 — Performance + Expenses. Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase10_performance_expenses.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$admin = Client::as('admin@meridianworks.com');
$hr = Client::as('hr@meridianworks.com');
$manager = Client::as('manager@meridianworks.com');
$employee = Client::as('employee@meridianworks.com');
$finance = Client::as('finance@meridianworks.com');
$nw = Client::as('admin@northwindretail.com');
$empId = emp_id('employee@meridianworks.com');
$mgrId = emp_id('manager@meridianworks.com');

section('Review cycles & goals');
$manager->submit('/dashboard/performance', ['_action' => 'cycle', '_form' => 'cycle', 'name' => 'X', 'startDate' => '2027-01-01', 'endDate' => '2027-06-30']);
check('manager cannot create cycles', (int)qv("SELECT COUNT(*) FROM ReviewCycle WHERE name = 'X'") === 0);
$r = $hr->submit('/dashboard/performance', ['_action' => 'cycle', '_form' => 'cycle', 'name' => 'Bad', 'startDate' => '2027-06-30', 'endDate' => '2027-01-01']);
check('end before start refused', $r->has('end date must be on or after'));
$hr->submit('/dashboard/performance', ['_action' => 'cycle', '_form' => 'cycle', 'name' => 'H1 2027 <Review>', 'startDate' => '2027-01-01', 'endDate' => '2027-06-30']);
$cycle = q1("SELECT * FROM ReviewCycle WHERE companyId = ? AND name = 'H1 2027 <Review>'", [$MW]);
check('HR creates an ACTIVE cycle + audit', $cycle !== null && $cycle['status'] === 'ACTIVE' && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ?", [$cycle['id']]) === 1);
q("UPDATE ReviewCycle SET status = 'CLOSED' WHERE companyId = ? AND id <> ?", [$MW, $cycle['id']]);
$page = $employee->get('/dashboard/performance');
check('active cycle shown (escaped) with goal + self-appraisal forms', $page->has('Add a goal — H1 2027 &lt;Review&gt;') && $page->has('Self-appraisal — H1 2027'));
$employee->submit('/dashboard/performance', ['_action' => 'goal', '_form' => 'goal', 'cycleId' => $cycle['id'], 'title' => 'Ship v2', 'description' => 'Deliver', 'weight' => '40']);
check('employee adds a goal', (int)qv('SELECT weight FROM Goal WHERE employeeId = ? AND title = ?', [$empId, 'Ship v2']) === 40);
$r = $employee->submit('/dashboard/performance', ['_action' => 'goal', '_form' => 'goal', 'cycleId' => $cycle['id'], 'title' => 'Too heavy', 'weight' => '150']);
check('weight over 100 refused', $r->has('out of range'));
$nwCycle = 'cnwcycle0000000000000001';
q("INSERT INTO ReviewCycle (id, companyId, name, startDate, endDate, status) VALUES (?, ?, 'NW cycle', NOW(), NOW(), 'ACTIVE')", [$nwCycle, company_id('Northwind Retail')]);
$r = $employee->submit('/dashboard/performance', ['_action' => 'goal', '_form' => 'goal', 'cycleId' => $nwCycle, 'title' => 'Foreign', 'weight' => '10']);
check("another company's cycle refused", $r->has('Review cycle not found.') && (int)qv("SELECT COUNT(*) FROM Goal WHERE title = 'Foreign'") === 0);

section('Self-appraisal & manager review');
$r = $employee->submit('/dashboard/performance', ['_action' => 'self', '_form' => 'self', 'cycleId' => $cycle['id'], 'selfRating' => '7', 'selfComments' => 'x']);
check('self-rating outside 1–5 refused', $r->has('Rate yourself from 1 to 5.'));
$employee->submit('/dashboard/performance', ['_action' => 'self', '_form' => 'self', 'cycleId' => $cycle['id'], 'selfRating' => '4', 'selfComments' => 'Good half']);
$rev = q1('SELECT * FROM PerformanceReview WHERE employeeId = ? AND cycleId = ?', [$empId, $cycle['id']]);
check('self-appraisal creates the review routed to the manager', $rev !== null && (int)$rev['selfRating'] === 4 && $rev['reviewerId'] === $mgrId && $rev['rating'] === null);
$mp = $manager->get('/dashboard/performance');
check('manager sees direct reports to rate', $mp->has('Rate your team') && $mp->has('Rohan Verma'));
$manager->submit('/dashboard/performance', ['_action' => 'review', '_form' => 'review', 'employeeId' => $empId, 'cycleId' => $cycle['id'], 'rating' => '5', 'comments' => 'Excellent']);
$rev = q1('SELECT * FROM PerformanceReview WHERE employeeId = ? AND cycleId = ?', [$empId, $cycle['id']]);
check('manager review saved APPROVED, self-rating untouched', (int)$rev['rating'] === 5 && $rev['status'] === 'APPROVED' && (int)$rev['selfRating'] === 4 && $rev['selfComments'] === 'Good half');
check('employee notified with the rating', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.message LIKE '%5/5%'", [$empId]) === 1);
$employee->submit('/dashboard/performance', ['_action' => 'self', '_form' => 'self', 'cycleId' => $cycle['id'], 'selfRating' => '3', 'selfComments' => 'Revised']);
$rev = q1('SELECT * FROM PerformanceReview WHERE employeeId = ? AND cycleId = ?', [$empId, $cycle['id']]);
check('re-submitting self-appraisal never overwrites the manager rating', (int)$rev['rating'] === 5 && (int)$rev['selfRating'] === 3);
$mine = $employee->get('/dashboard/performance');
check('My reviews shows both ratings', $mine->has('5/5') && $mine->has('Your self-rating: 3/5'));
$outside = emp_id('finance@meridianworks.com');
$manager->submit('/dashboard/performance', ['_action' => 'review', '_form' => 'review', 'employeeId' => $outside, 'cycleId' => $cycle['id'], 'rating' => '1', 'comments' => 'x']);
check('manager cannot review someone outside their reports', (int)qv('SELECT COUNT(*) FROM PerformanceReview WHERE employeeId = ? AND cycleId = ?', [$outside, $cycle['id']]) === 0);
$employee->post('/dashboard/performance', ['_action' => 'review', 'employeeId' => $mgrId, 'cycleId' => $cycle['id'], 'rating' => '1', 'comments' => 'x']);
check('employee cannot review their manager', (int)qv('SELECT COUNT(*) FROM PerformanceReview WHERE employeeId = ? AND cycleId = ?', [$mgrId, $cycle['id']]) === 0);
$manager->submit('/dashboard/performance', ['_action' => 'review', '_form' => 'review', 'employeeId' => $empId, 'cycleId' => $cycle['id'], 'rating' => '9', 'comments' => 'x']);
check('manager rating outside 1–5 refused', (int)qv('SELECT rating FROM PerformanceReview WHERE employeeId = ? AND cycleId = ?', [$empId, $cycle['id']]) === 5);
$hr->submit('/dashboard/performance', ['_action' => 'review', '_form' => 'review', 'employeeId' => $outside, 'cycleId' => $cycle['id'], 'rating' => '4', 'comments' => 'HR override']);
check('HR can review company-wide', (int)qv('SELECT rating FROM PerformanceReview WHERE employeeId = ? AND cycleId = ?', [$outside, $cycle['id']]) === 4);

section('Expenses');
$r = $employee->submit('/dashboard/expenses', ['_action' => 'submit', '_form' => 'claim', 'category' => 'Travel', 'amount' => '0', 'description' => 'x']);
check('zero amount refused', $r->has('Enter an amount greater than zero.'));
$r = $employee->submit('/dashboard/expenses', ['_action' => 'submit', '_form' => 'claim', 'category' => 'Yacht', 'amount' => '100', 'description' => 'x']);
check('unknown category refused', $r->has('Choose a valid category.'));
$employee->submit('/dashboard/expenses', ['_action' => 'submit', '_form' => 'claim', 'category' => 'Travel', 'amount' => '1234.5', 'description' => 'Cab <b>fare</b>']);
$claim = q1('SELECT * FROM ExpenseClaim WHERE employeeId = ? ORDER BY submittedAt DESC LIMIT 1', [$empId]);
check('claim stored PENDING, routed to manager', $claim !== null && (float)$claim['amount'] === 1234.5 && $claim['status'] === 'PENDING' && $claim['approverId'] === $mgrId);
check('manager notified with ₹ amount', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.message LIKE ?", [$mgrId, '%₹1,234.5%']) === 1);
check('claim listed for the employee', $employee->get('/dashboard/expenses')->has('₹1,234.50'));
$q = $manager->get('/dashboard/expenses');
check('manager queue shows the claim, description escaped', $q->has('Cab &lt;b&gt;fare&lt;/b&gt;'));
$employee->post('/dashboard/expenses', ['_action' => 'decide', 'id' => $claim['id'], 'approve' => '1']);
check('employee cannot approve own claim', qv('SELECT status FROM ExpenseClaim WHERE id = ?', [$claim['id']]) === 'PENDING');
$nw->post('/dashboard/expenses', ['_action' => 'decide', 'id' => $claim['id'], 'approve' => '1'], '/dashboard');
check('other tenant cannot decide', qv('SELECT status FROM ExpenseClaim WHERE id = ?', [$claim['id']]) === 'PENDING');
check('finance sees every pending claim in the company', $finance->get('/dashboard/expenses')->has('Cab &lt;b&gt;fare&lt;/b&gt;'));
$finance->submit('/dashboard/expenses', ['_action' => 'decide', 'id' => $claim['id'], 'approve' => '1']);
check('finance approves (company-wide override)', qv('SELECT status FROM ExpenseClaim WHERE id = ?', [$claim['id']]) === 'APPROVED' && qv('SELECT actedAt FROM ExpenseClaim WHERE id = ?', [$claim['id']]) !== null);
check('employee notified + audited', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Expense claim approved'", [$empId]) === 1
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'APPROVE'", [$claim['id']]) === 1);
$r = $manager->submit('/dashboard/expenses', ['_action' => 'decide', 'id' => $claim['id'], 'approve' => '0']);
check('cannot decide twice', qv('SELECT status FROM ExpenseClaim WHERE id = ?', [$claim['id']]) === 'APPROVED');
$finance->submit('/dashboard/expenses', ['_action' => 'submit', '_form' => 'claim', 'category' => 'Software', 'amount' => '500', 'description' => 'Own']);
$own = q1('SELECT * FROM ExpenseClaim WHERE employeeId = ? ORDER BY submittedAt DESC LIMIT 1', [emp_id('finance@meridianworks.com')]);
$r = $finance->submit('/dashboard/expenses', ['_action' => 'decide', 'id' => $own['id'], 'approve' => '1']);
check('finance cannot approve their own claim (original allowed it)', qv('SELECT status FROM ExpenseClaim WHERE id = ?', [$own['id']]) === 'PENDING' && $r->has('cannot approve or reject your own'));
$employee->submit('/dashboard/expenses', ['_action' => 'submit', '_form' => 'claim', 'category' => 'Meals', 'amount' => '300', 'description' => 'Lunch']);
$c2 = q1('SELECT * FROM ExpenseClaim WHERE employeeId = ? ORDER BY submittedAt DESC LIMIT 1', [$empId]);
$manager->submit('/dashboard/expenses', ['_action' => 'decide', 'id' => $c2['id'], 'approve' => '0']);
check('assigned manager rejects', qv('SELECT status FROM ExpenseClaim WHERE id = ?', [$c2['id']]) === 'REJECTED');
check('IT admin has no Expenses module', Client::as('it@meridianworks.com')->get('/dashboard/expenses')->status === 303);

finish();
