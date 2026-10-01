<?php
/** /dashboard/recruitment — port of app/dashboard/recruitment/page.tsx */
declare(strict_types=1);

$jobs = db_all(
    'SELECT j.*, (SELECT COUNT(*) FROM Candidate c WHERE c.jobPostingId = j.id) AS candidates FROM JobPosting j WHERE j.companyId = ? ORDER BY j.createdAt DESC',
    [$auth['companyId']]
);

app_layout_open($auth, 'Recruitment');
echo page_header('Recruitment', 'Open roles and candidate pipelines.',
    '<a href="' . e(url('/dashboard/recruitment/new')) . '" class="btn btn-primary">' . icon('plus', 15) . ' New job posting</a>');
?>
<?php if (!$jobs): ?>
    <div class="card"><?= empty_state('briefcase', 'No job postings yet', 'Create your first opening to start tracking candidates.') ?></div>
<?php else: ?>
    <div class="grid gap-4 grid-cols-[repeat(auto-fit,minmax(280px,1fr))]">
        <?php foreach ($jobs as $job): ?>
            <a href="<?= e(url('/dashboard/recruitment/' . $job['id'])) ?>" class="card p-5 hover:border-brand-soft transition-colors">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <p class="font-display font-semibold text-ink"><?= e($job['title']) ?></p>
                    <?= status_badge($job['status']) ?>
                </div>
                <p class="text-sm text-muted flex items-center gap-1.5 mb-1"><?= icon('map-pin', 13) ?> <?= e($job['location']) ?></p>
                <p class="text-sm text-muted flex items-center gap-1.5"><?= icon('users', 13) ?> <?= (int)$job['candidates'] ?> candidate(s) · <?= (int)$job['openings'] ?> opening(s)</p>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php app_layout_close();
