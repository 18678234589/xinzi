<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

// 统计数据
$emp_count    = (int)db()->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$order_count  = (int)db()->query("SELECT COUNT(*) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
$order_total  = (float)db()->query("SELECT COALESCE(SUM(order_amount),0) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
$salary_count = (int)db()->query("SELECT COUNT(*) FROM salaries")->fetchColumn();

// 各部门人数
$dept_stats = db()->query("SELECT department, COUNT(*) as cnt, SUM(base_salary) as total_salary FROM employees GROUP BY department ORDER BY department")->fetchAll();

// 店铺列表（含关联订单数）
$shop_stats = get_shop_list();

// 本月订单情况
$this_month = date('Y-m', strtotime('-1 month'));
$stmt = db()->prepare("
    SELECT e.name, e.department, COUNT(o.id) as order_cnt, COALESCE(SUM(o.order_amount),0) as amount
    FROM employees e
    LEFT JOIN orders o ON o.employee_id = e.id AND DATE_FORMAT(o.order_date, '%Y-%m') = ? AND COALESCE(o.is_deleted, 0) = 0
    GROUP BY e.id
    ORDER BY amount DESC
    LIMIT 10
");
$stmt->execute([$this_month]);
$month_orders = $stmt->fetchAll();

define('BASE_PATH', __DIR__);
$page_title = '系统首页';
include __DIR__ . '/includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card blue">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-users fa-3x text-info mr-3"></i>
                <div>
                    <div class="text-muted small">员工总数</div>
                    <div class="h3 mb-0 font-weight-bold"><?php echo $emp_count; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card orange">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-file-invoice fa-3x text-warning mr-3"></i>
                <div>
                    <div class="text-muted small">订单总数</div>
                    <div class="h3 mb-0 font-weight-bold"><?php echo $order_count; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card green">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-yen-sign fa-3x text-success mr-3"></i>
                <div>
                    <div class="text-muted small">订单总金额</div>
                    <div class="h3 mb-0 font-weight-bold">¥<?php echo money($order_total); ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card purple">
            <div class="card-body d-flex align-items-center">
                <i class="fas fa-money-check-alt fa-3x text-purple mr-3" style="color:#6f42c1"></i>
                <div>
                    <div class="text-muted small">薪资记录数</div>
                    <div class="h3 mb-0 font-weight-bold"><?php echo $salary_count; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-building text-primary"></i> 部门概览</h5></div>
            <div class="card-body">
                <?php if ($dept_stats): ?>
                <table class="table table-sm">
                    <thead><tr><th>部门</th><th>人数</th><th>基本工资合计</th></tr></thead>
                    <tbody>
                    <?php foreach ($dept_stats as $d): ?>
                        <tr>
                            <td><span class="badge badge-info badge-dept"><?php echo e($d['department']); ?></span></td>
                            <td><?php echo $d['cnt']; ?> 人</td>
                            <td>¥<?php echo money($d['total_salary']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="text-muted text-center py-3">暂无数据</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-store text-success"></i> 店铺概览</h5>
                    <a href="<?php echo BASE_URL; ?>/shops/index.php" class="btn btn-sm btn-outline-secondary">管理店铺</a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($shop_stats): ?>
                <table class="table table-sm">
                    <thead><tr><th>店铺</th><th>订单数</th></tr></thead>
                    <tbody>
                    <?php foreach ($shop_stats as $s): ?>
                        <tr>
                            <td><span class="badge badge-info"><i class="fas fa-store"></i> <?php echo e($s['name']); ?></span></td>
                            <td><?php echo $s['order_count']; ?> 笔</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="text-muted text-center py-3">暂无店铺数据，前往 <a href="<?php echo BASE_URL; ?>/shops/index.php">店铺管理</a> 添加</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-chart-bar text-success"></i> <?php echo $this_month; ?> 月订单排行 (TOP 10)</h5></div>
            <div class="card-body">
                <?php if ($month_orders): ?>
                <table class="table table-sm table-hover">
                    <thead><tr><th>排名</th><th>姓名</th><th>部门</th><th>订单数</th><th>订单金额</th></tr></thead>
                    <tbody>
                    <?php foreach ($month_orders as $i => $o): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo e($o['name']); ?></td>
                            <td><?php echo e($o['department']); ?></td>
                            <td><?php echo $o['order_cnt']; ?></td>
                            <td class="font-weight-bold text-success">¥<?php echo money($o['amount']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="text-muted text-center py-3">暂无数据</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
