<?php
require_once file_exists(__DIR__ . '/../includes/ProjectPayrollSheet.php') ? __DIR__ . '/../includes/ProjectPayrollSheet.php' : 'includes/ProjectPayrollSheet.php';

echo "=== Template Tech Reconcile Smoke Test ===\n";

$month = '2026-10';
$results = ps_monthly_results($month, true);

$techRules = array_filter($results, function ($r) {
    return in_array($r['rule_name'], ['模板技术月度统算差额', '模板技术超额奖金'], true);
});

echo "Found " . count($techRules) . " technical monthly rule rows in $month:\n";
foreach ($techRules as $r) {
    echo "  Emp {$r['employee_id']} | {$r['rule_name']}: ¥{$r['amount']} | {$r['detail']}\n";
}

$sheet = pps_sheet($month);
$byEmp = [];
foreach ($sheet['rows'] as $row) {
    $byEmp[(int)$row['id']] = $row;
}

foreach ([70 => '光君', 74 => '孙妍', 79 => '张强'] as $eid => $name) {
    if (!isset($byEmp[$eid])) {
        echo "[FAIL] $name ($eid) not found in payroll sheet!\n";
        exit(1);
    }
    $r = $byEmp[$eid];
    echo "\n[$name] ID $eid:\n";
    echo "  底薪: {$r['base']} | 全勤: {$r['bonus']} | 提成: {$r['share']} | 应发: {$r['payable']} | 实发: {$r['net']}\n";
}

echo "\n[PASS] Template tech reconcile smoke test passed.\n";
