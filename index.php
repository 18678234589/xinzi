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
        // 被授权审核业务的人员（如网站人事冯超）：看到所授权业务（不含本人参与）的成本待审、资源待确认、售价待补
        require_once __DIR__ . '/includes/ProjectBatchReview.php';
        $delScope = $my_eid > 0 ? pbr_scope($actor) : null;
        if ($delScope && empty($delScope['all']) && !empty($delScope['businesses'])) {
            $in = implode(',', array_map(function ($b) { return db()->quote($b); }, $delScope['businesses']));
            $notMine = ' AND NOT EXISTS (SELECT 1 FROM project_participants dp WHERE dp.order_id=o.id AND dp.employee_id=' . $my_eid . ')';
            $base = " o.project_type IN ($in) AND o.settlement_status IN ('draft','review')" . $notMine;
            $project_todo['pending_costs'] = (int)db()->query("SELECT COUNT(*) FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE c.review_status='pending' AND" . $base)->fetchColumn();
            $project_todo['pending_resources'] = (int)db()->query("SELECT COUNT(*) FROM project_order_resources r JOIN project_orders o ON o.id=r.order_id WHERE r.domain_mode='pending' AND" . $base)->fetchColumn();
            $project_todo['missing_price'] = (int)db()->query("SELECT COUNT(*) FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE COALESCE(s.price_source,'missing')='missing' AND" . $base)->fetchColumn();
            $project_todo['delegate'] = true;
        }
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
require_once __DIR__ . '/includes/ProjectRenewals.php';
$homeCss = __DIR__ . '/assets/css/home.css';
$todoTotal = $project_todo ? (int)$project_todo['pending_costs'] + (int)$project_todo['pending_cash'] + (int)$project_todo['pending_resources'] + (int)$project_todo['missing_price'] : 0;
$B = BASE_URL;
?>
<link rel="stylesheet" href="<?php echo e($B . '/assets/css/home.css?v=' . (is_file($homeCss) ? filemtime($homeCss) : 1)); ?>">
<div class="project-intake-page hm">
<header class="hm-hero">
  <div><div class="hm-eyebrow"><i class="far fa-calendar-alt"></i> <?php echo date('Y 年 n 月 j 日'); ?> · 星期<?php echo $weekdays[(int)date('w')]; ?></div>
    <h2><?php echo $greeting; ?>，<?php echo e($display_name); ?></h2>
    <p><?php if ($project_todo === null): ?>项目结算表尚未初始化，请先执行 <code>php migrations/apply_project.php</code>。<?php elseif ($todoTotal > 0): ?>当前有 <b><?php echo $todoTotal; ?></b> 项待处理事项，点下方标签直接前往。<?php else: ?>暂无待审事项，一切顺利。<?php endif; ?></p></div>
  <div class="hm-cta"><a class="hm-btn is-main" href="<?php echo $B; ?>/project/index.php?entry=1"><i class="fas fa-pen"></i> 录入项目订单</a><a class="hm-btn" href="<?php echo $B; ?>/project/payroll.php"><i class="fas fa-wallet"></i> 项目报酬结算</a></div>
</header>

<section class="hm-kpis" aria-label="概览">
<?php if ($is_finance_or_admin): ?>
  <div class="hm-kpi sky"><i class="fas fa-users"></i><div><small>合作人员</small><strong><?php echo $emp_count; ?></strong></div></div>
  <div class="hm-kpi amber"><i class="fas fa-receipt"></i><div><small>店铺订单总数</small><strong><?php echo number_format($order_count); ?></strong></div></div>
  <div class="hm-kpi"><i class="fas fa-yen-sign"></i><div><small>店铺订单总金额</small><strong title="¥<?php echo money($order_total); ?>">¥<?php echo $order_total >= 100000 ? number_format($order_total / 10000, 2) . '<em>万</em>' : money($order_total); ?></strong></div></div>
  <a class="hm-kpi plum" href="<?php echo $B; ?>/project/index.php"><i class="fas fa-folder-open"></i><div><small>本月项目订单</small><strong><?php echo $project_todo ? (int)$project_todo['month_orders'] : '—'; ?></strong></div></a>
<?php else: ?>
  <div class="hm-kpi sky"><i class="fas fa-user-check"></i><div><small>我的岗位</small><strong style="font-size:20px"><?php echo e($display_role); ?></strong></div></div>
  <a class="hm-kpi amber" href="<?php echo $B; ?>/project/index.php"><i class="fas fa-folder-open"></i><div><small>我的本月订单</small><strong><?php echo (int)($project_todo['month_orders'] ?? 0); ?><em>单</em></strong></div></a>
  <a class="hm-kpi" href="<?php echo $B; ?>/project/payroll.php"><i class="fas fa-wallet"></i><div><small>我的报酬结算</small><strong style="font-size:20px">报酬中心 ›</strong></div></a>
  <a class="hm-kpi plum" href="<?php echo $B; ?>/project/dashboard.php"><i class="fas fa-chart-line"></i><div><small>我的经营看板</small><strong style="font-size:20px">进入看板 ›</strong></div></a>
<?php endif; ?>
</section>

<nav class="hm-strip" aria-label="待办与常用入口">
<?php if ($project_todo !== null): ?>
  <b>待办</b>
  <?php if ($todoTotal === 0): ?><a class="hm-chip ok" href="<?php echo $B; ?>/project/index.php"><i class="fas fa-check-circle"></i> 暂无待审事项</a><?php endif; ?>
  <?php if ((int)$project_todo['pending_costs']): ?><a class="hm-chip warn" href="<?php echo $B; ?>/project/index.php?state=todo"><i class="fas fa-receipt"></i> 成本待审 <strong><?php echo (int)$project_todo['pending_costs']; ?></strong></a><?php endif; ?>
  <?php if ((int)$project_todo['pending_cash']): ?><a class="hm-chip warn" href="<?php echo $B; ?>/project/index.php?state=todo"><i class="fas fa-coins"></i> 收退款待审 <strong><?php echo (int)$project_todo['pending_cash']; ?></strong></a><?php endif; ?>
  <?php if ((int)$project_todo['pending_resources']): ?><a class="hm-chip warn" href="<?php echo $B; ?>/project/index.php?state=todo"><i class="fas fa-server"></i> 资源待技术确认 <strong><?php echo (int)$project_todo['pending_resources']; ?></strong></a><?php endif; ?>
  <?php if ((int)$project_todo['missing_price']): ?><a class="hm-chip warn" href="<?php echo $B; ?><?php echo !empty($project_todo['delegate']) ? '/project/index.php?state=todo' : '/project/index.php?state=missing_price'; ?>" title="查看这些订单；可用 ETMLL 同步补齐"><i class="fas fa-tag"></i> 售价待补 <strong><?php echo (int)$project_todo['missing_price']; ?></strong></a><?php endif; ?>
  <span class="hm-gap"></span>
<?php endif; ?>
  <b>常用</b>
  <a class="hm-chip" href="<?php echo $B; ?>/project/index.php"><i class="fas fa-folder-open"></i> 项目订单</a>
  <a class="hm-chip" href="<?php echo $B; ?>/project/dashboard.php"><i class="fas fa-chart-line"></i> 经营看板</a>
  <a class="hm-chip" href="<?php echo $B; ?>/project/rules.php"><i class="fas fa-percent"></i> 规则中心</a>
<?php if ($is_finance_or_admin): ?>
  <a class="hm-chip" href="<?php echo $B; ?>/project/import.php"><i class="fas fa-file-excel"></i> 导入部门表格</a>
  <a class="hm-chip" href="<?php echo $B; ?>/shops/etmll_sync.php"><i class="fas fa-sync-alt"></i> ETMLL 订单同步</a>
  <a class="hm-chip" href="<?php echo $B; ?>/attendance/index.php"><i class="fas fa-calendar-check"></i> 服务时长确认表</a>
<?php else: ?>
  <a class="hm-chip" href="<?php echo $B; ?>/project/welfare.php"><i class="fas fa-heart"></i> 全员福利池</a>
<?php endif; ?>
</nav>

<div class="hm-sec">项目报酬<small>财务核对通过（标绿）后可申请结算</small></div>
<?php include __DIR__ . '/includes/payroll_sheet_card.php'; // 项目报酬表（对财务看全部人员，普通合作人员仅看本人） ?>

<div class="hm-sec">提醒与算法</div>
<?php include __DIR__ . '/includes/renewal_due_widget.php'; include __DIR__ . '/includes/domain_missing_widget.php'; include __DIR__ . '/includes/renewal_info_popup.php'; ?>
<?php include __DIR__ . '/includes/rule_algo_card.php'; ?>

<?php if ($is_finance_or_admin && $shop_stats): ?>
<details class="hm-shops"><summary><i class="fas fa-store"></i> 店铺概览<span><?php echo count($shop_stats); ?> 家店铺 · 点击展开</span><a class="hm-chip" href="<?php echo $B; ?>/shops/index.php" onclick="event.stopPropagation()">管理店铺</a></summary>
  <div class="hm-shopgrid"><?php foreach ($shop_stats as $s): ?><div><span><?php echo e($s['name']); ?></span><b><?php echo number_format((int)$s['order_count']); ?><em> 笔</em></b></div><?php endforeach; ?></div>
</details>
<?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
