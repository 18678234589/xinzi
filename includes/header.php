<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
$current_admin = current_admin();
$project_staff = null;
$governance_nav = false;
$governance_committee_nav = false;
$governance_election_due = 0;
$unread_messages = 0;
$needs_phone_binding = false;
$department_upload_nav = (bool)$current_admin;
$department_upload_business = '网站续费';
if (!$current_admin && isset($_SESSION['project_user_id'])) {
    $staffStmt = db()->prepare('SELECT u.username,u.role,u.employee_id,u.phone,e.name,e.department FROM project_users u JOIN employees e ON e.id=u.employee_id WHERE u.id=? AND u.is_active=1');
    $staffStmt->execute([(int)$_SESSION['project_user_id']]);
    $project_staff = $staffStmt->fetch();
    if ($project_staff) {
        $needs_phone_binding = empty($project_staff['phone']);
        try {
            $governanceStmt = db()->prepare('SELECT governance_role FROM project_governance_members WHERE employee_id=? AND is_active=1 LIMIT 1');
            $governanceStmt->execute([(int)$project_staff['employee_id']]);
            $governance_role = $governanceStmt->fetchColumn();
            $governance_nav = (bool)$governance_role;
            $governance_committee_nav = $governance_role === 'committee';
            // 未读站内信：所有合作人员都统计（上传待补全等通知也走站内信）
            { try { $unreadStmt = db()->prepare('SELECT COUNT(*) FROM project_messages WHERE employee_id=? AND read_at IS NULL'); $unreadStmt->execute([(int)$project_staff['employee_id']]); $unread_messages = (int)$unreadStmt->fetchColumn(); } catch (PDOException $e) { $unread_messages = 0; } }
            if ($governance_committee_nav) {
                $governance_election_due = (int)db()->query("SELECT COUNT(*) FROM project_governance_rotations r LEFT JOIN project_governance_elections el ON el.rotation_id=r.id WHERE COALESCE(r.end_date,DATE_SUB(DATE_ADD(r.start_date,INTERVAL 3 MONTH),INTERVAL 1 DAY))<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) AND (el.id IS NULL OR el.status<>'closed')")->fetchColumn();
            }
        } catch (PDOException $e) {
            // 新迁移尚未执行时，普通页面仍可正常打开；新栏目保持隐藏。
            $governance_nav = false;
            $governance_committee_nav = false;
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
// 平台与服务器信息：仅白名单（股东、管理、admin、服务器维护）可见
$vault_nav = false;
if ($current_admin || $project_staff) {
    try { require_once __DIR__ . '/ProjectVault.php'; $vault_nav = pv_can_access(ps_actor()); } catch (Throwable $e) { $vault_nav = false; }
}
$display_name = $current_admin['username'] ?? ($project_staff['name'] ?? ($project_staff['username'] ?? ''));
$display_role = $current_admin ? '财务 / 管理员' : (($project_staff['role'] ?? '') === 'governance' ? '管理层' : (($project_staff['role'] ?? '') === 'vault' ? '管理' : (($project_staff['role'] ?? '') === 'technical' ? '技术' : '客服')));

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
$is_project_orders = $is_project && !in_array($_rel, ['project/dashboard.php', 'project/payroll.php', 'project/settings.php', 'project/system.php', 'project/rules.php', 'project/profile.php', 'project/files.php', 'project/refunds.php', 'project/governance.php', 'project/governance_ideas.php', 'project/governance_election.php', 'project/governance_evidence.php', 'project/welfare.php', 'project/contributions.php', 'project/messages.php', 'project/holidays.php', 'project/vault.php', 'project/corrections.php', 'project/dup_feedback.php', 'project/rule_requests.php', 'project/preview_as.php', 'project/order_fixes.php'], true);
if (strpos($_rel, 'project/knowledge') === 0) $is_project_orders = false;
if (in_array($_rel, ['project/renewals.php','project/renewal_sms.php'], true)) $is_project_orders = false;
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
    <link href="<?php echo BASE_URL; ?>/assets/css/knowledge.css?v=20261002.3" rel="stylesheet">
    <style>
    .phone-bind-reminder{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.25rem;margin-bottom:1rem;border:2px solid #e0a330;border-radius:12px;background:#fff6db;color:#6e4600;box-shadow:0 0 0 2px #f7d578;animation:phone-bind-glow 1.8s ease-in-out 4}
    .phone-bind-reminder a{display:inline-block;white-space:nowrap;padding:.5rem .85rem;border-radius:8px;background:#8d5200;color:#fff;font-weight:700;text-decoration:none}
    .phone-bind-reminder a:hover,.phone-bind-reminder a:focus{background:#694000;color:#fff}
    @keyframes phone-bind-glow{50%{box-shadow:0 0 0 7px #f3bd55,0 0 22px #e7a62a}}
    @media(max-width:575px){.phone-bind-reminder{align-items:flex-start;flex-direction:column}}
    @media(prefers-reduced-motion:reduce){.phone-bind-reminder{animation:none}}
    </style>
    <?php if (in_array(($_rel ?? ''), ['project/governance.php','project/governance_ideas.php','project/governance_election.php','project/contributions.php','project/messages.php','project/holidays.php'], true) || (($_rel ?? '') === 'project/rules.php' && ($_GET['domain'] ?? $_POST['domain'] ?? '') === 'governance')): ?><link href="<?php echo BASE_URL; ?>/assets/css/governance.css" rel="stylesheet"><?php endif; ?>
    <?php if (($_rel ?? '') === 'project/dashboard.php'): ?><link href="<?php echo BASE_URL; ?>/assets/css/partner-dashboard.css" rel="stylesheet"><?php endif; ?>
    <?php if (($_rel ?? '') === 'project/dashboard.php'): ?><link href="<?php echo BASE_URL; ?>/assets/css/partner-dashboard-future.css" rel="stylesheet"><?php endif; ?>
    <?php if (($_rel ?? '') === 'project/welfare.php' || (($_rel ?? '') === 'project/rules.php' && ($_GET['domain'] ?? '') === 'welfare')): ?><link href="<?php echo BASE_URL; ?>/assets/css/welfare.css" rel="stylesheet"><?php endif; ?>
</head>
<body class="app-warm<?php echo ($_rel ?? '') === 'project/dashboard.php' ? ' pd-shell' : ''; ?>">
<nav class="navbar navbar-expand-lg navbar-light fixed-top app-topbar">
    <button class="app-menu-btn d-lg-none" type="button" id="sidebarToggle" aria-label="打开菜单"><i class="fas fa-bars"></i></button>
    <a class="navbar-brand" href="<?php echo BASE_URL; ?><?php echo ($project_staff['role'] ?? '') === 'governance' ? '/project/governance_ideas.php' : (($project_staff['role'] ?? '') === 'vault' ? '/project/vault.php' : ($project_staff ? '/project/index.php' : '/index.php')); ?>"><span class="app-brand-mark"><i class="fas fa-seedling"></i></span> 项目合作结算中心</a>
    <div class="ml-auto d-flex align-items-center">
        <?php if ($project_staff || $current_admin) { $bellCount = $project_staff ? (int)$unread_messages : (function () { try { require_once __DIR__ . '/commission_explain.php'; require_once __DIR__ . '/dup_feedback.php'; require_once __DIR__ . '/ProjectCostRequests.php'; return ps_corr_pending_count() + (pd_is_dedicated_finance(ps_actor() ?: []) ? pd_answered_count() : 0) + pcr_pending_count(ps_actor() ?: []) + (function () { try { require_once __DIR__ . '/ProjectRuleAlgo.php'; require_once __DIR__ . '/ProjectOrderFix.php'; return pra_pending_count() + pof_pending_count(); } catch (Throwable $e) { return 0; } })(); } catch (Throwable $e) { return 0; } })(); $bellHref = BASE_URL . ($project_staff ? '/project/messages.php' : '/project/corrections.php'); ?><a class="app-bell mr-3" href="<?php echo $bellHref; ?>" title="<?php echo $project_staff ? '消息通知' : '待处理的更正申请与重复订单号说明'; ?>" aria-label="消息通知"><i class="fas fa-bell"></i><?php if ($bellCount > 0): ?><span class="app-bell-badge"><?php echo $bellCount > 99 ? '99+' : $bellCount; ?></span><?php endif; ?></a><?php } ?>
        <span class="app-user mr-3"><span class="app-user-avatar" aria-hidden="true"><?php echo e(mb_substr($display_name, 0, 1)); ?></span><span class="d-none d-sm-inline"><strong><?php echo e($display_name); ?></strong><small><?php echo e($display_role); ?></small></span></span>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="btn btn-sm app-logout"><i class="fas fa-sign-out-alt"></i> 退出</a>
    </div>
</nav>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="sidebar sidebar-project">
    <div class="sidebar-project-brand"><span class="sidebar-project-mark"><i class="fas fa-seedling"></i></span><span><strong>项目合作结算</strong><small>把每一份付出，算得清楚</small></span></div>
    <?php if ($project_staff): ?>
    <?php if ($project_staff['role'] === 'vault'): ?>
    <div class="sidebar-project-label">管理</div>
    <?php echo $nav('/project/vault.php', 'fa-key', '平台与服务器信息', $_rel === 'project/vault.php'); ?>
    <?php echo $nav('/project/messages.php', 'fa-envelope', '我的站内信' . ($unread_messages ? '（' . $unread_messages . '）' : ''), $_rel === 'project/messages.php'); ?>
    <?php echo $nav('/project/profile.php', 'fa-user-cog', '我的账号', $_rel === 'project/profile.php'); ?>
    <?php elseif ($project_staff['role'] === 'governance'): ?>
    <div class="sidebar-project-label">管理层工作台</div>
    <?php if (ps_governance_has_business(['id' => (int)($_SESSION['project_user_id'] ?? 0)])) echo $nav('/project/index.php', 'fa-file-circle-plus', '提交订单 / 我的订单', $_rel === 'project/index.php' || $_rel === 'project/order.php'); ?>
    <?php echo $nav('/project/governance_ideas.php', 'fa-lightbulb', '三天脑洞', $_rel === 'project/governance_ideas.php'); ?>
    <?php echo $nav('/project/messages.php', 'fa-envelope', '我的站内信' . ($unread_messages ? '（' . $unread_messages . '）' : ''), $_rel === 'project/messages.php'); ?>
    <?php if ($governance_committee_nav): echo $nav('/project/governance_ideas.php#penalties', 'fa-shield-alt', '缺报核对与豁免', false); endif; ?>
    <?php if ($governance_committee_nav): echo $nav('/project/governance_election.php', 'fa-vote-yea', '换届投票', $_rel === 'project/governance_election.php'); endif; ?>
    <?php echo $nav('/project/governance.php', 'fa-clipboard-check', '事项与评审', $_rel === 'project/governance.php'); ?>
    <?php if ($governance_committee_nav): echo $nav('/project/contributions.php', 'fa-bug', '建议 / Bug 奖励', $_rel === 'project/contributions.php'); echo $nav('/project/holidays.php', 'fa-calendar-day', '法定节假日', $_rel === 'project/holidays.php'); endif; ?>
    <?php echo $nav('/project/rules.php?domain=governance', 'fa-book-open', '考核规则', $_rel === 'project/rules.php'); ?>
    <?php if ($vault_nav): echo $nav('/project/vault.php', 'fa-key', '平台与服务器信息', $_rel === 'project/vault.php'); endif; ?>
    <?php echo $nav('/project/payroll.php', 'fa-wallet', '我的项目报酬', $_rel === 'project/payroll.php'); ?>
    <?php echo $nav('/project/welfare.php', 'fa-heart', '全员福利池', $_rel === 'project/welfare.php'); ?>
    <?php echo $nav('/project/profile.php', 'fa-user-cog', '我的账号', $_rel === 'project/profile.php'); ?>
    <?php else: ?>
    <div class="sidebar-project-label">我的工作台</div>
    <?php echo $nav('/project/dashboard.php', 'fa-chart-line', '我的经营看板', $_rel === 'project/dashboard.php'); ?>
    <?php echo $nav('/project/index.php', 'fa-folder-open', '我的项目订单', $is_project_orders); ?>
    <?php if ($department_upload_nav): echo $nav('/project/import.php?scope=department&business=' . rawurlencode($department_upload_business), 'fa-users', '部门订单上传', $_rel === 'project/import.php' && ($_GET['scope'] ?? $_POST['scope'] ?? '') === 'department'); endif; ?>
    <?php echo $nav('/project/payroll.php', 'fa-wallet', '我的项目报酬', $_rel === 'project/payroll.php'); ?>
    <?php echo $nav('/project/welfare.php', 'fa-heart', '全员福利池', $_rel === 'project/welfare.php'); ?>
    <?php echo $nav('/project/files.php', 'fa-file-excel', '我上传的表格', $_rel === 'project/files.php'); ?>
    <?php if ($vault_nav): echo $nav('/project/vault.php', 'fa-key', '平台与服务器信息', $_rel === 'project/vault.php'); endif; ?>
    <?php if (!$governance_nav): echo $nav('/project/messages.php', 'fa-envelope', '我的站内信' . ($unread_messages ? '（' . $unread_messages . '）' : ''), $_rel === 'project/messages.php'); endif; ?>
    <?php echo $nav('/project/refunds.php', 'fa-undo-alt', '退款与返现', $_rel === 'project/refunds.php'); ?>
    <?php echo $nav('/project/profile.php', 'fa-user-cog', '我的账号', $_rel === 'project/profile.php'); ?>
    <?php if ($governance_nav): ?><div class="sidebar-project-label">管理层专属</div><?php echo $nav('/project/governance_ideas.php', 'fa-lightbulb', '三天脑洞', $_rel === 'project/governance_ideas.php'); echo $nav('/project/messages.php', 'fa-envelope', '我的站内信' . ($unread_messages ? '（' . $unread_messages . '）' : ''), $_rel === 'project/messages.php'); if ($governance_committee_nav) { echo $nav('/project/governance_ideas.php#penalties', 'fa-shield-alt', '缺报核对与豁免', false); echo $nav('/project/governance_election.php', 'fa-vote-yea', '换届投票', $_rel === 'project/governance_election.php'); echo $nav('/project/contributions.php', 'fa-bug', '建议 / Bug 奖励', $_rel === 'project/contributions.php'); echo $nav('/project/holidays.php', 'fa-calendar-day', '法定节假日', $_rel === 'project/holidays.php'); } echo $nav('/project/governance.php', 'fa-clipboard-check', '事项与评审', $_rel === 'project/governance.php'); echo $nav('/project/rules.php?domain=governance', 'fa-book-open', '考核规则', $_rel === 'project/rules.php' && ($_GET['domain'] ?? '') === 'governance'); endif; ?>
    <div class="sidebar-project-tip"><i class="fas fa-lock"></i> 这里只显示你参与或代录的订单，以及你自己的报酬。</div>
    <?php endif; ?>
    <?php else: ?>
    <?php echo $nav('/index.php', 'fa-home', '工作台首页', $is_home); ?>
    <div class="sidebar-project-label">日常办公</div>
    <?php echo $nav('/project/dashboard.php', 'fa-chart-line', '合作方经营看板', $_rel === 'project/dashboard.php'); ?>
    <?php echo $nav('/project/index.php', 'fa-folder-open', '项目订单', $is_project_orders); ?>
    <?php echo $nav('/project/import.php?scope=department&business=网站续费', 'fa-users', '网站售后部门订单', $_rel === 'project/import.php' && ($_GET['scope'] ?? $_POST['scope'] ?? '') === 'department'); ?>
    <?php echo $nav('/project/payroll.php', 'fa-wallet', '项目报酬结算', $_rel === 'project/payroll.php'); ?>
    <?php echo $nav('/project/welfare.php', 'fa-heart', '全员福利池', $_rel === 'project/welfare.php'); ?>
    <?php echo $nav('/project/contributions.php', 'fa-bug', '建议 / Bug 奖励', $_rel === 'project/contributions.php'); ?>
    <?php echo $nav('/project/holidays.php', 'fa-calendar-day', '法定节假日', $_rel === 'project/holidays.php'); ?>
    <?php echo $nav('/project/files.php', 'fa-file-excel', '原始表格', $_rel === 'project/files.php'); ?>
    <?php echo $nav('/project/refunds.php', 'fa-undo-alt', '退款与返现', $_rel === 'project/refunds.php'); ?>
    <?php echo $nav('/shops/index.php', 'fa-store', '店铺交易流水', $group_active === 'shop'); ?>
    <?php echo $nav('/employees/index.php', 'fa-users', '人员与考勤', $group_active === 'people'); ?>
    <div class="sidebar-project-label">财务与配置</div>
    <?php if ($current_admin) { require_once __DIR__ . '/commission_explain.php'; $corrPending = ps_corr_pending_count(); require_once __DIR__ . '/correction_tabs.php'; $corrAll = pc_pending_total(); echo $nav(pc_first_url(), 'fa-flag', '分成更正申请' . ($corrAll ? '（' . $corrAll . '）' : ''), in_array($_rel, ['project/corrections.php', 'project/rule_requests.php', 'project/order_fixes.php'], true)); echo $nav('/project/preview_as.php', 'fa-user-secret', '员工视角预览', $_rel === 'project/preview_as.php'); } ?>
    <?php if ($current_admin) { require_once __DIR__ . '/dup_feedback.php'; if (pd_is_dedicated_finance(ps_actor() ?: [])) { $dupPending = pd_answered_count(); echo $nav('/project/dup_feedback.php', 'fa-clone', '订单号重复说明' . ($dupPending ? '（' . $dupPending . '）' : ''), $_rel === 'project/dup_feedback.php'); } } ?>
    <?php echo $nav('/project/settings.php#cost-center', 'fa-layer-group', '成本中心与账户', $_rel === 'project/settings.php'); ?>
    <?php echo $nav('/salaries/settle.php', 'fa-calculator', '原系统结算', $group_active === 'legacy'); ?>
    <?php echo $nav('/project/system.php', 'fa-sliders-h', '系统设置', $_rel === 'project/system.php'); ?>
    <?php if ($vault_nav): echo $nav('/project/vault.php', 'fa-key', '平台与服务器信息', $_rel === 'project/vault.php'); endif; ?>
    <?php endif; ?>
    <?php include __DIR__ . '/renewal_nav.php'; ?>
    <div class="sidebar-project-label">知识与成长</div>
    <?php echo $nav('/project/knowledge.php', 'fa-book-open', '共创知识库', in_array($_rel, ['project/knowledge.php','project/knowledge_article.php','project/knowledge_categories.php','project/knowledge_integrations.php','project/knowledge_skills.php','project/knowledge_skill.php','project/knowledge_skill_import.php','project/knowledge_skills_export.php','project/knowledge_costs.php'], true)); ?>
    <div class="sidebar-kb-subnav">
    <?php echo $nav('/project/knowledge_links.php', 'fa-compass', '常用网址', $_rel === 'project/knowledge_links.php'); ?>
    <?php echo $nav('/project/knowledge_rules.php', 'fa-sliders-h', '规则中心', in_array($_rel, ['project/knowledge_rules.php','project/rules.php','project/governance_rules.php','project/welfare_rules.php'], true)); ?>
    </div>
    <div class="sidebar-project-footer">
        <a class="sidebar-project-token" href="https://token.laibangwo.com/" target="_blank" rel="noopener noreferrer" aria-label="Token 工作台">
            <span class="sidebar-project-token-mark"><i class="fas fa-bolt" aria-hidden="true"></i></span>
            <span class="sidebar-project-token-copy"><strong>Token 工作台</strong></span>
            <i class="fas fa-external-link-alt sidebar-project-token-external" aria-hidden="true"></i>
        </a>
    </div>
</div>

<div class="main-content">
<?php if ($needs_phone_binding): ?><div class="phone-bind-reminder" role="alert"><span><i class="fas fa-exclamation-circle" aria-hidden="true"></i> <strong>请尽快绑定手机号</strong>：你现在可以阅读共享知识库；提交内容和办理其他业务前，需要完成绑定。</span><a href="<?php echo BASE_URL; ?>/project/profile.php?first=1#phone">立即绑定手机号 →</a></div><?php endif; ?>
<?php if ($unread_messages && ($_rel ?? '') !== 'project/messages.php'): ?><div class="alert alert-warning mb-3"><i class="fas fa-envelope mr-1"></i> 你有 <?php echo (int)$unread_messages; ?> 条未读站内信，<a href="<?php echo BASE_URL; ?>/project/messages.php" class="alert-link">点此查看并处理</a>。</div><?php endif; ?>
<?php include __DIR__ . '/import_followup_modal.php'; ?>
<?php if ($governance_committee_nav && $governance_election_due): ?><div class="alert alert-warning mb-3"><i class="fas fa-vote-yea mr-1"></i> 本届轮值即将结束，请监委会<a href="<?php echo BASE_URL; ?>/project/governance_election.php" class="alert-link">发起或完成换届投票</a>。</div><?php endif; ?>
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
