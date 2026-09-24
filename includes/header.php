<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
$current_admin = current_admin();
$project_staff = null;
$governance_nav = false;
$department_upload_nav = (bool)$current_admin;
$department_upload_business = '网站续费';
if (!$current_admin && isset($_SESSION['project_user_id'])) {
    $staffStmt = db()->prepare('SELECT u.username,u.role,u.employee_id,e.name,e.department FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.id=? AND u.is_active=1');
    $staffStmt->execute([(int)$_SESSION['project_user_id']]);
    $project_staff = $staffStmt->fetch();
    if ($project_staff) {
        try {
            $governanceStmt = db()->prepare('SELECT 1 FROM project_governance_members WHERE employee_id=? AND is_active=1 LIMIT 1');
            $governanceStmt->execute([(int)$project_staff['employee_id']]);
            $governance_nav = (bool)$governanceStmt->fetchColumn();
        } catch (PDOException $e) {
            // 新迁移尚未执行时，普通页面仍可正常打开；新栏目保持隐藏。
            $governance_nav = false;
        }
    }
    if ($project_staff && $project_staff['department'] === '网站售后部') {
        $departmentStmt = db()->prepare("SELECT business_name FROM project_user_businesses WHERE user_id=? AND business_name IN ('网站续费','网站修改') ORDER BY (business_name='网站续费') DESC LIMIT 1");
        $departmentStmt->execute([(int)$_SESSION['project_user_id']]);
        $assignedDepartmentBusiness = $departmentStmt->fetchColumn();
        $department_upload_nav = (bool)$assignedDepartmentBusiness;
        if ($assignedDepartmentBusiness) $department_upload_business = $assignedDepartmentBusiness;
    }
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
$is_project_orders = $is_project && !in_array($_rel, ['project/dashboard.php', 'project/payroll.php', 'project/settings.php', 'project/system.php', 'project/rules.php', 'project/profile.php', 'project/files.php', 'project/refunds.php', 'project/governance.php', 'project/governance_evidence.php'], true);
// 合并栏目：同类页面在侧栏只占一个入口，进入后顶部页签切换。
$nav_groups = [
    'shop' => [['/shops/index.php', 'fa-store', '店铺管理', $is_shops], ['/shops/etmll_sync.php', 'fa-sync-alt', 'ETMLL 订单同步', $is_etmll], ['/orders/index.php', 'fa-file-upload', '平台订单导入', $is_orders], ['/abnormal/index.php', 'fa-exclamation-triangle', '异常订单', $is_abnormal]],
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
    <?php if (($_rel ?? '') === 'project/governance.php' || (($_rel ?? '') === 'project/rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'governance')): ?><link href="<?php echo BASE_URL; ?>/assets/css/governance.css" rel="stylesheet"><?php endif; ?>
    <?php if (($_rel ?? '') === 'project/dashboard.php'): ?><link href="<?php echo BASE_URL; ?>/assets/css/partner-dashboard.css" rel="stylesheet"><?php endif; ?>
    <?php if (($_rel ?? '') === 'project/dashboard.php'): ?><link href="<?php echo BASE_URL; ?>/assets/css/partner-dashboard-future.css" rel="stylesheet"><?php endif; ?>
</head>
<body class="app-warm<?php echo ($_rel ?? '') === 'project/dashboard.php' ? ' pd-shell' : ''; ?>">
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
    <?php echo $nav('/project/dashboard.php', 'fa-chart-line', '我的经营看板', $_rel === 'project/dashboard.php'); ?>
    <?php echo $nav('/project/index.php', 'fa-folder-open', '我的项目订单', $is_project_orders); ?>
    <?php if ($department_upload_nav): echo $nav('/project/import.php?scope=department&business=' . rawurlencode($department_upload_business), 'fa-users', '部门订单上传', $_rel === 'project/import.php' && ($_GET['scope'] ?? $_POST['scope'] ?? '') === 'department'); endif; ?>
    <?php echo $nav('/project/payroll.php', 'fa-wallet', '我的项目报酬', $_rel === 'project/payroll.php'); ?>
    <?php echo $nav('/project/files.php', 'fa-file-excel', '我上传的表格', $_rel === 'project/files.php'); ?>
    <?php echo $nav('/project/refunds.php', 'fa-undo-alt', '退款与返现', $_rel === 'project/refunds.php'); ?>
    <?php echo $nav('/project/profile.php', 'fa-user-cog', '我的账号', $_rel === 'project/profile.php'); ?>
    <?php if ($governance_nav): ?><div class="sidebar-project-label">管理层专属</div><?php echo $nav('/project/governance.php', 'fa-clipboard-check', '管理层激励考核', $_rel === 'project/governance.php' || ($_rel === 'project/rules.php' && ($_GET['domain'] ?? '') === 'governance')); endif; ?>
    <div class="sidebar-project-tip"><i class="fas fa-lock"></i> 这里只显示你参与或代录的订单，以及你自己的报酬。</div>
    <?php else: ?>
    <?php echo $nav('/index.php', 'fa-home', '工作台首页', $is_home); ?>
    <div class="sidebar-project-label">日常办公</div>
    <?php echo $nav('/project/dashboard.php', 'fa-chart-line', '合作方经营看板', $_rel === 'project/dashboard.php'); ?>
    <?php echo $nav('/project/index.php', 'fa-folder-open', '项目订单', $is_project_orders); ?>
    <?php echo $nav('/project/import.php?scope=department&business=网站续费', 'fa-users', '网站售后部门订单', $_rel === 'project/import.php' && ($_GET['scope'] ?? $_POST['scope'] ?? '') === 'department'); ?>
    <?php echo $nav('/project/payroll.php', 'fa-wallet', '项目报酬结算', $_rel === 'project/payroll.php'); ?>
    <?php echo $nav('/project/files.php', 'fa-file-excel', '原始表格', $_rel === 'project/files.php'); ?>
    <?php echo $nav('/project/refunds.php', 'fa-undo-alt', '退款与返现', $_rel === 'project/refunds.php'); ?>
    <?php echo $nav('/shops/index.php', 'fa-store', '店铺交易流水', $group_active === 'shop'); ?>
    <?php echo $nav('/employees/index.php', 'fa-users', '人员与考勤', $group_active === 'people'); ?>
    <div class="sidebar-project-label">财务与配置</div>
    <?php echo $nav('/project/rules.php', 'fa-percent', '规则中心', $_rel === 'project/rules.php'); ?>
    <?php echo $nav('/project/settings.php#cost-center', 'fa-layer-group', '成本中心与账户', $_rel === 'project/settings.php'); ?>
    <?php echo $nav('/salaries/settle.php', 'fa-calculator', '原系统结算', $group_active === 'legacy'); ?>
    <?php echo $nav('/project/system.php', 'fa-sliders-h', '系统设置', $_rel === 'project/system.php'); ?>
    <?php endif; ?>
    <div class="sidebar-project-footer">
        <a class="sidebar-project-token" href="https://token.laibangwo.com/" target="_blank" rel="noopener noreferrer" aria-label="Token 工作台">
            <span class="sidebar-project-token-mark"><i class="fas fa-bolt" aria-hidden="true"></i></span>
            <span class="sidebar-project-token-copy"><strong>Token 工作台</strong></span>
            <i class="fas fa-external-link-alt sidebar-project-token-external" aria-hidden="true"></i>
        </a>
    </div>
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
