<?php
/**
 * Phase 9 — Recruitment + Onboarding. Run after dev/tests/reset_test_db.sh:
 *   php hrms-php/dev/tests/phase9_recruitment_onboarding.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$MW = company_id('Meridian Works');
$admin = Client::as('admin@meridianworks.com');
$hr = Client::as('hr@meridianworks.com');
$recruiter = Client::as('recruiter@meridianworks.com');
$manager = Client::as('manager@meridianworks.com');
$employee = Client::as('employee@meridianworks.com');
$it = Client::as('it@meridianworks.com');
$nw = Client::as('admin@northwindretail.com');
$mgrId = emp_id('manager@meridianworks.com');

section('Job postings');
$r = $recruiter->post('/dashboard/recruitment/new', ['_action' => 'create', '_form' => 'job', 'title' => 'Data <i>Engineer</i>', 'department' => 'Engineering',
    'location' => 'Pune', 'openings' => '2', 'description' => "Line 1\r\nLine 2"]);
$jobId = basename($r->location);
$job = q1('SELECT * FROM JobPosting WHERE id = ?', [$jobId]);
check('recruiter creates a job → redirected to it', $job !== null && $job['companyId'] === $MW && (int)$job['openings'] === 2 && $job['status'] === 'OPEN');
check('posted by the recruiter, CREATE audited', $job['postedById'] === emp_id('recruiter@meridianworks.com')
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'CREATE'", [$jobId]) === 1);
check('description line endings normalised', $job['description'] === "Line 1\nLine 2");
$list = $recruiter->get('/dashboard/recruitment');
check('list shows the job, title escaped', $list->has('Data &lt;i&gt;Engineer&lt;/i&gt;'));
$bad = $recruiter->submit('/dashboard/recruitment/new', ['_action' => 'create', '_form' => 'job', 'title' => '', 'department' => 'x', 'location' => 'y', 'openings' => '0']);
check('missing title / zero openings refused', $bad->has('is required') || $bad->has('out of range'));
check('employee cannot open recruitment', $employee->get('/dashboard/recruitment')->status === 303);
check('manager cannot open recruitment', $manager->get('/dashboard/recruitment')->status === 303);
check('other tenant cannot open the job (404)', $nw->get("/dashboard/recruitment/$jobId")->status === 404);
$recruiter->submit("/dashboard/recruitment/$jobId", ['_action' => 'status', 'status' => 'ON_HOLD']);
check('job put on hold + audited', qv('SELECT status FROM JobPosting WHERE id = ?', [$jobId]) === 'ON_HOLD'
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'STATUS_CHANGE'", [$jobId]) === 1);
$recruiter->submit("/dashboard/recruitment/$jobId", ['_action' => 'status', 'status' => 'DELETED']);
check('invalid job status refused', qv('SELECT status FROM JobPosting WHERE id = ?', [$jobId]) === 'ON_HOLD');

section('Candidate pipeline');
$recruiter->submit("/dashboard/recruitment/$jobId", ['_action' => 'candidate', '_form' => 'candidate', 'name' => 'Meera Joshi', 'email' => 'meera@example.com', 'phone' => '99999', 'notes' => 'Strong SQL']);
$cand = q1('SELECT * FROM Candidate WHERE jobPostingId = ?', [$jobId]);
check('candidate added in APPLIED stage', $cand !== null && $cand['stage'] === 'APPLIED');
$bad = $recruiter->submit("/dashboard/recruitment/$jobId", ['_action' => 'candidate', '_form' => 'candidate', 'name' => 'X', 'email' => 'nope']);
check('invalid candidate email refused', $bad->has('Enter a valid Email address.'));
$recruiter->submit("/dashboard/recruitment/$jobId", ['_action' => 'stage', 'candidateId' => $cand['id'], 'stage' => 'INTERVIEW']);
check('stage changed + audited', qv('SELECT stage FROM Candidate WHERE id = ?', [$cand['id']]) === 'INTERVIEW'
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'STAGE_CHANGE'", [$cand['id']]) === 1);
$otherJob = (string)qv('SELECT id FROM JobPosting WHERE companyId = ? AND id <> ? LIMIT 1', [$MW, $jobId]);
$recruiter->submit("/dashboard/recruitment/$otherJob", ['_action' => 'stage', 'candidateId' => $cand['id'], 'stage' => 'REJECTED']);
check("can't move a candidate through another job's page", qv('SELECT stage FROM Candidate WHERE id = ?', [$cand['id']]) === 'INTERVIEW');
$nw->post("/dashboard/recruitment/$jobId", ['_action' => 'stage', 'candidateId' => $cand['id'], 'stage' => 'REJECTED'], '/dashboard');
check('other tenant cannot change the stage', qv('SELECT stage FROM Candidate WHERE id = ?', [$cand['id']]) === 'INTERVIEW');
$recruiter->submit("/dashboard/recruitment/$jobId", ['_action' => 'stage', 'candidateId' => $cand['id'], 'stage' => 'HIRED']);
$page = $recruiter->get("/dashboard/recruitment/$jobId");
check('recruiter sees "Awaiting HR to onboard" for a hire', $page->has('Awaiting HR to onboard') && !$page->has('Onboard as Employee'));
check('HR sees the Onboard as Employee button', $hr->get("/dashboard/recruitment/$jobId")->has('Onboard as Employee'));

section('Hire → employee → onboarding checklist');
$engDept = (string)qv("SELECT id FROM Department WHERE companyId = ? AND name = 'Engineering'", [$MW]);
$r = $hr->post('/dashboard/employees/new', ['_action' => 'create', '_form' => 'employee', 'candidateId' => $cand['id'], 'firstName' => 'Meera', 'lastName' => 'Joshi',
    'email' => 'meera.joshi@meridianworks.com', 'designation' => 'Data Engineer', 'departmentId' => $engDept, 'branchId' => '', 'managerId' => $mgrId,
    'employmentType' => 'FULL_TIME', 'dateOfJoining' => '2026-10-05', 'createLogin' => 'on', 'role' => 'EMPLOYEE']);
$newEmp = basename($r->location);
preg_match('/data-temp-password>([^<]+)</', $hr->get("/dashboard/employees/$newEmp")->body, $m);
$meeraPw = html_entity_decode($m[1] ?? '', ENT_QUOTES);
$ob = q1('SELECT * FROM Onboarding WHERE employeeId = ?', [$newEmp]);
check('checklist created IN_PROGRESS from the 8 templates', $ob !== null && $ob['status'] === 'IN_PROGRESS' && (int)qv('SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ?', [$ob['id']]) === 8);
check('job page now links to the employee', $recruiter->get("/dashboard/recruitment/$jobId")->has('View employee'));
$listHr = $hr->get('/dashboard/onboarding');
check('onboarding list shows progress 0/8', $listHr->has('Meera Joshi') && $listHr->has('0/8 tasks'));
check('manager of the hire sees it in their list', $manager->get('/dashboard/onboarding')->has('Meera Joshi'));
check('IT admin (company-wide) sees it', $it->get('/dashboard/onboarding')->has('Meera Joshi'));
check('employee cannot open onboarding', $employee->get('/dashboard/onboarding')->status === 303);
$task = static fn(string $cat) => (string)qv("SELECT id FROM OnboardingTask WHERE onboardingId = ? AND category = ? AND status = 'PENDING' ORDER BY id LIMIT 1", [$ob['id'], $cat]);

section('Task completion rules');
$it->submit("/dashboard/onboarding/{$ob['id']}", ['_action' => 'complete', 'taskId' => $task('HR')]);
check('IT cannot complete an HR task', (int)qv("SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ? AND status = 'DONE'", [$ob['id']]) === 0);
$t = $task('IT');
$it->submit("/dashboard/onboarding/{$ob['id']}", ['_action' => 'complete', 'taskId' => $t]);
check('IT completes an IT task (completedBy + time recorded)', q1('SELECT status, completedById, completedAt FROM OnboardingTask WHERE id = ?', [$t])['status'] === 'DONE'
    && qv('SELECT completedById FROM OnboardingTask WHERE id = ?', [$t]) === emp_id('it@meridianworks.com'));
check('completion audited', (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND action = 'ONBOARDING_TASK_COMPLETED'", [$t]) === 1);
$t = $task('MANAGER');
$manager->submit("/dashboard/onboarding/{$ob['id']}", ['_action' => 'complete', 'taskId' => $t]);
check("the hire's own manager completes a MANAGER task", qv('SELECT status FROM OnboardingTask WHERE id = ?', [$t]) === 'DONE');
$fin = Client::as('finance@meridianworks.com');
$fin->post("/dashboard/onboarding/{$ob['id']}", ['_action' => 'complete', 'taskId' => $task('MANAGER')], '/dashboard');
check('a non-onboarding role cannot complete tasks', (int)qv("SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ? AND status = 'DONE'", [$ob['id']]) === 2);
$meera = Client::as('meera.joshi@meridianworks.com', $meeraPw);
$meera->post("/dashboard/onboarding/{$ob['id']}", ['_action' => 'complete', 'taskId' => $task('HR')], '/dashboard');
check('the new hire cannot tick their own tasks', (int)qv("SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ? AND status = 'DONE'", [$ob['id']]) === 2);
$nw->post("/dashboard/onboarding/{$ob['id']}", ['_action' => 'complete', 'taskId' => $task('HR')], '/dashboard');
check('other tenant cannot complete tasks', (int)qv("SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ? AND status = 'DONE'", [$ob['id']]) === 2);
check('other tenant cannot open the checklist', $nw->get("/dashboard/onboarding/{$ob['id']}")->status === 404);
$hrEmp = emp_id('hr@meridianworks.com');
$hrOb = q1('SELECT o.id FROM Onboarding o WHERE o.employeeId = ?', [$hrEmp]);
if ($hrOb === null) {
    q("INSERT INTO Onboarding (id, employeeId) VALUES ('ctesthronboarding0000001', ?)", [$hrEmp]);
    q("INSERT INTO OnboardingTask (id, onboardingId, category, title) VALUES ('ctesthrtask0000000000001', 'ctesthronboarding0000001', 'HR', 'Self task')");
    $hrOb = ['id' => 'ctesthronboarding0000001'];
}
$r = $hr->submit("/dashboard/onboarding/{$hrOb['id']}", ['_action' => 'complete', 'taskId' => 'ctesthrtask0000000000001']);
check('HR cannot complete their own onboarding (blanket rights excluded)', qv("SELECT status FROM OnboardingTask WHERE id = 'ctesthrtask0000000000001'") === 'PENDING' && $r->has('cannot complete your own'));

section('Checklist completion');
$page = $hr->get("/dashboard/onboarding/{$ob['id']}");
$pendingHrMgr = (int)qv("SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ? AND status = 'PENDING' AND category IN ('HR','MANAGER')", [$ob['id']]);
check('HR sees Mark done exactly for pending HR + MANAGER tasks', substr_count($page->body, 'Mark done') === $pendingHrMgr, (string)substr_count($page->body, 'Mark done'));
foreach (q("SELECT id, category FROM OnboardingTask WHERE onboardingId = ? AND status = 'PENDING'", [$ob['id']]) as $row) {
    ($row['category'] === 'IT' ? $it : $hr)->submit("/dashboard/onboarding/{$ob['id']}", ['_action' => 'complete', 'taskId' => $row['id']]);
}
$ob2 = q1('SELECT * FROM Onboarding WHERE id = ?', [$ob['id']]);
check('last task completes the checklist', $ob2['status'] === 'COMPLETED' && $ob2['completedAt'] !== null);
check('the hire is notified', (int)qv("SELECT COUNT(*) FROM Notification n JOIN Employee e ON e.userId = n.userId WHERE e.id = ? AND n.title = 'Onboarding complete'", [$newEmp]) === 1);
check('list shows 8/8 COMPLETED', $hr->get('/dashboard/onboarding')->has('8/8 tasks'));

section('Manual start');
$noOb = (string)qv('SELECT e.id FROM Employee e LEFT JOIN Onboarding o ON o.employeeId = e.id WHERE e.companyId = ? AND o.id IS NULL AND e.status <> \'EXITED\' LIMIT 1', [$MW]);
$profile = $hr->get("/dashboard/employees/$noOb");
check('profile offers "Start onboarding checklist"', $profile->has('Start onboarding checklist'));
$hr->submit("/dashboard/employees/$noOb", ['_action' => 'start_onboarding']);
check('checklist started manually + audited', (int)qv('SELECT COUNT(*) FROM Onboarding WHERE employeeId = ?', [$noOb]) === 1
    && (int)qv("SELECT COUNT(*) FROM AuditLog WHERE entityId = ? AND entityType = 'Onboarding'", [$noOb]) === 1);
$r = $hr->submit("/dashboard/employees/$noOb", ['_action' => 'start_onboarding']);
check('cannot start twice', (int)qv('SELECT COUNT(*) FROM Onboarding WHERE employeeId = ?', [$noOb]) === 1);
q('DELETE t FROM OnboardingTask t JOIN Onboarding o ON o.id = t.onboardingId WHERE o.employeeId = ?', [$noOb]);
q('DELETE FROM Onboarding WHERE employeeId = ?', [$noOb]);
$manager->post("/dashboard/employees/$noOb", ['_action' => 'start_onboarding'], '/dashboard');
check('manager cannot start a checklist', (int)qv('SELECT COUNT(*) FROM Onboarding WHERE employeeId = ?', [$noOb]) === 0);

finish();
