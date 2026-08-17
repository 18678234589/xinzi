<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
ensureCsPerfSchema();

$page_title = '客服绩效';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
if ($month < 1 || $month > 12)    $month = (int)date('m');

// 绩效名单：绩效底薪只涉及名单内员工，页面可自定义增删
$upMsg = '';
$upErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'member_add') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($eid > 0 && add_cs_perf_member($eid)) {
            $upMsg = '已加入绩效名单，之后计入客服绩效';
        } else {
            $upErr = '加入失败，请重试';
        }
    } elseif ($action === 'member_remove') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($eid > 0 && remove_cs_perf_member($eid)) {
            $upMsg = '已从绩效名单移除';
        } else {
            $upErr = '移除失败，请重试';
        }
    } elseif ($action === 'import') {
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $r = import_cs_perf_file($_FILES['file']['tmp_name'], 'admin:' . basename($_FILES['file']['name']), $year, $month);
            $upMsg = sprintf('导入完成：匹配 %d 人，未匹配 %d 条，错误 %d 条', $r['matched'], $r['pending'], $r['errors']);
        } else {
            $upErr = '请选择要导入的绩效表文件（CSV/TXT）';
        }
    }
}

$allEmployees = get_employees();
$roster       = get_cs_perf_members();
$rosterIds = [];
foreach ($roster as $r) $rosterIds[(int)$r['id']] = true;
$addable = [];
foreach ($allEmployees as $e) {
    if (isset($rosterIds[(int)$e['id']])) continue;
    $addable[] = $e;
}

$rows = [];
$deptCfgMap = [];
foreach (get_cs_perf_dept_configs() as $dc) $deptCfgMap[(string)$dc['department']] = $dc;
foreach ($roster as $emp) {
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
    <div class="card-header bg-primary text-white"><i class="fas fa-cloud-upload-alt"></i> 上传绩效表（<?php echo $year; ?>年<?php echo $month; ?>月）</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="mb-0">
            <input type="hidden" name="action" value="import">
            <div class="form-row align-items-end">
                <div class="col">
                    <label class="small text-muted">选择千牛/百牛官网导出的客服绩效表（CSV / TXT，UTF-8 或 GBK）</label>
                    <input type="file" name="file" accept=".csv,.txt" class="form-control" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> 上传并自动匹配</button>
                </div>
            </div>
            <small class="text-muted d-block mt-2">
                <i class="fas fa-info-circle"></i> 系统自动识别列名：客服/旺旺、净销售额、询单最终下单转化率、旺旺回复率、平均响应时长（响应时长支持 HH:MM:SS / X分X秒 / 秒）；
                文件中没有日期时默认归入当前所选月份；导入后按「旺旺账号 → 姓名」自动匹配名单内员工，未匹配的进入下方【待匹配清单】。
            </small>
        </form>
    </div>
</div>

<!-- 绩效人员名单管理 -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-user-cog"></i> 绩效人员名单（绩效底薪只统计名单内员工，按需增删）</div>
    <div class="card-body">
        <form method="post" class="form-inline mb-2">
            <input type="hidden" name="action" value="member_add">
            <select name="employee_id" class="form-control form-control-sm mr-1" style="width:240px" required>
                <option value="">选择员工加入名单</option>
                <?php foreach ($addable as $emp): ?>
                    <option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name']); ?>（<?php echo e($emp['department']); ?>）</option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> 添加</button>
            <?php if (!$addable): ?>
                <span class="ml-2 text-muted small">所有员工都已在名单中</span>
            <?php endif; ?>
        </form>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>姓名</th><th>部门</th><th>绩效基数</th><th style="width:100px">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($roster as $emp): ?>
                    <?php $rc = $deptCfgMap[(string)$emp['department']] ?? null; ?>
                    <tr>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td>
                            <?php if ($rc): ?>
                                <strong><?php echo number_format((float)$rc['base'], 2); ?></strong>
                                <small class="text-muted">(<?php echo e($rc['scheme_name'] ?: '默认方案'); ?>)</small>
                            <?php else: ?>
                                <span class="text-danger small"><i class="fas fa-exclamation-circle"></i> 未配置，去<a href="schemes.php">设置</a></span>
                            <?php endif; ?>
                        </td>
                        <td style="width:100px">
                            <form method="post" onsubmit="return confirm('移除后将不再统计该员工的客服绩效，确定？')">
                                <input type="hidden" name="action" value="member_remove">
                                <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i> 移除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$roster): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">名单为空，请从上方选择员工加入</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> 只有名单内的员工参与绩效底薪；导入的绩效表会按「旺旺账号 → 姓名」自动匹配到对应员工名下。</small>
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
                    ?>
                    <tr>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><?php echo e($emp['wangwang'] ?? ''); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td><?php echo $netSales > 0 ? number_format($netSales, 2) : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $inquiryConv > 0 ? $inquiryConv . '%' : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $wangReply > 0 ? $wangReply . '%' : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $avgResponse > 0 ? $avgResponse : '<span class="text-muted">-</span>'; ?></td>
                        <td>
                            <?php if ((float)$calc['amount'] > 0): ?>
                                <a href="#" class="text-decoration-none" data-toggle="tooltip" title="<?php echo e($calc['formula']); ?>" onclick="return false;"><strong><?php echo number_format((float)$calc['amount'], 2); ?></strong> <i class="fas fa-info-circle text-muted"></i></a>
                            <?php else: ?>
                                <span class="text-muted" title="<?php echo e($calc['formula']); ?>">0</span>
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
<!-- 同步日志 -->
<div class="card">
    <div class="card-header"><i class="fas fa-history"></i> 最近导入记录</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr><th>时间</th><th>来源</th><th>匹配</th><th>未匹配</th><th>错误</th><th>明细</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td><?php echo e($l['created_at']); ?></td>
                        <td><?php echo e($l['source_file']); ?></td>
                        <td><?php echo (int)$l['matched']; ?></td>
                        <td><?php echo (int)$l['pending']; ?></td>
                        <td><?php echo (int)$l['errors']; ?></td>
                        <td><small class="text-muted"><?php echo e($l['detail'] ?? ''); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
