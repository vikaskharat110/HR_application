<?php
/** Phase 4 RBAC diagnostic (JSON). Disabled unless APP_ENV=local. */
declare(strict_types=1);

if (config('app.env') !== 'local') {
    throw new HttpError(404);
}
$modules = [];
foreach (nav_items() as $item) {
    $modules[$item['href']] = has_module_access($auth, $item['href']);
}
$payroll = [];
foreach (PAYROLL_ACTIONS as $a) {
    $payroll[$a] = has_payroll_action($auth, $a);
}
[$qSql, $qParams] = approval_queue_where($auth);
json_response([
    'auth' => array_diff_key($auth, ['companyStatus' => 1]),
    'modules' => $modules,
    'hidden' => hidden_hrefs($auth),
    'payroll' => $payroll,
    'visibleCount' => count(visible_employee_ids($auth)),
    'pendingLeaveInQueue' => (int)db_value("SELECT COUNT(*) FROM LeaveRequest WHERE status = 'PENDING' AND $qSql", $qParams),
    'sessionId' => substr(session_id(), 0, 8),
]);
