<?php if ($sum['service_fee'] > 0): ?><tr class="table-light"><td>服务费</td><td>店铺服务费（售价 × <?php echo round($sum['service_fee_rate'] * 100, 2); ?>%）</td><td>1 项</td><td class="text-right">¥<?php
    echo money($sum['service_fee']); ?></td><td class="text-right">¥<?php echo money($sum['service_fee']); ?></td><td>一次性</td><td>订单售价</td><td>系统计算</td></tr><?php
    endif; ?>
<?php foreach ($costs as $cost): ?><tr class="<?php echo $cost['review_status'] === 'rejected' ? 'text-muted' : ''; ?>"><td><?php echo e(ps_label('category', $cost['category'])); ?></td><td><?php
    echo e($cost['item_name']); ?><?php if ($cost['reason']): ?><div class="small text-muted"><?php echo e($cost['reason']); ?></div><?php endif; ?></td><td><?php echo e($cost['quantity'
    ] . ' ' . $cost['unit']); ?></td><td class="text-right">¥<?php echo money($cost['unit_price']); ?></td><td class="text-right">¥<?php echo money($cost['amount']); ?><?php if (
    $actor['role'] === 'finance' && $cost['supplier_amount'] !== null): ?><div class="small text-muted">采购 ¥<?php echo money($cost['supplier_amount']); ?></div><?php endif; ?></td><td><?php
    echo e(ps_label('cost_kind', $cost['cost_kind'])); ?></td><td><?php if ($cost['proof_path']): ?><a href="<?php echo BASE_URL; ?>/project/proof.php?id=<?php echo (int)$cost['id'
    ]; ?>">查看凭证</a><?php endif; ?></td><td><?php echo e(ps_label('review', $cost['review_status'])); ?><?php if ($cost['review_note'] !== ''): ?><div class="small text-muted"><?php
    echo e($cost['review_note']); ?></div><?php endif; ?>
<?php if ($actor['role'] === 'finance' && $canEdit && $cost['review_status'] === 'pending'): ?><form method="post" class="form-inline mt-1"><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review_cost"><input type="hidden" name="cost_id" value="<?php echo (int)$cost['id']; ?>"><input class="form-control form-control-sm mr-1" name="review_note" placeholder="审核意见"><button class="btn btn-success btn-sm mr-1" name="decision" value="approved">通过</button><button class="btn btn-outline-danger btn-sm" name="decision" value="rejected">驳回</button></form><?php
    endif; ?><?php if ($actor['role'] === 'finance' && $canEdit && $cost['review_status'] === 'approved'): ?><form method="post" class="form-inline mt-1"><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="void_cost"><input type="hidden" name="cost_id" value="<?php echo (int)$cost['id']; ?>"><input class="form-control form-control-sm mr-1" name="review_note" placeholder="作废原因" required><button class="btn btn-outline-danger btn-sm">作废</button></form><?php
    endif; ?></td></tr><?php endforeach; ?>
<?php if (!$costs): ?><tr><td colspan="8" class="text-center text-muted">暂无成本</td></tr><?php endif; ?></tbody></table></div></div>

<?php if ($canEdit && ps_business_normalize($order['project_type']) === '商标' && in_array($actor['role'], ['customer_service', 'technical', 'finance'], true)): require_once (dirname
    ((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectTrademarkCost.php'; $tmTemplates = ptc_templates(); $tmCount = trim((string)($businessDetails['trademark_count'] ?? '')); $tmServices
    = array_values(array_filter($tmTemplates, function ($t) { return ptc_kind($t) === 'service'; })); $tmOthers = array_values(array_filter($tmTemplates, function ($t) { return in_array
    (ptc_kind($t), ['extra', 'variable'], true); })); ?>
<div class="card mb-3 border-primary"><div class="card-header"><i class="fas fa-copyright mr-1"></i> 商标成本快捷录入 <span class="text-muted small">（单价来自成本中心，选一下即可）</span></div><div class="card-body">
<?php if (!$tmTemplates): ?><div class="text-muted">成本中心还没有商标成本项目，请联系财务添加。</div><?php else: ?>
<?php if ($tmServices): ?><form method="post" class="form-row align-items-end mb-2"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="tm_cost">
<div class="form-group col-md-5 mb-1"><label>服务项目（每件）</label><select class="form-control" name="template_id"><?php foreach ($tmServices as $t): ?><option value="<?php
    echo (int)$t['id']; ?>"><?php echo e(ptc_keyword($t) . ' · ¥' . money($t['price']) . '/件'); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-3 mb-1"><label>商标件数</label><input class="form-control" type="number" step="1" min="1" name="quantity" value="<?php echo e(is_numeric($tmCount)
    && (float)$tmCount > 0 ? $tmCount : '1'); ?>" required></div>
<div class="form-group col-md-4 mb-1"><button class="btn btn-primary btn-block">设为本单服务成本</button></div></form><small class="text-muted d-block mb-3">选的服务项目 × 件数入账；重新选择会替换原来的服务成本。</small><?php
    endif; ?>
<?php if ($tmOthers): ?><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="tm_cost">
<div class="form-group col-md-5 mb-1"><label>附加项 / 按实际金额</label><select class="form-control" name="template_id" id="tmOtherTemplate"><?php foreach ($tmOthers as $t)
    : ?><option value="<?php echo (int)$t['id']; ?>" data-kind="<?php echo e(ptc_kind($t)); ?>"><?php echo e($t['name'] . ($t['specification'] !== '' ? ' · ' . $t['specification']
    : '') . (ptc_kind($t) === 'variable' ? ' · 填实际金额' : ' · ¥' . money($t['price']) . '/' . $t['unit'])); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-3 mb-1"><label id="tmOtherLabel">数量</label><input class="form-control" type="number" step="0.01" min="0.01" name="quantity" id="tmOtherQty" required></div>
<div class="form-group col-md-4 mb-1"><button class="btn btn-outline-primary btn-block">追加</button></div></form>
<small class="text-muted d-block">成品商标、国际商标、法务外包每单报价不同：向供应商 / 法务问清后填实际金额，金额进财务审核。</small>
<script><?php /* split: assets/js/project_order_2.js */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_2.js'; ?></script><?php endif; ?>
<?php endif; ?></div></div>
<?php endif; ?>
<?php if ($canEdit && ($actor['role'] === 'technical' || $actor['role'] === 'finance')): ?>
<div class="card mb-3"><div class="card-header">＋添加项目成本</div><div class="card-body"><form method="post" enctype="multipart/form-data" class="form-row align-items-end">
<input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_cost">
<div class="form-group col-md-4"><label>选择成本项目</label><select class="form-control" name="template_id" id="projectTemplate"><option value="0">其他 / 自定义成本（需凭证审核）</option><?php
    foreach ($templates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e(($t['price_mode'] ?? 'fixed') === 'percent' ? round((float)$order['contract_amount'
    ] * (float)$t['price'] / 100, 2) : $t['price']); ?>" data-proof="<?php echo (int)$t['requires_proof']; ?>"><?php echo e(ps_label('category', $t['category']) . ' / ' . $t['name'
    ] . ($t['specification'] !== '' ? ' / ' . $t['specification'] : '') . ' · ' . (($t['price_mode'] ?? 'fixed') === 'percent' ? '售价 × ' . rtrim(rtrim($t['price'], '0'), '.')
    . '%' : '¥' . money($t['price']) . '/' . $t['unit'])); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>数量</label><input id="projectQuantity" class="form-control" type="number" step="0.01" min="0.01" name="quantity" value="1" required></div><div class="form-group col-md-2 project-custom-field"><label>自定义类别</label><select class="form-control" name="custom_category"><option value="other">其他</option><option value="certificate">SSL 证书</option><option value="domain">域名</option><option value="server">服务器</option><option value="certification">认证</option><option value="api">API</option><option value="plugin">插件</option><option value="outsourcing">外包</option></select></div><div class="form-group col-md-2 project-custom-field"><label>自定义名称</label><input class="form-control" name="item_name"></div><div class="form-group col-md-2 project-custom-field"><label>自定义单价</label><input id="projectCustomPrice" class="form-control" type="number" step="0.01" min="0" name="unit_price"></div><div class="form-group col-md-2 project-custom-field"><label>成本周期</label><select class="form-control" name="cost_kind"><option value="one_time">一次性</option><option value="annual">年度</option><option value="monthly">月度</option></select></div>
<div class="form-group col-md-4 project-custom-field"><label>自定义原因</label><input class="form-control" name="reason"></div><div class="form-group col-md-5" id="projectProofField"><label>凭证（JPG/PNG/PDF ≤5MB）</label><input id="projectProof" class="form-control-file" type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf"></div><div class="form-group col-md-3"><button class="btn btn-success btn-block">添加成本</button></div>
</form><small class="text-muted">预计小计：<strong id="projectCostPreview">—</strong>。标准单价自动带入；超过 ¥500 或需凭证的项目进入财务审核。</small></div></div>
<script><?php /* split: assets/js/project_order_3.js */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_3.js'; ?></script>
<?php endif; ?>

<div class="card mb-3"><div class="card-header d-flex justify-content-between flex-wrap"><span>参与人与分成计算</span><span class="text-muted small">按“业务 › 岗位 › 订单类型”匹配成本中心的分成规则；组池模式按组内权重分摊，独立模式按权重分摊直接成本</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>分成组</th><th>合作人员</th><th>岗位</th><th>组内权重</th><th>匹配规则</th><th class="text-right"><?php
    echo $canEdit ? '预计分成（含补助）' : '已审核分成（含补助）'; ?></th><?php if ($actor['role'] === 'finance'): ?><th>操作</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($people as $person): if ($actor['role'] !== 'finance' && (int)$person['employee_id'] !== $actor['employee_id']) continue; $snapshotKey = $person['commission_group']
    . ':' . $person['employee_id']; $calcPerson = $personLookup[$snapshotKey] ?? null; $calc = $calcPerson['calc'] ?? null; $snapshot = $snapshotByPerson[$snapshotKey] ?? null; ?><tr><td><?php
    echo e(ps_label('group', $person['commission_group'])); ?></td><td><?php echo e($person['name'] . ' · ' . $person['department']); ?></td><td><?php echo e($person['role_name']
    ?: '—'); ?></td><td><?php echo money($person['group_weight'] * 100); ?>%</td>
<td class="small"><?php if (!$canEdit && $snapshot): ?><?php echo e(ps_label('mode', $snapshot['calc_mode'])); ?> · <?php echo money($snapshot['rate'] * 100); ?>%<div class="project-calc-note"><?php
    echo e($snapshot['calc_note']); ?></div><?php elseif ($calc): ?><?php echo e(ps_label('mode', $calc['mode'])); ?> · <?php echo round($calc['rate'] * 100, 4); ?>% · 服务费 <?php
    echo round($calc['fee_rate'] * 100, 2); ?>%<?php if ($calcPerson['rule']['note'] ?? ''): ?><div class="text-muted"><?php echo e($calcPerson['rule']['note']); ?></div><?php endif
    ; ?><div class="project-calc-note"><?php echo e($calc['note']); ?></div><?php else: ?><span class="text-danger">未匹配到规则</span><?php if ($actor['role'] === 'finance')
    : ?> · <a href="<?php echo BASE_URL; ?>/project/settings.php#rules">去配置</a><?php endif; ?><?php endif; ?></td>
<td class="text-right text-nowrap"><?php $modalId = 'calcModal' . (int)$person['id']; ?><a href="#" class="calc-amount-link" data-toggle="modal" data-target="#<?php echo $modalId;
    ?>" title="点击查看计算过程"><?php if (!$canEdit): ?>¥<?php echo money($snapshot['commission_amount'] ?? 0); ?><?php if ((float)($snapshot['subsidy_amount'] ?? 0) > 0)
    : ?><div class="small text-muted">含补助 ¥<?php echo money($snapshot['subsidy_amount']); ?></div><?php endif; ?><?php elseif ($calc): $estimated = $calcPerson['estimated_calc'
    ]; ?>¥<?php echo money(round($calc['share'] + $calc['subsidy'], 2)); ?><?php if ($calc['subsidy'] > 0): ?><div class="small text-muted">含补助 ¥<?php echo money($calc['subsidy'
    ]); ?></div><?php endif; ?><?php if (abs($estimated['share'] - $calc['share']) > 0.004): ?><div class="small text-muted"><?php echo !empty($estimated['income_estimated']) ? '按售价预估'
    : '待审成本通过后'; ?> ¥<?php echo money(round($estimated['share'] + $estimated['subsidy'], 2)); ?></div><?php endif; ?><?php else: ?>待配置<?php endif; ?></a><div class="small"><a href="#" data-toggle="modal" data-target="#<?php
    echo $modalId; ?>"><i class="fas fa-calculator"></i> 计算过程</a></div></td>
<?php if ($actor['role'] === 'finance'): ?><td><?php if ($canEdit): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="remove_participant"><input type="hidden" name="participant_id" value="<?php
    echo (int)$person['id']; ?>"><button class="btn btn-outline-danger btn-sm">移除</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$people): ?><tr><td colspan="7" class="text-center text-muted">尚无参与人</td></tr><?php endif; ?></tbody></table></div>
<?php $commissionCorrections = ps_corr_for_order($id); foreach ($people as $person): if ($actor['role'] !== 'finance' && (int)$person['employee_id'] !== $actor['employee_id']) continue
    ; $mk = $person['commission_group'] . ':' . $person['employee_id']; $mp = $personLookup[$mk] ?? null; echo ps_render_calc_modal('calcModal' . (int)$person['id'], $person + ['rule'
    => $mp['rule'] ?? null], $order, $mp['calc'] ?? null, $mp['estimated_calc'] ?? null, $snapshotByPerson[$mk] ?? null, $id, $commissionCorrections, true); endforeach; ?>
<script><?php /* split: assets/js/project_order_4.js */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_4.js'; ?></script>
<?php if ($actor['role'] === 'finance' && $canEdit): ?><div class="card-body border-top"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_participant"><div class="form-group col-md-4"><label>合作人员</label><select name="employee_id" class="form-control" required><option value="">选择合作人员</option><?php
    foreach ($employees as $emp): ?><option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name'] . ' · ' . $emp['department'] . ' · ID ' . $emp['id']); ?></option><?php
    endforeach; ?></select></div><div class="form-group col-md-2"><label>组别</label><select name="commission_group" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div><div class="form-group col-md-2"><label>岗位</label><input name="role_name" class="form-control" list="projectRoleNames" placeholder="留空用默认岗位"><datalist id="projectRoleNames"><?php
    foreach (['前端','外包前端','后端','售后','模板技术','资料员','技术','客服','定制客服','定制技术'] as $roleOption): ?><option value="<?php echo e($roleOption
    ); ?>"><?php endforeach; ?></datalist></div><div class="form-group col-md-2"><label>组内权重 %</label><input name="group_weight" class="form-control" type="number" min="0.0001" max="100" step="0.0001" value="100"></div><div class="form-group col-md-2"><button class="btn btn-outline-primary btn-block">添加/更新</button></div></form><small class="text-muted">技术组、客服组各自合计 100%；重复添加同一合作人员可更新其权重。</small></div><?php
    endif; ?>
</div>
<?php
$adjustQuery = db()->prepare('SELECT a.*,e.name FROM project_commission_adjustments a JOIN employees e ON e.id=a.employee_id WHERE a.order_id=? ORDER BY a.id');
$adjustQuery->execute([$id]);
$adjustments = $adjustQuery->fetchAll();
if ($actor['role'] !== 'finance') $adjustments = array_values(array_filter($adjustments, function ($a) use ($actor) { return (int)$a['employee_id'] === (int)$actor['employee_id'];
    }));
?>
<?php if ((!$canEdit && $actor['role'] === 'finance') || $adjustments): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between flex-wrap"><span><?php echo $canEdit ? '后月退款补扣' : '售后退款 / 成本调整'; ?></span><span class="text-muted small"><?php
    echo $canEdit ? '订单所属月份的分成不再改动；退款发生在之后的月份，在退款当月按差额补扣，算法见下方' : '订单已审核锁定：退款或补成本不改原结算，按差额生成调整，计入指定月份'
    ; ?></span></div>
<?php if ($adjustments): ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>计入月份</th><th>合作人员</th><th>分成组</th><th class="text-right">调整金额</th><th>原因 / 计算</th><th>登记时间</th></tr></thead><tbody><?php
    foreach ($adjustments as $adj): ?><tr><td><?php echo e($adj['payroll_month']); ?></td><td><?php echo e($adj['name']); ?></td><td><?php echo e(ps_label('group', $adj['commission_group'
    ])); ?></td><td class="text-right font-weight-bold <?php echo (float)$adj['amount'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ((float)$adj['amount'] > 0 ? '+' : '')
    . '¥' . money($adj['amount']); ?></td><td class="small"><?php echo e(ps_contact_for($actor, $adj['reason'])); ?><div class="project-calc-note"><?php echo e($adj['calc_note']);
    ?></div></td><td class="small text-muted"><?php echo e($adj['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($actor['role'] === 'finance' && !$canEdit): ?><div class="card-body<?php echo $adjustments ? ' border-top' : ''; ?>"><form method="post" class="form-row align-items-end" onsubmit="return confirm('确认登记售后调整？退款将直接记为已审核，并按差额扣回或补发分成。')"><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="post_adjustment">
<div class="form-group col-md-2"><label>退款金额 ¥</label><input class="form-control" type="number" min="0" step="0.01" name="refund_amount" placeholder="如 678"></div>
<div class="form-group col-md-2"><label>成本调整 ¥</label><input class="form-control" type="number" step="0.01" name="cost_delta" placeholder="冲减填负数，如 -180"></div>
<div class="form-group col-md-4"><label>原因</label><input class="form-control" name="reason" maxlength="300" required placeholder="如 客户退款，保留域名注册 120 元"></div>
<div class="form-group col-md-2"><label>计入月份</label><input class="form-control" type="month" name="adjust_month" value="<?php echo e(ps_next_open_month()); ?>" required></div>
<div class="form-group col-md-2"><button class="btn btn-outline-danger btn-block">登记调整</button></div>
</form><small class="text-muted">系统按审核时的比例重算：全额退款会收回分成和每单补助；部分退款或成本变化只调整差额。默认计入下一个未锁定月份。</small></div><?php
    endif; ?>
</div>
<?php endif; ?>

<?php if ($orderRequests): ?>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-history mr-1"></i>交付与产品升级申请记录</span>
    <span class="small text-muted">共 <?php echo count($orderRequests); ?> 条记录</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead>
        <tr>
          <th>申请时间</th>
          <th>类型</th>
          <th>申请人</th>
          <th>申请内容 / 凭证</th>
          <th>状态</th>
          <th>审核人</th>
          <th>审核时间</th>
          <th>审核备注</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orderRequests as $req):
          $rData = $req['data'];
          $isDelivery = $req['request_type'] === 'delivery_completion';
        ?>
        <tr>
          <td class="small text-muted"><?php echo e($req['created_at']); ?></td>
          <td>
            <?php if ($isDelivery): ?>
              <span class="badge badge-success">交付完成申请</span>
            <?php else: ?>
              <span class="badge badge-primary">产品升级申请</span>
            <?php endif; ?>
          </td>
          <td><?php echo e($req['applicant_name']); ?></td>
          <td class="small">
            <?php if ($isDelivery): ?>
              <?php echo e($rData['delivery_note'] ?: '交付完成申请'); ?>
              <?php if (!empty($rData['proof_path'])): ?>
                <div><a href="<?php echo BASE_URL; ?>/project/proof.php?request_id=<?php echo (int)$req['id']; ?>" target="_blank"><i class="fas fa-image mr-1"></i>查看企微群凭证</a></div>
              <?php endif; ?>
            <?php else: ?>
              <div><strong><?php echo e($rData['from_name'] ?? ''); ?></strong> → <strong><?php echo e($rData['to_name'] ?? ''); ?></strong></div>
              <?php if (!empty($rData['customer_payment_note'])): ?><div class="text-muted">客户补款：<?php echo e($rData['customer_payment_note']); ?></div><?php endif; ?>
              <?php if (!empty($rData['upgrade_reason'])): ?><div class="text-muted">原因：<?php echo e($rData['upgrade_reason']); ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($req['status'] === 'pending'): ?>
              <span class="badge badge-warning">待审核</span>
            <?php elseif ($req['status'] === 'approved'): ?>
              <span class="badge badge-success">已通过</span>
            <?php else: ?>
              <span class="badge badge-danger">已驳回</span>
            <?php endif; ?>
          </td>
          <td><?php echo e($req['reviewer_name'] ?: ($req['reviewer_username'] ?: '—')); ?></td>
          <td class="small text-muted"><?php echo e($req['reviewed_at'] ?: '—'); ?></td>
          <td class="small">
            <?php echo e($req['review_note'] ?: '—'); ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

