<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
ensureCsPerfSchema();

$page_title = '客服绩效';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
if ($month < 1 || $month > 12)    $month = (int)date('m');

// 手动上传导入一份采集文件
$upMsg = '';
$upErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $r = import_cs_perf_file($_FILES['file']['tmp_name'], 'admin:' . basename($_FILES['file']['name']));
        $upMsg = sprintf('导入完成：匹配 %d 人，未匹配 %d 条，错误 %d 条', $r['matched'], $r['pending'], $r['errors']);
    } else {
        $upErr = '请选择要导入的采集文件（CSV）';
    }
}

$employees = get_employees();
$rows = [];
foreach ($employees as $emp) {
    $perf = get_cs_performance((int)$emp['id'], $year, $month);
    $liveDeal = get_employee_deal_count((int)$emp['id'], $year, $month);
    $deal = $perf && $perf['deal_count'] !== null ? (int)$perf['deal_count'] : $liveDeal;
    $rows[] = ['emp' => $emp, 'perf' => $perf, 'deal' => $deal, 'liveDeal' => $liveDeal];
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
        <button class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#importModal">
            <i class="fas fa-file-upload"></i> 手动导入采集文件
        </button>
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

<!-- 员工绩效概览 -->
<div class="card mb-3">
    <div class="card-header"><i class="fas fa-users"></i> 客服绩效总览（<?php echo $year; ?>年<?php echo $month; ?>月）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>员工</th><th>旺旺账号</th><th>部门</th>
                        <th>进线人数</th><th>平均回复(秒)</th><th>成交数</th><th>转化率</th>
                        <th>来源</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                    <?php
                    $emp  = $r['emp'];
                    $perf = $r['perf'];
                    $incoming = $perf ? (int)$perf['incoming_count'] : 0;
                    $replySpd = $perf ? (float)$perf['reply_speed'] : 0;
                    $conv = $incoming > 0 ? round($r['deal'] / $incoming * 100, 1) : 0;
                    $hasData = $perf && $incoming > 0;
                    ?>
                    <tr>
                        <td><?php echo e($emp['name']); ?></td>
                        <td><?php echo e($emp['wangwang'] ?? ''); ?></td>
                        <td><span class="badge badge-info"><?php echo e($emp['department']); ?></span></td>
                        <td><?php echo $incoming ?: '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $replySpd > 0 ? $replySpd : '<span class="text-muted">-</span>'; ?></td>
                        <td>
                            <?php echo $r['deal']; ?>
                            <?php if ($perf && $perf['deal_count'] !== null): ?>
                                <span class="badge badge-warning" title="手动覆盖">改</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $hasData ? $conv . '%' : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $perf ? e($perf['source_file'] ?: '已录入') : '<span class="text-muted">无数据</span>'; ?></td>
                        <td>
                            <a href="month.php?employee_id=<?php echo (int)$emp['id']; ?>&year=<?php echo $year; ?>&month=<?php echo $month; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> 编辑</a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">暂无员工</td></tr>
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

<!-- 手动导入模态 -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <div class="modal-header">
                    <h5 class="modal-title">手动导入客服绩效采集文件</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">选择客服采集工具（或第三方）生成的 CSV，列名需含：姓名/旺旺、日期、进线数、回复总秒数（或回复速度）。导入后按旺旺账号/姓名自动匹配员工。</p>
                    <input type="file" name="file" accept=".csv,.txt" class="form-control-file">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> 导入</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
