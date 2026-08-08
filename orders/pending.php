<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = db();

$page_title = '待核验订单';
$success = '';
$error = '';

// ========== 查询参数 ==========
$filter_month = trim($_GET['month'] ?? '');                    // 空=全部月份（跨月汇总）
$filter_dept  = trim($_GET['department'] ?? '');
$filter_emp   = (int)($_GET['employee_id'] ?? 0);
$filter_search = trim($_GET['search_no'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 100;

$departments = get_departments();
$employees   = get_employees();

// ========== 构建查询：跨月、全员工汇总所有「未核验」订单 ==========
// 未核验：raw_data.__order_status__ = '未核验'；排除店铺上传行与已删除行
$where  = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '') AND COALESCE(o.is_deleted, 0) = 0 ";
$where .= " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__order_status__')) = '未核验' ";
$params = [];
if ($filter_month) {
    $where .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ? ";
    $params[] = $filter_month;
}
if ($filter_dept) {
    // 部门订单（employee_id=0）通过 __dept__ 匹配；个人订单按员工所属部门匹配
    $where .= " AND (e.department = ? OR (o.employee_id = 0 AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?)) ";
    $params[] = $filter_dept;
    $params[] = $filter_dept;
}
if ($filter_emp) {
    $where .= " AND o.employee_id = ? ";
    $params[] = $filter_emp;
}
if ($filter_search !== '') {
    $where .= " AND o.order_no LIKE ? ";
    $params[] = '%' . $filter_search . '%';
}

// 总数 & 金额
$countSql = "SELECT COUNT(*) FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $where;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total_count = (int)$stmt->fetchColumn();

$sumSql = "SELECT COALESCE(SUM(o.order_amount), 0) FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $where;
$stmt = $pdo->prepare($sumSql);
$stmt->execute($params);
$total_amount = (float)$stmt->fetchColumn();

// 分页数据
$offset = ($page - 1) * $per_page;
$total_pages = max(1, (int)ceil($total_count / $per_page));

$listSql = "
    SELECT o.*, e.name AS emp_name, e.department AS emp_dept
    FROM orders o
    LEFT JOIN employees e ON e.id = o.employee_id
    " . $where . "
    ORDER BY o.order_date DESC, o.id DESC
    LIMIT {$per_page} OFFSET {$offset}
";
$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// 待核验按月份汇总（用于快速筛选）
$monthStats = [];
$msSql = "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS ym, COUNT(*) AS cnt, COALESCE(SUM(o.order_amount), 0) AS amt
          FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $where . "
          GROUP BY ym ORDER BY ym DESC";
$msStmt = $pdo->prepare($msSql);
$msStmt->execute($params);
$monthStats = $msStmt->fetchAll();

// 构建查询参数（用于分页/筛选链接）
$baseQ = [];
if ($filter_month) $baseQ['month'] = $filter_month;
if ($filter_dept)  $baseQ['department'] = $filter_dept;
if ($filter_emp)   $baseQ['employee_id'] = $filter_emp;
if ($filter_search) $baseQ['search_no'] = $filter_search;

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-hourglass-half text-warning"></i> 待核验订单
        <small class="text-muted" style="font-size:.75em">跨月 · 全员汇总（未核验不计薪，核验通过按核验当月计入薪资）</small>
    </h4>
    <a href="<?php echo BASE_URL; ?>/orders/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> 返回订单管理
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo e($success); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?><button type="button" class="close" data-dismiss="alert">&times;</button></div>
<?php endif; ?>

<!-- 统计卡片 -->
<div class="row mb-3">
    <div class="col-md-4">
        <div class="card stat-card orange">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-hourglass-half fa-2x text-warning mr-3"></i>
                <div>
                    <div class="text-muted small">待核验订单</div>
                    <div class="h4 mb-0 font-weight-bold"><?php echo $total_count; ?> 条</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card blue">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-yen-sign fa-2x text-info mr-3"></i>
                <div>
                    <div class="text-muted small">待核验金额</div>
                    <div class="h4 mb-0 font-weight-bold">¥<?php echo money($total_amount); ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small mb-2"><i class="fas fa-calendar"></i> 按月份汇总</div>
                <?php if ($monthStats): ?>
                    <div style="max-height:120px;overflow-y:auto">
                        <?php foreach ($monthStats as $ms): ?>
                            <a href="?month=<?php echo e($ms['ym']); ?>" class="badge <?php echo ($filter_month === $ms['ym']) ? 'badge-warning' : 'badge-light text-dark border'; ?> mr-2 mb-1" style="font-size:.85em">
                                <?php echo e($ms['ym']); ?> · <?php echo $ms['cnt']; ?>条 / ¥<?php echo money($ms['amt']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span class="text-muted">无数据</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 筛选 -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="form-inline flex-wrap">
            <input type="month" name="month" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($filter_month); ?>" placeholder="月份">
            <select name="department" class="form-control form-control-sm mr-2 mb-1">
                <option value="">全部部门</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?php echo e($d); ?>" <?php echo $filter_dept === $d ? 'selected' : ''; ?>><?php echo e($d); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="employee_id" class="form-control form-control-sm mr-2 mb-1">
                <option value="">全部员工</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo (int)$emp['id']; ?>" <?php echo $filter_emp === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name']); ?><?php echo $emp['department'] ? '（' . e($emp['department']) . '）' : ''; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="search_no" class="form-control form-control-sm mr-2 mb-1" value="<?php echo e($filter_search); ?>" placeholder="订单号">
            <button type="submit" class="btn btn-sm btn-primary mb-1"><i class="fas fa-search"></i> 筛选</button>
            <?php if ($filter_month || $filter_dept || $filter_emp || $filter_search): ?>
                <a href="?" class="btn btn-sm btn-outline-secondary mb-1">清除筛选</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- 待核验列表 -->
<div class="card">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="mb-0"><i class="fas fa-list text-warning"></i> 待核验订单
                <small class="text-muted" style="font-size:.8em">共 <?php echo $total_count; ?> 条 / ¥<?php echo money($total_amount); ?></small>
            </h5>
            <div class="text-muted small">勾选订单后，在下方选择核验标准与计入薪资月份，点击「核验选中」</div>
        </div>
    </div>
    <div class="card-body p-0">
        <form method="post" id="pendingVerifyForm" onsubmit="return false;"><!-- 核验走 AJAX，不整页提交 -->
            <input type="hidden" name="action" value="verify_placeholder">

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width:32px"><input type="checkbox" id="checkAll" title="全选" onclick="toggleBatch(this)"></th>
                            <th>ID</th>
                            <th>订单号</th>
                            <th>归属</th>
                            <th>模块</th>
                            <th>金额</th>
                            <th>订单日期</th>
                            <th>状态</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($orders): foreach ($orders as $o): ?>
                        <?php $rawData = !empty($o['raw_data']) ? (json_decode($o['raw_data'], true) ?: []) : []; ?>
                        <?php $isDeptSummary = ($o['order_scope'] === 'department' && (int)$o['employee_id'] === 0); ?>
                        <?php $deptName = $isDeptSummary ? ($rawData['__dept__'] ?? '') : $o['emp_dept']; ?>
                        <tr>
                            <td><input type="checkbox" class="row-check" name="ids[]" value="<?php echo $o['id']; ?>" onclick="updateBatchBar()"></td>
                            <td><?php echo $o['id']; ?></td>
                            <td class="small"><?php echo e($o['order_no'] ?: '--'); ?></td>
                            <td>
                                <?php if ($isDeptSummary): ?>
                                    <span class="badge badge-success"><i class="fas fa-building"></i> <?php echo e($deptName ?: '部门'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-primary"><i class="fas fa-user"></i> <?php echo e($o['emp_name'] ?: '--'); ?></span>
                                    <?php if ($o['emp_dept']): ?><small class="text-muted d-block"><?php echo e($o['emp_dept']); ?></small><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($o['project'] ?? '--'); ?></td>
                            <td class="text-success font-weight-bold">¥<?php echo money($o['order_amount']); ?></td>
                            <td class="text-muted small"><?php echo !empty($o['order_date']) ? e($o['order_date']) : '--'; ?></td>
                            <td><span class="badge badge-warning"><i class="fas fa-hourglass-half"></i> 未核验</span></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>全部订单已核验完成</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 批量核验栏 -->
            <div id="batchBar" class="d-flex align-items-center flex-wrap p-2 bg-light border-top" style="display:none">
                <span id="selectedCount" class="text-muted small mr-3">已选 0 条</span>
                <select id="verifyType" class="form-control form-control-sm mr-2 mb-1" style="width:auto">
                    <option value="success">核验标准：交易成功</option>
                    <option value="shipped">核验标准：已发货</option>
                </select>
                <label class="small text-muted mr-2 mb-1">计入薪资月份：</label>
                <input type="month" id="creditMonth" class="form-control form-control-sm mr-3 mb-1" style="width:auto" value="<?php echo e(date('Y-m')); ?>">
                <button type="button" class="btn btn-sm btn-success mr-2 mb-1" onclick="doVerifyPending()"><i class="fas fa-check-double"></i> 核验选中</button>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-1" onclick="clearSelection()">取消选择</button>
            </div>
        </form>

        <?php if ($total_pages > 1): ?>
        <nav class="p-2">
            <ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => 1])); ?>">«</a></li>
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $page - 1])); ?>">‹</a></li>
                <?php $start = max(1, $page - 2); $end = min($total_pages, $page + 2); if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $i])); ?>"><?php echo $i; ?></a></li>
                <?php endfor; if ($end < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $page + 1])); ?>">›</a></li>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($baseQ, ['page' => $total_pages])); ?>">»</a></li>
            </ul>
            <p class="text-center text-muted small mt-1 mb-0">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页，每页 <?php echo $per_page; ?> 条</p>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(function(c){ return c.value; });
}
function updateBatchBar() {
    var ids = getSelectedIds();
    var n = ids.length;
    var ca = document.getElementById('checkAll');
    var cbs = document.querySelectorAll('.row-check');
    if (ca) { ca.checked = n === cbs.length && n > 0; ca.indeterminate = n > 0 && n < cbs.length; }
    document.getElementById('selectedCount').textContent = '已选 ' + n + ' 条';
    document.getElementById('batchBar').style.display = n > 0 ? 'flex' : 'none';
}
function toggleBatch(el) {
    var checked = el.checked;
    document.querySelectorAll('.row-check').forEach(function(c){ c.checked = checked; });
    updateBatchBar();
}
function clearSelection() {
    document.querySelectorAll('.row-check').forEach(function(c){ c.checked = false; });
    updateBatchBar();
}
function doVerifyPending() {
    var ids = getSelectedIds();
    if (!ids.length) { alert('请先勾选订单'); return; }
    var verifyType = document.getElementById('verifyType').value;
    var creditMonth = document.getElementById('creditMonth').value;
    if (!creditMonth) { alert('请选择计入薪资月份'); return; }
    var label = verifyType === 'shipped' ? '已发货' : '交易成功';
    if (!confirm('将以「' + label + '」为标准核验选中的 ' + ids.length + ' 条订单，不符合的将标记为异常。核验通过后计入薪资月份：' + creditMonth + '（核验当月）。确定继续？')) return;
    var btn = document.querySelector('#batchBar button.btn-success');
    var btns = document.querySelectorAll('#batchBar button');
    btns.forEach(function(b){ b.disabled = true; });
    if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 核验中...';
    $.ajax({
        url: '<?php echo BASE_URL; ?>/orders/index.php',
        type: 'POST',
        data: { action: 'verify_pending', ids: ids, verify_type: verifyType, credit_month: creditMonth },
        dataType: 'json',
        success: function(res) {
            btns.forEach(function(b){ b.disabled = false; });
            if (btn) btn.innerHTML = '<i class="fas fa-check-double"></i> 核验选中';
            if (res.ok) {
                var msg = '核验完成！共 ' + res.total + ' 条订单';
                if (res.updated > 0) msg += '，更新 ' + res.updated + ' 条状态';
                msg += '\n正常/已确认: ' + res.normal + ' 条\n异常: ' + res.abnormal + ' 条';
                alert(msg);
                location.reload();
            } else {
                alert('核验失败: ' + (res.msg || '未知错误'));
            }
        },
        error: function() {
            btns.forEach(function(b){ b.disabled = false; });
            if (btn) btn.innerHTML = '<i class="fas fa-check-double"></i> 核验选中';
            alert('请求失败，请重试');
        }
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
