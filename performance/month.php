<?php
require_once __DIR__ . '/../includes/auth.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $y = (int)($_POST['year'] ?? 0);
        $m = (int)($_POST['month'] ?? 0);
        $replySpeed = (float)($_POST['reply_speed'] ?? 0);
        $incoming   = (int)($_POST['incoming_count'] ?? 0);
        $netSales = (float)($_POST['net_sales'] ?? 0);
        $inquiryConv = parse_percent((string)($_POST['inquiry_conv'] ?? ''));
        $wangReply = parse_percent((string)($_POST['wangwang_reply'] ?? ''));
        $deal = trim($_POST['deal_count'] ?? '');
        $remark = trim($_POST['remark'] ?? '');
        if ($employeeId > 0 && $y >= 2000 && $m >= 1 && $m <= 12) {
            $dealVal = $deal === '' ? null : (int)$deal;
            $orderCount = (int)($_POST['order_count'] ?? 0);
            $incoming   = (int)($_POST['incoming_count'] ?? 0);
            // 转化率：有「下单人数 + 询单人数」时按 下单÷询单 计算，并展示计算过程；否则用直接录入值
            if ($orderCount > 0 && $incoming > 0) $inquiryConv = round($orderCount / $incoming * 100, 2);
            $stmt = db()->prepare("INSERT INTO customer_service_performance
                (employee_id, year, month, reply_speed, incoming_count, deal_count, remark,
                 net_sales, inquiry_conv, wangwang_reply, order_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count),
                deal_count=VALUES(deal_count), remark=VALUES(remark), source_file='admin编辑',
                net_sales=VALUES(net_sales), inquiry_conv=VALUES(inquiry_conv), wangwang_reply=VALUES(wangwang_reply),
                order_count=VALUES(order_count)");
            $stmt->execute([$employeeId, $y, $m, $replySpeed, $incoming, $dealVal, $remark, $netSales, $inquiryConv, $wangReply, $orderCount]);
            cs_perf_cache_reset();
            $msg = '已保存员工绩效';
        } else {
            $err = '参数错误';
        }
    } elseif ($action === 'assign') {
        $pendingId = (int)($_POST['pending_id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        try {
            $stmt = db()->prepare("SELECT * FROM cs_perf_pending WHERE id=?");
            $stmt->execute([$pendingId]);
            $p = $stmt->fetch();
            if ($p && $employeeId > 0) {
                $incoming = (int)$p['incoming_count'];
                $replySpeed = 0.0;
                if ($incoming > 0 && (float)$p['total_reply_seconds'] > 0) {
                    $replySpeed = round((float)$p['total_reply_seconds'] / $incoming, 1);
                }
                $up = db()->prepare("INSERT INTO customer_service_performance
                    (employee_id, year, month, reply_speed, incoming_count, deal_count, remark, source_file,
                     net_sales, inquiry_conv, wangwang_reply, order_count)
                    VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count), source_file=VALUES(source_file),
                    net_sales=VALUES(net_sales), inquiry_conv=VALUES(inquiry_conv), wangwang_reply=VALUES(wangwang_reply),
                    order_count=VALUES(order_count)");
                $remark = '由待匹配补录（' . ($p['name'] !== '' ? $p['name'] : $p['wangwang']) . '）';
                // 转化率：待匹配行有 下单人数+询单人数 时按 下单÷询单 计算
                $pInquiryConv = (float)$p['inquiry_conv'];
                $pOrderCount  = (int)($p['order_count'] ?? 0);
                if ($pOrderCount > 0 && $incoming > 0) $pInquiryConv = round($pOrderCount / $incoming * 100, 2);
                $up->execute([$employeeId, (int)$p['year'], (int)$p['month'], $replySpeed, $incoming, $remark, 'pending:' . basename((string)$p['source_file']),
                    (float)$p['net_sales'], $pInquiryConv, (float)$p['wangwang_reply'], $pOrderCount]);
                db()->prepare("DELETE FROM cs_perf_pending WHERE id=?")->execute([$pendingId]);
                cs_perf_cache_reset();
                $msg = '已把待匹配数据归属到员工并写入';
            } else {
                $err = '待匹配记录不存在或未选择员工';
            }
        } catch (PDOException $ex) {
            $err = '操作失败: ' . $ex->getMessage();
        }
    } elseif ($action === 'delete_pending') {
        db()->prepare("DELETE FROM cs_perf_pending WHERE id=?")->execute([(int)($_POST['pending_id'] ?? 0)]);
        cs_perf_cache_reset();
        $msg = '已删除该待匹配记录';
    }
}

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

/* ============ 绩效金额算法过程明细：与总览/薪资结算共用 cs_perf_calc_detail，结果绝对一致 ============ */
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

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-edit"></i> 客服绩效 - 编辑/补录（<?php echo $year; ?>年<?php echo $month; ?>月）</h4>
    <a href="index.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> 返回总览</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($msg); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($err); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 月份/员工切换 -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="form-inline">
            <select name="year" class="form-control form-control-sm mr-1">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                <?php endfor; ?>
            </select>
            <select name="month" class="form-control form-control-sm mr-2">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $month === $m ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                <?php endfor; ?>
            </select>
            <select name="employee_id" class="form-control form-control-sm mr-2">
                <option value="0">全部员工</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo (int)$emp['id']; ?>" <?php echo $filterEmp === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-search"></i> 切换</button>
        </form>
    </div>
</div>

<!-- 员工绩效逐人编辑 -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-users"></i> 逐人编辑（净销售额/询单转化率/旺旺回复率 = 采集上报；平均回复秒 = 采集上报；成交数留空则按订单自动统计；<strong>填了「下单人数」时转化率自动 = 下单 ÷ 询单并显示计算过程</strong>）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle">
                <thead class="thead-light">
                    <tr>
                        <th>员工</th><th>旺旺账号</th><th>净销售额(元)</th><th>询单转化率(%)</th><th>下单人数</th><th>旺旺回复率(%)</th><th>进线人数</th><th>平均回复(秒)</th>
                        <th>成交数(留空自动)</th><th>备注</th><th style="width:100px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows):
                    foreach ($rows as $r):
                        $emp = $r['emp'];
                        $perf = $r['perf'];
                        $dealDisplay = $perf && $perf['deal_count'] !== null ? (int)$perf['deal_count'] : '';
                        ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                                <input type="hidden" name="year" value="<?php echo $year; ?>">
                                <input type="hidden" name="month" value="<?php echo $month; ?>">
                                <td><?php echo e($emp['name']); ?><br><small class="text-muted"><?php echo e($emp['department']); ?></small></td>
                                <td><?php echo e($emp['wangwang'] ?? ''); ?></td>
                                <td><input type="number" name="net_sales" min="0" step="any" class="form-control form-control-sm" style="width:110px" value="<?php echo $perf ? (float)$perf['net_sales'] : 0; ?>"></td>
                                <td><input type="number" name="inquiry_conv" min="0" step="any" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ? (float)$perf['inquiry_conv'] : 0; ?>"></td>
                                <td>
                                    <input type="number" name="order_count" min="0" class="form-control form-control-sm" style="width:80px" value="<?php echo $perf ? (int)($perf['order_count'] ?? 0) : 0; ?>">
                                    <div style="font-size:11px" class="text-muted">转化率=下单÷进线自动算</div>
                                </td>
                                <td><input type="number" name="wangwang_reply" min="0" step="any" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ? (float)$perf['wangwang_reply'] : 0; ?>"></td>
                                <td><input type="number" name="incoming_count" min="0" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ? (int)$perf['incoming_count'] : 0; ?>"></td>
                                <td><input type="number" name="reply_speed" min="0" step="any" class="form-control form-control-sm" style="width:100px" value="<?php echo $perf ? (float)$perf['reply_speed'] : 0; ?>"></td>
                                <td>
                                    <input type="number" name="deal_count" min="0" class="form-control form-control-sm" style="width:120px" placeholder="自动:<?php echo $r['liveDeal']; ?>" value="<?php echo e($dealDisplay); ?>">
                                    <small class="text-muted">订单自动统计 <?php echo $r['liveDeal']; ?> 个</small>
                                </td>
                                <td><input type="text" name="remark" class="form-control form-control-sm" value="<?php echo e($perf['remark'] ?? ''); ?>"></td>
                                <td><button class="btn btn-sm btn-primary"><i class="fas fa-save"></i> 保存</button></td>
                            </form>
                        </tr>
                    <?php endforeach;
                else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">暂无员工</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 绩效金额算法过程 -->
<div class="card mb-3">
    <div class="card-header py-2"><i class="fas fa-calculator"></i> <strong>绩效金额算法过程</strong>
        <small class="text-muted ml-2">与「客服绩效总览」「薪资结算」共用同一计算函数（cs_perf_calc），金额绝对一致；点「详细过程」查看每一指标如何命中档位、加权、排名</small>
    </div>
    <?php foreach ($rows as $r):
        $cEmp = $r['emp'];
        $cDt  = $perfDetails[(int)$cEmp['id']] ?? null;
        if ($cDt === null) continue;
        $modeLabel = $calcModeLabels[$cDt['mode']] ?? (string)$cDt['mode'];
        $sp    = !empty($cDt['scheme']) ? cs_perf_scheme_params($cDt['scheme']) : null;
        $spOn  = [];
        if ($sp) {
            foreach ($calcFixedDefs as $fd) {
                $w  = (float)($sp['w_' . $fd['key']] ?? 0);
                $ti = $sp['tiers_' . $fd['key']] ?? [];
                $spOn[$fd['key']] = $w > 0 && is_array($ti) && $ti;
            }
        }
        ?>
        <div class="card-body py-3 border-top">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <strong><?php echo e($cEmp['name']); ?></strong>
                    <small class="text-muted ml-1"><?php echo e($cEmp['department']); ?></small>
                    <span class="badge badge-light border ml-2"><i class="fas fa-cog"></i> <?php echo e($modeLabel); ?></span>
                </div>
                <div>
                    <span class="badge badge-info">绩效金额 ¥<?php echo number_format((float)$cDt['amount'], 2); ?></span>
                    <button class="btn btn-sm btn-outline-secondary" data-toggle="collapse" data-target="#calc-<?php echo (int)$cEmp['id']; ?>" aria-expanded="<?php echo $filterEmp > 0 ? 'true' : 'false'; ?>">详细过程</button>
                </div>
            </div>
            <div class="collapse <?php echo $filterEmp > 0 ? 'show' : ''; ?>" id="calc-<?php echo (int)$cEmp['id']; ?>">
                <div style="font-size:13px;line-height:1.8">
                    <div class="alert alert-light border py-2 mb-3" style="font-size:12.5px"><strong>最终算式：</strong><?php echo e($cDt['formula']); ?></div>

                    <?php if ($cDt['mode'] === 'rank'): /* ===== 设计客服：排名制 ===== */ ?>
                        <p class="text-muted mb-3">设计客服不用「基数 × 达成率」，而是排名制：<strong class="text-dark">各店铺分别按绩效方案算出综合达成率 → 取平均得分为排名分 → 部门内前三名拿固定底薪（第1名850 / 第2名800 / 第3名750），不再乘达成率、无保底封顶。</strong></p>

                        <div class="font-weight-bold mb-1">第1步 · 每店铺采集数据 → 命中档位 → 店综合达成率</div>
                        <table class="table table-sm table-bordered mb-3" style="font-size:12px">
                            <thead class="thead-light">
                                <tr><th>店铺</th><th>净销售额(元)</th><th>询单转化率(%)</th><th>旺旺回复率(%)</th><th>平均回复(秒)</th><th style="width:130px">该店综合达成率</th></tr>
                            </thead>
                            <tbody>
                            <?php if ($cDt['stores']): foreach ($cDt['stores'] as $s):
                                $mByKey = [];
                                foreach ($s['metrics'] as $m) $mByKey[$m['key']] = $m; ?>
                                <tr>
                                    <td><strong><?php echo e($s['store'] !== '' ? $s['store'] : '综合行(人工)'); ?></strong></td>
                                    <?php foreach ($calcFixedDefs as $fd):
                                        $m = $mByKey[$fd['key']] ?? null;
                                        $rawVal = (float)($s['row'][$fd['field']] ?? 0);
                                        if ($m) {
                                            $sub = '档[' . e(cs_perf_fmt_range($m['tier'])) . '] → ' . e($calcPct($m['rate'] * 100, 1)) . ' × 权重' . e($calcNum($m['weight'], 1));
                                        } elseif (!empty($spOn[$fd['key']])) {
                                            $sub = '实际值≤0，未命中档位';
                                        } else {
                                            $sub = '未启用（权重0或未配档位）';
                                        } ?>
                                        <td><?php echo e($calcNum($rawVal, $fd['dec']) . $fd['unit']); ?>
                                            <div style="font-size:11px" class="text-muted"><?php echo $sub; ?></div>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="align-middle text-center">
                                        <?php if ($s['ok']): ?>
                                            <strong><?php echo e($calcPct($s['composite'] * 100, 2)); ?></strong>
                                            <div style="font-size:11px" class="text-muted">按方案四指标加权</div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <div style="font-size:11px" class="text-muted">未命中，不参与</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-2">当月无绩效采集数据（得分为0）</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>

                        <div class="font-weight-bold mb-1">第2步 · 各店综合 → 平均得分（排名分）</div>
                        <?php
                        $okComps = [];
                        foreach ($cDt['stores'] as $s) if ($s['ok']) $okComps[] = sprintf('%s %s', $s['store'] !== '' ? $s['store'] : '综合行', $calcPct($s['composite'] * 100, 2));
                        ?>
                        <p class="mb-0">
                            <?php if ($okComps): ?>
                                得分 = (<?php echo e(implode(' + ', $okComps)); ?>) ÷ <?php echo count($okComps); ?> 店 = <strong><?php echo e($calcPct((float)$cDt['score'] * 100, 2)); ?></strong>
                                <small class="text-muted">（存在「综合行(人工)」时只取该行得分，店铺上传行不重复计入）</small>
                            <?php else: ?>
                                得分 = <strong>0%</strong>（无有效店铺数据）
                            <?php endif; ?>
                        </p>

                        <div class="font-weight-bold mb-1 mt-3">第3步 · 部门内排名 → 底薪档位（同分按员工ID升序）</div>
                        <table class="table table-sm table-bordered mb-2" style="font-size:12px">
                            <thead class="thead-light"><tr><th style="width:70px">名次</th><th>员工</th><th>平均得分</th><th style="width:110px">本月绩效底薪</th></tr></thead>
                            <tbody>
                            <?php foreach ($cDt['ranking'] as $item): $isMe = (int)$item['id'] === (int)$cEmp['id']; ?>
                                <tr class="<?php echo $isMe ? 'table-active' : ''; ?>">
                                    <td>第<?php echo (int)$item['rank']; ?>名<?php if ($isMe): ?><span class="badge badge-info ml-1">本人</span><?php endif; ?></td>
                                    <td><?php echo e($item['name']); ?><?php if ((int)$item['rank'] <= count($cDt['rank_tiers'])): ?><small class="text-muted ml-1">→ 底薪<?php echo e($calcNum($cDt['rank_tiers'][(int)$item['rank'] - 1], 0)); ?></small><?php endif; ?></td>
                                    <td><?php echo e($calcPct($item['score'] * 100, 2)); ?></td>
                                    <td><?php echo (float)$item['amount'] > 0 ? '<strong>¥' . $calcNum($item['amount'], 2) . '</strong>' : '¥0.00 <small class="text-muted">仅前三名</small>'; ?></td>
                                </tr>
                            <?php endforeach; if (!$cDt['ranking']): ?>
                                <tr><td colspan="4" class="text-center text-muted py-2">无排名名单（绩效方案未配置）</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    <?php else: /* ===== 基数 × 综合达成率（scheme / legacy / 各异常态） ===== */ ?>
                        <?php if (in_array($cDt['mode'], ['scheme', 'legacy', 'no_metrics'], true) && !empty($cDt['perf'])): ?>

                            <?php if ($cDt['mode'] === 'legacy'): ?>
                                <p class="text-muted mb-2">该部门未配置「绩效方案」，回退旧版「客服绩效底薪」四因素算法：综合达成率 = Σ(权重×实际/目标) ÷ Σ权重。</p>
                            <?php else: ?>
                                <p class="text-muted mb-2">部门配置基数 + 方案：<strong class="text-dark"><?php if (!empty($cDt['scheme'])) echo e($cDt['scheme']['name']); ?></strong>
                                    <?php if ($sp && ((float)$sp['floor_pct'] > 0 || (float)$sp['cap_pct'] > 0)): ?><small class="text-muted ml-1">（方案含保底<?php echo e($calcNum($sp['floor_pct'], 0)); ?>%/封顶<?php echo e($calcNum($sp['cap_pct'], 0)); ?>%，仅当最终金额过低/过高时生效）</small><?php endif; ?></p>
                            <?php endif; ?>

                            <div class="font-weight-bold mb-1">第1步 · 采集数据来源（<?php echo e($year); ?>年<?php echo e($month); ?>月）</div>
                            <table class="table table-sm table-bordered mb-2" style="font-size:12px">
                                <thead class="thead-light">
                                    <tr><th>店铺</th><th>净销售额(元)</th><th>进线人数</th><th>下单人数</th><th>询单转化率(%)</th><th>旺旺回复率(%)</th><th>平均回复(秒)</th><th>成交数</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($cDt['perf_rows'] as $pr): ?>
                                    <tr>
                                        <td><?php echo e($pr['store'] !== '' ? $pr['store'] : '综合行(人工)'); ?></td>
                                        <td><?php echo e($calcNum($pr['net_sales'], 2)); ?></td>
                                        <td><?php echo (int)$pr['incoming_count']; ?></td>
                                        <td><?php echo (int)($pr['order_count'] ?? 0); ?></td>
                                        <td><?php echo e($calcNum($pr['inquiry_conv'], 2) . '%'); ?></td>
                                        <td><?php echo e($calcNum($pr['wangwang_reply'], 2) . '%'); ?></td>
                                        <td><?php echo e($calcNum($pr['reply_speed'], 1)); ?></td>
                                        <td><?php echo $pr['deal_count'] !== null && $pr['deal_count'] !== '' ? (int)$pr['deal_count'] : '<small class="text-muted">留空</small>'; ?></td>
                                    </tr>
                                <?php endforeach; if (empty($cDt['perf_rows'])): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-2">无数据</td></tr>
                                <?php endif; ?>
                                <?php if (count($cDt['perf_rows']) > 1): ?>
                                    <tr class="table-light">
                                        <td colspan="8" style="font-size:11.5px">多店聚合口径：净销售额/进线/下单人数求和；询单转化率、旺旺回复率取「有值店铺」的平均；平均回复按进线加权；成交数为各店之和。<strong>若存在「综合行(人工)」（本页编辑/补录产生）则优先只取该行。</strong></td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>

                            <p class="mb-0" style="font-size:12px">
                                用于计算的<strong>综合绩效行</strong>：净销售额 <strong class="text-dark"><?php echo e($calcNum($cDt['perf']['net_sales'], 2)); ?></strong> 元 /
                                询单转化率 <strong class="text-dark"><?php echo e($calcNum($cDt['perf']['inquiry_conv'], 2)); ?>%</strong>
                                <?php $deriv = cs_perf_conv_derivation((float)$cDt['perf']['inquiry_conv'], (int)($cDt['perf']['order_count'] ?? 0), (int)$cDt['perf']['incoming_count']); if ($deriv): ?><span class="text-muted">(<?php echo e($deriv); ?>)</span><?php endif; ?> /
                                旺旺回复率 <strong class="text-dark"><?php echo e($calcNum($cDt['perf']['wangwang_reply'], 2)); ?>%</strong> /
                                平均回复 <strong class="text-dark"><?php echo e($calcNum($cDt['perf']['reply_speed'], 1)); ?></strong> 秒 /
                                进线 <?php echo (int)$cDt['perf']['incoming_count']; ?> /
                                成交数 <?php echo (int)$cDt['deal_info']['value']; ?> <small class="text-muted">(<?php echo $cDt['deal_info']['source'] === 'auto' ? '订单系统自动统计；无人工值时用此数' : '人工录入'; ?>)</small>
                            </p>

                            <?php if ($cDt['metrics']): ?>
                                <div class="font-weight-bold mb-1 mt-3">第2步 · 每指标 <?php echo $cDt['mode'] === 'legacy' ? '实际/目标' : '命中档位'; ?> → 达成率 → 加权贡献</div>
                                <table class="table table-sm table-bordered mb-2" style="font-size:12px">
                                    <?php if ($cDt['mode'] === 'legacy'): ?>
                                        <thead class="thead-light"><tr><th>指标</th><th>实际值</th><th>目标值</th><th>达成率</th><th>权重</th><th>计算方法</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($cDt['metrics'] as $m): ?>
                                            <tr>
                                                <td><?php echo e($m['label']); ?></td>
                                                <td><?php echo e($calcNum($m['value'], $m['unit'] === '秒' ? 1 : 2) . $m['unit']); ?></td>
                                                <td><?php echo e($calcNum($m['target'], $m['unit'] === '%' ? 2 : ($m['unit'] === '秒' ? 0 : 0)) . $m['unit']); ?></td>
                                                <td><?php echo e($calcPct($m['rate'] * 100, 1)); ?></td>
                                                <td><?php echo e($calcNum($m['weight'], 1)); ?></td>
                                                <td class="text-muted"><?php echo e($m['how']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    <?php else: ?>
                                        <thead class="thead-light"><tr><th>指标</th><th>实际值</th><th>命中档位</th><th>达成率</th><th>权重</th><th>加权贡献(权重×达成率)</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($calcFixedDefs as $fd): $m = null;
                                            foreach ($cDt['metrics'] as $mm) if ($mm['key'] === $fd['key']) { $m = $mm; break; }
                                            if ($m): ?>
                                            <tr class="table-warning">
                                                <td><?php echo e($fd['label']); ?></td>
                                                <td><?php echo e($calcNum($m['value'], 2) . $m['unit']); ?></td>
                                                <td>档[<?php echo e(cs_perf_fmt_range($m['tier'])); ?>]</td>
                                                <td><?php echo e($calcPct($m['rate'] * 100, 1)); ?></td>
                                                <td><?php echo e($calcNum($m['weight'], 1)); ?></td>
                                                <td><?php echo e($calcNum($m['weight'], 1) . ' × ' . $calcPct($m['rate'] * 100, 1) . ' = ' . $calcNum($m['contribution'], 3)); ?></td>
                                            </tr>
                                            <?php elseif (!empty($spOn[$fd['key']])): ?>
                                            <tr class="text-muted">
                                                <td><?php echo e($fd['label']); ?></td>
                                                <td colspan="5">实际值 <?php echo e($calcNum((float)($cDt['perf'][$fd['field']] ?? 0), $fd['dec'])) . ' ≤ 0，未命中任何档位，不参与加权'; ?></td>
                                            </tr>
                                            <?php else: ?>
                                            <tr class="text-muted"><td><?php echo e($fd['label']); ?></td><td colspan="5">未启用（权重0或未配置档位区间，不参与加权）</td></tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        </tbody>
                                    <?php endif; ?>
                                </table>
                            <?php endif; ?>

                            <?php if ($cDt['composite'] !== null): ?>
                                <div class="font-weight-bold mb-1 mt-3">第3步 · 综合达成率 → 金额（基数 × 综合达成率，保底/封顶）</div>
                                <ol class="mb-0 pl-3" style="font-size:12.5px">
                                    <li>综合达成率 = Σ(权重×达成率) ÷ Σ权重 = <?php echo e($calcNum($cDt['rate_sum'] ?? ($cDt['w_sum'] > 0 ? $cDt['composite'] * $cDt['w_sum'] : 0), 4)); ?> ÷ <?php echo e($calcNum($cDt['w_sum'], 1)); ?> = <strong><?php echo e($calcPct($cDt['composite'] * 100, 2)); ?></strong></li>
                                    <li>基数 ¥<?php echo e(number_format($cDt['base'], 2)); ?> × 综合 <?php echo e($calcPct($cDt['composite'] * 100, 2)); ?> = <strong>¥<?php echo e(number_format($cDt['raw_amount'], 2)); ?></strong></li>
                                    <?php if (!empty($cDt['floor_amount'])): ?>
                                        <li>保底 <?php echo e($calcNum($cDt['floor_pct'], 0)); ?>% = ¥<?php echo e(number_format($cDt['floor_amount'], 2)); ?>（金额低于此值时取保底）</li>
                                    <?php endif; ?>
                                    <?php if (!empty($cDt['cap_amount'])): ?>
                                        <li>封顶 <?php echo e($calcNum($cDt['cap_pct'], 0)); ?>% = ¥<?php echo e(number_format($cDt['cap_amount'], 2)); ?>（金额高于此值时取封顶）</li>
                                    <?php endif; ?>
                                    <li class="font-weight-bold">最终绩效金额 = ¥<?php echo e(number_format($cDt['amount'], 2)); ?><?php if ($cDt['clamp_text'] && abs($cDt['amount'] - $cDt['raw_amount']) > 0.005): ?><span class="badge badge-warning ml-1">已触发<?php echo e(trim($cDt['clamp_text'])); ?></span><?php endif; ?></li>
                                </ol>
                            <?php else: ?>
                                <div class="mt-2 text-muted">方案未配置有效指标：需在「绩效配置→方案」中为指标设置权重与档位区间/目标值（净销售额/询单转化率/旺旺回复率/平均回复，或旧版的权重与目标）。</div>
                            <?php endif; ?>
                        <?php else: /* no_scheme / base_zero / no_data */ ?>
                            <div class="alert alert-light border mb-0" style="font-size:12.5px">
                                <div><strong>本月绩效金额 = ¥<?php echo e(number_format($cDt['amount'], 2)); ?></strong></div>
                                <div class="text-muted"><?php echo e($cDt['formula']); ?>
                                    <?php if ($cDt['mode'] === 'no_scheme'): ?>：需在「客服绩效」菜单的<strong>绩效配置</strong>页为部门设置基数和方案，或由薪资模块的「客服绩效底薪」旧配置提供计算参数。<?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <div class="card-body text-center text-muted">无参与员工，无法计算算法过程</div>
    <?php endif; ?>
</div>

<!-- 待匹配补录 -->
<div class="card">
    <div class="card-header bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> 待匹配补录（<?php echo $year; ?>年<?php echo $month; ?>月，共 <?php echo count($pending); ?> 条）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>旺旺账号</th><th>姓名</th><th>净销售额(元)</th><th>询单转化率(%)</th><th>下单人数</th><th>旺旺回复率(%)</th><th>进线</th><th>回复总秒</th><th>来源</th><th>归属员工</th><th style="width:190px">操作</th></tr>
                </thead>
                <tbody>
                <?php if ($pending): foreach ($pending as $p): ?>
                    <tr>
                        <td><?php echo e($p['wangwang']); ?></td>
                        <td><?php echo e($p['name']); ?></td>
                        <td><?php echo (float)$p['net_sales'] ? number_format((float)$p['net_sales'], 2) : '0.00'; ?></td>
                        <td>
                            <?php if ((float)$p['inquiry_conv'] > 0): ?>
                                <?php $derivP = cs_perf_conv_derivation((float)$p['inquiry_conv'], (int)($p['order_count'] ?? 0), (int)$p['incoming_count']); ?>
                                <?php echo rtrim(rtrim(number_format((float)$p['inquiry_conv'], 2), '0'), '.') . '%'; ?>
                                <?php if ($derivP): ?><span class="text-muted" style="font-size:11px" title="<?php echo e($derivP); ?>">(下单<?php echo (int)$p['order_count']; ?>÷进线<?php echo (int)$p['incoming_count']; ?>)</span><?php endif; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td><?php echo (int)($p['order_count'] ?? 0); ?></td>
                        <td><?php echo (float)$p['wangwang_reply'] ? rtrim(rtrim(number_format((float)$p['wangwang_reply'], 2), '0'), '.') . '%' : '-'; ?></td>
                        <td><?php echo (int)$p['incoming_count']; ?></td>
                        <td><?php echo (float)$p['total_reply_seconds']; ?></td>
                        <td><small class="text-muted"><?php echo e($p['source_file']); ?></small></td>
                        <td>
                            <form method="post" class="form-inline">
                                <input type="hidden" name="action" value="assign">
                                <input type="hidden" name="pending_id" value="<?php echo (int)$p['id']; ?>">
                                <select name="employee_id" class="form-control form-control-sm mr-1" style="width:130px">
                                    <option value="">选择员工</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name']); ?>(<?php echo e($emp['wangwang'] ?? '无'); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-primary"><i class="fas fa-check"></i> 归属</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('确定删除该待匹配记录？')">
                                <input type="hidden" name="action" value="delete_pending">
                                <input type="hidden" name="pending_id" value="<?php echo (int)$p['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> 删除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">本月没有待匹配数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
