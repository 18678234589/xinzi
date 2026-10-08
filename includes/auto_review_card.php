<?php
require_once __DIR__ . '/ProjectAutoReview.php';
$autoCheck = null;
if (pa_storage_available()) {
    $autoQuery = db()->prepare('SELECT a.*,s.synced_at source_synced_at FROM project_auto_reviews a LEFT JOIN project_order_sources s ON s.order_id=a.order_id WHERE a.order_id=?');
    $autoQuery->execute([(int)$order['id']]); $autoCheck = $autoQuery->fetch();
}
$autoRow = $order;
if ($autoCheck) {
    $autoRow += ['auto_review_state'=>$autoCheck['state'],'auto_review_policy'=>$autoCheck['policy_version'],'auto_review_version'=>$autoCheck['checked_row_version'],'auto_review_source_at'=>$autoCheck['checked_source_at'],'source_synced_at'=>$autoCheck['source_synced_at'],'auto_review_reasons'=>$autoCheck['reasons_json'],'auto_review_checked_at'=>$autoCheck['checked_at']];
}
$autoView = pa_view($autoRow);
$autoEvidence = $autoCheck ? (json_decode($autoCheck['evidence_json'],true) ?: []) : [];
?>
<section class="card mb-3" aria-label="系统证据核对" style="border:1px solid #d2e5dd;background:linear-gradient(120deg,#f0f8f4,#fffaf0)">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:12px"><div><small style="color:#477464;letter-spacing:.08em">EVIDENCE · 自动核对</small><h5 class="mt-1 mb-1">让每一份分成都有据可查</h5><span class="badge badge-<?php echo e($autoView['tone']); ?>"><?php echo e($autoView['label']); ?></span></div>
    <?php if ($actor['role']==='finance'): ?><form method="post" action="<?php echo BASE_URL; ?>/project/review.php"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="check_order"><input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>"><button class="btn btn-primary btn-sm" style="color:#fff" type="submit">重新核对这笔订单</button></form><?php endif; ?></div>
    <?php if ($autoView['reasons']): ?><ul class="small mt-3 mb-2"><?php foreach ($autoView['reasons'] as $autoReason): ?><li class="mb-1"><?php echo e($autoReason['text']); ?></li><?php endforeach; ?></ul><?php elseif ($autoView['state']==='queued'): ?><p class="small text-muted mt-2 mb-1">资料保存后会自动核对，较大批次由定时任务接续处理。无需重复上传；售价不会被当作已到账。</p><?php else: ?><p class="small text-muted mt-2 mb-1">已记录核对依据。自动核对不代表自动发款；历史已结算、已锁定金额不会被系统覆盖。</p><?php endif; ?>
    <?php if (!empty($autoEvidence['monthly_allowance_rules']) && $autoView['state']!=='queued'): ?><div class="alert alert-success small mt-3 mb-2"><strong>月度单量补助资格已自动核验</strong>：<?php echo e(implode('、',array_column($autoEvidence['monthly_allowance_rules'],'rule_name'))); ?>。按原月度算法去重计入一次，不会额外生成逐单补助；利润分成仍需核对真实实收。</div><?php endif; ?>
    <?php if ($autoEvidence && $autoView['state']!=='queued'): ?><details class="mt-2"><summary class="small" style="cursor:pointer;color:#2c6b55">查看核对依据与分成公式</summary><div class="small mt-2 text-muted">核对时间：<?php echo e($autoCheck['checked_at']); ?> · 规则版本：<?php echo e($autoCheck['policy_version']); ?><br>已核验收款 ¥<?php echo money($autoEvidence['receipt']??0); ?> − 已登记退款 ¥<?php echo money($autoEvidence['refund']??0); ?>；直接成本 ¥<?php echo money($autoEvidence['direct_cost']??0); ?>，服务费 ¥<?php echo money($autoEvidence['service_fee']??0); ?>。<br>收退款记录 <?php echo count($autoEvidence['cash_ids']??[]); ?> 条，成本记录 <?php echo count($autoEvidence['cost_ids']??[]); ?> 条。</div><?php foreach ($autoEvidence['groups']??[] as $autoGroup): ?><div class="small mt-2"><?php echo e(ps_label('group',$autoGroup['group'])); ?> · <?php echo e($autoGroup['role']); ?>：<?php echo e($autoGroup['formula']); ?></div><?php endforeach; ?></details><?php endif; ?>
  </div>
</section>
