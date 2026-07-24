<?php
	@ini_set('memory_limit', '512M');
	require_once __DIR__ . '/../includes/auth.php';
	require_login();

	$page_title = '异常订单';
	$success = '';
	$error = '';

	// download导出
	if (($_GET['export'] ?? '') === '1') {
	    $shopName = $_GET['shop'] ?? '';
	    $month = $_GET['month'] ?? '';
	    $empName = $_GET['emp'] ?? '';
	    $data = get_abnormal_orders($shopName, $month, $empName);
	    $rows = $data['items'];

	    header('Content-Type: text/csv; charset=utf-8');
	    $filename = '异常订单_' . date('Ymd_His') . '.csv';
	    header('Content-Disposition: attachment; filename="' . $filename . '"');
	    // UTF-8 BOM 兼容Excel
	    echo "\xEF\xBB\xBF";
	    $out = fopen('php://output', 'w');
	    fputcsv($out, ['店铺', '订单号', '差异类型', '归属员工', '员工上传售价', '店铺订单售价', '差异金额', '员工上传日期', '店铺订单日期', '员工订单ID', '店铺订单ID']);
	    foreach ($rows as $r) {
	        fputcsv($out, [
	            $r['shop_name'],
	            $r['order_no'],
	            $r['diff_type'] === 'missing' ? '店铺缺失' : '售价不一致',
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

	$filter_month = $_GET['month'] ?? date('Y-m', strtotime('-1 month'));
	$filter_emp = trim($_GET['emp_search'] ?? '');
	$filter_dept = $_GET['dept'] ?? '';  // 选中的部门
	$abn = get_abnormal_orders('', $filter_month);
	$items = $abn['items'];
	$total_abn = count($items);

	// 取员工部门映射
	$pdo = db();
	$empDeptMap = []; // emp_name => department
	try {
	    $deptRows = $pdo->query("SELECT name, department FROM employees WHERE name <> '' AND COALESCE(department,'') <> ''")->fetchAll();
	    foreach ($deptRows as $dr) {
	        $empDeptMap[$dr['name']] = $dr['department'];
	    }
	} catch (\Throwable $e) {}

	// 按员工汇总异常
	$empStats = [];
	foreach ($items as $r) {
	    $empName = $r['emp_name'] ?? '';
	    $empId = $r['employee_id'] ?? '';
	    $key = $empName !== '' ? $empName : ('#' . $empId);
	    if (!isset($empStats[$key])) {
	        $empStats[$key] = [
	            'emp_name' => $empName !== '' ? $empName : ('#' . $empId),
	            'employee_id' => $empId,
	            'department' => $empDeptMap[$empName] ?? '未分配部门',
	            'missing' => 0,
	            'mismatch' => 0,
	            'total' => 0,
	        ];
	    }
	    if ($r['diff_type'] === 'missing') {
	        $empStats[$key]['missing']++;
	    } else {
	        $empStats[$key]['mismatch']++;
	    }
	    $empStats[$key]['total']++;
	}
	// 去掉 total=0
	$empStats = array_filter($empStats, fn($s) => $s['total'] > 0);

	// 按部门汇总
	$deptStats = [];
	foreach ($empStats as $s) {
	    $dept = $s['department'];
	    if (!isset($deptStats[$dept])) {
	        $deptStats[$dept] = [
	            'dept_name' => $dept,
	            'emp_count' => 0,
	            'missing' => 0,
	            'mismatch' => 0,
	            'total' => 0,
	        ];
	    }
	    $deptStats[$dept]['emp_count']++;
	    $deptStats[$dept]['missing'] += $s['missing'];
	    $deptStats[$dept]['mismatch'] += $s['mismatch'];
	    $deptStats[$dept]['total'] += $s['total'];
	}
	$deptStats = array_values($deptStats);
	usort($deptStats, fn($a, $b) => $b['total'] - $a['total']);

	// 员工搜索过滤
	if ($filter_emp !== '') {
	    $empStats = array_filter($empStats, fn($s) => mb_stripos($s['emp_name'], $filter_emp) !== false);
	}
	// 部门过滤（点击部门后只显示该部门员工）
	if ($filter_dept !== '') {
	    $empStats = array_filter($empStats, fn($s) => $s['department'] === $filter_dept);
	}
	$empStats = array_values($empStats);
	usort($empStats, fn($a, $b) => $b['total'] - $a['total']);

	define('BASE_PATH', dirname(__DIR__));
	include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <div>
        <?php if ($filter_dept): ?>
            <a href="<?php echo BASE_URL; ?>/abnormal/index.php<?php echo $filter_month ? '?month='.urlencode($filter_month) : ''; ?>" class="btn btn-outline-secondary btn-sm mr-2">
                <i class="fas fa-arrow-left"></i> 返回部门列表
            </a>
        <?php endif; ?>
        <h4 class="font-weight-bold mb-0 d-inline-block"><i class="fas fa-exclamation-triangle text-warning"></i> 异常订单</h4>
        <span class="badge badge-warning ml-2">共 <?php echo $total_abn; ?> 条异常</span>
        <?php if ($filter_dept): ?>
            <span class="badge badge-info ml-1"><i class="fas fa-sitemap"></i> <?php echo e($filter_dept); ?></span>
        <?php endif; ?>
        <?php if ($filter_emp): ?>
            <span class="badge badge-primary ml-1"><i class="fas fa-filter"></i> 员工: <?php echo e($filter_emp); ?></span>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center">
        <form method="get" class="form-inline mr-2">
            <?php if ($filter_emp): ?><input type="hidden" name="emp_search" value="<?php echo e($filter_emp); ?>"><?php endif; ?>
            <?php if ($filter_dept): ?><input type="hidden" name="dept" value="<?php echo e($filter_dept); ?>"><?php endif; ?>
            <input type="month" name="month" class="form-control form-control-sm mr-1" value="<?php echo e($filter_month); ?>" onchange="this.form.submit()">
            <?php if ($filter_month): ?>
                <button type="submit" name="month" value="" class="btn btn-sm btn-outline-secondary">全部</button>
            <?php endif; ?>
        </form>
        <?php if (!$filter_dept): ?>
        <!-- 部门列表页不显示员工搜索 -->
        <?php else: ?>
        <form method="get" class="form-inline mr-2">
            <?php if ($filter_month): ?><input type="hidden" name="month" value="<?php echo e($filter_month); ?>"><?php endif; ?>
            <?php if ($filter_dept): ?><input type="hidden" name="dept" value="<?php echo e($filter_dept); ?>"><?php endif; ?>
            <div class="input-group input-group-sm">
                <input type="text" name="emp_search" class="form-control" placeholder="搜索员工..." value="<?php echo e($filter_emp); ?>">
                <div class="input-group-append">
                    <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
                    <?php if ($filter_emp): ?>
                        <a href="<?php echo BASE_URL; ?>/abnormal/index.php?dept=<?php echo urlencode($filter_dept); ?><?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        <?php endif; ?>
        <?php if ($total_abn > 0): ?>
        <a href="<?php echo BASE_URL; ?>/abnormal/index.php?export=1<?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?><?php echo $filter_emp ? '&emp='.urlencode($filter_emp) : ''; ?>" class="btn btn-sm btn-outline-success">
            <i class="fas fa-download"></i> 导出
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <b>异常定义：</b>员工上传的订单号在店铺订单表里<b>查不到</b>（店铺缺失），或<b>能查到但售价不一致</b>。
    <br><span class="text-muted small">对比范围，员工上传订单（personal）vs 病铺订单（department），按订单号 + 月份匹配，按<b>售价</b>对比（非利润）。</span>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php endif; ?>

<?php if (!$filter_dept): ?>
<!-- ===== 第一层：部门概览 ===== -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-sitemap text-primary"></i> 各部门异常概览</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($deptStats)): ?>
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
                        <th>部门</th>
                        <th style="width:100px">员工数</th>
                        <th style="width:120px">店铺缺失</th>
                        <th style="width:120px">售价不一致</th>
                        <th style="width:100px">异常总数</th>
                        <th style="width:150px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($deptStats as $i => $s): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <span class="badge badge-info"><i class="fas fa-sitemap"></i> <?php echo e($s['dept_name']); ?></span>
                        </td>
                        <td><span class="text-muted"><?php echo $s['emp_count']; ?> 人</span></td>
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
                            <a href="<?php echo BASE_URL; ?>/abnormal/index.php?dept=<?php echo urlencode($s['dept_name']); ?><?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-users"></i> 查看员工
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

<?php else: ?>
<!-- ===== 第二层：部门内员工列表 ===== -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0">
            <i class="fas fa-users text-primary"></i> <?php echo e($filter_dept); ?> - 员工异常概览
            <?php if ($filter_emp): ?>
                <span class="text-muted small">（搜索"<?php echo e($filter_emp); ?>"，共 <?php echo count($empStats); ?> 人）</span>
            <?php else: ?>
                <span class="text-muted small">（共 <?php echo count($empStats); ?> 人）</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($empStats)): ?>
            <div class="text-center text-success py-5">
                <i class="fas fa-check-circle fa-3x mb-2 d-block"></i>
                <b>该部门暂无异常订单</b>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>员工</th>
                        <th style="width:120px">店铺缺失</th>
                        <th style="width:120px">售价不一致</th>
                        <th style="width:100px">异常总数</th>
                        <th style="width:200px">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($empStats as $i => $s): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td>
                            <span class="badge badge-secondary"><i class="fas fa-user"></i> <?php echo e($s['emp_name']); ?></span>
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
                            <a href="<?php echo BASE_URL; ?>/abnormal/employee.php?emp=<?php echo urlencode($s['emp_name']); ?><?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-list"></i> 查看明细
                            </a>
                            <a href="<?php echo BASE_URL; ?>/abnormal/employee.php?emp=<?php echo urlencode($s['emp_name']); ?>&export=1<?php echo $filter_month ? '&month='.urlencode($filter_month) : ''; ?>"
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
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
