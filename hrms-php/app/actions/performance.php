<?php
/** Performance — port of lib/actions/performance.ts. */
declare(strict_types=1);

function action_create_review_cycle(array $auth, string $name, string $startRaw, string $endRaw): void
{
    if (!is_company_admin($auth)) {
        throw new UserError('Only HR or the Company Admin can create review cycles.');
    }
    $companyId = require_company($auth);
    $name = v_required(trim($name), 'Cycle name');
    $start = v_date($startRaw, 'start date');
    $end = v_date($endRaw, 'end date');
    if ($end < $start) {
        throw new UserError('The end date must be on or after the start date.');
    }
    db_tx(function () use ($auth, $companyId, $name, $start, $end) {
        $id = db_insert('ReviewCycle', ['companyId' => $companyId, 'name' => $name, 'startDate' => $start, 'endDate' => $end, 'status' => 'ACTIVE']);
        audit($auth, 'CREATE', 'ReviewCycle', $id);
    });
}

function find_company_cycle(array $auth, string $cycleId): array
{
    $cycle = is_valid_id($cycleId) ? db_one('SELECT * FROM ReviewCycle WHERE id = ? AND companyId = ?', [$cycleId, $auth['companyId'] ?? '']) : null;
    if ($cycle === null) {
        throw new UserError('Review cycle not found.');
    }
    return $cycle;
}

function action_add_goal(array $auth, array $in): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $cycle = find_company_cycle($auth, (string)($in['cycleId'] ?? ''));
    $weight = trim((string)($in['weight'] ?? ''));
    $data = [
        'employeeId' => $auth['employeeId'],
        'cycleId' => $cycle['id'],
        'title' => v_required(trim((string)($in['title'] ?? '')), 'Goal title'),
        'description' => v_max(trim((string)($in['description'] ?? '')), 'Description', 5000),
        'weight' => $weight === '' ? 0 : v_int($weight, 'Weight', 0, 100),
    ];
    db_tx(function () use ($auth, $data) {
        $id = db_insert('Goal', $data);
        audit($auth, 'CREATE', 'Goal', $id);
    });
}

function v_rating(string $raw, string $message): int
{
    if (!preg_match('/^[1-5]$/', trim($raw))) {
        throw new UserError($message);
    }
    return (int)$raw;
}

/**
 * Self-appraisal (BRD FR-081): stored beside — never overwriting — the
 * manager's rating. Without an existing review row, one is created with the
 * resolved manager as reviewer.
 */
function action_submit_self_appraisal(array $auth, string $cycleId, string $ratingRaw, string $comments): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $rating = v_rating($ratingRaw, 'Rate yourself from 1 to 5.');
    $comments = v_max(trim($comments), 'Comments', 5000);
    $cycle = find_company_cycle($auth, $cycleId);
    db_tx(function () use ($auth, $cycle, $rating, $comments) {
        $existing = db_value('SELECT id FROM PerformanceReview WHERE employeeId = ? AND cycleId = ? FOR UPDATE', [$auth['employeeId'], $cycle['id']]);
        if ($existing) {
            db_update('PerformanceReview', ['selfRating' => $rating, 'selfComments' => $comments, 'selfSubmittedAt' => now()], 'id = ?', [$existing]);
        } else {
            $reviewer = resolve_approver($auth['employeeId']) ?? throw new UserError('No manager on record to route this to — contact HR.');
            db_insert('PerformanceReview', ['employeeId' => $auth['employeeId'], 'cycleId' => $cycle['id'], 'reviewerId' => $reviewer['id'],
                'selfRating' => $rating, 'selfComments' => $comments, 'selfSubmittedAt' => now()]);
        }
        audit($auth, 'SELF_APPRAISAL', 'PerformanceReview', $auth['employeeId'], ['cycleId' => $cycle['id'], 'selfRating' => $rating]);
    });
}

/** Manager review — the direct manager (or HR/Admin) only, same single-reviewer rule as approvals. */
function action_submit_review(array $auth, string $employeeId, string $cycleId, string $ratingRaw, string $comments): void
{
    $employee = db_one('SELECT id, managerId, userId FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $auth['companyId'] ?? '']);
    if ($employee === null) {
        throw new UserError('Employee not found.');
    }
    assert_can_act_on($auth, $employeeId, $employee['managerId']);
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $rating = v_rating($ratingRaw, 'Rate from 1 to 5.');
    $comments = v_max(trim($comments), 'Comments', 5000);
    $cycle = find_company_cycle($auth, $cycleId);
    db_tx(function () use ($auth, $employee, $cycle, $rating, $comments) {
        $existing = db_value('SELECT id FROM PerformanceReview WHERE employeeId = ? AND cycleId = ? FOR UPDATE', [$employee['id'], $cycle['id']]);
        $data = ['rating' => $rating, 'comments' => $comments, 'status' => 'APPROVED', 'reviewerId' => $auth['employeeId']];
        if ($existing) {
            db_update('PerformanceReview', $data, 'id = ?', [$existing]);
            $id = $existing;
        } else {
            $id = db_insert('PerformanceReview', ['employeeId' => $employee['id'], 'cycleId' => $cycle['id']] + $data);
        }
        if ($employee['userId']) {
            notify($employee['userId'], 'Performance review submitted', "Your manager submitted a review with a rating of $rating/5.", '/dashboard/performance');
        }
        audit($auth, 'SUBMIT_REVIEW', 'PerformanceReview', $id);
    });
}
