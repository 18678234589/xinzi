<?php $orderItems = poi_items((int)$order['id']); if ($orderItems): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between flex-wrap"><span>订单商品明细 · <?php echo count($orderItems); ?> 项</span><span class="text-muted small">套餐、证书分别保留；总收款只记一次</span></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th>商品 / 服务</th><th>分项售价</th><th>原表成本</th><th>核算成本</th><th>核对状态</th><th>来源</th></tr></thead><tbody>
<?php foreach ($orderItems as $oi): ?>
<tr><td><?php echo e($oi['item_name']); ?><div class="small text-muted"><?php echo e(ps_label('category',$oi['category'])); ?></div><?php if (mb_strpos($oi['resource_hint'],'待财务核对')!==false): ?><div class="small text-info"><?php echo e($oi['resource_hint']); ?></div><?php endif; ?></td>
<td><?php echo $oi['sale_amount'] === null ? '整单计价 · 未拆分' : '¥' . money($oi['sale_amount']); ?></td>
<td><?php echo $oi['reported_cost'] === null ? '—' : '¥' . money($oi['reported_cost']); ?></td>
<td><?php $linkedCost = null; foreach ($costs as $oc) if ((int)$oc['id']===(int)$oi['cost_id']) { $linkedCost=$oc; break; } echo $linkedCost ? '¥' . money($linkedCost['amount']) : '待确认'; ?></td>
<td><span class="badge <?php echo $oi['cost_status']==='approved' ? 'badge-success' : 'badge-info'; ?>"><?php echo $oi['cost_status']==='approved' ? '已关联成本' : ($oi['cost_id'] ? '待资源确认 / 成本审核' : '请确认成本与套餐'); ?></span>
<?php if (!$oi['cost_id'] && $canEdit && in_array($actor['role'], ['finance','technical'], true) && $costs): ?>
<form method="post" class="d-flex mt-2"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="link_item_cost"><input type="hidden" name="item_id" value="<?php echo (int)$oi['id']; ?>"><select name="cost_id" class="form-control form-control-sm" aria-label="关联本商品成本" required><option value="">选择下方已录入的成本</option><?php foreach ($costs as $oc): if (!in_array($oc['review_status'],['approved','pending'],true)) continue; ?><option value="<?php echo (int)$oc['id']; ?>"><?php echo e($oc['item_name']); ?> · ¥<?php echo money($oc['amount']); ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary btn-sm text-nowrap ml-1">关联</button></form>
<?php endif; ?></td>
<td class="small text-muted">原表第 <?php echo (int)$oi['source_line']; ?> 行</td></tr>
<?php endforeach; ?>
</tbody></table></div><div class="card-body py-2 small text-muted">分项售价不会再次计入收入。来源成本与标准价格不一致、或无法唯一识别的商品，须核对后才能结算。</div></div>
<?php endif; ?>
<?php if ($canEdit && ps_business_normalize($order['project_type'])==='网站模板'): ?>
<details class="card mb-3"><summary class="card-header" style="cursor:pointer">＋补充商品明细（例如 SSL证书）</summary><div class="card-body"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_order_item"><div class="col-md-6 mb-2"><label>商品 / 服务名称</label><input name="item_name" class="form-control" placeholder="SSL证书 / 泛域名SSL证书 / 程序套餐名称" maxlength="255" required></div><div class="col-md-3 mb-2"><label>分项售价（可留空）</label><input name="item_sale" class="form-control" type="number" min="0" step="0.01" placeholder="200"></div><div class="col-md-3 mb-2"><button class="btn btn-outline-primary">添加商品明细</button></div></form><div class="small text-muted">只补商品，不新增订单、不重复计收款。特殊商品先添加，再在下方录入成本并关联。</div></div></details>
<?php endif; ?>
