<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
ensureCsPerfSchema();

$page_title = '客服绩效 - 本月数据';

$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));
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
        $deal = trim($_POST['deal_count'] ?? '');
        $remark = trim($_POST['remark'] ?? '');
        if ($employeeId > 0 && $y >= 2000 && $m >= 1 && $m <= 12) {
            $dealVal = $deal === '' ? null : (int)$deal;
            $stmt = db()->prepare("INSERT INTO customer_service_performance
                (employee_id, year, month, reply_speed, incoming_count, deal_count, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count),
                deal_count=VALUES(deal_count), remark=VALUES(remark), source_file='admin编辑'");
            $stmt->execute([$employeeId, $y, $m, $replySpeed, $incoming, $dealVal, $remark]);
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
                    (employee_id, year, month, reply_speed, incoming_count, deal_count, remark, source_file)
                    VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count), source_file=VALUES(source_file)");
                $remark = '由待匹配补录（' . ($p['name'] !== '' ? $p['name'] : $p['wangwang']) . '）';
                $up->execute([$employeeId, (int)$p['year'], (int)$p['month'], $replySpeed, $incoming, $remark, 'pending:' . basename((string)$p['source_file'])]);
                db()->prepare("DELETE FROM cs_perf_pending WHERE id=?")->execute([$pendingId]);
                $msg = '已把待匹配数据归属到员工并写入';
            } else {
                $err = '待匹配记录不存在或未选择员工';
            }
        } catch (PDOException $ex) {
            $err = '操作失败: ' . $ex->getMessage();
        }
    } elseif ($action === 'delete_pending') {
        db()->prepare("DELETE FROM cs_perf_pending WHERE id=?")->execute([(int)($_POST['pending_id'] ?? 0)]);
        $msg = '已删除该待匹配记录';
    }
}

$employees = get_employees();
$rows = [];
foreach ($employees as $emp) {
    if ($filterEmp > 0 && (int)$emp['id'] !== $filterEmp) continue;
    $perf = get_cs_performance((int)$emp['id'], $year, $month);
    $liveDeal = get_employee_deal_count((int)$emp['id'], $year, $month);
    $rows[] = ['emp' => $emp, 'perf' => $perf, 'liveDeal' => $liveDeal];
}

$pending = [];
try {
    $pending = db()->query("SELECT * FROM cs_perf_pending WHERE year=" . (int)$year . " AND month=" . (int)$month . " ORDER BY name, wangwang")->fetchAll();
} catch (\Throwable $e) {}

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
    <div class="card-header"><i class="fas fa-users"></i> 逐人编辑（进线人数 = 采集上报；平均回复秒 = 采集上报；成交数留空则按订单自动统计）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle">
                <thead class="thead-light">
                    <tr>
                        <th>员工</th><th>旺旺账号</th><th>进线人数</th><th>平均回复(秒)</th>
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
                                <td><input type="number" name="incoming_count" min="0" class="form-control form-control-sm" style="width:110px" value="<?php echo $perf ? (int)$perf['incoming_count'] : 0; ?>"></td>
                                <td><input type="number" name="reply_speed" min="0" step="any" class="form-control form-control-sm" style="width:110px" value="<?php echo $perf ? (float)$perf['reply_speed'] : 0; ?>"></td>
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
                    <tr><td colspan="7" class="text-center text-muted py-4">暂无员工</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 待匹配补录 -->
<div class="card">
    <div class="card-header bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> 待匹配补录（<?php echo $year; ?>年<?php echo $month; ?>月，共 <?php echo count($pending); ?> 条）</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered mb-0">
                <thead class="thead-light">
                    <tr><th>旺旺账号</th><th>姓名</th><th>进线</th><th>回复总秒</th><th>来源</th><th>归属员工</th><th style="width:190px">操作</th></tr>
                </thead>
                <tbody>
                <?php if ($pending): foreach ($pending as $p): ?>
                    <tr>
                        <td><?php echo e($p['wangwang']); ?></td>
                        <td><?php echo e($p['name']); ?></td>
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
                    <tr><td colspan="7" class="text-center text-muted py-3">本月没有待匹配数据</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
