<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
$current_admin = current_admin();

// 计算当前脚本相对站点根的路径，用于侧边栏高亮判断
$_script = $_SERVER['SCRIPT_NAME'] ?? '';
$_rel    = $_script;
if (BASE_URL !== '' && strpos($_script, BASE_URL) === 0) {
    $_rel = substr($_script, strlen(BASE_URL));
}
$_rel = ltrim($_rel, '/'); // 如 index.php / employees/index.php / salaries/settle.php

$is_home        = ($_rel === 'index.php');
$is_departments = (strpos($_rel, 'departments/') === 0);
$is_shops       = (strpos($_rel, 'shops/') === 0);
$is_employees   = (strpos($_rel, 'employees/') === 0);
$is_orders      = (strpos($_rel, 'orders/') === 0);
$is_abnormal    = (strpos($_rel, 'abnormal/') === 0);
$is_attendance  = (strpos($_rel, 'attendance/') === 0);
$is_settle      = ($_rel === 'salaries/settle.php');
$is_query       = ($_rel === 'salaries/query.php');
$is_insurance   = (strpos($_rel, 'insurance/') === 0);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title ?? '财务薪资结算系统'); ?> - 财务薪资结算系统</title>
    <link href="<?php echo BASE_URL; ?>/assets/lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/lib/font-awesome/css/all.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .navbar-brand { font-weight: 700; }
        .sidebar {
            position: fixed; top: 56px; left: 0; bottom: 0;
            width: 220px; background: #343a40; padding-top: 20px;
            z-index: 100; overflow-y: auto;
        }
        .sidebar a {
            display: block; color: #adb5bd; padding: 12px 20px;
            text-decoration: none; transition: all .2s;
            border-left: 3px solid transparent;
        }
        .sidebar a:hover { color: #fff; background: rgba(255,255,255,.08); }
        .sidebar a.active { color: #fff; background: rgba(255,255,255,.12); border-left-color: #28a745; }
        .sidebar a i { width: 20px; text-align: center; margin-right: 8px; }
        .main-content { margin-left: 220px; padding: 20px; margin-top: 56px; }
        .card { box-shadow: 0 1px 3px rgba(0,0,0,.08); border: none; }
        .stat-card { border-left: 4px solid; }
        .stat-card.green { border-left-color: #28a745; }
        .stat-card.blue { border-left-color: #17a2b8; }
        .stat-card.orange { border-left-color: #fd7e14; }
        .stat-card.purple { border-left-color: #6f42c1; }

        /* ===== 响应式：侧栏抽屉 ===== */
        .sidebar-backdrop {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,.5); z-index: 99; display: none;
        }
        .sidebar-backdrop.show { display: block; }
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform .3s ease;
                z-index: 1000;
            }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0 !important; }
        }
        @media (min-width: 992px) {
            #sidebarToggle { display: none; }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <a class="navbar-brand" href="<?php echo BASE_URL; ?>/index.php"><i class="fas fa-coins"></i> 财务薪资结算系统</a>
    <button class="navbar-toggler d-lg-none border-0" type="button" id="sidebarToggle"
            style="position:fixed;top:10px;left:10px;z-index:1100;background:#343a40;color:#fff;">
        <i class="fas fa-bars"></i>
    </button>
    <div class="ml-auto d-flex align-items-center">
        <span class="text-light mr-3">
            <i class="fas fa-user-circle"></i>
            <?php echo e($current_admin['username'] ?? ''); ?>
        </span>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="btn btn-outline-light btn-sm"><i class="fas fa-sign-out-alt"></i> 退出</a>
    </div>
</nav>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="sidebar">
    <a href="<?php echo BASE_URL; ?>/index.php" class="<?php echo $is_home ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> 系统首页</a>
    <a href="<?php echo BASE_URL; ?>/departments/index.php" class="<?php echo $is_departments ? 'active' : ''; ?>"><i class="fas fa-sitemap"></i> 部门管理</a>
    <a href="<?php echo BASE_URL; ?>/shops/index.php" class="<?php echo $is_shops ? 'active' : ''; ?>"><i class="fas fa-store"></i> 店铺管理</a>
    <a href="<?php echo BASE_URL; ?>/employees/index.php" class="<?php echo $is_employees ? 'active' : ''; ?>"><i class="fas fa-users"></i> 员工管理</a>
    <a href="<?php echo BASE_URL; ?>/orders/index.php" class="<?php echo $is_orders ? 'active' : ''; ?>"><i class="fas fa-file-upload"></i> 订单上传</a>
    <a href="<?php echo BASE_URL; ?>/abnormal/index.php" class="<?php echo $is_abnormal ? 'active' : ''; ?>"><i class="fas fa-exclamation-triangle"></i> 异常订单</a>
    <a href="<?php echo BASE_URL; ?>/attendance/index.php" class="<?php echo $is_attendance ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> 考勤表</a>
    <a href="<?php echo BASE_URL; ?>/insurance/index.php" class="<?php echo $is_insurance ? 'active' : ''; ?>"><i class="fas fa-shield-alt"></i> 保险管理</a>
    <a href="<?php echo BASE_URL; ?>/salaries/settle.php" class="<?php echo $is_settle ? 'active' : ''; ?>"><i class="fas fa-calculator"></i> 薪资结算</a>
    <a href="<?php echo BASE_URL; ?>/salaries/query.php" class="<?php echo $is_query ? 'active' : ''; ?>"><i class="fas fa-search-dollar"></i> 薪资查询</a>
</div>

<div class="main-content">
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
