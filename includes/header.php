<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
$current_admin = current_admin();
$project_staff = null;
if (!$current_admin && isset($_SESSION['project_user_id'])) {
    $staffStmt = db()->prepare('SELECT u.username,u.role,e.name FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.id=? AND u.is_active=1');
    $staffStmt->execute([(int)$_SESSION['project_user_id']]);
    $project_staff = $staffStmt->fetch();
}
$display_name = $current_admin['username'] ?? ($project_staff['name'] ?? ($project_staff['username'] ?? ''));
$display_role = $current_admin ? '财务 / 管理员' : (($project_staff['role'] ?? '') === 'technical' ? '技术' : '客服');

// 计算当前脚本相对站点根的路径，用于侧边栏高亮判断
$_script = $_SERVER['SCRIPT_NAME'] ?? '';
$_rel    = $_script;
if (BASE_URL !== '' && strpos($_script, BASE_URL) === 0) {
    $_rel = substr($_script, strlen(BASE_URL));
}
$_rel = ltrim($_rel, '/'); // 如 index.php / employees/index.php / salaries/settle.php

$is_home        = ($_rel === 'index.php');
$is_departments = (strpos($_rel, 'departments/') === 0);
$is_etmll       = ($_rel === 'shops/etmll_sync.php');
$is_shops       = (strpos($_rel, 'shops/') === 0) && !$is_etmll;
$is_employees   = (strpos($_rel, 'employees/') === 0);
$is_orders      = (strpos($_rel, 'orders/') === 0);
$is_abnormal    = (strpos($_rel, 'abnormal/') === 0);
$is_attendance  = (strpos($_rel, 'attendance/') === 0);
$is_performance = (strpos($_rel, 'performance/') === 0);
$is_settle      = ($_rel === 'salaries/settle.php');
$is_query       = ($_rel === 'salaries/query.php');
$is_insurance   = (strpos($_rel, 'insurance/') === 0);
$is_project     = (strpos($_rel, 'project/') === 0);
$is_project_orders = $is_project && !in_array($_rel, ['project/payroll.php', 'project/settings.php', 'project/system.php', 'project/rules.php', 'project/profile.php', 'project/files.php', 'project/refunds.php'], true);
// 合并栏目：同类页面在侧栏只占一个入口，进入后顶部页签切换。
$nav_groups = [
    'shop' => [['/shops/index.php', 'fa-store', '店铺管理', $is_shops], ['/shops/etmll_sync.php', 'fa-sync-alt', 'ETMLL 订单同步', $is_etmll], ['/orders/index.php', 'fa-file-upload', '订单上传', $is_orders], ['/abnormal/index.php', 'fa-exclamation-triangle', '异常订单', $is_abnormal]],
    'people' => [['/employees/index.php', 'fa-users', '合作人员', $is_employees], ['/departments/index.php', 'fa-sitemap', '部门', $is_departments], ['/attendance/index.php', 'fa-calendar-check', '考勤表', $is_attendance], ['/performance/index.php', 'fa-headset', '客服绩效', $is_performance], ['/insurance/index.php', 'fa-shield-alt', '保险', $is_insurance]],
    'legacy' => [['/salaries/settle.php', 'fa-calculator', '报酬结算', $is_settle], ['/salaries/query.php', 'fa-search-dollar', '结算查询', $is_query]],
];
$group_active = null;
foreach ($nav_groups as $group_key => $items) foreach ($items as $item) if ($item[3]) $group_active = $group_key;
$nav = function ($href, $icon, $label, $active) {
    return '<a href="' . BASE_URL . $href . '" class="' . ($active ? 'active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '><i class="fas ' . $icon . '"></i> ' . $label . '</a>';
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title ?? '项目合作结算中心'); ?> - 项目合作结算中心</title>
    <link href="<?php echo BASE_URL; ?>/assets/lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/lib/font-awesome/css/all.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/project-intake.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/theme.css" rel="stylesheet">
</head>
<body class="app-warm">
<nav class="navbar navbar-expand-lg navbar-light fixed-top app-topbar">
    <button class="app-menu-btn d-lg-none" type="button" id="sidebarToggle" aria-label="打开菜单"><i class="fas fa-bars"></i></button>
    <a class="navbar-brand" href="<?php echo BASE_URL; ?><?php echo $project_staff ? '/project/index.php' : '/index.php'; ?>"><span class="app-brand-mark"><i class="fas fa-seedling"></i></span> 项目合作结算中心</a>
    <div class="ml-auto d-flex align-items-center">
        <span class="app-user mr-3"><span class="app-user-avatar" aria-hidden="true"><?php echo e(mb_substr($display_name, 0, 1)); ?></span><span class="d-none d-sm-inline"><strong><?php echo e($display_name); ?></strong><small><?php echo e($display_role); ?></small></span></span>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="btn btn-sm app-logout"><i class="fas fa-sign-out-alt"></i> 退出</a>
    </div>
</nav>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="sidebar sidebar-project">
    <div class="sidebar-project-brand"><span class="sidebar-project-mark"><i class="fas fa-seedling"></i></span><span><strong>项目合作结算</strong><small>把每一份付出，算得清楚</small></span></div>
    <?php if ($project_staff): ?>
    <div class="sidebar-project-label">我的工作台</div>
    <?php echo $nav('/project/index.php', 'fa-folder-open', '我的项目订单', $is_project_orders); ?>
    <?php echo $nav('/project/payroll.php', 'fa-wallet', '我的项目报酬', $_rel === 'project/payroll.php'); ?>
    <?php echo $nav('/project/files.php', 'fa-file-excel', '我上传的表格', $_rel === 'project/files.php'); ?>
    <?php echo $nav('/project/refunds.php', 'fa-undo-alt', '退款与返现', $_rel === 'project/refunds.php'); ?>
    <?php echo $nav('/project/profile.php', 'fa-user-cog', '我的账号', $_rel === 'project/profile.php'); ?>
    <div class="sidebar-project-tip"><i class="fas fa-lock"></i> 这里只显示你参与的订单和你自己的报酬。</div>
    <?php else: ?>
    <?php echo $nav('/index.php', 'fa-home', '工作台首页', $is_home); ?>
    <div class="sidebar-project-label">日常办公</div>
    <?php echo $nav('/project/index.php', 'fa-folder-open', '项目订单', $is_project_orders); ?>
    <?php echo $nav('/project/payroll.php', 'fa-wallet', '项目报酬结算', $_rel === 'project/payroll.php'); ?>
    <?php echo $nav('/project/files.php', 'fa-file-excel', '原始表格', $_rel === 'project/files.php'); ?>
    <?php echo $nav('/project/refunds.php', 'fa-undo-alt', '退款与返现', $_rel === 'project/refunds.php'); ?>
    <?php echo $nav('/shops/index.php', 'fa-store', '店铺与订单', $group_active === 'shop'); ?>
    <?php echo $nav('/employees/index.php', 'fa-users', '人员与考勤', $group_active === 'people'); ?>
    <div class="sidebar-project-label">财务与配置</div>
    <?php echo $nav('/project/rules.php', 'fa-percent', '规则中心', $_rel === 'project/rules.php'); ?>
    <?php echo $nav('/project/settings.php#cost-center', 'fa-layer-group', '成本中心与账户', $_rel === 'project/settings.php'); ?>
    <?php echo $nav('/salaries/settle.php', 'fa-calculator', '原系统结算', $group_active === 'legacy'); ?>
    <?php echo $nav('/project/system.php', 'fa-sliders-h', '系统设置', $_rel === 'project/system.php'); ?>
    <?php endif; ?>
</div>

<div class="main-content">
<?php
// 财务 / 管理员仍在用默认密码（= 登录名）时提醒修改
if ($current_admin && ($_rel ?? '') !== 'project/system.php') {
    $pwdCheck = db()->prepare('SELECT password FROM admins WHERE id=?');
    $pwdCheck->execute([(int)$current_admin['id']]);
    if ($pwdCheck->fetchColumn() === md5((string)$current_admin['username'])) echo '<div class="alert alert-warning">你的财务账号还在使用默认密码（登录名），它能看到所有人的订单与报酬，请<a href="' . BASE_URL . '/project/system.php#my-password">现在修改</a>。</div>';
}
?>
<?php if (!$project_staff && $group_active): ?><nav class="app-tabs mb-3" aria-label="同类页面"><?php foreach ($nav_groups[$group_active] as $item): ?><a href="<?php echo BASE_URL . $item[0]; ?>" class="<?php echo $item[3] ? 'active' : ''; ?>"<?php echo $item[3] ? ' aria-current="page"' : ''; ?>><i class="fas <?php echo $item[1]; ?>"></i> <?php echo $item[2]; ?></a><?php endforeach; ?></nav><?php endif; ?>
<script>
(function(){
    var btn = document.getElementById('sidebarToggle');
    var sb  = document.querySelector('.sidebar');
    var bd  = document.getElementById('sidebarBackdrop');
    function toggle(){ sb.classList.toggle('open'); bd.classList.toggle('show'); }
    if(btn) btn.addEventListener('click', toggle);
    if(bd) bd.addEventListener('click', toggle);
})();
</script>
