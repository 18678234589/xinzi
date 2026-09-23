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

// 上月订单情况
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

// 项目合作待办（项目结算表未迁移时静默跳过）
$project_todo = null;
try {
    $project_todo = db()->query("SELECT
        (SELECT COUNT(*) FROM project_orders WHERE settlement_status IN ('draft','review')) AS open_orders,
        (SELECT COUNT(*) FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE c.review_status='pending' AND o.settlement_status IN ('draft','review')) AS pending_costs,
        (SELECT COUNT(*) FROM project_cash_movements m WHERE m.review_status='pending') AS pending_cash,
        (SELECT COUNT(*) FROM project_order_resources r JOIN project_orders o ON o.id=r.order_id WHERE r.domain_mode='pending' AND o.settlement_status IN ('draft','review')) AS pending_resources,
        (SELECT COUNT(*) FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.settlement_status IN ('draft','review') AND COALESCE(s.price_source,'missing')='missing') AS missing_price,
        (SELECT COUNT(*) FROM project_orders WHERE order_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) AS month_orders")->fetch();
} catch (PDOException $e) {
    $project_todo = null;
}

$hour = (int)date('G');
$greeting = $hour < 6 ? '夜深了' : ($hour < 11 ? '早上好' : ($hour < 14 ? '中午好' : ($hour < 18 ? '下午好' : '晚上好')));
$weekdays = ['日', '一', '二', '三', '四', '五', '六'];

define('BASE_PATH', __DIR__);
$page_title = '工作台首页';
include __DIR__ . '/includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow"><?php echo date('Y 年 n 月 j 日'); ?> · 星期<?php echo $weekdays[(int)date('w')]; ?></div><h2><?php echo $greeting; ?>，<?php echo e($display_name); ?></h2><p>今天也辛苦了。常用入口都在下面，项目订单的待审事项会在这里提醒你。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/index.php?entry=1"><i class="fas fa-pen mr-1"></i> 录入项目订单</a><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/payroll.php"><i class="fas fa-wallet mr-1"></i> 项目报酬结算</a></div></div>

<div class="dash-grid mb-3">
    <div class="dash-tile"><span class="dash-tile-icon sky"><i class="fas fa-users"></i></span><div><small>合作人员</small><strong><?php echo $emp_count; ?></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon amber"><i class="fas fa-receipt"></i></span><div><small>店铺订单总数</small><strong><?php echo number_format($order_count); ?></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon"><i class="fas fa-yen-sign"></i></span><div><small>店铺订单总金额</small><strong>¥<?php echo money($order_total); ?></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon plum"><i class="fas fa-folder-open"></i></span><div><small>本月项目订单</small><strong><?php echo $project_todo ? (int)$project_todo['month_orders'] : '—'; ?></strong></div></div>
</div>

<div class="card mb-3"><div class="card-header">待处理事项</div><div class="card-body">
<?php if ($project_todo === null): ?>
    <p class="text-muted mb-0">项目结算表尚未初始化，请先执行 <code>php migrations/apply_project.php</code>。</p>
<?php else: $todoTotal = (int)$project_todo['pending_costs'] + (int)$project_todo['pending_cash'] + (int)$project_todo['pending_resources'] + (int)$project_todo['missing_price']; ?>
    <div class="dash-todo">
    <?php if ($todoTotal === 0): ?>
        <a class="is-clear" href="<?php echo BASE_URL; ?>/project/index.php"><i class="fas fa-check-circle"></i> 暂无待审事项，一切顺利</a>
    <?php else: ?>
        <?php if ((int)$project_todo['pending_costs']): ?><a href="<?php echo BASE_URL; ?>/project/index.php?state=todo"><i class="fas fa-receipt"></i> 成本待审 <strong><?php echo (int)$project_todo['pending_costs']; ?></strong></a><?php endif; ?>
        <?php if ((int)$project_todo['pending_cash']): ?><a href="<?php echo BASE_URL; ?>/project/index.php?state=todo"><i class="fas fa-coins"></i> 收退款待审 <strong><?php echo (int)$project_todo['pending_cash']; ?></strong></a><?php endif; ?>
        <?php if ((int)$project_todo['pending_resources']): ?><a href="<?php echo BASE_URL; ?>/project/index.php?state=todo"><i class="fas fa-server"></i> 资源待技术确认 <strong><?php echo (int)$project_todo['pending_resources']; ?></strong></a><?php endif; ?>
        <?php if ((int)$project_todo['missing_price']): ?><a href="<?php echo BASE_URL; ?>/shops/etmll_sync.php"><i class="fas fa-tag"></i> 售价待补（可 ETMLL 同步补齐） <strong><?php echo (int)$project_todo['missing_price']; ?></strong></a><?php endif; ?>
    <?php endif; ?>
    </div>
<?php endif; ?>
</div></div>

<div class="dash-actions mb-3">
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/index.php?entry=1"><i class="fas fa-pen"></i><span>录入项目订单<small>输单号自动带出买家</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/import.php"><i class="fas fa-file-excel"></i><span>导入部门表格<small>支持现有各部门格式</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/shops/etmll_sync.php"><i class="fas fa-sync-alt"></i><span>ETMLL 订单同步<small>自动补全项目订单</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/rules.php"><i class="fas fa-percent"></i><span>规则中心<small>分成比例与月度奖励</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/attendance/index.php"><i class="fas fa-calendar-check"></i><span>考勤表<small>上传与核对出勤</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/salaries/settle.php"><i class="fas fa-calculator"></i><span>原系统报酬结算<small>底薪、绩效与考勤</small></span></a>
</div>

<div class="row">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="fas fa-sitemap mr-1"></i> 部门概览</span><a href="<?php echo BASE_URL; ?>/departments/index.php" class="btn btn-sm btn-outline-secondary">管理部门</a></div>
            <?php if ($dept_stats): ?>
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>部门</th><th class="text-right">人数</th><th class="text-right">固定服务费合计</th></tr></thead>
                <tbody>
                <?php foreach ($dept_stats as $d): ?>
                    <tr>
                        <td><span class="badge badge-info badge-dept"><?php echo e($d['department']); ?></span></td>
                        <td class="text-right"><?php echo $d['cnt']; ?> 人</td>
                        <td class="text-right">¥<?php echo money($d['total_salary']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <div class="card-body"><p class="text-muted text-center py-3 mb-0">暂无部门数据</p></div>
            <?php endif; ?>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="fas fa-store mr-1"></i> 店铺概览</span><a href="<?php echo BASE_URL; ?>/shops/index.php" class="btn btn-sm btn-outline-secondary">管理店铺</a></div>
            <?php if ($shop_stats): ?>
            <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>店铺</th><th class="text-right">订单数</th></tr></thead>
                <tbody>
                <?php foreach ($shop_stats as $s): ?>
                    <tr>
                        <td><i class="fas fa-store text-muted mr-1"></i> <?php echo e($s['name']); ?></td>
                        <td class="text-right"><?php echo number_format((int)$s['order_count']); ?> 笔</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <div class="card-body"><p class="text-muted text-center py-3 mb-0">暂无店铺数据，前往 <a href="<?php echo BASE_URL; ?>/shops/index.php">店铺管理</a> 添加</p></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><i class="fas fa-chart-bar mr-1"></i> <?php echo e($this_month); ?> 个人订单排行（前 10）</div>
            <?php if ($month_orders): ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>排名</th><th>姓名</th><th>部门</th><th class="text-right">订单数</th><th class="text-right">订单金额</th></tr></thead>
                <tbody>
                <?php foreach ($month_orders as $i => $o): ?>
                    <tr>
                        <td><span class="dash-rank<?php echo $i < 3 ? ' top' : ''; ?>"><?php echo $i + 1; ?></span></td>
                        <td><?php echo e($o['name']); ?></td>
                        <td class="text-muted"><?php echo e($o['department']); ?></td>
                        <td class="text-right"><?php echo $o['order_cnt']; ?></td>
                        <td class="text-right font-weight-bold text-success">¥<?php echo money($o['amount']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
                <div class="card-body"><p class="text-muted text-center py-3 mb-0">上月暂无订单数据</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
