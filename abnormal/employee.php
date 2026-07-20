<?php
	require_once __DIR__ . '/../includes/auth.php';
	require_login();

	$page_title = '员工异常订单明细';
	$success = '';
	$error = '';

	$empName = $_GET['emp'] ?? '';
	$filter_month = $_GET['month'] ?? '';

	if ($empName === '') {
	    header('Location: ' . BASE_URL . '/abnormal/index.php');
	    exit;
	}

	// 导出当前员工异常
	if (($_GET['export'] ?? '') === '1') {
	    $data = get_abnormal_orders('', $filter_month);
	    $rows = array_values(array_filter($data['items'], fn($r) => ($r['emp_name'] ?? '') === $empName));

	    header('Content-Type: text/csv; charset=utf-8');
	    $filename = '异常订单_' . $empName . '_' . date('Ymd_His') . '.csv';
	    header('Content-Disposition: attachment; filename="' . $filename . '"');
	    echo "\xEF\xBB\xBF";
	    $out = fopen('php://output', 'w');
	    fputcsv($out, ['店铺', '订单号', '差异类型', '归属员工', '员工上传金额', '店铺订单金额', '差异金额', '员工上传日期', '店铺订单日期', '员工订单ID', '店铺订单ID']);
	    foreach ($rows as $r) {
	        fputcsv($out, [
	            $r['shop_name'],
	            $r['order_no'],
	            $r['diff_type'] === 'missing' ? '店铺缺失' : '金额不一致',
	            $r['emp_name'] ?? '',
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

	$abn = get_abnormal_orders('', $filter_month);
// 只显示当前员工的异常
$items = array_values(array_filter($abn['items'], fn($r) => ($r['emp_name'] ?? '') === $empName));

// 分页
$page = max(1, (int)($_GET['page'] ?? 1));
$page_size = (int)($_GET['page_size'] ?? 20);
if (!in_array($page_size, [10, 20, 50, 100])) $page_size = 20;
$total = count($items);
$pages = $page_size > 0 ? (int)ceil($total / $page_size) : 0;
$offset = ($page - 1) * $page_size;
$pageItems = array_slice($items, $offset, $page_size);

// 统计
$cntMissing = 0; $cntMismatch = 0;
foreach ($items as $r) {
    if ($r['diff_type'] === 'missing') $cntMissing++;
    else $cntMismatch++;
}

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <div>
        <a href="<?php echo BASE_URL; ?>/abnormal/index.php<?php echo $filter_month ? '?month='.urlencode($filter_month) : ''; ?>" class="btn btn-outline-secondary btn-sm mr-2">
            <i class="fas fa-arrow-left"></i> 返回
        </a>
        <h4 class="font-weight-bold mb-0 d-inline-block">
            <i class="fas fa-exclamation-triangle text-warning"></i> 异常订单明细
        </h4>
        <span class="badge badge-secondary ml-2"><i class="fas fa-user"></i> <?php echo e($empName); ?></span>
    </div>
    <div class="d-flex align-items-center">
        <form method="get" class="form-inline mr-2">
            <input type="hidden" name="emp" value="<?php echo e($empName); ?>">
            <input type="month" name="month" class="form-control form-control-sm mr-1" value="<?php echo e($filter_month); ?>" onchange="this.form.submit()">
            <?php if ($filter_month): ?>
                <button type="submit" name="month" value="" class="btn btn-sm btn-outline-secondary">全部</button>
            <?php endif; ?>
        </form>
        <?php if ($total > 0): ?>
        <a href="<?php echo BASE_URL; ?>/abnormal/employee.php?emp=<?php echo urlencode($empName); ?>&export=1<?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="fas fa-download"></i> 导出
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body py-2">
                <div class="text-muted small">店铺缺失</div>
                <div class="font-weight-bold text-danger h5 mb-0"><?php echo $cntMissing; ?> 条</div>
                <small class="text-muted">员工上传了但店铺订单表查不到</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body py-2">
                <div class="text-muted small">金额不一致</div>
                <div class="font-weight-bold text-warning h5 mb-0"><?php echo $cntMismatch; ?> 条</div>
                <small class="text-muted">两边都有同一订单号但金额不同</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-secondary">
            <div class="card-body py-2">
                <div class="text-muted small">异常总数</div>
                <div class="font-weight-bold h5 mb-0"><?php echo $total; ?> 条</div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($items)): ?>
    <div class="card">
        <div class="card-body text-center text-success py-5">
            <i class="fas fa-check-circle fa-3x mb-2 d-block"></i>
            <b>该员工无异常订单</b>
            <p class="text-muted">所有上传订单与店铺订单都能匹配上</p>
        </div>
    </div>
<?php else: ?>
<div class="card">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="mb-0"><i class="fas fa-list text-info"></i> 异常订单列表</h5>
            <div class="d-flex align-items-center">
                <label class="text-muted small mb-0 mr-1">每页</label>
                <select class="form-control form-control-sm" style="width:auto" onchange="changePageSize(this.value)">
                    <?php foreach ([10,20,50,100] as $ps): ?>
                        <option value="<?php echo $ps; ?>" <?php if($page_size==$ps) echo 'selected'; ?>><?php echo $ps; ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="text-muted small ml-1">条</span>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width:60px">#</th>
                        <th>店铺</th>
                        <th>订单号</th>
                        <th>差异类型</th>
                        <th>归属员工</th>
                        <th class="text-right">员工上传金额</th>
                        <th class="text-right">店铺订单金额</th>
                        <th class="text-right">差异金额</th>
                        <th>员工上传日期</th>
                        <th>店铺订单日期</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pageItems as $i => $r):
                    $isMissing = $r['diff_type'] === 'missing';
                    $isMismatch = $r['diff_type'] === 'mismatch';
                ?>
                    <tr class="<?php echo $isMissing ? 'table-danger' : 'table-warning'; ?>">
                        <td><small class="text-muted"><?php echo $offset + $i + 1; ?></small></td>
                        <td><span class="badge badge-info"><i class="fas fa-store"></i> <?php echo e($r['shop_name']); ?></span></td>
                        <td><span class="text-monospace"><?php echo e($r['order_no']); ?></span></td>
                        <td>
                            <?php if ($isMissing): ?>
                                <span class="badge badge-danger"><i class="fas fa-times-circle"></i> 店铺缺失</span>
                            <?php else: ?>
                                <span class="badge badge-warning"><i class="fas fa-exchange-alt"></i> 金额不一致</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['emp_name'])): ?>
                                <span class="badge badge-secondary"><i class="fas fa-user"></i> <?php echo e($r['emp_name']); ?></span>
                            <?php elseif (!empty($r['employee_id'])): ?>
                                <span class="text-muted">#<?php echo e($r['employee_id']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">--</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right font-weight-bold <?php echo $isMismatch ? 'text-primary' : ''; ?>">¥<?php echo money($r['emp_amount']); ?></td>
                        <td class="text-right <?php echo $isMissing ? 'text-muted' : 'font-weight-bold text-success'; ?>">
                            <?php echo $r['shop_amount'] !== null ? '¥'.money($r['shop_amount']) : '<span class="text-muted">--</span>'; ?>
                        </td>
                        <td class="text-right font-weight-bold <?php echo $isMissing ? 'text-danger' : 'text-warning'; ?>">
                            <?php echo $isMissing ? '¥'.money($r['emp_amount']) : '¥'.money($r['diff_amount']); ?>
                        </td>
                        <td><small><?php echo e($r['emp_date']); ?></small></td>
                        <td>
                            <?php echo $r['shop_date'] ? '<small>'.e($r['shop_date']).'</small>' : '<small class="text-muted">--</small>'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-white">
        <nav class="d-flex justify-content-between align-items-center">
            <ul class="pagination pagination-sm mb-0">
                <?php
                $baseLink = BASE_URL . '/abnormal/employee.php?emp=' . urlencode($empName)
                    . ($filter_month ? '&month='.urlencode($filter_month) : '')
                    . '&page_size=' . $page_size . '&page=';
                ?>
                <li class="page-item <?php if($page<=1) echo 'disabled'; ?>"><a class="page-link" href="<?php echo $baseLink.($page-1); ?>">&laquo;</a></li>
                <?php
                $startP = max(1, $page - 2);
                $endP = min($pages, $page + 2);
                if ($startP > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                for ($i = $startP; $i <= $endP; $i++):
                ?>
                    <li class="page-item <?php if($i==$page) echo 'active'; ?>"><a class="page-link" href="<?php echo $baseLink.$i; ?>"><?php echo $i; ?></a></li>
                <?php endfor;
                if ($endP < $pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                ?>
                <li class="page-item <?php if($page>=$pages) echo 'disabled'; ?>"><a class="page-link" href="<?php echo $baseLink.($page+1); ?>">&raquo;</a></li>
            </ul>
            <span class="text-muted small">第 <?php echo $page; ?> / <?php echo $pages; ?> 页 · 共 <?php echo $total; ?> 条</span>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function changePageSize(ps){
    var url = new URL(window.location.href);
    url.searchParams.set('page_size', ps);
    url.searchParams.set('page', '1');
    window.location = url;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
