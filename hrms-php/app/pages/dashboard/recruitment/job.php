<?php
/** /dashboard/recruitment/{jobId} — port of app/dashboard/recruitment/[jobId]/page.tsx */
declare(strict_types=1);

$jobId = $params['jobId'];
$job = db_one('SELECT * FROM JobPosting WHERE id = ? AND companyId = ?', [$jobId, $auth['companyId']]);
if ($job === null) {
    throw new HttpError(404);
}
handle_post([
    'status' => function () use ($auth, $jobId) {
        action_toggle_job_status($auth, $jobId, post('status'));
        return null;
    },
    'candidate' => function () use ($auth, $jobId) {
        action_add_candidate($auth, $jobId, $_POST);
        return null;
    },
    'stage' => function () use ($auth, $jobId) {
        action_update_candidate_stage($auth, post_id('candidateId'), $jobId, post('stage'));
        return null;
    },
]);

// Onboarding (creating the employee master record) stays with HR/Company Admin — BRD Section 10.
$canOnboard = is_company_admin($auth);
const PIPELINE_STAGES = ['APPLIED', 'SCREENING', 'INTERVIEW', 'OFFERED', 'HIRED', 'REJECTED'];
$candidates = db_all(
    'SELECT c.*, e.id AS employeeId FROM Candidate c LEFT JOIN Employee e ON e.candidateId = c.id WHERE c.jobPostingId = ? ORDER BY c.appliedAt DESC',
    [$jobId]
);

app_layout_open($auth, $job['title']);
echo back_link('/dashboard/recruitment', 'Back to recruitment');
$statusForm = '<form method="post" class="flex items-center gap-2">' . form_fields('status') . '<select name="status" class="input !w-auto text-sm">';
foreach (['OPEN' => 'Open', 'ON_HOLD' => 'On hold', 'CLOSED' => 'Closed'] as $v => $l) {
    $statusForm .= '<option value="' . $v . '"' . selected($v, $job['status']) . '>' . $l . '</option>';
}
$statusForm .= '</select><button class="btn btn-secondary text-sm">Update</button></form>';
echo page_header($job['title'], $job['department'] . ' · ' . $job['location'] . ' · ' . $job['openings'] . ' opening(s)', $statusForm);
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="card p-6">
        <p class="text-sm font-semibold text-ink mb-4">Add candidate</p>
        <form method="post" class="space-y-3">
            <?= form_fields('candidate', 'candidate') ?>
            <div><label class="field-label">Full name</label><input name="name" required maxlength="191" value="<?= e(old('name')) ?>" class="input"></div>
            <div><label class="field-label">Email</label><input name="email" type="email" required maxlength="191" value="<?= e(old('email')) ?>" class="input"></div>
            <div><label class="field-label">Phone</label><input name="phone" maxlength="50" value="<?= e(old('phone')) ?>" class="input"></div>
            <div><label class="field-label">Notes</label><textarea name="notes" rows="2" maxlength="5000" class="input"><?= e(old('notes')) ?></textarea></div>
            <?= flash_alerts('candidate') ?>
            <button type="submit" class="btn btn-primary w-full justify-center">Add to pipeline</button>
        </form>
        <?php if ($job['description']): ?>
            <div class="mt-5 pt-5 border-t border-border-soft">
                <p class="text-sm font-semibold text-ink mb-1.5">Description</p>
                <p class="text-sm text-ink-soft whitespace-pre-line"><?= e($job['description']) ?></p>
            </div>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2 card p-5">
        <p class="text-sm font-semibold text-ink mb-4">Candidates (<?= count($candidates) ?>)</p>
        <?php if (!$candidates): ?>
            <p class="text-sm text-muted py-6 text-center">No candidates yet.</p>
        <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($candidates as $c): ?>
                    <li class="rounded-lg border border-border p-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-ink"><?= e($c['name']) ?></p>
                            <div class="flex items-center gap-3 text-xs text-muted mt-1">
                                <span class="flex items-center gap-1"><?= icon('mail', 12) ?> <?= e($c['email']) ?></span>
                                <?php if ($c['phone']): ?><span class="flex items-center gap-1"><?= icon('phone', 12) ?> <?= e($c['phone']) ?></span><?php endif; ?>
                                <span>Applied <?= e(format_date($c['appliedAt'])) ?></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($c['stage'] === 'HIRED'): ?>
                                <?php if ($c['employeeId']): ?>
                                    <a href="<?= e(url('/dashboard/employees/' . $c['employeeId'])) ?>" class="text-xs font-medium text-brand hover:underline">View employee</a>
                                <?php elseif ($canOnboard): ?>
                                    <a href="<?= e(url('/dashboard/employees/new?candidateId=' . $c['id'])) ?>" class="btn btn-secondary !py-1.5 !px-2.5 text-xs"><?= icon('user-plus', 13) ?> Onboard as Employee</a>
                                <?php else: ?>
                                    <span class="text-xs text-muted">Awaiting HR to onboard</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?= status_badge($c['stage']) ?>
                            <form method="post"><?= form_fields('stage') ?><input type="hidden" name="candidateId" value="<?= e($c['id']) ?>">
                                <?= auto_submit_select('stage', $c['stage'], PIPELINE_STAGES) ?></form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php app_layout_close();
