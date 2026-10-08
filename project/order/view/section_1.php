<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/auto_review_card.php'; ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0">订单结算单 <small class="text-muted"><?php echo e($order['order_no']); ?></small></h4><a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>/project/index.php">返回订单</a></div>
<?php if (in_array(ps_business_normalize($order['project_type']), ['网站模板', 'AI网站定制'], true)): ?><div class="mb-3"><a class="btn btn-outline-primary btn-sm" href="<?php echo BASE_URL; ?>/project/site_group.php?id=<?php echo $id; ?>">网站项目与同号付款分配</a></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if (strpos((string)$order['order_no'], 'WX-') === 0 && $canEdit): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between" style="gap:8px"><span><i class="fas fa-link mr-1"></i><strong>这是没有订单号的订单（系统生成了内部号）。</strong>拿到客户的真实订单号后，在这里补录，退款和店铺流水才能自动对上。</span>
<form method="post" class="form-inline" onsubmit="return confirm('确认把订单号改成所填内容？')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="rename_order_no"><input class="form-control form-control-sm mr-2" name="new_order_no" maxlength="100" placeholder="真实订单号" required style="min-width:220px"><button class="btn btn-sm btn-warning">补录订单号</button></form></div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">已保存</div><?php endif; ?>
<?php if (isset($_GET['corr'])): ?><div class="alert alert-success">更正申请已提交，财务和管理员会在“更正申请”里看到，处理结果会回复到你的站内信。</div><?php endif; ?>
<?php if (isset($_GET['adjusted'])): ?><div class="alert alert-success">售后调整已登记，生成 <?php echo (int)$_GET['adjusted']; ?> 条分成调整（金额无变化的人员不生成）。</div><?php endif; ?>
<?php if ($todos): ?><div class="project-todo-bar mb-3"><strong><i class="fas fa-list-check mr-1"></i>待办</strong><?php foreach ($todos as [$todoText, $todoLevel]): ?><span class="badge badge-<?php echo e($todoLevel); ?>"><?php echo e($todoText); ?></span><?php endforeach; ?></div><?php endif; ?>
<?php foreach ($shopMatches as $shopMatch): if (!$shopMatch['refund'] && ($order['shop'] === '' || $shopMatch['shop'] === $order['shop']) && ($shopMatch['price'] === null || $orderSource['price_source'] === 'missing' || abs((float)$shopMatch['price'] - (float)$order['contract_amount']) < 0.005)) continue; if ($order['shop'] !== '' && $shopMatch['shop'] !== $order['shop']) continue; ?>
<div class="alert alert-warning project-shop-alert small"><i class="fas fa-store mr-1"></i> <?php echo e($shopMatch['source']); ?>流水（<?php echo e($shopMatch['shop']); ?>）<?php if ($shopMatch['refund']): ?>显示此订单号有退款 / 交易关闭<?php echo $shopMatch['refund_amount'] > 0 ? '（¥' . money($shopMatch['refund_amount']) . '）' : ''; ?><?php echo $shopMatch['status'] !== '' ? '，状态：' . e($shopMatch['status']) : ''; ?>。<?php echo $actor['role'] === 'finance' ? '请核对后在下方登记退款。' : '请告知财务核对退款。'; ?><?php else: ?>售价 ¥<?php echo money($shopMatch['price']); ?> 与结算单售价 ¥<?php echo money($order['contract_amount']); ?> 不一致，请财务核对。<?php endif; ?></div>
<?php endforeach; ?>
<div class="card mb-3"><div class="card-body">
  <div class="row"><div class="col-md-3"><small class="text-muted">客户</small><div><?php echo e($order['customer_name'] ?: '待补充'); ?></div></div><div class="col-md-3"><small class="text-muted">业务 / 订单类型</small><div><?php echo e($order['project_type']); ?><?php if ($orderKinds): ?> · <?php if ($canEdit && ($actor['role'] === 'finance' || trim((string)$order['order_kind']) === '')): ?><form method="post" class="d-inline-flex align-items-center"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="set_order_kind"><select name="order_kind" class="form-control form-control-sm mr-1" aria-label="订单类型"><option value="">选择订单类型</option><?php foreach ($orderKinds as $kindName): ?><option value="<?php echo e($kindName); ?>" <?php echo $order['order_kind'] === $kindName ? 'selected' : ''; ?>><?php echo e($kindName); ?></option><?php endforeach; ?></select><?php if ($actor['role'] === 'finance'): ?><label class="small mb-0 mr-1"><input type="checkbox" name="apply_future" value="1" checked> 今后同类上传也按此类</label><?php endif; ?><button class="btn btn-sm btn-outline-primary">保存类型</button></form><?php else: ?><?php echo e($order['order_kind'] ?: '未填'); ?><?php endif; ?><?php endif; ?></div></div><div class="col-md-3"><small class="text-muted">状态</small><div><?php echo e(ps_label('settlement', $order['settlement_status'])); ?> / <?php echo $order['delivery_status'] === 'finished' ? '<span class="text-success font-weight-bold">已交付完成</span>' : '<span class="text-muted">交付未完成</span>'; ?></div></div><div class="col-md-3"><small class="text-muted">负责审核财务</small><div><strong><?php echo e($assignedReviewerName); ?></strong><?php if ($canReviewThisBusiness): ?> <span class="badge badge-success">由您负责</span><?php endif; ?></div></div></div>
  <?php if ($actor['role'] === 'finance' && !$canEdit && $orderKinds): ?><form method="post" class="form-inline mt-3 pt-3 border-top" onsubmit="return confirm('确认纠正订单类型？原审核快照保留，分成差额将计入所选未锁定月份。')"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="set_order_kind"><label class="mr-2">纠正已审核订单类型</label><select name="order_kind" class="form-control form-control-sm mr-2" required><?php foreach ($orderKinds as $kindName): ?><option value="<?php echo e($kindName); ?>" <?php echo $order['order_kind'] === $kindName ? 'selected' : ''; ?>><?php echo e($kindName); ?></option><?php endforeach; ?></select><input type="month" name="adjust_month" class="form-control form-control-sm mr-2" value="<?php echo e(ps_next_open_month(date('Y-m'))); ?>" required><label class="small mr-2 mb-0"><input type="checkbox" name="apply_future" value="1" checked> 此人以后同类上传默认此类</label><button class="btn btn-sm btn-outline-primary">更换类目并重算差额</button></form><?php endif; ?>
  <div class="row mt-3 pt-3 border-top"><div class="col-md-3"><small class="text-muted">付款昵称</small><div><?php echo e($orderSource['payment_nickname'] ?: '待上传补全'); ?></div></div><div class="col-md-3"><small class="text-muted">售价</small><div><?php echo $orderSource['price_source'] === 'missing' ? '待上传补全' : '¥' . money($order['contract_amount']); ?></div></div><div class="col-md-3"><small class="text-muted">店铺交易状态</small><div><?php echo e($orderSource['trade_status'] ?: '待上传补全'); ?></div></div><div class="col-md-3"><small class="text-muted">订单日期</small><div><?php echo e($order['order_date']); ?></div></div></div>
  <?php if (!empty($orderSource['payment_reference'])): ?><div class="mt-3 pt-3 border-top"><small class="text-muted">微信交易流水号 / 支付订单号</small><div><?php echo e($orderSource['payment_reference']); ?></div></div><?php endif; ?>
  <?php if ($businessDefinition && $businessDefinition['fields']): ?><div class="row mt-3 pt-3 border-top"><?php foreach ($businessDefinition['fields'] as $key => $label): ?><div class="col-md-6 mb-2"><small class="text-muted"><?php echo e($label); ?></small><div><?php echo e(ps_contact_for($actor, ($businessDetails[$key] ?? '') ?: '—', $key === 'customer_wechat')); ?></div></div><?php endforeach; ?></div><?php endif; ?>
  <?php if (trim((string)$order['note']) !== ''): ?><div class="alert alert-light border small mt-3 mb-0"><strong>订单录入信息<?php echo $businessDefinition && $businessDefinition['resources'] ? '与资源提示' : ''; ?>：</strong><?php echo nl2br(e(ps_contact_for($actor, $order['note']))); ?><?php if ($businessDefinition && $businessDefinition['resources']): ?><div class="text-muted">已选择的标准域名/服务器会显示在下方成本明细；SSL 等非标准成本仍需补录并上传凭证。</div><?php endif; ?></div><?php endif; ?>
  <hr><div class="row text-center"><div class="col-md-3"><small>可结算收入</small><h4>¥<?php echo money($sum['income']); ?></h4></div><div class="col-md-3"><small><?php echo $sum['service_fee'] > 0 ? '直接成本（含服务费）' : '已审核直接成本'; ?></small><h4>¥<?php echo money($sum['approved_cost']); ?></h4></div><div class="col-md-3"><small>项目贡献利润</small><h4 class="text-success">¥<?php echo money($sum['profit']); ?></h4></div><div class="col-md-3"><small>待审成本 / 审核后预计利润</small><h4>¥<?php echo money($sum['pending_cost']); ?> / ¥<?php echo money($sum['estimated_profit']); ?></h4></div></div>
  <?php if ($sum['service_fee'] > 0): ?><div class="text-muted text-center small mt-2">直接成本已含店铺服务费：售价 ¥<?php echo money($order['contract_amount']); ?> × <?php echo round($sum['service_fee_rate'] * 100, 2); ?>% = ¥<?php echo money($sum['service_fee']); ?>；程序套餐、域名与服务器成本在下方逐项显示。个人分成按各自规则的服务费率计算，见“参与人与分成计算”。</div><?php endif; ?>
  <?php if ($actor['role'] === 'finance'): ?><div class="row text-center mt-2"><div class="col-md-6">技术项目分成池：<strong><?php echo ($canEdit && $sum['groups']['technical']['pool'] === null) ? '待配置' : '¥' . money($canEdit ? $sum['groups']['technical']['pool'] : $snapshotPool['technical']); ?></strong>（<?php echo $canEdit ? ($sum['groups']['technical']['rate'] === null ? '无规则' : money($sum['groups']['technical']['rate'] * 100) . '%') : '已审核快照'; ?>）</div><div class="col-md-6">客服项目分成池：<strong><?php echo ($canEdit && $sum['groups']['customer_service']['pool'] === null) ? '待配置' : '¥' . money($canEdit ? $sum['groups']['customer_service']['pool'] : $snapshotPool['customer_service']); ?></strong>（<?php echo $canEdit ? ($sum['groups']['customer_service']['rate'] === null ? '无规则' : money($sum['groups']['customer_service']['rate'] * 100) . '%') : '已审核快照'; ?>）</div></div><?php else: ?><div class="text-center mt-2"><?php echo $canEdit ? '本人预计项目分成' : '本人已审核项目分成'; ?>：<strong><?php echo $ownCommissionConfigured ? '¥' . money($ownCommission) : '待配置'; ?></strong></div><?php endif; ?>
</div></div>

<?php if ($pendingDeliveryReq): ?>
<div class="card border-warning mb-3">
  <div class="card-header bg-warning text-dark font-weight-bold d-flex justify-content-between align-items-center flex-wrap" style="gap:8px">
    <span><i class="fas fa-clipboard-check mr-2"></i>【待审核】交付完成申请</span>
    <span class="badge badge-light">申请人：<?php echo e($pendingDeliveryReq['applicant_name']); ?>（<?php echo e(substr($pendingDeliveryReq['created_at'], 0, 16)); ?>）</span>
  </div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-7">
        <p class="mb-2"><strong>交付说明 / 验收留言：</strong><?php echo e($pendingDeliveryReq['data']['delivery_note'] ?: '无特别说明'); ?></p>
        <?php if (!empty($pendingDeliveryReq['data']['proof_path'])): ?>
        <p class="mb-0"><strong>企微群交付凭证：</strong><a class="btn btn-outline-info btn-sm" href="<?php echo BASE_URL; ?>/project/proof.php?request_id=<?php echo (int)$pendingDeliveryReq['id']; ?>" target="_blank"><i class="fas fa-image mr-1"></i>查看企微群聊天/交付凭证截图</a></p>
        <?php endif; ?>
      </div>
      <div class="col-md-5">
        <small class="text-muted d-block mb-1 text-right">负责审核财务：<strong><?php echo e($assignedReviewerName); ?></strong></small>
        <?php if ($actor['role'] === 'finance'): ?>
          <?php if ($canReviewThisBusiness): ?>
          <form method="post" class="mt-2 bg-light p-2 rounded border">
            <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
            <input type="hidden" name="action" value="review_delivery_completion">
            <input type="hidden" name="request_id" value="<?php echo (int)$pendingDeliveryReq['id']; ?>">
            <div class="form-group mb-2">
              <input class="form-control form-control-sm" name="review_note" placeholder="审核意见（驳回必填，通过可选填）">
            </div>
            <div class="text-right">
              <button class="btn btn-success btn-sm mr-1" name="decision" value="approved" onclick="return confirm('确认通过此交付完成申请？订单交付状态将变更为已完成。')"><i class="fas fa-check mr-1"></i>通过交付完成</button>
              <button class="btn btn-outline-danger btn-sm" name="decision" value="rejected" onclick="return confirm('确认驳回此交付申请？')"><i class="fas fa-times mr-1"></i>驳回</button>
            </div>
          </form>
          <?php else: ?>
          <div class="alert alert-secondary small p-2 mb-0 text-left">该业务指定由 <strong><?php echo e($assignedReviewerName); ?></strong> 审核，您当前无权审核此业务。</div>
          <?php endif; ?>
        <?php else: ?>
        <div class="badge badge-warning p-2 d-block text-center mt-2">等待财务（<?php echo e($assignedReviewerName); ?>）核对企微群并审核</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($pendingUpgradeReq): ?>
<div class="card border-primary mb-3">
  <div class="card-header bg-primary text-white font-weight-bold d-flex justify-content-between align-items-center flex-wrap" style="gap:8px">
    <span><i class="fas fa-level-up-alt mr-2"></i>【待审核】产品升级申请：<?php echo e($pendingUpgradeReq['data']['from_name'] ?? '原套餐'); ?> → <?php echo e($pendingUpgradeReq['data']['to_name'] ?? '新套餐'); ?></span>
    <span class="badge badge-light">申请人：<?php echo e($pendingUpgradeReq['applicant_name']); ?>（<?php echo e(substr($pendingUpgradeReq['created_at'], 0, 16)); ?>）</span>
  </div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-7">
        <p class="mb-1"><strong>升级原因：</strong><?php echo e($pendingUpgradeReq['data']['upgrade_reason'] ?: '无'); ?></p>
        <p class="mb-2"><strong>客户补款信息：</strong><?php echo e($pendingUpgradeReq['data']['customer_payment_note'] ?: '无'); ?></p>
        <div class="alert alert-info small mb-0">
          <i class="fas fa-info-circle mr-1"></i><strong>核查提示：</strong>请宋文娜或审核财务前往咱们后台核查客户补差价实际金额，并在右侧填写补差价成本。审核通过后将自动生成已审核的“产品升级补差成本”，并更新订单程序套餐，重新计算提成。
        </div>
      </div>
      <div class="col-md-5">
        <small class="text-muted d-block mb-1 text-right">负责审核财务：<strong><?php echo e($assignedReviewerName); ?></strong></small>
        <?php if ($actor['role'] === 'finance'): ?>
          <?php if ($canReviewThisBusiness): ?>
          <form method="post" enctype="multipart/form-data" class="bg-light p-2 rounded border">
            <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
            <input type="hidden" name="action" value="review_product_upgrade">
            <input type="hidden" name="request_id" value="<?php echo (int)$pendingUpgradeReq['id']; ?>">
            <div class="form-group mb-2">
              <label class="small mb-1 font-weight-bold">后台查验补差价成本金额 ¥ <span class="text-danger">*</span></label>
              <input type="number" step="0.01" min="0" name="diff_amount" class="form-control form-control-sm" placeholder="如 200.00" required>
            </div>
            <div class="form-group mb-2">
              <label class="small mb-1">后台查验截图/补款凭证（可选）</label>
              <input type="file" name="diff_proof" class="form-control-file small" accept=".jpg,.jpeg,.png,.pdf">
            </div>
            <div class="form-group mb-2">
              <input class="form-control form-control-sm" name="review_note" placeholder="审核意见或核验说明">
            </div>
            <div class="text-right">
              <button class="btn btn-primary btn-sm mr-1" name="decision" value="approved" onclick="return confirm('确认后台已查验实付补差金额并审核升级？')"><i class="fas fa-check mr-1"></i>确认升级并录入差价成本</button>
              <button class="btn btn-outline-danger btn-sm" name="decision" value="rejected" onclick="return confirm('确认驳回此升级申请？')"><i class="fas fa-times mr-1"></i>驳回</button>
            </div>
          </form>
          <?php else: ?>
          <div class="alert alert-secondary small p-2 mb-0">该业务指定由 <strong><?php echo e($assignedReviewerName); ?></strong> 审核，您当前无权审核此业务。</div>
          <?php endif; ?>
        <?php else: ?>
        <div class="badge badge-info p-2 d-block text-center mt-3">等待财务（<?php echo e($assignedReviewerName); ?>）在后台核对补差价并审核</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($actor['role'] === 'finance' && $canEdit): ?>
<div class="card mb-3"><div class="card-header">销售/财务信息</div><div class="card-body"><form method="post" class="form-row align-items-end">
<input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="update_order"><input type="hidden" name="row_version" value="<?php echo (int)$order['row_version']; ?>">
<div class="form-group col-md-2"><label>客户</label><input class="form-control" name="customer_name" value="<?php echo e($order['customer_name']); ?>"></div><div class="form-group col-md-2"><label>店铺</label><input class="form-control" name="shop" value="<?php echo e($order['shop']); ?>"></div>
<div class="form-group col-md-2"><label>成交价</label><input type="number" step="0.01" min="0" class="form-control" name="contract_amount" value="<?php echo e($order['contract_amount']); ?>"></div><div class="form-group col-md-2"><label>交付</label><select class="form-control" name="delivery_status"><option value="unfinished">未完成</option><option value="finished" <?php echo $order['delivery_status'] === 'finished' ? 'selected' : ''; ?>>已完成</option></select></div><div class="form-group col-md-2"><button class="btn btn-primary btn-block">保存</button></div>
</form></div></div>
<?php endif; ?>

<?php if ($collabOrder && $canEdit && in_array($actor['role'], ['customer_service','finance'], true)): ?>
<div class="card mb-3 project-form-card"><div class="card-body"><h5><i class="fas fa-heart mr-2 text-danger"></i>客服提交 · 买家与交易信息</h5><p class="text-muted small">与技术共用订单号 <?php echo e($order['order_no']); ?>。可补空字段；已有资料请由财务核对更正。售价不等于已审核实收。</p>
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_customer_intake">
<div class="form-group col-md-3"><label>客户 / 公司</label><input class="form-control" name="customer_name" maxlength="200" value="<?php echo e($order['customer_name']); ?>" <?php echo $actor['role'] !== 'finance' && $order['customer_name'] !== '' ? 'readonly' : ''; ?>></div>
<div class="form-group col-md-3"><label>店铺</label><select class="form-control" name="shop" <?php echo $actor['role'] !== 'finance' && $order['shop'] !== '' ? 'disabled' : ''; ?>><option value="">待补充</option><?php foreach ($shops as $shopName): ?><option value="<?php echo e($shopName); ?>" <?php echo $order['shop'] === $shopName ? 'selected' : ''; ?>><?php echo e($shopName); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-3"><label>买家付款昵称</label><input class="form-control" name="payment_nickname" maxlength="200" value="<?php echo e($orderSource['payment_nickname']); ?>" <?php echo $actor['role'] !== 'finance' && $orderSource['nickname_source'] !== 'missing' ? 'readonly' : ''; ?>></div>
<div class="form-group col-md-3"><label>售价 ¥</label><input class="form-control" type="number" min="0" step="0.01" name="contract_amount" value="<?php echo $orderSource['price_source'] === 'missing' ? '' : e($order['contract_amount']); ?>" <?php echo $actor['role'] !== 'finance' && $orderSource['price_source'] !== 'missing' ? 'readonly' : ''; ?>></div>
<div class="form-group col-md-5"><label>微信交易流水号 / 支付订单号</label><input class="form-control" name="payment_reference" maxlength="200" value="<?php echo e($orderSource['payment_reference'] ?? ''); ?>" <?php echo $actor['role'] !== 'finance' && !empty($orderSource['payment_reference']) ? 'readonly' : ''; ?>></div><div class="form-group col-md-4"><label>店铺交易状态</label><input class="form-control" name="trade_status" maxlength="100" value="<?php echo e($orderSource['trade_status']); ?>" placeholder="不填可由店铺订单上传补全" <?php echo $actor['role'] !== 'finance' && $orderSource['status_source'] !== 'missing' ? 'readonly' : ''; ?>></div><div class="form-group col-md-3"><button class="btn btn-success btn-block">保存客服资料</button></div>
</form></div></div>
<?php endif; ?>

