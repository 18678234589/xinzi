<?php

require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';
require_login();
ensureCsPerfSchema();

$page_title = '客服绩效';

// 总览默认显示上月（与上传默认归入上月一致，绩效表通常是已结束的上月数据）
$year  = (int)($_GET['year'] ?? date('Y', strtotime('-1 month')));
$month = (int)($_GET['month'] ?? date('n', strtotime('-1 month')));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
if ($month < 1 || $month > 12)    $month = (int)date('m');

// 上传默认归入上个月（可在上传卡里改）
$upYD = (int)($_POST['upload_year']  ?? date('Y', strtotime('-1 month')));
$upMD = (int)($_POST['upload_month'] ?? date('n', strtotime('-1 month')));
if ($upYD < 2000 || $upYD > 2100) $upYD = (int)date('Y');
if ($upMD < 1 || $upMD > 12)      $upMD = (int)date('n');

// 绩效名单：绩效固定服务费只涉及名单内合作人员，页面可自定义增删
$upMsg = '';
$upErr = '';
/* split: performance/index/actions/dispatch.php */ include (dirname(__DIR__, 1)) . '/index/actions/dispatch.php';

// 参与名单按部门自动生成（部门已配置基数+方案 且 未被排除）；被排除者单独列出手动恢复
$participants = get_cs_perf_participants();
$excluded     = get_cs_perf_excluded();

$rows = [];
$deptCfgMap = [];
foreach (get_cs_perf_dept_configs() as $dc) $deptCfgMap[(string)$dc['department']] = $dc;
foreach ($participants as $emp) {
    $perf = get_cs_performance((int)$emp['id'], $year, $month);
    $liveDeal = get_employee_deal_count((int)$emp['id'], $year, $month);
    $deal = $perf && $perf['deal_count'] !== null ? (int)$perf['deal_count'] : $liveDeal;
    $rows[] = [
        'emp' => $emp, 'perf' => $perf, 'deal' => $deal, 'liveDeal' => $liveDeal,
        'calc' => cs_perf_calc((int)$emp['id'], $year, $month),
        'netSales'     => $perf ? (float)$perf['net_sales'] : 0.0,
        'inquiryConv'  => $perf ? (float)$perf['inquiry_conv'] : 0.0,
        'wangReply'    => $perf ? (float)$perf['wangwang_reply'] : 0.0,
        'avgResponse'  => $perf ? (float)$perf['reply_speed'] : 0.0,
        'orderTotal' => get_employee_order_total((int)$emp['id'], $year, $month),
        'deptCfg' => $deptCfgMap[(string)$emp['department']] ?? null,
    ];
}

// 待匹配清单
$pending = [];
try {
    $pending = db()->query("SELECT * FROM cs_perf_pending WHERE year=" . (int)$year . " AND month=" . (int)$month . " ORDER BY name, wangwang")->fetchAll();
} catch (\Throwable $e) {}

// 近20条同步日志
$logs = [];
try {
    $logs = db()->query("SELECT * FROM cs_perf_sync_log ORDER BY id DESC LIMIT 20")->fetchAll();
} catch (\Throwable $e) {}

// 按来源文件合并（同一文件重复上传只显示最新一次，标注上传次数）
$logFiles = []; // source_file => ['latest'=>row, 'count'=>n]
foreach ($logs as $l) {
    $k = (string)($l['source_file'] ?? '');
    if ($k === '') $k = '(未知来源)';
    if (!isset($logFiles[$k])) {
        $logFiles[$k] = ['latest' => $l, 'count' => 1];
    } else {
        $logFiles[$k]['count']++;
        if ((int)$l['id'] > (int)$logFiles[$k]['latest']['id']) $logFiles[$k]['latest'] = $l;
    }
}

// 查看某次上传的导入明细（模态框用，按 source_file 汇总已匹配/未匹配/错误）
if (($_GET['action'] ?? '') === 'upload_view' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $src = (string)($_GET['source'] ?? '');
    if ($src === '') {
        echo json_encode(['found' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pdo  = db();
    $log  = null;
    try {
        $st = $pdo->prepare("SELECT source_file, matched, pending, errors, detail, created_at FROM cs_perf_sync_log WHERE source_file=? ORDER BY id DESC LIMIT 1");
        $st->execute([$src]);
        $row = $st->fetch();
        if ($row) {
            $log = [
                'source_file' => (string)$row['source_file'],
                'matched'     => (int)$row['matched'],
                'pending'     => (int)$row['pending'],
                'errors'      => (int)$row['errors'],
                'detail'      => json_decode((string)$row['detail'], true) ?: [],
                'created_at'  => (string)$row['created_at'],
            ];
        }
    } catch (\Throwable $e) {}
    $matched = [];
    $pending = [];
    try {
        $st = $pdo->prepare("SELECT c.year, c.month, c.net_sales, c.inquiry_conv, c.wangwang_reply, c.reply_speed, c.incoming_count, c.order_count,
                e.name AS emp_name, e.wangwang AS emp_wang, e.department
            FROM customer_service_performance c LEFT JOIN employees e ON e.id=c.employee_id
            WHERE c.source_file=? ORDER BY c.year DESC, c.month DESC, e.name");
        $st->execute([$src]);
        $matched = $st->fetchAll();
    } catch (\Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT wangwang, name, year, month, incoming_count, total_reply_seconds, net_sales, inquiry_conv, wangwang_reply, order_count FROM cs_perf_pending WHERE source_file=? ORDER BY year DESC, month DESC, name, wangwang");
        $st->execute([$src]);
        $pending = $st->fetchAll();
    } catch (\Throwable $e) {}
    echo json_encode(['found' => true, 'log' => $log, 'matched' => $matched, 'pending' => $pending], JSON_UNESCAPED_UNICODE);
    exit;
}

define('BASE_PATH', dirname((dirname(__DIR__, 1))));
