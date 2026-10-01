<?php
/** /dashboard/onboarding/{id} — port of app/dashboard/onboarding/[id]/page.tsx */
declare(strict_types=1);

$onboarding = db_one(
    'SELECT o.*, e.firstName, e.lastName, e.avatarColor, e.designation, e.managerId FROM Onboarding o JOIN Employee e ON e.id = o.employeeId
      WHERE o.id = ? AND e.companyId = ?',
    [$params['id'], $auth['companyId']]
);
if ($onboarding === null) {
    throw new HttpError(404);
}
if (!has_role($auth, ['COMPANY_ADMIN', 'HR', 'IT_ADMIN']) && !can_see_employee($auth, $onboarding['employeeId'])) {
    redirect('/dashboard/onboarding');
}
handle_post([
    'complete' => function () use ($auth) {
        action_complete_onboarding_task($auth, post_id('taskId'));
        return null;
    },
]);
$tasks = db_all('SELECT * FROM OnboardingTask WHERE onboardingId = ? ORDER BY id', [$onboarding['id']]);
const CATEGORY_LABEL = ['HR' => 'HR', 'IT' => 'IT', 'MANAGER' => 'Manager'];

app_layout_open($auth, $onboarding['firstName'] . ' ' . $onboarding['lastName'] . ' — onboarding');
?>
<div class="max-w-3xl">
    <?= back_link('/dashboard/onboarding', 'Back to onboarding') ?>
    <?= page_header($onboarding['firstName'] . ' ' . $onboarding['lastName'], $onboarding['designation'] . ' — onboarding checklist', status_badge($onboarding['status'])) ?>
    <div class="card p-5 mb-5 flex items-center gap-3">
        <?= avatar($onboarding['firstName'], $onboarding['lastName'], $onboarding['avatarColor']) ?>
        <a href="<?= e(url('/dashboard/employees/' . $onboarding['employeeId'])) ?>" class="text-sm text-brand hover:underline">View employee profile</a>
    </div>
    <?php foreach (CATEGORY_LABEL as $category => $label):
        $group = array_values(array_filter($tasks, static fn($t) => $t['category'] === $category));
        if (!$group) {
            continue;
        }
        $canComplete = can_complete_onboarding_task($auth, $category, $onboarding['managerId'])
            && $auth['employeeId'] !== $onboarding['employeeId']; ?>
        <div class="card p-5 mb-5">
            <p class="text-sm font-semibold text-ink mb-3"><?= e($label) ?> tasks</p>
            <ul class="divide-y divide-border-soft">
                <?php foreach ($group as $t):
                    $done = $t['status'] === 'DONE'; ?>
                    <li class="py-2.5 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <?= $done ? icon('circle-check', 16, 'text-brand shrink-0') : icon('circle', 16, 'text-muted shrink-0') ?>
                            <div>
                                <p class="text-sm <?= $done ? 'text-muted line-through' : 'text-ink' ?>"><?= e($t['title']) ?></p>
                                <?php if ($t['completedAt']): ?><p class="text-xs text-muted">Completed <?= e(format_datetime($t['completedAt'])) ?></p><?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$done && $canComplete): ?>
                            <form method="post"><?= form_fields('complete') ?><input type="hidden" name="taskId" value="<?= e($t['id']) ?>">
                                <button class="btn btn-secondary !py-1 !px-2.5 text-xs">Mark done</button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</div>
<?php app_layout_close();
