<?php
/** Recruitment — port of lib/actions/recruitment.ts (Company Admin, HR, Recruiter). */
declare(strict_types=1);

const RECRUITMENT_ROLES = ['COMPANY_ADMIN', 'HR', 'RECRUITER'];
const JOB_STATUSES = ['OPEN', 'ON_HOLD', 'CLOSED'];
const CANDIDATE_STAGES = ['APPLIED', 'SCREENING', 'INTERVIEW', 'SELECTED', 'OFFERED', 'REJECTED', 'HIRED'];

function assert_recruiter(array $auth): string
{
    if (!has_role($auth, RECRUITMENT_ROLES)) {
        throw new UserError('You are not authorized to manage recruitment.');
    }
    return require_company($auth);
}

function assert_owns_job(string $companyId, string $jobId): array
{
    $job = db_one('SELECT * FROM JobPosting WHERE id = ? AND companyId = ?', [$jobId, $companyId]);
    if ($job === null) {
        throw new UserError('Job posting not found.');
    }
    return $job;
}

/** @return string redirect to the new job */
function action_create_job_posting(array $auth, array $in): string
{
    $companyId = assert_recruiter($auth);
    $openings = trim((string)($in['openings'] ?? ''));
    $data = [
        'companyId' => $companyId,
        'title' => v_required(trim((string)($in['title'] ?? '')), 'Job title'),
        'department' => v_required(trim((string)($in['department'] ?? '')), 'Department'),
        'location' => v_required(trim((string)($in['location'] ?? '')), 'Location'),
        'openings' => $openings === '' ? 1 : v_int($openings, 'Openings', 1, 10000),
        'description' => v_max(normalize_line_endings((string)($in['description'] ?? '')), 'Description', 20000),
        'postedById' => $auth['employeeId'],
    ];
    $id = db_tx(function () use ($auth, $data) {
        $id = db_insert('JobPosting', $data);
        audit($auth, 'CREATE', 'JobPosting', $id);
        return $id;
    });
    return "/dashboard/recruitment/$id";
}

function action_toggle_job_status(array $auth, string $jobId, string $status): void
{
    $companyId = assert_recruiter($auth);
    $status = v_enum($status, JOB_STATUSES, 'status');
    assert_owns_job($companyId, $jobId);
    db_tx(function () use ($auth, $jobId, $status) {
        db_update('JobPosting', ['status' => $status], 'id = ?', [$jobId]);
        audit($auth, 'STATUS_CHANGE', 'JobPosting', $jobId, ['status' => $status]);
    });
}

function action_add_candidate(array $auth, string $jobId, array $in): void
{
    $companyId = assert_recruiter($auth);
    assert_owns_job($companyId, $jobId);
    $data = [
        'jobPostingId' => $jobId,
        'name' => v_required(trim((string)($in['name'] ?? '')), 'Full name'),
        'email' => v_email((string)($in['email'] ?? '')),
        'phone' => v_max(trim((string)($in['phone'] ?? '')), 'Phone', 50),
        'notes' => v_max(normalize_line_endings((string)($in['notes'] ?? '')), 'Notes', 5000),
    ];
    db_tx(function () use ($auth, $data) {
        $id = db_insert('Candidate', $data);
        audit($auth, 'CREATE', 'Candidate', $id);
    });
}

function action_update_candidate_stage(array $auth, string $candidateId, string $jobId, string $stage): void
{
    $companyId = assert_recruiter($auth);
    $stage = v_enum($stage, CANDIDATE_STAGES, 'stage');
    assert_owns_job($companyId, $jobId);
    if (!db_value('SELECT 1 FROM Candidate WHERE id = ? AND jobPostingId = ?', [$candidateId, $jobId])) {
        throw new UserError('Candidate not found.');
    }
    db_tx(function () use ($auth, $candidateId, $stage) {
        db_update('Candidate', ['stage' => $stage], 'id = ?', [$candidateId]);
        audit($auth, 'STAGE_CHANGE', 'Candidate', $candidateId, ['stage' => $stage]);
    });
}
