<?php
require_once __DIR__ . '/ProjectReviewPolicy.php';
$auditBusiness = (string)($_GET['audit_business'] ?? $_POST['audit_business'] ?? '网站续费');
if (!in_array($auditBusiness,$activeBusinesses,true)) $auditBusiness='网站续费';
$auditRules = [];
foreach (['order'=>$rules,'monthly'=>$monthlyRules] as $scope=>$rows) foreach ($rows as $r) {
    if (empty($r['is_active'])) continue;
    $businesses = explode(',', $scope==='order' ? $r['project_type'] : $r['scope_business']);
    if (!in_array($auditBusiness,$businesses,true)) continue;
    $auditRules[] = ['scope'=>$scope,'rule'=>$r];
}
?>
<section class="card mb-4" id="review-policy" style="border:1px solid #d0e4dc;background:linear-gradient(120deg,#f0f8f4,#fffaf0)">
<div class="card-body"><div class="d-flex justify-content-between flex-wrap align-items-center" style="gap:12px"><div><small class="text-muted">规则 → 自动核验 → 原结算算法</small><h5 class="mt-1 mb-1">无流水与核算周期</h5></div>
<form method="get" class="form-inline"><input type="hidden" name="month" value="<?php echo e($month); ?>"><label class="mr-2" for="auditBusiness">查看业务</label><select name="audit_business" id="auditBusiness" class="form-control form-control-sm mr-2"><?php foreach($activeBusinesses as $b): ?><option <?php echo $b===$auditBusiness?'selected':''; ?>><?php echo e($b); ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary btn-sm">查看规则</button></form></div>
<p class="small text-muted mt-3 mb-2">仅固定单量补助允许无流水自动核验。收入／利润比例规则必须核对真实实收；月度补助不会另发逐单补助。核算周期由对应规则算法决定，改周期请在相应规则区建立新版本，不直接挪动已用规则。</p>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>规则</th><th>核算周期</th><th>无流水策略</th><th>操作</th></tr></thead><tbody>
<?php foreach($auditRules as $item): $r=$item['rule'];$scope=$item['scope'];$eligible=prp_cash_independent($scope,$r);$fid='review-policy-'.$scope.'-'.$r['id']; ?>
<tr><td><?php echo e($scope==='monthly' ? $r['name'] : $r['project_type'].' · '.ps_label('group',$r['commission_group']).' · '.$r['role_name'].' · '.$r['order_kind']); ?><div class="small text-muted">规则 #<?php echo (int)$r['id']; ?> · 生效 <?php echo e($r['effective_from']); ?></div></td><td><?php echo $scope==='monthly'?'按月汇总一次':'按订单核算'; ?></td>
<td><select form="<?php echo $fid; ?>" name="allow_no_receipt" class="form-control form-control-sm" <?php echo !$eligible?'disabled':''; ?>><option value="0" <?php echo !prp_allow_no_receipt($scope,$r)?'selected':''; ?>>必须核对实收</option><?php if($eligible): ?><option value="1" <?php echo prp_allow_no_receipt($scope,$r)?'selected':''; ?>>允许无流水核验单量补助</option><?php endif; ?></select><?php if(!$eligible): ?><small class="text-muted">算法依赖收入／利润或人工核验，系统禁止豁免</small><?php endif; ?></td>
<td><?php if($eligible): ?><form method="post" id="<?php echo $fid; ?>"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="review_policy_save"><input type="hidden" name="rule_scope" value="<?php echo $scope; ?>"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="calculation_period" value="<?php echo $scope; ?>"><input type="hidden" name="audit_business" value="<?php echo e($auditBusiness); ?>"><input type="hidden" name="month" value="<?php echo e($month); ?>"><button class="btn btn-primary btn-sm" style="color:#fff">保存策略</button></form><?php else: ?><span class="badge badge-light">实收保护</span><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if(!$auditRules): ?><tr><td colspan="4" class="text-muted">该业务尚无生效规则，请先配置逐单或月度规则。</td></tr><?php endif; ?>
</tbody></table></div></div></section>
