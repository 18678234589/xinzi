<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title = '薪资查询';

// 筛选条件
$filter_dept  = $_GET['department'] ?? '';
$filter_emp   = (int)($_GET['employee_id'] ?? 0);
$filter_month = $_GET['month'] ?? '';

// 构建查询
$sql = "SELECT s.*, e.name, e.department, e.base_salary, e.commission_rate
        FROM salaries s
        JOIN employees e ON s.employee_id = e.id
        WHERE 1=1";
$params = [];
if ($filter_dept) { $sql .= " AND e.department = ?"; $params[] = $filter_dept; }
if ($filter_emp) { $sql .= " AND s.employee_id = ?"; $params[] = $filter_emp; }
if ($filter_month) { $sql .= " AND s.month = ?"; $params[] = $filter_month; }
$sql .= " ORDER BY s.month DESC, e.department, e.name";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$salaries = $stmt->fetchAll();

$departments = get_departments();
$employees   = get_employees();

// 导出处理
if (isset($_GET['export'])) {
    $headers = ['月份', '姓名', '部门', '订单总额', '提成金额', '实发工资', '全勤奖', '结算时间'];
    $rows = [];
    foreach ($salaries as $s) {
        $rows[] = [
            $s['month'],
            $s['name'],
            $s['department'],
            $s['order_total'],
            $s['commission'],
            $s['net_pay'],
            $s['full_attendance_bonus'] ?? 0,
            $s['created_at'],
        ];
    }
    $filename = '薪资报表_' . date('YmdHis') . '.xls';
    export_excel($headers, $rows, $filename);
}

// 统计汇总
$grand_order_total = array_sum(array_column($salaries, 'order_total'));
$grand_commission  = array_sum(array_column($salaries, 'commission'));
$grand_net_pay     = array_sum(array_column($salaries, 'net_pay'));

define('BASE_PATH', dirname(__DIR__));
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="font-weight-bold mb-0"><i class="fas fa-search-dollar"></i> 薪资查询</h4>
    <?php if ($salaries): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => '1'])); ?>" class="btn btn-success">
        <i class="fas fa-file-export"></i> 导出Excel报表
    </a>
    <?php endif; ?>
</div>

<!-- 筛选栏 -->
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="form-inline flex-wrap">
            <div class="form-group mr-2 mb-1">
                <label class="mr-1">部门:</label>
                <select name="department" class="form-control form-control-sm" id="filterDept" onchange="updateEmpFilter()">
                    <option value="">全部部门</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo e($d); ?>" <?php echo $filter_dept === $d ? 'selected' : ''; ?>><?php echo e($d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <label class="mr-1">员工:</label>
                <select name="employee_id" class="form-control form-control-sm" id="filterEmp">
                    <option value="">全部员工</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" data-dept="<?php echo e($emp['department']); ?>" <?php echo $filter_emp == $emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-2 mb-1">
                <label class="mr-1">月份:</label>
                <input type="month" name="month" class="form-control form-control-sm" value="<?php echo e($filter_month); ?>">
            </div>
            <button type="submit" class="btn btn-sm btn-info mr-1 mb-1"><i class="fas fa-search"></i> 查询</button>
            <a href="query.php" class="btn btn-sm btn-secondary mb-1"><i class="fas fa-redo"></i> 重置</a>
            <?php if ($salaries): ?>
            <button type="button" class="btn btn-sm btn-outline-success mb-1 ml-auto" onclick="location.href='?<?php echo http_build_query(array_merge($_GET, ['export' => '1'])); ?>'">
                <i class="fas fa-download"></i> 导出Excel
            </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- 汇总卡片 -->
<?php if ($salaries): ?>
<div class="row mb-3">
    <div class="col-md-4">
        <div class="card stat-card blue">
            <div class="card-body">
                <div class="text-muted small">订单总额合计</div>
                <div class="h4 mb-0 text-primary">¥<?php echo money($grand_order_total); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card orange">
            <div class="card-body">
                <div class="text-muted small">提成金额合计</div>
                <div class="h4 mb-0 text-warning">¥<?php echo money($grand_commission); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card green">
            <div class="card-body">
                <div class="text-muted small">实发工资合计</div>
                <div class="h4 mb-0 text-success">¥<?php echo money($grand_net_pay); ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 薪资列表 -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered">
                <thead class="thead-light">
                    <tr>
                        <th>月份</th><th>姓名</th><th>部门</th><th>订单总额</th><th>提成金额</th><th>实发工资</th><th>全勤奖</th><th>结算时间</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($salaries): foreach ($salaries as $s): ?>
                    <tr>
                        <td><span class="badge badge-secondary"><?php echo e($s['month']); ?></span></td>
                        <td><?php echo e($s['name']); ?></td>
                        <td><span class="badge badge-info"><?php echo e($s['department']); ?></span></td>
                        <td>¥<?php echo money($s['order_total']); ?></td>
                        <td>¥<?php echo money($s['commission']); ?></td>
                        <td class="font-weight-bold text-success">¥<?php echo money($s['net_pay']); ?></td>
                        <td class="text-success">+¥<?php echo money($s['full_attendance_bonus'] ?? 0); ?></td>
                        <td><small class="text-muted"><?php echo $s['created_at']; ?></small></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-2 d-block"></i>暂无薪资记录，请先进行薪资结算
                    </td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($salaries): ?>
                <tfoot>
                    <tr class="table-light font-weight-bold">
                        <td colspan="3" class="text-right">合计</td>
                        <td>¥<?php echo money($grand_order_total); ?></td>
                        <td>¥<?php echo money($grand_commission); ?></td>
                        <td class="text-success">¥<?php echo money($grand_net_pay); ?></td>
                        <td></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script>
function updateEmpFilter() {
    var dept = $('#filterDept').val();
    $('#filterEmp option').each(function() {
        var $opt = $(this);
        if (!$opt.val()) return; // 跳过"全部员工"
        if (!dept || $opt.data('dept') === dept) {
            $opt.show();
        } else {
            $opt.hide();
        }
    });
    // 如果当前选中项被隐藏，重置为全部
    var $sel = $('#filterEmp');
    if ($sel.find('option:selected').is(':hidden')) {
        $sel.val('');
    }
}
<?php if ($filter_dept): ?>updateEmpFilter();<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
