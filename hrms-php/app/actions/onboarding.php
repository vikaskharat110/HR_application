<?php
/** Onboarding checklists — port of lib/actions/onboarding.ts. */
declare(strict_types=1);

/** Creates an IN_PROGRESS checklist cloned from the company's task templates. Returns the Onboarding id. */
function create_onboarding_checklist(string $companyId, string $employeeId): string
{
    $onboardingId = db_insert('Onboarding', ['employeeId' => $employeeId]);
    foreach (db_all('SELECT category, title FROM OnboardingTaskTemplate WHERE companyId = ? ORDER BY sortOrder ASC, id ASC', [$companyId]) as $t) {
        db_insert('OnboardingTask', ['onboardingId' => $onboardingId, 'category' => $t['category'], 'title' => $t['title']]);
    }
    return $onboardingId;
}

/** Manual start for someone with no checklist yet (hired before templates existed, or not via recruitment). */
function action_start_onboarding(array $auth, string $employeeId): void
{
    $companyId = require_company($auth);
    $employee = db_one('SELECT id FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $companyId]);
    if ($employee === null) {
        throw new UserError('Employee not found.');
    }
    db_tx(function () use ($auth, $companyId, $employeeId) {
        if (db_value('SELECT 1 FROM Onboarding WHERE employeeId = ? FOR UPDATE', [$employeeId])) {
            throw new UserError('This employee already has an onboarding checklist.');
        }
        create_onboarding_checklist($companyId, $employeeId);
        $taskCount = (int)db_value('SELECT COUNT(*) FROM OnboardingTaskTemplate WHERE companyId = ?', [$companyId]);
        audit($auth, 'CREATE', 'Onboarding', $employeeId, ['manualStart' => true, 'taskCount' => $taskCount]);
    });
}

/** Who may tick a task depends on its category (BRD FR-032). */
function can_complete_onboarding_task(array $auth, string $category, ?string $employeeManagerId): bool
{
    if ($auth['role'] === 'COMPANY_ADMIN') {
        return true;
    }
    return match ($category) {
        'HR' => $auth['role'] === 'HR',
        'IT' => $auth['role'] === 'IT_ADMIN',
        'MANAGER' => $auth['role'] === 'HR' || ($auth['employeeId'] !== null && $auth['employeeId'] === $employeeManagerId),
        default => false,
    };
}

/** Marks a task done; completes the checklist (and notifies the employee) when it was the last one. */
function action_complete_onboarding_task(array $auth, string $taskId): void
{
    $task = db_one(
        'SELECT t.*, o.employeeId, e.managerId, e.userId AS employeeUserId FROM OnboardingTask t
           JOIN Onboarding o ON o.id = t.onboardingId JOIN Employee e ON e.id = o.employeeId
          WHERE t.id = ? AND e.companyId = ?',
        [$taskId, $auth['companyId'] ?? '']
    );
    if ($task === null) {
        throw new UserError('Onboarding task not found.');
    }
    if ($task['status'] === 'DONE') {
        return;
    }
    // Nobody signs off their own onboarding (HR's blanket rights included).
    if ($auth['employeeId'] === $task['employeeId']) {
        throw new UserError('You cannot complete your own onboarding tasks — ask your manager or HR/Admin.');
    }
    if (!can_complete_onboarding_task($auth, $task['category'], $task['managerId'])) {
        throw new UserError('You are not authorized to complete this onboarding task.');
    }
    db_tx(function () use ($auth, $task) {
        if (db_value('SELECT status FROM OnboardingTask WHERE id = ? FOR UPDATE', [$task['id']]) === 'DONE') {
            return;
        }
        db_update('OnboardingTask', ['status' => 'DONE', 'completedById' => $auth['employeeId'], 'completedAt' => now()], 'id = ?', [$task['id']]);
        $remaining = (int)db_value("SELECT COUNT(*) FROM OnboardingTask WHERE onboardingId = ? AND status <> 'DONE'", [$task['onboardingId']]);
        if ($remaining === 0) {
            db_update('Onboarding', ['status' => 'COMPLETED', 'completedAt' => now()], 'id = ?', [$task['onboardingId']]);
        }
        audit($auth, 'ONBOARDING_TASK_COMPLETED', 'OnboardingTask', $task['id'], ['title' => $task['title'], 'category' => $task['category']]);
        if ($remaining === 0 && $task['employeeUserId']) {
            notify($task['employeeUserId'], 'Onboarding complete', 'All your onboarding tasks have been completed — welcome aboard!', "/dashboard/employees/{$task['employeeId']}");
        }
    });
}
