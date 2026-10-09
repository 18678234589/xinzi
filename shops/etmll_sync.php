<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/etmll_sync.php';
require_once __DIR__ . '/../includes/etmll_push.php';
require_once __DIR__ . '/../includes/etmll_bidirectional.php';

$page_title = 'ETMLL订单同步';
$success = '';
$error   = '';
$notice  = '';
$result  = null;   // 正式同步结果
$preview = null;   // 预览结果（未写入）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    $action = $_POST['action'] ?? '';
    set_time_limit(0);
    try {
        if ($action === 'sync') {
            if (etmll_push_since() === '') ps_setting_set('etmll_push_since',date('Y-m-d H:i:s'),(int)$_SESSION['admin_id']);
            $both=etmll_bidirectional_run(false,100000);$result=$both['pull'];$push=$both['push'];
            $success = "双向同步完成：本站→ETMLL 新增 {$push['pushed']} 条、订单信息更新 {$push['updated']} 条；ETMLL→本站已核对 {$result['scanned']} 条来源记录，新增 {$result['inserted']} 条、更新 {$result['updated']} 条、关联已有流水 {$result['linked_existing']} 条"
                     . ($result['project_filled'] > 0 ? "；已自动补全 {$result['project_filled']} 张项目订单" : '')
                     . "；项目状态更新 {$result['project_status_updated']} 条、已有流水状态更新 {$result['linked_status_updated']} 条、退款证据更新 {$result['linked_evidence_updated']} 条";
            if ($push['pushed']+$push['updated']+$result['inserted']+$result['updated']+$result['linked_existing']+$result['project_filled']+$result['project_status_updated']+$result['linked_status_updated']+$result['linked_evidence_updated'] === 0) $notice = '两边已核对，没有新订单或状态变化。先在任一系统导入最新淘宝表；同步不直接连接淘宝。开启同步之前未推送的历史订单需单独预览回填。';
            try {
                ps_audit('etmll_sync',0,'sync',['type'=>'admin','id'=>(int)$_SESSION['admin_id']],$result);
            } catch (Throwable $auditError) {
                error_log('ETMLL 同步已完成，操作日志暂未写入。');
            }
        } elseif ($action === 'push_preview' || $action === 'push_backfill') {
            if (etmll_push_since() === '') ps_setting_set('etmll_push_since', date('Y-m-d H:i:s'), (int)$_SESSION['admin_id']);
            $dry = $action === 'push_preview';
            $pushResult = etmll_push_run($dry, true, 20000);
            $notice = $dry
                ? '回填预览（未写入）：近一年内本站有、ETMLL 没有的订单共 ' . $pushResult['would_push'] . ' 条，合计 ¥' . number_format($pushResult['amount'], 2) . '；已有订单预计更新信息 ' . $pushResult['would_update'] . ' 条。确认无误后点“确认回填到 ETMLL”。'
                : '';
            if (!$dry) $success = '已回填 ' . $pushResult['pushed'] . ' 条订单到 ETMLL（合计 ¥' . number_format($pushResult['amount'], 2) . '），已有订单信息更新 ' . $pushResult['updated'] . ' 条，无需更新 ' . $pushResult['skipped_existing'] . ' 条；新回填订单标记为“' . ETMLL_PUSH_TAG . '”，合伙人归属待分配。';
        } elseif ($action === 'preview') {
            $preview = etmll_sync_run(true);
            $notice  = "预览完成：预计新增 {$preview['inserted']} 条、更新 {$preview['updated']} 条、关联已有流水 {$preview['linked_existing']} 条；尚未写入。";
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
            <h6 class="text-muted mb-1">已与ETMLL关联</h6>
            <h4 class="font-weight-bold mb-0"><?php echo number_format($status['synced_total']); ?> 条</h4>
        </div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card orange"><div class="card-body">
            <h6 class="text-muted mb-1">最近同步时间</h6>
            <h6 class="font-weight-bold mb-0"><?php echo e($status['last_run_at'] ?: ($status['last_sync_at'] ?: '从未同步')); ?></h6>
        </div></div>
    </div>
</div>
<div class="alert alert-light border mb-3"><i class="fas fa-clock text-info"></i> ETMLL 来源最新付款：<strong><?php echo e(substr((string)$status['latest_source_paid'],0,19) ?: '暂无付款记录'); ?></strong>。该日期表示已有数据的付款时间；请先在任一系统导入最新淘宝订单表，再执行双向同步。</div>
<?php $pull = $status['last_pull']; ?>
<div class="alert <?php echo !$pull || $pull['run_status'] !== 'success' || strtotime($pull['finished_at'] ?: $pull['started_at']) < time()-900 ? 'alert-warning' : 'alert-info'; ?> mb-3">
    <i class="fas fa-heartbeat"></i> 最近拉取：<?php echo e($pull ? ($pull['finished_at'] ?: $pull['started_at']) : '暂无运行记录'); ?>
    · <?php echo e($pull ? (['success'=>'核对完成','failed'=>'同步失败，请检查任务日志','running'=>'正在同步'][$pull['run_status']] ?? '待检查') : '请运行一次同步'); ?>。
    超过 15 分钟没有完成记录时，请检查定时任务。零新增不代表失败，状态更新也会单独统计。
</div>
<div class="alert alert-light border mb-3">状态来源说明：交易状态来自已导入的淘宝订单表。请导出包含已有订单最新状态的表格；重复导入用于更新，不需新建订单。按钮和每 5 分钟任务均执行双向同步，保留人工金额及已结算记录。</div>

<?php $pushSince = etmll_push_since(); ?>
<div class="card mb-3 border-info">
    <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-exchange-alt text-info"></i> 双向自动同步（仅近一年订单）</h5></div>
    <div class="card-body">
        <p class="mb-2 small text-muted">按钮和每 5 分钟任务均双向核对近一年订单。任一端导入的新订单与交易状态变化都会核对；本站上传可推进 ETMLL 已有订单的状态、补充发货时间，不覆盖结算金额、分账或归属。旧状态不回退，回收站不恢复。未曾推送的历史订单仍需预览回填。</p>
        <p class="mb-2">自动推送起点：<strong><?php echo e($pushSince ?: '未开启'); ?></strong>（此后新上传的订单才自动推送）</p>
        <?php if (!empty($pushResult)): ?><div class="alert alert-light border small mb-2">近一年历史订单：本站有而 ETMLL 没有 <strong><?php echo (int)($pushResult['would_push'] + $pushResult['pushed']); ?></strong> 条，¥<?php echo number_format($pushResult['amount'], 2); ?>
            <?php foreach ($pushResult['by_shop'] as $sn => $cnt): ?><span class="badge badge-light border ml-1"><?php echo e($sn); ?> <?php echo (int)$cnt; ?></span><?php endforeach; ?></div><?php endif; ?>
        <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="push_preview"><button class="btn btn-outline-info btn-sm">预览历史回填</button></form>
        <form method="post" class="d-inline" onsubmit="return confirm('确认把近一年内本站有、ETMLL 没有的历史订单写入 ETMLL？\n这些订单会进入 ETMLL 的订单表（合伙人归属待分配），可能影响 ETMLL 的结算统计。');"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="push_backfill"><button class="btn btn-warning btn-sm">确认回填到 ETMLL</button></form>
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
                <form method="post" class="mb-2" data-sync-form>
                    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
                    <input type="hidden" name="action" value="sync">
                    <button type="submit" class="btn btn-success btn-block"><i class="fas fa-sync-alt"></i> 立即双向同步订单与状态</button>
                </form>
                <form method="post" data-sync-form>
                    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
                    <input type="hidden" name="action" value="preview">
                    <button type="submit" class="btn btn-outline-primary btn-block"><i class="fas fa-eye"></i> 预览（不写入）</button>
                </form>
                <hr>
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> 同步规则：
                    <ul class="pl-3 mb-0">
                        <li>按<b>店铺＋订单号</b>匹配店铺流水；客服或技术个人订单不会阻止导入</li>
                        <li>ETMLL 来源的已有流水会更新交易状态、金额和退款变化；重复同步不会新增相同流水</li>
                        <li>手动上传的已有流水保留原内容；回收站记录不会自动恢复</li>
                        <li>新旧流水都会按订单号补全对应项目的空资料，保护人工填写和已审核信息</li>
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
                    <thead class="thead-light"><tr><th>店铺</th><th class="text-right">新增</th><th class="text-right">更新</th><th class="text-right">关联</th></tr></thead>
                    <tbody>
                    <?php $changedShops = array_unique(array_merge(array_keys($r['by_shop']),array_keys($r['by_shop_updated']),array_keys($r['by_shop_linked']))); foreach ($changedShops as $shopName): ?>
                        <tr><td><?php echo e($shopName); ?></td><td class="text-right"><?php echo number_format($r['by_shop'][$shopName] ?? 0); ?></td><td class="text-right"><?php echo number_format($r['by_shop_updated'][$shopName] ?? 0); ?></td><td class="text-right"><?php echo number_format($r['by_shop_linked'][$shopName] ?? 0); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$changedShops): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">核对完成，已有店铺流水没有新的变化</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white"><small class="text-muted">未付款：<?php echo (int)$r['skipped_unpaid']; ?> 条 · 缺少店铺：<?php echo (int)$r['skipped_no_shop']; ?> 条 · 回收站保留：<?php echo (int)$r['skipped_deleted']; ?> 条<?php if ($r['skipped_missing_local']): ?> · 来源已有同步记录但本站流水已移除：<?php echo (int)$r['skipped_missing_local']; ?> 条<?php endif; ?></small></div>
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

<script>
(function () {
    document.querySelectorAll('[data-sync-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.busy === '1') { event.preventDefault(); return; }
            form.dataset.busy = '1';
            var button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (form.querySelector('[name="action"]').value === 'sync' ? '正在同步，请稍候…' : '正在核对，请稍候…');
        });
    });
}());
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
