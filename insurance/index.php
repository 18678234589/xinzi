<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = '保险管理';
$success = '';
$error = '';

// 配置文件路径
$configPath = __DIR__ . '/../config/insurance.php';

// 读取当前配置
$insuranceConfig = @include $configPath;
$currentAmount = (float)($insuranceConfig['amount'] ?? 0);
$updated_at = '';
if (file_exists($configPath)) {
    $fmtime = filemtime($configPath);
    if ($fmtime) $updated_at = date('Y-m-d H:i:s', $fmtime);
}

// 保存金额
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $newAmount = round((float)($_POST['amount'] ?? 0), 2);
        if ($newAmount < 0) {
            $error = '保险金额不能为负数';
        } else {
            $configContent = "<?php\n/**\n * 保险扣除配置\n *\n * amount = 每月保险扣除金额（全员统一）\n * 每年基数变化时，只需修改此金额即可。\n */\nreturn [\n    'amount' => " . var_export($newAmount, true) . ",\n];\n";
            if (@file_put_contents($configPath, $configContent) !== false) {
                $currentAmount = $newAmount;
                $updated_at = date('Y-m-d H:i:s');
                $success = sprintf('保险金额已更新为 ¥%s，结算时将自动扣除', money($newAmount));
            } else {
                $error = '配置文件写入失败，请检查 config/ 目录权限';
            }
        }
    }
}

// 统计有多少员工已结算的薪资记录中扣除了保险
$insStats = ['total' => 0, 'total_deduct' => 0];
try {
    $stmt = db()->query("SELECT COUNT(*) as cnt, COALESCE(SUM(insurance_amount),0) as total FROM salaries WHERE insurance_amount > 0");
    $row = $stmt->fetch();
    if ($row) {
        $insStats['total'] = (int)$row['cnt'];
        $insStats['total_deduct'] = (float)$row['total'];
    }
} catch (PDOException $e) {
    // insurance_amount 列可能还不存在，忽略
}

// 获取员工总数
$empCount = 0;
try {
    $empCount = (int)db()->query("SELECT COUNT(*) FROM employees")->fetchColumn();
} catch (PDOException $e) {}

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-shield-alt text-info"></i> 保险管理</h4>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<div class="row">
    <!-- 左侧：金额设置 -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-edit text-primary"></i> 保险金额设置</h5></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <div class="form-group">
                        <label><i class="fas fa-shield-alt text-info"></i> 每月保险扣除金额</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                            <input type="number" name="amount" class="form-control form-control-lg" step="0.01" min="0" value="<?php echo e($currentAmount); ?>" required>
                        </div>
                        <small class="text-muted">全员统一金额，每月薪资结算时默认扣除。每年基数变化时，直接在这里修改即可。</small>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="fas fa-save"></i> 保存</button>
                </form>
                <?php if ($updated_at): ?>
                <div class="text-muted text-center mt-2" style="font-size:12px;">
                    <i class="far fa-clock"></i> 最后更新：<?php echo e($updated_at); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 右侧：信息说明 + 统计 -->
    <div class="col-md-7">
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-info-circle text-info"></i> 使用说明</h5></div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>设置金额后，在<b>薪资结算</b>页面会默认勾选"扣除保险"，自动从实发工资中扣除该金额。</li>
                    <li>遇到<b>不需要扣保险的员工</b>，在结算页面手动取消勾选即可。</li>
                    <li>每年保险基数变化时，只需在此页面修改金额，所有后续结算自动使用新金额。</li>
                    <li>已结算的历史记录不受影响，保持原扣除金额。</li>
                </ul>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="card stat-card blue">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-2x text-muted mb-2"></i>
                        <div class="h4 mb-0"><?php echo $empCount; ?></div>
                        <small class="text-muted">员工总数</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card orange">
                    <div class="card-body text-center">
                        <i class="fas fa-receipt fa-2x text-muted mb-2"></i>
                        <div class="h4 mb-0"><?php echo $insStats['total']; ?></div>
                        <small class="text-muted">已扣保险结算数</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card green">
                    <div class="card-body text-center">
                        <i class="fas fa-yen-sign fa-2x text-muted mb-2"></i>
                        <div class="h4 mb-0">¥<?php echo money($insStats['total_deduct']); ?></div>
                        <small class="text-muted">累计扣除金额</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-paper-plane text-success"></i> 快捷入口</h6></div>
            <div class="card-body">
                <a href="<?php echo BASE_URL; ?>/salaries/settle.php" class="btn btn-outline-success"><i class="fas fa-calculator"></i> 前往薪资结算</a>
                <a href="<?php echo BASE_URL; ?>/salaries/query.php" class="btn btn-outline-info"><i class="fas fa-search-dollar"></i> 查看薪资记录</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
