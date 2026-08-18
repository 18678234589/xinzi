<?php
require_once __DIR__ . '/../includes/auth.php';
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

// 绩效名单：绩效底薪只涉及名单内员工，页面可自定义增删
$upMsg = '';
$upErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'member_exclude') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($eid > 0 && exclude_cs_perf_member($eid)) {
            $upMsg = '已排除该员工，之后不再计入客服绩效';
        } else {
            $upErr = '排除失败，请重试';
        }
    } elseif ($action === 'member_include') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($eid > 0 && include_cs_perf_member($eid)) {
            $upMsg = '已恢复该员工参与客服绩效';
        } else {
            $upErr = '恢复失败，请重试';
        }
    } elseif ($action === 'import') {
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $upY = (int)($_POST['upload_year'] ?? 0);
            $upM = (int)($_POST['upload_month'] ?? 0);
            if (!($upY >= 2000 && $upM >= 1 && $upM <= 12)) { $upY = (int)date('Y', strtotime('-1 month')); $upM = (int)date('n', strtotime('-1 month')); }
            $store = trim((string)($_POST['store'] ?? ''));
            $r = import_cs_perf_file($_FILES['file']['tmp_name'], 'admin:' . basename($_FILES['file']['name']), $upY, $upM, $store);
            $upMsg = sprintf('导入完成（归入 %d-%02d 月%s）：匹配 %d 人，未匹配 %d 条，错误 %d 条', $upY, $upM, $store !== '' ? '，「店铺」=' . htmlspecialchars($store, ENT_QUOTES, 'UTF-8') : '', $r['matched'], $r['pending'], $r['errors']);
        } else {
            $upErr = '请选择要导入的绩效表文件（XLSX / CSV / TXT）';
        }
    } elseif ($action === 'upload_delete') {
        $src = trim($_POST['source_file'] ?? '');
        if ($src !== '') {
            $q = db()->quote($src);
            db()->exec("DELETE FROM customer_service_performance WHERE source_file=$q");
            db()->exec("DELETE FROM cs_perf_pending WHERE source_file=$q");
            db()->exec("DELETE FROM cs_perf_sync_log WHERE source_file=$q");
            $upMsg = '已删除该次上传的数据（匹配数据、待匹配记录及导入记录）';
        } else {
            $upErr = '删除失败：未指定来源文件';
        }
    }
}

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

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-comments-dollar"></i> 客服绩效</h4>
    <div>
        <a href="month.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-edit"></i> 本月的补录/编辑
        </a>
        <a href="schemes.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-tasks"></i> 绩效方案/算法
        </a>
        <a href="#perfUpload" class="btn btn-primary btn-sm">
            <i class="fas fa-cloud-upload-alt"></i> 上传绩效表
        </a>
    </div>
</div>

<?php if ($upMsg): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($upMsg); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($upErr): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($upErr); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 月份筛选 -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="form-inline">
            <div class="form-group mr-2">
                <label class="mr-1">月份:</label>
                <select name="year" class="form-control form-control-sm" style="width:90px">
                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                    <?php endfor; ?>
                </select>
                <select name="month" class="form-control form-control-sm ml-1" style="width:90px">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $month === $m ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-search"></i> 查看</button>
        </form>
    </div>
</div>

<!-- 上传绩效表（官网导出） -->
<div class="card border-primary mb-3" id="perfUpload">
    <div class="card-header bg-primary text-white"><i class="fas fa-cloud-upload-alt"></i> 上传绩效表（归入 <?php echo $upYD; ?>年<?php echo $upMD; ?>月）</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="mb-0">
            <input type="hidden" name="action" value="import">
            <div class="form-row align-items-end mb-2">
                <div class="col-auto">
                    <label class="small text-muted">归入月份（文件里没有日期时使用，默认上个月）</label>
                    <div class="form-inline">
                        <select name="upload_year" class="form-control form-control-sm mr-1" style="width:92px">
                            <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $upYD === $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                            <?php endfor; ?>
                        </select>
                        <select name="upload_month" class="form-control form-control-sm ml-1" style="width:86px">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $upMD === $m ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="col-auto">
                    <label class="small text-muted">店铺名称（同一员工允许多店；<strong>设计客服</strong>请按店铺分别上传两店并填写区分，用于「两店绩效平均」）</label>
                    <input type="text" name="store" class="form-control form-control-sm" placeholder="如：美呀美旗舰店" style="width:180px">
                </div>
            </div>
            <div class="form-row align-items-end">
                <div class="col">
                    <label class="small text-muted">选择千牛/百牛官网导出的客服绩效表（XLSX / CSV / TXT，UTF-8 或 GBK）</label>
                    <input type="file" name="file" accept=".xlsx,.csv,.txt" class="form-control" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> 上传并自动匹配</button>
                </div>
            </div>
            <small class="text-muted d-block mt-2">
                <i class="fas fa-info-circle"></i> 系统自动识别列名：客服/旺旺、净销售额、询单最终下单转化率、旺旺回复率、平均响应时长（响应时长支持 HH:MM:SS / X分X秒 / 秒）；
                <strong>转化率：</strong>上传表没有转化率列也没关系，识别到「下单人数 + 询单人数」时会自动按 <strong>转化率 = 下单人数 ÷ 询单人数</strong> 计算，并在页面显示计算过程（下单X ÷ 询单Y = Z%）；
                文件里没有日期时归入上方所选月份（默认上个月）；导入后按「旺旺账号 → 姓名」自动匹配名单内员工，未匹配的进入下方【待匹配清单】。
                <strong>设计客服：</strong>两店数据请分别上传（店铺名不同即可），系统会各自算出达成率并取平均值，再按部门内前三名定底薪 850/800/750 元。
            </small>
        </form>
    </div>
</div>

<!-- 绩效参与名单（按部门自动） -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-user-cog"></i> 绩效参与名单（部门配置了基数+方案 → 自动参与；<strong>设计客服</strong>恒参与并按排名定底薪）</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>姓名</th><th>部门</th><th>绩效基数+方案</th><th style="width:120px">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($participants as $emp): ?>
                    <?php $rc = $deptCfgMap[(string)$emp['department']] ?? null; ?>
                    <?php $isRankDept = ((string)$emp['department'] === CS_PERF_RANK_DEPT); ?>
                    <tr>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td>
                            <?php if ($isRankDept): ?>
                                <span class="badge badge-success"><i class="fas fa-trophy"></i> 按排名定底薪（两店绩效平均，前三名 850/800/750）</span>
                            <?php elseif ($rc): ?>
                                <strong><?php echo number_format((float)$rc['base'], 2); ?></strong>
                                <small class="text-muted">(<?php echo e($rc['scheme_name'] ?: '默认方案'); ?>)</small>
                            <?php else: ?>
                                <span class="text-danger small"><i class="fas fa-exclamation-circle"></i> 部门未配置，去<a href="schemes.php">设置</a></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" onsubmit="return confirm('排除后该员工将不再计入客服绩效，确定？')">
                                <input type="hidden" name="action" value="member_exclude">
                                <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i> 排除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$participants): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">暂无参与员工。普通部门请先在「<a href="schemes.php">绩效方案/算法</a>」页配置<strong>绩效基数</strong>与<strong>方案</strong>后自动参与；<strong>设计客服</strong>无需配置即按排名参与。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($excluded): ?>
        <h6 class="mt-3 mb-2"><i class="fas fa-ban text-danger"></i> 已排除员工（不再计入）</h6>
        <table class="table table-sm table-bordered mb-0" style="max-width:560px">
            <tbody>
            <?php foreach ($excluded as $emp): ?>
                <tr>
                    <td><?php echo e($emp['name']); ?></td>
                    <td><span class="badge badge-secondary"><?php echo e($emp['department']); ?></span></td>
                    <td>
                        <form method="post" class="mb-0">
                            <input type="hidden" name="action" value="member_include">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                            <button class="btn btn-sm btn-outline-success"><i class="fas fa-undo"></i> 恢复</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> 员工按所在部门自动参与绩效：普通部门为「基数×综合达成率」，<strong>设计客服</strong>为「两店绩效平均 → 部门内前三名定底薪 850/800/750」；被排除者不会出现在总览、也不计入薪资。导入的绩效表按「旺旺账号 → 姓名」自动匹配到对应员工名下。</small>
    </div>
</div>

<!-- 员工绩效概览 -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-users"></i> 客服绩效总览（<?php echo $year; ?>年<?php echo $month; ?>月）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>员工</th><th>旺旺账号</th><th>部门</th>
                        <th>净销售额(元)</th><th>询单转化率(%)</th><th>旺旺回复率(%)</th><th>平均响应(秒)</th>
                        <th>绩效金额</th>
                        <th>来源</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <?php
                    $emp  = $r['emp'];
                    $perf = $r['perf'];
                    $netSales   = $perf ? (float)$perf['net_sales'] : 0.0;
                    $inquiryConv = $perf ? (float)$perf['inquiry_conv'] : 0.0;
                    $wangReply  = $perf ? (float)$perf['wangwang_reply'] : 0.0;
                    $avgResponse = $perf ? (float)$perf['reply_speed'] : 0.0;
                    $calc = $r['calc'];
                    $calcRank = (($emp['department'] ?? '') === CS_PERF_RANK_DEPT && isset($calc['rank']) && $calc['rank'] !== null) ? (int)$calc['rank'] : null;
                    ?>
                    <tr>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><?php echo e($emp['wangwang'] ?? ''); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td><?php echo $netSales > 0 ? number_format($netSales, 2) : '<span class="text-muted">-</span>'; ?></td>
                        <td>
                            <?php if ($inquiryConv > 0): ?>
                                <?php
                                $orderCount = (int)($perf['order_count'] ?? 0);
                                $incTmp     = (int)($perf['incoming_count'] ?? 0);
                                $deriv = cs_perf_conv_derivation($inquiryConv, $orderCount, $incTmp);
                                ?>
                                <span title="<?php echo $deriv ? e($deriv) : '询单转化率'; ?>"><?php echo rtrim(rtrim(number_format($inquiryConv, 2, '.', ''), '0'), '.') . '%'; ?></span>
                                <?php if ($deriv): ?><span class="text-muted" style="font-size:11px">(下单<?php echo $orderCount; ?>÷询单<?php echo $incTmp; ?>)</span><?php endif; ?>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </td>
                        <td><?php echo $wangReply > 0 ? $wangReply . '%' : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $avgResponse > 0 ? $avgResponse : '<span class="text-muted">-</span>'; ?></td>
                        <td>
                            <?php if ((float)$calc['amount'] > 0): ?>
                                <a href="#" class="text-decoration-none" data-toggle="tooltip" title="<?php echo e($calc['formula']); ?>" onclick="return false;"><strong><?php echo number_format((float)$calc['amount'], 2); ?></strong> <i class="fas fa-info-circle text-muted"></i></a>
                                <?php if ($calcRank !== null): ?><span class="badge badge-warning ml-1">第<?php echo $calcRank; ?>名</span><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" title="<?php echo e($calc['formula']); ?>">0<?php echo $calcRank === null ? '' : '（第' . $calcRank . '名）'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $perf ? e($perf['source_file'] ?: '已录入') : '<span class="text-muted">无数据</span>'; ?></td>
                        <td>
                            <a href="month.php?employee_id=<?php echo (int)$emp['id']; ?>&year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> 编辑</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">暂无员工</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($pending): ?>
<!-- 待匹配清单 -->
<div class="card mb-3">
    <div class="card-header bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> 待匹配清单（<?php echo $year; ?>年<?php echo $month; ?>月，共 <?php echo count($pending); ?> 条）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>旺旺账号</th><th>姓名</th><th>进线</th><th>回复总秒</th><th>来源</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $p): ?>
                    <tr>
                        <td><?php echo e($p['wangwang']); ?></td>
                        <td><?php echo e($p['name']); ?></td>
                        <td><?php echo (int)$p['incoming_count']; ?></td>
                        <td><?php echo (float)$p['total_reply_seconds']; ?></td>
                        <td><small class="text-muted"><?php echo e($p['source_file']); ?></small></td>
                        <td><a href="month.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-user-cog"></i> 补录归属</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($logs): ?>
<!-- 同步日志：已上传的绩效表（可查看/删除） -->
<div class="card">
    <div class="card-header"><i class="fas fa-history"></i> 已上传的绩效表（最近导入记录，共 <?php echo count($logFiles); ?> 个文件）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr><th>时间</th><th>来源文件</th><th>匹配</th><th>未匹配</th><th>错误</th><th style="width:150px">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logFiles as $k => $lf): ?>
                    <?php $l = $lf['latest']; $fname = preg_replace('/^admin:/', '', (string)$k); ?>
                    <tr>
                        <td><?php echo e($l['created_at']); ?></td>
                        <td>
                            <?php echo e($fname !== '' ? $fname : $k); ?>
                            <?php if ($lf['count'] > 1): ?>
                                <span class="badge badge-warning" title="同一文件被上传了 <?php echo (int)$lf['count']; ?> 次">上传了 <?php echo (int)$lf['count']; ?> 次</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int)$l['matched']; ?></td>
                        <td><?php echo (int)$l['pending']; ?></td>
                        <td><?php echo (int)$l['errors']; ?></td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-info view-upload-btn"
                                    data-source="<?php echo e($l['source_file']); ?>" data-name="<?php echo e($fname !== '' ? $fname : $k); ?>">
                                <i class="fas fa-eye"></i> 查看
                            </button>
                            <form method="post" class="d-inline" onsubmit="return confirm('确定删除该次上传的所有数据？\n将移除本次导入的匹配数据与待匹配记录，员工该月绩效将变为无数据。');">
                                <input type="hidden" name="action" value="upload_delete">
                                <input type="hidden" name="source_file" value="<?php echo e($l['source_file']); ?>">
                                <button class="btn btn-sm btn-outline-danger" title="删除"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <small class="text-muted d-block p-2"><i class="fas fa-info-circle"></i> 「查看」展示该次上传实际导入的数据（已匹配 + 未匹配 + 错误明细）；「删除」会连同该文件导入的匹配/待匹配数据一并移除。</small>
    </div>
</div>
<?php endif; ?>

<!-- 上传明细模态框 -->
<div class="modal fade" id="uploadViewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-table"></i> 上传明细 <small id="uvSource" class="text-muted"></small></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="uvBody"></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
function escHtml(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function pad2(n){ n = Number(n); if (isNaN(n)) return ''; return String(n).length < 2 ? '0'+n : String(n); }
function renderUploadView(d){
    var html = '', i;
    if(!d || !d.found){ $('#uvBody').html('<div class="alert alert-warning mb-0">未找到该上传记录</div>'); return; }
    var log = d.log;
    if(log){
        html += '<div class="alert alert-light border mb-3 py-2">导入时间 <b>'+escHtml(log.created_at)+'</b>；匹配 <b>'+escHtml(log.matched)+'</b> 人，未匹配 <b>'+escHtml(log.pending)+'</b> 条，错误 <b>'+escHtml(log.errors)+'</b> 条</div>';
    }
    if(log && log.errors > 0 && log.detail && log.detail.length){
        html += '<div class="alert alert-danger py-2"><strong>错误明细：</strong><ul class="mb-0">';
        for(i=0;i<log.detail.length;i++){ html += '<li>'+escHtml(log.detail[i])+'</li>'; }
        html += '</ul></div>';
    }
    if(d.matched && d.matched.length){
        html += '<h6 class="font-weight-bold text-primary mt-3"><i class="fas fa-user-check"></i> 已匹配数据（'+d.matched.length+'）</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-3"><thead class="thead-light"><tr><th>员工</th><th>旺旺</th><th>部门</th><th>月份</th><th>净销售额</th><th>转化率%</th><th>下单人数</th><th>回复率%</th><th>响应(秒)</th></tr></thead><tbody>';
        for(i=0;i<d.matched.length;i++){
            var r = d.matched[i];
            var deriv = (r.order_count>0 && Number(r.incoming_count)>0) ? '（转化率=下单'+r.order_count+'÷询单'+r.incoming_count+'）' : '';
            html += '<tr><td>'+escHtml(r.emp_name||'')+'</td><td>'+escHtml(r.emp_wang||'')+'</td><td>'+escHtml(r.department||'')+'</td>'
                  + '<td>'+escHtml(r.year)+'-'+pad2(r.month)+'</td><td>'+escHtml(r.net_sales)+'</td>'
                  + '<td title="'+escHtml(deriv)+'">'+escHtml(r.inquiry_conv)+(deriv?' <span style="font-size:11px">'+escHtml('('+r.order_count+'÷'+r.incoming_count+')')+'</span>':'')+'</td>'
                  + '<td>'+escHtml(r.order_count||0)+'</td>'
                  + '<td>'+escHtml(r.wangwang_reply)+'</td><td>'+escHtml(r.reply_speed)+'</td></tr>';
        }
        html += '</tbody></table></div>';
    } else {
        html += '<div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle"></i> 该次上传未匹配到任何员工数据（matched=0）。</div>';
    }
    if(d.pending && d.pending.length){
        html += '<h6 class="font-weight-bold text-warning mt-3"><i class="fas fa-user-clock"></i> 未匹配暂存（'+d.pending.length+'）</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-3"><thead class="thead-light"><tr><th>旺旺</th><th>姓名</th><th>月份</th><th>进线</th><th>回复总秒</th><th>净销售额</th><th>转化率%</th><th>下单人数</th><th>回复率%</th></tr></thead><tbody>';
        for(i=0;i<d.pending.length;i++){
            var p = d.pending[i];
            var deriv = (p.order_count>0 && Number(p.incoming_count)>0) ? '（转化率=下单'+p.order_count+'÷询单'+p.incoming_count+'）' : '';
            html += '<tr><td>'+escHtml(p.wangwang||'')+'</td><td>'+escHtml(p.name||'')+'</td><td>'+escHtml(p.year)+'-'+pad2(p.month)+'</td>'
                  + '<td>'+escHtml(p.incoming_count)+'</td><td>'+escHtml(p.total_reply_seconds)+'</td><td>'+escHtml(p.net_sales)+'</td>'
                  + '<td title="'+escHtml(deriv)+'">'+escHtml(p.inquiry_conv)+(deriv?' <span style="font-size:11px">'+escHtml('('+p.order_count+'÷'+p.incoming_count+')')+'</span>':'')+'</td>'
                  + '<td>'+escHtml(p.order_count||0)+'</td>'
                  + '<td>'+escHtml(p.wangwang_reply)+'</td></tr>';
        }
        html += '</tbody></table></div>';
    } else {
        html += '<div class="alert alert-info py-2"><i class="fas fa-check"></i> 无未匹配暂存。</div>';
    }
    $('#uvBody').html(html);
}
$(document).ready(function(){
    $(document).on('click', '.view-upload-btn', function(){
        var src  = $(this).attr('data-source');
        var name = $(this).attr('data-name');
        $('#uvSource').text(name);
        $('#uvBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> 加载中...</div>');
        $('#uploadViewModal').modal('show');
        fetch('index.php?action=upload_view&source='+encodeURIComponent(src))
            .then(function(r){ return r.json(); })
            .then(function(d){ renderUploadView(d); })
            .catch(function(){ $('#uvBody').html('<div class="alert alert-danger mb-0">加载失败，请重试</div>'); });
    });
});
</script>

