<?php
/** /dashboard/performance — port of app/dashboard/performance/page.tsx */
declare(strict_types=1);

handle_post([
    'cycle' => function () use ($auth) {
        action_create_review_cycle($auth, post('name'), post('startDate'), post('endDate'));
        return null;
    },
    'goal' => function () use ($auth) {
        action_add_goal($auth, $_POST);
        return null;
    },
    'self' => function () use ($auth) {
        action_submit_self_appraisal($auth, post('cycleId'), post('selfRating'), post_raw('selfComments'));
        flash('success', 'Self-appraisal saved.', 'self');
        return null;
    },
    'review' => function () use ($auth) {
        action_submit_review($auth, post_id('employeeId'), post('cycleId'), post('rating'), post_raw('comments'));
        flash('success', 'Review saved.', 'review');
        return null;
    },
]);

$co = $auth['companyId'];
$canManageCycles = is_company_admin($auth);
$managerial = is_managerial($auth);
$cycles = db_all('SELECT * FROM ReviewCycle WHERE companyId = ? ORDER BY startDate DESC', [$co]);
$activeCycle = db_one("SELECT * FROM ReviewCycle WHERE companyId = ? AND status = 'ACTIVE' ORDER BY startDate DESC LIMIT 1", [$co]);
$myGoals = $auth['employeeId'] ? db_all('SELECT g.*, c.name AS cycleName FROM Goal g JOIN ReviewCycle c ON c.id = g.cycleId WHERE g.employeeId = ? ORDER BY g.id DESC', [$auth['employeeId']]) : [];
$myReviews = $auth['employeeId'] ? db_all(
    'SELECT r.*, c.name AS cycleName, m.firstName AS rFirst, m.lastName AS rLast FROM PerformanceReview r
       JOIN ReviewCycle c ON c.id = r.cycleId JOIN Employee m ON m.id = r.reviewerId WHERE r.employeeId = ?',
    [$auth['employeeId']]
) : [];
$myActive = null;
foreach ($myReviews as $r) {
    if ($activeCycle && $r['cycleId'] === $activeCycle['id']) {
        $myActive = $r;
    }
}
$team = [];
if ($managerial && $activeCycle) {
    // A Manager reviews only direct reports; HR/Admin keep the company-wide view.
    if ($auth['role'] === 'MANAGER') {
        $team = db_all('SELECT id, firstName, lastName, avatarColor FROM Employee WHERE managerId = ? AND companyId = ? ORDER BY firstName', [$auth['employeeId'] ?? '__none__', $co]);
    } else {
        [$in, $ids] = db_in(array_values(array_diff(visible_employee_ids($auth), [$auth['employeeId']])));
        $team = db_all("SELECT id, firstName, lastName, avatarColor FROM Employee WHERE id IN ($in) ORDER BY firstName", $ids);
    }
    $existing = [];
    foreach (db_all('SELECT employeeId, rating, comments FROM PerformanceReview WHERE cycleId = ?', [$activeCycle['id']]) as $r) {
        $existing[$r['employeeId']] = $r;
    }
}
$ratingOptions = static function (int $current): string {
    $h = '';
    for ($n = 1; $n <= 5; $n++) {
        $h .= '<option value="' . $n . '"' . selected($n, $current) . ">$n / 5</option>";
    }
    return $h;
};

app_layout_open($auth, 'Performance');
echo page_header('Performance', 'Goals, review cycles, and ratings.');
?>
<div class="grid lg:grid-cols-3 gap-5">
    <div class="space-y-5">
        <?php if ($canManageCycles): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Review cycles</p>
                <ul class="space-y-2 mb-4">
                    <?php foreach ($cycles as $c): ?>
                        <li class="flex items-center justify-between text-sm"><span class="text-ink-soft"><?= e($c['name']) ?></span><?= status_badge($c['status']) ?></li>
                    <?php endforeach; ?>
                    <?php if (!$cycles): ?><p class="text-sm text-muted">No cycles yet.</p><?php endif; ?>
                </ul>
                <?= flash_alerts('cycle') ?>
                <details class="text-sm">
                    <summary class="cursor-pointer text-brand font-medium">+ New cycle</summary>
                    <form method="post" class="space-y-3 mt-3">
                        <?= form_fields('cycle', 'cycle') ?>
                        <input name="name" required maxlength="191" placeholder="e.g. H1 2027 Review" class="input">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="date" name="startDate" required class="input">
                            <input type="date" name="endDate" required class="input">
                        </div>
                        <button type="submit" class="btn btn-primary w-full justify-center">Create cycle</button>
                    </form>
                </details>
            </div>
        <?php endif; ?>
        <?php if ($activeCycle && $auth['employeeId']): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Add a goal — <?= e($activeCycle['name']) ?></p>
                <form method="post" class="space-y-3">
                    <?= form_fields('goal', 'goal') ?>
                    <input type="hidden" name="cycleId" value="<?= e($activeCycle['id']) ?>">
                    <input name="title" required maxlength="191" placeholder="Goal title" class="input">
                    <textarea name="description" rows="2" maxlength="5000" placeholder="Description (optional)" class="input"></textarea>
                    <div><label class="field-label">Weight (%)</label><input type="number" name="weight" min="0" max="100" value="20" class="input"></div>
                    <?= flash_alerts('goal') ?>
                    <button type="submit" class="btn btn-primary w-full justify-center"><?= icon('plus', 14) ?> Add goal</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="lg:col-span-2 space-y-5">
        <?php if ($auth['employeeId']): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">My goals</p>
                <?php if (!$myGoals): ?>
                    <?= empty_state('target', 'No goals set', 'Add a goal for the active review cycle.') ?>
                <?php else: ?>
                    <ul class="divide-y divide-border-soft">
                        <?php foreach ($myGoals as $g): ?>
                            <li class="py-3">
                                <div class="flex items-center justify-between"><p class="text-sm font-medium text-ink"><?= e($g['title']) ?></p><span class="text-xs text-muted"><?= (int)$g['weight'] ?>% · <?= e($g['cycleName']) ?></span></div>
                                <?php if ($g['description']): ?><p class="text-sm text-ink-soft mt-1"><?= e($g['description']) ?></p><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($auth['employeeId'] && $activeCycle): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-1">Self-appraisal — <?= e($activeCycle['name']) ?></p>
                <p class="text-xs text-muted mb-3">Rate your own performance this cycle — your manager sees this alongside their own review.</p>
                <form method="post" class="flex flex-wrap items-start gap-2">
                    <?= form_fields('self', 'self') ?>
                    <input type="hidden" name="cycleId" value="<?= e($activeCycle['id']) ?>">
                    <select name="selfRating" class="input !w-auto text-sm"><?= $ratingOptions((int)($myActive['selfRating'] ?? 3)) ?></select>
                    <input name="selfComments" maxlength="5000" value="<?= e($myActive['selfComments'] ?? '') ?>" placeholder="How would you describe your own performance?" class="input flex-1 min-w-[200px] text-sm">
                    <button class="btn btn-secondary text-sm shrink-0">Submit</button>
                </form>
                <div class="mt-3"><?= flash_alerts('self') ?></div>
            </div>
        <?php endif; ?>

        <?php if ($auth['employeeId']): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">My reviews</p>
                <?php if (!$myReviews): ?>
                    <p class="text-sm text-muted py-4">No reviews yet.</p>
                <?php else: ?>
                    <ul class="divide-y divide-border-soft">
                        <?php foreach ($myReviews as $r): ?>
                            <li class="py-3">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-medium text-ink"><?= e($r['cycleName']) ?></p>
                                    <?php if ($r['rating']): ?><span class="flex items-center gap-1 text-sm text-accent-dark font-semibold"><?= icon('star', 14, 'fill-current') ?> <?= (int)$r['rating'] ?>/5</span><?php endif; ?>
                                </div>
                                <?php if ($r['comments']): ?><p class="text-sm text-ink-soft mt-1">Manager: <?= e($r['comments']) ?></p><?php endif; ?>
                                <?php if ($r['rating']): ?><p class="text-xs text-muted mt-1">by <?= e($r['rFirst'] . ' ' . $r['rLast']) ?></p><?php endif; ?>
                                <?php if ($r['selfRating']): ?>
                                    <p class="text-sm text-ink-soft mt-2 pt-2 border-t border-border-soft"><span class="font-medium text-ink">Your self-rating: <?= (int)$r['selfRating'] ?>/5</span><?= $r['selfComments'] ? ' — ' . e($r['selfComments']) : '' ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($managerial && $activeCycle): ?>
            <div class="card p-5">
                <p class="text-sm font-semibold text-ink mb-3">Rate your team — <?= e($activeCycle['name']) ?></p>
                <?= flash_alerts('review') ?>
                <ul class="divide-y divide-border-soft">
                    <?php foreach ($team as $emp):
                        $ex = $existing[$emp['id']] ?? null; ?>
                        <li class="py-4 flex flex-wrap items-center gap-4">
                            <?= avatar($emp['firstName'], $emp['lastName'], $emp['avatarColor']) ?>
                            <span class="text-sm font-medium text-ink w-40 shrink-0"><?= e($emp['firstName'] . ' ' . $emp['lastName']) ?></span>
                            <form method="post" class="flex items-center gap-2 flex-1">
                                <?= form_fields('review', 'review') ?>
                                <input type="hidden" name="employeeId" value="<?= e($emp['id']) ?>"><input type="hidden" name="cycleId" value="<?= e($activeCycle['id']) ?>">
                                <select name="rating" class="input !w-auto text-sm"><?= $ratingOptions((int)($ex['rating'] ?? 3)) ?></select>
                                <input name="comments" maxlength="5000" value="<?= e($ex['comments'] ?? '') ?>" placeholder="Comments" class="input text-sm">
                                <button class="btn btn-secondary text-sm shrink-0">Save</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$team): ?><p class="text-sm text-muted py-4">No direct reports to review.</p><?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php app_layout_close();
