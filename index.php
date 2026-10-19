<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ProjectSettlement.php';

// 允许所有登录用户（后台管理员及项目合作人员）查看工作台首页
$actor = ps_actor();
if (!$actor) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_admin = !empty($_SESSION['admin_id']);
$is_finance = ($actor['role'] ?? '') === 'finance';
$is_finance_or_admin = $is_admin || $is_finance;
$my_eid = (int)($actor['employee_id'] ?? 0);

// 统计数据：只有财务和管理员才能查看全公司的店铺流水与店铺概览
if ($is_finance_or_admin) {
    $emp_count    = (int)db()->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    $order_count  = (int)db()->query("SELECT COUNT(*) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
    $order_total  = (float)db()->query("SELECT COALESCE(SUM(order_amount),0) FROM orders WHERE COALESCE(is_deleted, 0) = 0")->fetchColumn();
    $salary_count = (int)db()->query("SELECT COUNT(*) FROM salaries")->fetchColumn();
    $shop_stats   = get_shop_list();
} else {
    $emp_count    = 0;
    $order_count  = 0;
    $order_total  = 0.0;
    $salary_count = 0;
    $shop_stats   = [];
}

// 待办事项与订单统计
$project_todo = null;
try {
    if ($is_finance_or_admin) {
        $project_todo = db()->query("SELECT
            (SELECT COUNT(*) FROM project_orders WHERE settlement_status IN ('draft','review')) AS open_orders,
            (SELECT COUNT(*) FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE c.review_status='pending' AND o.settlement_status IN ('draft','review')) AS pending_costs,
            (SELECT COUNT(*) FROM project_cash_movements m WHERE m.review_status='pending') AS pending_cash,
            (SELECT COUNT(*) FROM project_order_resources r JOIN project_orders o ON o.id=r.order_id WHERE r.domain_mode='pending' AND o.settlement_status IN ('draft','review')) AS pending_resources,
            (SELECT COUNT(*) FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.settlement_status IN ('draft','review') AND COALESCE(s.price_source,'missing')='missing') AS missing_price,
            (SELECT COUNT(*) FROM project_orders WHERE order_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) AS month_orders")->fetch();
    } else {
        // 普通合作人员：只查本人当月的订单数与本人待确认事项
        $my_month_orders = 0;
        $my_pending_resources = 0;
        if ($my_eid > 0) {
            $my_month_orders = (int)db()->query("SELECT COUNT(DISTINCT o.id) FROM project_orders o JOIN project_participants p ON p.order_id=o.id WHERE p.employee_id=" . $my_eid . " AND o.order_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
            if (($actor['role'] ?? '') === 'technical') {
                $my_pending_resources = (int)db()->query("SELECT COUNT(*) FROM project_order_resources r JOIN project_orders o ON o.id=r.order_id JOIN project_participants p ON p.order_id=o.id WHERE p.employee_id=" . $my_eid . " AND r.domain_mode='pending' AND o.settlement_status IN ('draft','review')")->fetchColumn();
            }
        }
        $project_todo = [
            'open_orders' => 0,
            'pending_costs' => 0,
            'pending_cash' => 0,
            'pending_resources' => $my_pending_resources,
            'missing_price' => 0,
            'month_orders' => $my_month_orders,
        ];
    }
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
<?php require_once __DIR__ . '/includes/ProjectRenewals.php'; include __DIR__ . '/includes/renewal_due_widget.php'; include __DIR__ . '/includes/domain_missing_widget.php'; include __DIR__ . '/includes/renewal_info_popup.php'; ?>
<?php include __DIR__ . '/includes/rule_algo_card.php'; ?>

<?php if ($is_finance_or_admin): ?>
<div class="dash-grid mb-3">
    <div class="dash-tile"><span class="dash-tile-icon sky"><i class="fas fa-users"></i></span><div><small>合作人员</small><strong><?php echo $emp_count; ?></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon amber"><i class="fas fa-receipt"></i></span><div><small>店铺订单总数</small><strong><?php echo number_format($order_count); ?></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon"><i class="fas fa-yen-sign"></i></span><div><small>店铺订单总金额</small><strong title="¥<?php echo money($order_total); ?>">¥<?php echo $order_total >= 100000 ? number_format($order_total / 10000, 2) . '<span class="dash-unit">万</span>' : money($order_total); ?></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon plum"><i class="fas fa-folder-open"></i></span><div><small>本月项目订单</small><strong><?php echo $project_todo ? (int)$project_todo['month_orders'] : '—'; ?></strong></div></div>
</div>
<?php else: ?>
<div class="dash-grid mb-3">
    <div class="dash-tile"><span class="dash-tile-icon sky"><i class="fas fa-user-check"></i></span><div><small>我的岗位</small><strong><?php echo e($display_role); ?></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon amber"><i class="fas fa-folder-open"></i></span><div><small>我的本月订单</small><strong><?php echo (int)($project_todo['month_orders'] ?? 0); ?><span class="dash-unit">单</span></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon"><i class="fas fa-wallet"></i></span><div><small>我的报酬结算</small><strong><a href="<?php echo BASE_URL; ?>/project/payroll.php" style="color:inherit;text-decoration:none;">进入报酬中心 ›</a></strong></div></div>
    <div class="dash-tile"><span class="dash-tile-icon plum"><i class="fas fa-chart-line"></i></span><div><small>我的经营看板</small><strong><a href="<?php echo BASE_URL; ?>/project/dashboard.php" style="color:inherit;text-decoration:none;">进入看板 ›</a></strong></div></div>
</div>
<?php endif; ?>

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
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/index.php"><i class="fas fa-folder-open"></i><span>我的项目订单<small>查看参与的项目</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/dashboard.php"><i class="fas fa-chart-line"></i><span>我的经营看板<small>结算订单与分成统计</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/payroll.php"><i class="fas fa-wallet"></i><span>我的项目报酬<small>每月收入与明细</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/rules.php"><i class="fas fa-percent"></i><span>规则中心<small>分成比例与月度奖励</small></span></a>
    <?php if ($is_finance_or_admin): ?>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/import.php"><i class="fas fa-file-excel"></i><span>导入部门表格<small>支持现有各部门格式</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/shops/etmll_sync.php"><i class="fas fa-sync-alt"></i><span>ETMLL 订单同步<small>自动补全项目订单</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/attendance/index.php"><i class="fas fa-calendar-check"></i><span>考勤表<small>上传与核对出勤</small></span></a>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/salaries/settle.php"><i class="fas fa-calculator"></i><span>原系统报酬结算<small>底薪、绩效与考勤</small></span></a>
    <?php else: ?>
    <a class="dash-action" href="<?php echo BASE_URL; ?>/project/welfare.php"><i class="fas fa-heart"></i><span>全员福利池<small>查看集体福利与活动</small></span></a>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/payroll_sheet_card.php'; // 项目报酬表（对财务看全部人员，普通合作人员仅看本人） ?>

<?php if ($is_finance_or_admin && $shop_stats): ?>
<div class="row">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="fas fa-store mr-1"></i> 店铺概览</span><a href="<?php echo BASE_URL; ?>/shops/index.php" class="btn btn-sm btn-outline-secondary">管理店铺</a></div>
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
        </div>
    </div>
</div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
