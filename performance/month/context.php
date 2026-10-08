<?php

require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';
require_login();
ensureCsPerfSchema();

$page_title = '客服绩效 - 本月数据';

$year  = (int)($_GET['year'] ?? date('Y', strtotime('-1 month')));
$month = (int)($_GET['month'] ?? date('n', strtotime('-1 month')));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
if ($month < 1 || $month > 12)    $month = (int)date('m');
$filterEmp = (int)($_GET['employee_id'] ?? 0);

$msg = '';
$err = '';

/* split: performance/month/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/month/actions/dispatch.php';

$employees = get_cs_perf_participants(); // 绩效参与按部门自动（含设计客服恒参与），被排除者不在内
$rows = [];
foreach ($employees as $emp) {
    if ($filterEmp > 0 && (int)$emp['id'] !== $filterEmp) continue;
    $perf = get_cs_performance((int)$emp['id'], $year, $month); // 多店聚合 / 人工综合行优先
    $liveDeal = get_employee_deal_count((int)$emp['id'], $year, $month);
    $rows[] = ['emp' => $emp, 'perf' => $perf, 'liveDeal' => $liveDeal];
}

$pending = [];
try {
    $pending = db()->query("SELECT * FROM cs_perf_pending WHERE year=" . (int)$year . " AND month=" . (int)$month . " ORDER BY name, wangwang")->fetchAll();
} catch (\Throwable $e) {}

/* ============ 绩效金额算法过程明细：与总览/项目结算共用 cs_perf_calc_detail，结果绝对一致 ============ */
$perfDetails = [];
foreach ($rows as $r) {
    $perfDetails[(int)$r['emp']['id']] = cs_perf_calc_detail((int)$r['emp']['id'], $year, $month);
}
$calcModeLabels = [
    'rank'       => '排名制（设计客服）',
    'scheme'     => '基数 × 综合达成率',
    'legacy'     => '旧版四因素',
    'no_scheme'  => '部门未配置',
    'base_zero'  => '基数为0',
    'no_data'    => '无当月数据',
    'no_metrics' => '方案无有效指标',
];
$calcFixedDefs = [
    ['key' => 'net_sales',      'field' => 'net_sales',      'label' => '净销售额',   'unit' => '',  'dec' => 2],
    ['key' => 'inquiry_conv',   'field' => 'inquiry_conv',   'label' => '询单转化率', 'unit' => '%', 'dec' => 2],
    ['key' => 'wangwang_reply', 'field' => 'wangwang_reply', 'label' => '旺旺回复率', 'unit' => '%', 'dec' => 2],
    ['key' => 'avg_response',   'field' => 'reply_speed',    'label' => '平均回复',   'unit' => '秒', 'dec' => 1],
];
// 数字格式化去掉无意义尾 0：86000.00→86000，93.40%→93.4%
$calcNum = function ($v, $dec = 2) {
    $s = number_format((float)$v, $dec, '.', '');
    return (strpos($s, '.') !== false) ? rtrim(rtrim($s, '0'), '.') : $s;
};
$calcPct = function ($v, $dec = 1) use ($calcNum) { return $calcNum($v, $dec) . '%'; };

define('BASE_PATH', dirname((dirname(__DIR__, 1))));
