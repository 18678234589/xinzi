<?php $piCss = dirname(__DIR__, 3) . '/assets/css/project_index.css'; ?>
<link rel="stylesheet" href="<?php echo e(BASE_URL . '/assets/css/project_index.css?v=' . (is_file($piCss) ? filemtime($piCss) : 1)); ?>">
<div class="project-intake-page pi">
<?php require_once dirname(__DIR__,3).'/includes/ProjectRenewals.php'; $piFill = pr_ready() && pr_scope($actor)!=='none'; ?>
<header class="pi-hero">
  <div class="pi-hero-main"><div class="pi-eyebrow"><i class="fas fa-folder-open"></i> 项目合作结算中心 · 订单入口</div>
    <h2><?php if ($actor['role'] === 'finance'): echo e($page_title); else
    : $hour = (int)date('G'); echo ($hour < 11 ? '早上好' : ($hour < 14 ? '中午好' : ($hour < 18 ? '下午好' : '晚上好'))) . '，' . e($display_name); endif; ?></h2>
    <p><?php
    echo $actor['role'] === 'customer_service' ? '客服录入买家与成交信息并指定技术；技术在同一订单号补资源和成本，双方看到的是同一张结算单。'
    : ($actor['role'] === 'technical' ? '打开本人参与的订单补技术资料与成本；先建单时可在结算单关联客服。' : '客服与技术共用一张订单结算单。输入订单号即可从店铺 / ETMLL 流水带出买家与售价，标准成本从成本中心带入。'
    ); ?> 实收由财务确认。</p>
    <div class="pi-note"><i class="fas fa-shield-alt"></i> 系统核对，让分成更清楚：资料齐全自动核算；缺流水、缺资料和异常分别提示。售价不代替实收，付款不代替交付。</div></div>
  <div class="pi-hero-tools"><?php if ($allowedBusinesses): ?><button class="pi-btn is-main" type="button" id="manualOrderToggle" aria-controls="manual-order" aria-expanded="<?php
    echo $openEntry ? 'true' : 'false'; ?>"><i class="fas fa-pen"></i> <span><?php echo $openEntry ? '收起在线录单' : '在线录入订单'; ?></span></button><a class="pi-btn" href="<?php
    echo BASE_URL; ?>/project/import.php?business=<?php echo rawurlencode($selectedBusiness); ?>"><i class="fas fa-file-excel"></i> 批量导入 Excel</a><?php endif; ?><?php
    // 我的表格：当前筛选月份的在线表格（像 Excel 一样直接录入 / 粘贴，自动保存并同步到系统）
    require_once dirname(__DIR__, 3) . '/includes/ProjectOnlineSheets.php'; $piOnline = pols_businesses($actor);
    if ($piOnline): $piOnlineBusiness = in_array($selectedBusiness, $piOnline, true) ? $selectedBusiness : $piOnline[0]; ?><a class="pi-btn" title="像 Excel 一样直接在网页里录入或粘贴，改动自动保存并同步到系统" href="<?php echo BASE_URL; ?>/project/files.php?online=1&amp;business=<?php echo rawurlencode($piOnlineBusiness); ?>&amp;month=<?php echo e($month); ?>"><i class="fas fa-table"></i> 我的表格（<?php echo e($month); ?>）</a><?php endif; ?><?php
    if ($departmentImportBusiness): ?><a class="pi-btn" href="<?php echo BASE_URL; ?>/project/import.php?scope=department&amp;business=<?php echo rawurlencode($departmentImportBusiness
    ); ?>"><i class="fas fa-users"></i> 网站售后部门订单</a><?php endif; ?><?php if ($piFill): ?><a class="pi-btn" title="下载缺项模板，填空后上传；原有单笔修改与新订单导入都保留" href="<?php echo BASE_URL; ?>/project/batch_fill.php?month=<?php echo e($month); ?>"><i class="fas fa-file-upload"></i> 批量补全已有订单</a><?php endif; ?><?php if ($actor['role'] === 'finance'): ?><a class="pi-btn" href="<?php
    echo BASE_URL; ?>/project/review.php?month=<?php echo e($month); ?>"><i class="fas fa-shield-alt"></i> 系统核对</a><a class="pi-btn" href="<?php
    echo BASE_URL; ?>/project/settings.php#cost-center"><i class="fas fa-coins"></i> 成本中心</a><?php endif; ?></div>
</header>
