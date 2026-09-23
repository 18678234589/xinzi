<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/etmll_sync.php';

$page_title = 'ETMLL订单同步';
$success = '';
$error   = '';
$notice  = '';
$result  = null;   // 正式同步结果
$preview = null;   // 预览结果（未写入）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    set_time_limit(0);
    try {
        if ($action === 'sync') {
            $result  = etmll_sync_run(false);
            $success = "同步完成：新增 {$result['inserted']} 条店铺订单（本站已存在跳过 {$result['skipped_existing']} 条"
                     . ($result['skipped_unpaid'] > 0 ? "，未付款跳过 {$result['skipped_unpaid']} 条" : '')
                     . '）'
                     . ($result['project_filled'] > 0 ? "；已自动补全 {$result['project_filled']} 张项目订单的售价/店铺/交易状态" : '');
        } elseif ($action === 'preview') {
            $preview = etmll_sync_run(true);
            $notice  = "预览完成（未写入任何数据）：本次将新增 {$preview['inserted']} 条";
        }
    } catch (Throwable $ex) {
        $error = '操作失败: ' . $ex->getMessage();
    }
}

$status = null;
try {
    $status = etmll_sync_status();
} catch (Throwable $ex) {
    if ($error === '') {
        $error = '无法连接ETMLL数据库: ' . $ex->getMessage();
    }
}

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-sync-alt"></i> ETMLL订单同步</h4>
        <span class="badge badge-info ml-2" style="font-size:.9em"><i class="fas fa-database"></i> ETMLL系统（jujian库）</span>
    </div>
    <a href="<?php echo BASE_URL; ?>/shops/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> 返回店铺管理
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($notice): ?>
    <div class="alert alert-info alert-dismissible fade show"><i class="fas fa-info-circle"></i> <?php echo e($notice); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<?php if ($status): ?>
<!-- 统计卡片 -->
<div class="row mb-3">
    <div class="col-md-3 col-6">
        <div class="card stat-card blue"><div class="card-body">
            <h6 class="text-muted mb-1">ETMLL有效订单</h6>
            <h4 class="font-weight-bold mb-0"><?php echo number_format($status['etmll_total']); ?> 条</h4>
        </div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card green"><div class="card-body">
            <h6 class="text-muted mb-1">本站店铺流水</h6>
            <h4 class="font-weight-bold mb-0"><?php echo number_format($status['local_total']); ?> 条</h4>
        </div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card purple"><div class="card-body">
            <h6 class="text-muted mb-1">已从ETMLL同步</h6>
            <h4 class="font-weight-bold mb-0"><?php echo number_format($status['synced_total']); ?> 条</h4>
        </div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card orange"><div class="card-body">
            <h6 class="text-muted mb-1">最近同步时间</h6>
            <h6 class="font-weight-bold mb-0"><?php echo $status['last_sync_at'] ? e($status['last_sync_at']) : '从未同步'; ?></h6>
        </div></div>
    </div>
</div>

<div class="row">
    <!-- 左侧：店铺对照 -->
    <div class="col-md-7">
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-store text-info"></i> 店铺订单对照（ETMLL ↔ 本站）</h5></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>店铺</th><th style="width:110px">ETMLL订单</th><th style="width:110px">本站流水</th><th style="width:100px">差额</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($status['shops'] as $shopName => $c):
                        $diff = $c['etmll'] - $c['local'];
                    ?>
                        <tr>
                            <td><?php echo e($shopName); ?></td>
                            <td><?php echo number_format($c['etmll']); ?></td>
                            <td><?php echo number_format($c['local']); ?></td>
                            <td><?php if ($diff > 0): ?><span class="text-warning font-weight-bold">+<?php echo number_format($diff); ?></span><?php else: ?><span class="text-muted">--</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 右侧：同步操作 -->
    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-bolt text-warning"></i> 同步操作</h5></div>
            <div class="card-body">
                <form method="post" class="mb-2" onsubmit="return confirm('确定从ETMLL同步订单到本站？\n\n仅新增本站缺少的订单（按订单号去重），不会修改已有数据。')">
                    <input type="hidden" name="action" value="sync">
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-sync-alt"></i> 立即同步</button>
                </form>
                <form method="post" onsubmit="return true">
                    <input type="hidden" name="action" value="preview">
                    <button type="submit" class="btn btn-outline-primary btn-block"><i class="fas fa-eye"></i> 预览（不写入）</button>
                </form>
                <hr>
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> 同步规则：
                    <ul class="pl-3 mb-0">
                        <li>按<b>订单号</b>去重，只新增本站缺少的订单，已有数据（含回收站）不会被修改或重复导入</li>
                        <li>写入为<b>店铺流水</b>（与店铺订单上传格式一致），可在对应店铺页面查看</li>
                        <li>ETMLL中<b>交易关闭</b>的订单按退款单记负数金额，部分退款记净额</li>
                        <li>ETMLL中本站没有的店铺会自动创建</li>
                    </ul>
                </small>
            </div>
        </div>

        <?php if ($result || $preview):
            $r = $result ?: $preview;
        ?>
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-clipboard-list text-success"></i> <?php echo $result ? '本次同步结果' : '预览结果'; ?></h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="thead-light"><tr><th>店铺</th><th style="width:90px" class="text-right">新增</th></tr></thead>
                    <tbody>
                    <?php foreach ($r['by_shop'] as $shopName => $c): ?>
                        <tr><td><?php echo e($shopName); ?></td><td class="text-right"><?php echo number_format($c); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($r['by_shop'])): ?>
                        <tr><td colspan="2" class="text-center text-muted py-3">没有需要新增的订单</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($r['new_shops'])): ?>
            <div class="card-footer bg-white">
                <small>
                    <i class="fas fa-plus-circle text-success"></i>
                    <?php echo $result ? '已自动创建店铺：' : '将自动创建店铺：'; ?>
                    <?php foreach ($r['new_shops'] as $ns): ?><span class="badge badge-success mr-1"><?php echo e($ns); ?></span><?php endforeach; ?>
                </small>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
