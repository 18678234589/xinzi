<div class="project-intake-page">
<?php require_once dirname(__DIR__,3).'/includes/ProjectRenewals.php'; if(pr_ready() && pr_scope($actor)!=='none'): ?>
<div class="mb-3 d-flex align-items-center flex-wrap" style="gap:10px"><a class="btn btn-success btn-sm" style="color:#fff" href="<?php echo BASE_URL; ?>/project/batch_fill.php?month=<?php echo e($month); ?>"><i class="fas fa-file-upload mr-1"></i> 批量补全已有订单</a><span class="small text-muted">下载缺项模板，填空后上传；原有单笔修改与新订单导入都保留。</span></div>
<?php endif; ?>
<div class="card mb-3" style="background:linear-gradient(115deg,#eef8f2,#fff9ef);border-color:#d4e5dc"><div class="card-body d-flex align-items-center justify-content-between flex-wrap" style="gap:12px"><div><strong style="color:#2e6450"><i class="fas fa-shield-alt mr-1"></i> 系统核对，让分成更清楚</strong><div class="small text-muted mt-1">资料齐全自动核算；缺流水、缺资料和异常分别提示。售价不代替实收，付款不代替交付。</div></div><?php
    if($actor['role']==='finance'): ?><a class="btn btn-outline-primary btn-sm" href="<?php echo BASE_URL; ?>/project/review.php?month=<?php echo e($month); ?>">查看系统核对</a><?php
    endif; ?></div></div>
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 订单入口</div><h2><?php if ($actor['role'] === 'finance'): echo e($page_title); else
    : $hour = (int)date('G'); echo ($hour < 11 ? '早上好' : ($hour < 14 ? '中午好' : ($hour < 18 ? '下午好' : '晚上好'))) . '，' . e($display_name); endif; ?></h2><p><?php
    echo $actor['role'] === 'customer_service' ? '客服录入买家与成交信息并指定技术；技术在同一订单号补资源和成本，双方看到的是同一张结算单。'
    : ($actor['role'] === 'technical' ? '打开本人参与的订单补技术资料与成本；先建单时可在结算单关联客服。' : '客服与技术共用一张订单结算单。输入订单号即可从店铺 / ETMLL 流水带出买家与售价，标准成本从成本中心带入。'
    ); ?> 实收由财务确认。</p></div><div class="project-hero-actions"><?php if ($allowedBusinesses): ?><button class="btn btn-light" type="button" id="manualOrderToggle" aria-controls="manual-order" aria-expanded="<?php
    echo $openEntry ? 'true' : 'false'; ?>"><i class="fas fa-pen mr-1"></i> <span><?php echo $openEntry ? '收起在线录单' : '在线录入订单'; ?></span></button><a class="btn btn-outline-light" href="<?php
    echo BASE_URL; ?>/project/import.php?business=<?php echo rawurlencode($selectedBusiness); ?>"><i class="fas fa-file-excel mr-1"></i> 批量导入 Excel</a><?php endif; ?><?php
    if ($departmentImportBusiness): ?><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/import.php?scope=department&amp;business=<?php echo rawurlencode($departmentImportBusiness
    ); ?>"><i class="fas fa-users mr-1"></i> 网站售后部门订单</a><?php endif; ?><?php if ($actor['role'] === 'finance'): ?><a class="btn btn-outline-light" href="<?php echo
    BASE_URL; ?>/project/settings.php#cost-center">成本中心</a><?php endif; ?></div></div>
