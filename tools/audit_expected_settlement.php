<?php
// 只读全员核对：不上传、不建立结算、不修改收款或锁定记录。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ProjectExpectedSettlement.php';
$month = $argv[1] ?? '2026-09';
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$start = microtime(true);
$data = ps_expected_month($month);
$counts = ['cash' => 0, 'cost' => 0, 'rule' => 0, 'weight' => 0, 'refund' => 0];
$issueOrders = [];
foreach ($data['warnings'] as $eid => $orders) foreach ($orders as $oid => $warnings) {
    foreach ($warnings as $key => $flag) if ($flag) $issueOrders[$key][$oid] = true;
}
foreach ($counts as $key => $_) $counts[$key] = count($issueOrders[$key] ?? []);
$checked = 0; $sample = []; $nonzero = 0;
foreach (db()->query('SELECT id,name FROM employees ORDER BY id')->fetchAll() as $person) {
    $income = ps_partner_expected_income((int)$person['id'], $month);
    foreach (['fixed_fee', 'commission', 'attendance', 'total'] as $key) if (!is_finite($income[$key])) throw new RuntimeException('非有限金额：' . $person['id'] . ':' . $key);
    if ((int)round($income['total'] * 100) !== (int)round(($income['fixed_fee'] + $income['commission'] + $income['attendance']) * 100)) throw new RuntimeException('总金额不一致：' . $person['id']);
    $checked++;
    if (abs($income['total']) > .005) $nonzero++;
    if (in_array((int)$person['id'], [16,26,37,44,53,84], true)) $sample[] = ['id' => (int)$person['id'], 'name' => $person['name']] + $income;
}
echo json_encode(['month' => $month, 'people_checked' => $checked, 'nonzero_income_people' => $nonzero,
    'projection_rows' => count($data['snapshots']), 'warning_orders' => $counts, 'elapsed_seconds' => round(microtime(true) - $start, 3),
    'samples' => array_map(function ($s) { unset($s['items']); return $s; }, $sample), 'database_writes' => false], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
