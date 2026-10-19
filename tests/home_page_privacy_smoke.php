<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ProjectPartnerProfile.php';
require_once __DIR__ . '/../includes/ProjectPayrollSheet.php';

function test_assert($cond, $msg) {
    if (!$cond) {
        fwrite(STDERR, "Assertion failed: $msg\n");
        exit(1);
    }
}

// 1. 测试 pps_sees_all 权限隔离
$adminActor = ['type' => 'admin', 'id' => 1, 'role' => 'admin'];
$financeActor = ['type' => 'employee', 'employee_id' => 999, 'role' => 'finance'];
$techActor = ['type' => 'employee', 'employee_id' => 39, 'role' => 'technical'];
$csActor = ['type' => 'employee', 'employee_id' => 40, 'role' => 'customer_service'];
$mgrActor = ['type' => 'employee', 'employee_id' => 41, 'role' => 'management', 'account_role' => 'management'];

test_assert(pps_sees_all($adminActor) === true, 'Admin should see all');
test_assert(pps_sees_all($financeActor) === true, 'Finance should see all');
test_assert(pps_sees_all($techActor) === false, 'Technical staff must NOT see all');
test_assert(pps_sees_all($csActor) === false, 'Customer service must NOT see all');
test_assert(pps_sees_all($mgrActor) === false, 'Supervisor/management must NOT see all on home page');

// 2. 测试普通人员只看自己
$techSheet = pps_sheet('2026-09', 39, false);
test_assert(isset($techSheet['rows']), 'Sheet rows must be array');
test_assert(count($techSheet['rows']) <= 1, 'Technical staff can only have at most 1 row (themselves)');
if (count($techSheet['rows']) === 1) {
    test_assert((int)$techSheet['rows'][0]['id'] === 39, 'The single row must be the employee themselves');
}

echo "PASS home page privacy and role isolation smoke test\n";
