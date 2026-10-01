<?php
/**
 * /dashboard/exit/new — port of app/dashboard/exit/new/page.tsx. Not gated by
 * the Exit module: any employee can resign; HR/Admin can raise it for someone
 * (?employeeId=). Authorization is enforced again in the action.
 */
declare(strict_types=1);

$isHr = is_company_admin($auth);
$param = query('employeeId');
$employeeId = $param !== '' && $isHr && is_valid_id($param) ? $param : $auth['employeeId'];
if (!$employeeId) {
    redirect('/dashboard');
}
$employee = db_one('SELECT id, firstName, lastName FROM Employee WHERE id = ? AND companyId = ?', [$employeeId, $auth['companyId']]);
if ($employee === null) {
    redirect('/dashboard/exit');
}
handle_post([
    'resign' => fn() => '/dashboard/exit/' . action_initiate_resignation($auth, post('employeeId'), post('resignationDate'), post_raw('reason')),
]);

app_layout_open($auth, 'Submit resignation');
?>
<div class="max-w-xl">
    <?= back_link('/dashboard/exit', 'Back to exit / offboarding') ?>
    <?= page_header('Submit resignation', 'For ' . $employee['firstName'] . ' ' . $employee['lastName']) ?>
    <?= flash_alerts('resign') ?>
    <form method="post" class="card p-6 space-y-4">
        <?= form_fields('resign', 'resign') ?>
        <input type="hidden" name="employeeId" value="<?= e($employee['id']) ?>">
        <div><label class="field-label">Resignation date</label><input type="date" name="resignationDate" required value="<?= e(old('resignationDate', today_start()->format('Y-m-d'))) ?>" class="input"></div>
        <div><label class="field-label">Reason</label><textarea name="reason" rows="4" required maxlength="5000" class="input" placeholder="Reason for resignation"><?= e(old('reason')) ?></textarea></div>
        <div class="flex justify-end gap-2">
            <a href="<?= e(url('/dashboard/exit')) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Submit resignation</button>
        </div>
    </form>
</div>
<?php app_layout_close();
