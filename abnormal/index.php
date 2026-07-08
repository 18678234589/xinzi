<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = '异常订单';
$success = '';
$error = '';

// 下载导出
if (($_GET['export'] ?? '') === '1') {
    $shopName = $_GET['shop'] ?? '';
    $month = $_GET['month'] ?? '';
    $data = get_abnormal_orders($shopName, $month);
    $rows = $data['items'];

    header('Content-Type: text/csv; charset=utf-8');
    $filename = '异常订单_' . date('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // UTF-8 BOM 兼容Excel
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['店铺', '订单号', '差异类型', '员工上传金额', '店铺订单金额', '差异金额', '员工上传日期', '店铺订单日期', '员工订单ID', '店铺订单ID']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['shop_name'],
            $r['order_no'],
            $r['diff_type'] === 'missing' ? '店铺缺失' : '金额不一致',
            $r['emp_amount'],
            $r['shop_amount'] ?? '',
            $r['diff_amount'],
            $r['emp_date'],
            $r['shop_date'] ?? '',
            $r['emp_order_id'],
            $r['shop_order_id'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$filter_month = $_GET['month'] ?? '';
$abn = get_abnormal_orders('', $filter_month);
$shops = $abn['shops'];
$total_abn = count($abn['items']);

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <div>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-exclamation-triangle text-warning"></i> 异常订单</h4>
        <span class="badge badge-warning ml-2">共 <?php echo $total_abn; ?> 条异常</span>
    </div>
    <div class="d-flex align-items-center">
        <form method="get" class="form-inline mr-2">
            <input type="month" name="month" class="form-control form-control-sm mr-1" value="<?php echo e($filter_month); ?>" onchange="this.form.submit()">
            <?php if ($filter_month): ?>
                <button type="submit" name="month" value="" class="btn btn-sm btn-outline-secondary">全部</button>
            <?php endif; ?>
        </form>
        <?php if ($total_abn > 0): ?>
        <a href="<?php echo BASE_URL; ?>/abnormal/index.php?export=1<?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="fas fa-download"></i> 导出全部
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <b>异常定义：</b>员工上传的订单号在店铺订单表里<b>查不到</b>（店铺缺失），或<b>能查到但金额不一致</b>。
    <br><span class="text-muted small">对比范围：员工上传订单（personal）vs 店铺订单（department），按订单号 + 月份匹配。</span>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-store text-success"></i> 各店铺异常概览</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($shops)): ?>
            <div class="text-center text-success py-5">
                <i class="fas fa-check-circle fa-3x mb-2 d-block"></i>
                <b>暂无异常订单</b>
                <p class="text-muted">所有员工上传订单与店铺订单都能匹配上</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>店铺</th>
                        <th style="width:120px">店铺缺失</th>
                        <th style="width:120px">金额不一致</th>
                        <th style="width:100px">异常总数</th>
                        <th style="width:200px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($shops as $i => $s): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <span class="badge badge-info"><i class="fas fa-store"></i> <?php echo e($s['shop_name']); ?></span>
                        </td>
                        <td>
                            <?php if ($s['missing'] > 0): ?>
                                <span class="badge badge-danger"><?php echo $s['missing']; ?> 条</span>
                            <?php else: ?><span class="text-muted">--</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['mismatch'] > 0): ?>
                                <span class="badge badge-warning"><?php echo $s['mismatch']; ?> 条</span>
                            <?php else: ?><span class="text-muted">--</span><?php endif; ?>
                        </td>
                        <td><span class="badge badge-secondary"><?php echo $s['total']; ?> 条</span></td>
                        <td>
                            <a href="<?php echo BASE_URL; ?>/abnormal/shop.php?shop=<?php echo urlencode($s['shop_name']); ?><?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-list"></i> 查看明细
                            </a>
                            <a href="<?php echo BASE_URL; ?>/abnormal/index.php?export=1&shop=<?php echo urlencode($s['shop_name']); ?><?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>"
                               class="btn btn-sm btn-outline-success">
                                <i class="fas fa-download"></i> 导出
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
