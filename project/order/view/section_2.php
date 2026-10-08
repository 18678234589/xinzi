<?php if ($collabOrder && $canEdit && !$counterpartCount && in_array($actor['role'], ['customer_service','technical'], true)): ?>
<div class="card mb-3 project-form-card"><div class="card-body"><h5><i class="fas fa-user-friends mr-2 text-primary"></i>关联同单<?php echo $counterpartGroup === 'technical' ?
    '技术' : '客服'; ?></h5><p class="text-muted small">关联后，对方登录即可在自己的项目订单中看到这张结算单；不会创建第二个订单号。多人协作权重由财务调整。</p>
<?php if ($counterpartChoices): ?><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_counterpart"><div class="form-group col-md-9"><label>选择已开通<?php
    echo e(ps_business_normalize($order['project_type'])); ?>业务的<?php echo $counterpartGroup === 'technical' ? '技术' : '客服'; ?></label><select class="form-control" name="employee_id" required><option value="">请选择</option><?php
    foreach ($counterpartChoices as $person): ?><option value="<?php echo (int)$person['id']; ?>"><?php echo e($person['name'] . ' · ' . $person['department']); ?></option><?php endforeach
    ; ?></select></div><div class="form-group col-md-3"><button class="btn btn-outline-primary btn-block">关联到此订单</button></div></form><?php else: ?><div class="alert alert-warning mb-0">暂无可选账号，请联系财务开通对应业务。</div><?php
    endif; ?></div></div>
<?php endif; ?>

<?php if ($isWebsiteOrder): ?>
<div class="card mb-3 project-form-card border-info">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:8px">
      <h5 class="mb-0 text-info"><i class="fas fa-server mr-2"></i>技术协作 · 后端技术分配</h5>
      <?php if ($isFullstack): ?>
        <span class="badge badge-success"><i class="fas fa-check-double mr-1"></i>全栈交付：<?php echo e($currentFrontendPerson['name'] . ' (' . ($currentFrontendPerson['role_name'
    ] ?: '前端/后端') . ')'); ?></span>
      <?php elseif ($hasBackendTech): ?>
        <span class="badge badge-success"><i class="fas fa-check mr-1"></i>已指定后端：<?php echo e($currentBackendPerson['name'] . ' (' . ($currentBackendPerson['role_name']
    ?: '后端') . ')'); ?></span>
      <?php else: ?>
        <span class="badge badge-warning"><i class="fas fa-exclamation-circle mr-1"></i>待指定后端技术</span>
      <?php endif; ?>
    </div>
    <p class="text-muted small mt-2 mb-3">
      网站类订单（模板/定制）分前端与后端。客服上传订单时若未写后端技术，前端技术、客服或财务均可在此指定后端协作同事；若由技术一人独立交付，也可一键设置为“前后端均由本人完成”，系统将同时核算前端提成与后端提成。
    </p>

    <?php if ($canEdit && (in_array($actor['role'], ['customer_service', 'finance'], true) || $actor['role'] === 'technical')): ?>
      <?php if (!$hasBackendTech && $actor['role'] === 'technical' && (!$currentFrontendPerson || (int)$currentFrontendPerson['employee_id'] !== (int)$actor['employee_id'])): ?>
        <div class="p-3 bg-light rounded border mb-3 d-flex justify-content-between align-items-center flex-wrap" style="gap:10px">
          <div><strong>您是技术人员，本单尚未指定后端：</strong><span class="text-muted small">点击按钮即可主动认领此单后端协作任务</span></div>
          <form method="post" class="m-0">
            <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
            <input type="hidden" name="action" value="claim_backend">
            <button class="btn btn-info btn-sm" onclick="return confirm('确认认领此单的后端技术？')"><i class="fas fa-user-plus mr-1"></i>认领此单后端</button>
          </form>
        </div>
      <?php endif; ?>

      <div class="row">
        <div class="col-md-7 border-right">
          <label class="font-weight-bold small mb-2"><i class="fas fa-user-friends mr-1 text-primary"></i>方式一：指定后端技术同事（50% 成本分摊与独立提成）</label>
          <form method="post" class="form-row align-items-end">
            <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
            <input type="hidden" name="action" value="assign_backend">
            <input type="hidden" name="backend_mode" value="colleague">
            <div class="form-group col-md-8 mb-0">
              <select class="form-control" name="backend_employee_id" required>
                <option value="">选择后端技术同事...</option>
                <?php foreach ($backendChoices as $bEmp): ?>
                <option value="<?php echo (int)$bEmp['id']; ?>" <?php echo ($currentBackendPerson && (int)$currentBackendPerson['employee_id'] === (int)$bEmp['id']) ? 'selected' :
    ''; ?>>
                  <?php echo e($bEmp['name'] . ' · ' . $bEmp['department']); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-4 mb-0">
              <button class="btn btn-outline-info btn-block"><?php echo ($hasBackendTech && !$isFullstack) ? '更换后端技术' : '保存后端技术'; ?></button>
            </div>
          </form>
        </div>
        <div class="col-md-5">
          <label class="font-weight-bold small mb-2"><i class="fas fa-laptop-code mr-1 text-success"></i>方式二：前后端均由一人完成（全栈交付）</label>
          <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
            <input type="hidden" name="action" value="assign_backend">
            <input type="hidden" name="backend_mode" value="self_fullstack">
            <?php if ($currentFrontendPerson): ?>
              <input type="hidden" name="backend_employee_id" value="<?php echo (int)$currentFrontendPerson['employee_id']; ?>">
              <input type="hidden" name="frontend_employee_id" value="<?php echo (int)$currentFrontendPerson['employee_id']; ?>">
            <?php endif; ?>
            <button class="btn btn-success btn-block" onclick="return confirm('确认前后端均由<?php echo ($actor['role'] === 'technical' && $currentFrontendPerson && (int)$currentFrontendPerson
    ['employee_id'] === (int)$actor['employee_id'] ? '本人' : ($currentFrontendPerson['name'] ?? '技术')); ?>独立完成？系统将合并核算前端+后端两份提成。')">
              <i class="fas fa-check-double mr-1"></i>前后端均由<?php echo ($actor['role'] === 'technical' && $currentFrontendPerson && (int)$currentFrontendPerson['employee_id'
    ] === (int)$actor['employee_id'] ? '我' : ($currentFrontendPerson['name'] ?? '技术')); ?>一人完成
            </button>
          </form>
          <small class="text-muted d-block mt-1">角色将设为“前端/后端”，同时计提前端比例与后端比例。</small>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-light border mb-0 small">
        <i class="fas fa-lock mr-1 text-secondary"></i>
        <?php if (!$canEdit): ?>
          订单已审核锁定，技术协作分配已归档。
          <?php if ($isFullstack): ?>
            当前技术人员：<strong><?php echo e($currentFrontendPerson['name'] . ' (' . ($currentFrontendPerson['role_name'] ?: '前端/后端') . ')'); ?></strong> 独立完成前后端全栈交付。
          <?php elseif ($hasBackendTech): ?>
            当前后端技术人员：<strong><?php echo e($currentBackendPerson['name'] . ' (' . ($currentBackendPerson['role_name'] ?: '后端') . ')'); ?></strong>。
          <?php else: ?>
            本单未指定独立后端技术。
          <?php endif; ?>
          如需变更人员或分成，请联系财务通过反审核或调整单处理。
        <?php else: ?>
          当前账号暂无权变更技术分配。如需指定或更换后端技术，请联系本单技术、客服或财务人员操作。
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($order['project_type'] === '网站模板' && $canEdit && in_array($actor['role'], ['technical','finance'], true)): ?>
<div class="card mb-3 project-form-card"><div class="card-body"><h5><i class="fas fa-laptop-code mr-2 text-primary"></i>模板技术提交 · 交付资料</h5><p class="text-muted small">客服已录入的买家资料保留在同一订单；这里只补技术交付信息，资源成本在下方确认。</p><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="save_technical_details"><?php foreach (ps_business_catalog()['网站模板']['fields'] as $key => $label)
    : ?><div class="form-group col-md-4"><label><?php echo e($label); ?></label><input class="form-control" name="details[<?php echo e($key); ?>]" maxlength="300" value="<?php echo
    e($businessDetails[$key] ?? ''); ?>"></div><?php endforeach; ?><div class="col-12 text-right"><button class="btn btn-outline-primary">保存技术资料</button></div></form></div></div>
<?php endif; ?>

<?php if ($canEdit && $businessDefinition && $businessDefinition['resources'] && $orderResource && $orderResource['domain_mode'] === 'pending'): ?>
<div class="card mb-3 project-resource-pending"><div class="card-body"><h5><i class="fas fa-seedling mr-2"></i>技术提交 · 确认域名与服务器</h5><p class="text-muted mb-3">客服与技术共用此订单。技术确认后，选中的标准域名和服务器分别自动计入成本；未确认前不能审核分成。</p>
<?php if (in_array($actor['role'], ['technical','finance'], true)): ?><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token
    ()); ?>"><input type="hidden" name="action" value="confirm_resources"><?php if (!empty($businessDefinition['program'])): $programOptions = ps_intake_templates('program', $order
    ['project_type']); ?><div class="form-group col-md-12"><label>程序套餐（含空间 / 域名 / 商投，选中即按成本中心价入账）</label><select class="form-control" name="program_template_id" id="confirmProgram"><option value="0">不使用程序套餐</option><?php
    $lastProgram = ''; foreach ($programOptions as $programOption): if ($programOption['name'] !== $lastProgram): if ($lastProgram !== ''): ?></optgroup><?php endif; $lastProgram =
    $programOption['name']; ?><optgroup label="<?php echo e($programOption['name']); ?>"><?php endif; ?><option value="<?php echo (int)$programOption['id']; ?>"><?php echo e($programOption
    ['name'] . ' · ' . $programOption['specification'] . ' · ¥' . money($programOption['price'])); ?></option><?php endforeach; if ($lastProgram !== ''): ?></optgroup><?php endif
    ; ?></select><?php if (!$programOptions): ?><small class="text-warning">成本中心尚无程序套餐，请财务先导入《程序表记录》。</small><?php endif; ?></div><?php
    endif; ?><div class="form-group col-md-3"><label>域名使用</label><select class="form-control" name="domain_mode" id="confirmDomainMode" <?php echo empty($businessDefinition
    ['program']) ? 'required' : ''; ?>><option value=""><?php echo empty($businessDefinition['program']) ? '请选择' : '套餐已含 / 请选择'; ?></option><option value="none">无需域名</option><option value="template">使用标准域名</option></select></div><div class="form-group col-md-3" id="confirmDomainTemplateWrap" hidden><label>域名标准成本</label><select class="form-control" name="domain_template_id" id="confirmDomainTemplate"><option value="">选择域名和周期</option><?php
    foreach (ps_intake_templates('domain') as $domainOption): ?><option value="<?php echo (int)$domainOption['id']; ?>"><?php echo e($domainOption['name'] . ' ' . $domainOption['specification'
    ] . ' · ¥' . money($domainOption['price'])); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><label>服务器 / 空间</label><select class="form-control" name="server_template_id"><option value="0">无需标准服务器</option><?php
    foreach (ps_intake_templates('server') as $serverOption): ?><option value="<?php echo (int)$serverOption['id']; ?>"><?php echo e($serverOption['name'] . ' ' . $serverOption['specification'
    ] . ' · ¥' . money($serverOption['price'])); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><button class="btn btn-success btn-block">确认并带入成本</button></div></form><?php
    endif; ?></div></div>
<script><?php /* split: assets/js/project_order_1.js */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_1.js'; ?></script>
<?php endif; ?>

<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/order_credentials_card.php'; ?>
<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/order_renewal_card.php'; ?>

<?php if ($canEdit && $order['delivery_status'] !== 'finished' && !$pendingDeliveryReq && in_array($actor['role'], ['customer_service', 'technical', 'finance'], true)): ?>
<div class="card mb-3 project-form-card">
  <div class="card-body">
    <h5><i class="fas fa-tasks mr-2 text-success"></i>申请标记交付完成 · 上传交付凭证</h5>
    <p class="text-muted small">发货不等于网站订单已完成。次月核算需前往各个企业微信群查看聊天记录并核实交付后，在此上传企业微信群验收聊天截图或交付凭证，提交财务审核（负责审核财务：<strong><?php
    echo e($assignedReviewerName); ?></strong>）。</p>
    <form method="post" enctype="multipart/form-data" class="form-row align-items-end">
      <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
      <input type="hidden" name="action" value="apply_delivery_completion">
      <div class="form-group col-md-5">
        <label>企微群交付/验收凭证截图 <span class="text-danger">*</span>（JPG/PNG/PDF ≤5MB）</label>
        <input type="file" name="delivery_proof" class="form-control-file" accept=".jpg,.jpeg,.png,.pdf" required>
      </div>
      <div class="form-group col-md-5">
        <label>交付说明 / 企微群验收情况</label>
        <input type="text" name="delivery_note" class="form-control" placeholder="如：已核验企业微信群，客户已验收通过并上线" maxlength="300">
      </div>
      <div class="form-group col-md-2">
        <button class="btn btn-outline-success btn-block"><i class="fas fa-upload mr-1"></i>提交交付申请</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit && $upgradePrograms && !$pendingUpgradeReq && in_array($actor['role'], ['customer_service', 'technical', 'finance'], true)): ?>
<div class="card mb-3 project-form-card">
  <div class="card-body">
    <h5><i class="fas fa-arrow-circle-up mr-2 text-primary"></i>申请产品升级（如 JSP 展中升级 JSP 展高）</h5>
    <p class="text-muted small">客户开了几个月中途想升级版本时在此申请。提交后由负责财务（<strong><?php echo e($assignedReviewerName); ?></strong>）前往后台核查客户实际补差价金额并录入差价成本，审核通过后系统自动根据成本中心规则变更成本并重新核算提成。</p>
    <form method="post" class="form-row align-items-end">
      <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
      <input type="hidden" name="action" value="apply_product_upgrade">
      <div class="form-group col-md-4">
        <label>申请升级的目标程序套餐 <span class="text-danger">*</span></label>
        <select name="to_template_id" class="form-control" required>
          <option value="">请选择目标程序套餐</option>
          <?php foreach ($upgradePrograms as $prog): ?>
          <option value="<?php echo (int)$prog['id']; ?>"><?php echo e($prog['name'] . ($prog['specification'] ? ' · ' . $prog['specification'] : '') . ' · 标价 ¥' . money($prog
    ['price'])); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label>客户补款说明 / 微信流水</label>
        <input type="text" name="customer_payment_note" class="form-control" placeholder="如：客户微信补款 200 元" maxlength="200">
      </div>
      <div class="form-group col-md-3">
        <label>升级原因 / 客户要求</label>
        <input type="text" name="upgrade_reason" class="form-control" placeholder="如：客户增加展示功能升级展高" maxlength="300">
      </div>
      <div class="form-group col-md-2">
        <button class="btn btn-outline-primary btn-block"><i class="fas fa-paper-plane mr-1"></i>提交升级申请</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>收款与退款记录</span><span class="text-muted">已审核实收 ¥<?php echo money($order[
    'receipt_amount']); ?> · 已审核退款 ¥<?php echo money($order['refund_amount']); ?></span></div>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>时间</th><th>类型</th><th class="text-right">金额</th><th>说明</th><th>状态</th><th>操作</th></tr></thead><tbody>
<?php foreach ($cashMovements as $movement): ?><tr><td><?php echo e($movement['created_at']); ?></td><td><?php echo $movement['movement_type'] === 'receipt' ? '收款' : '退款';
    ?></td><td class="text-right">¥<?php echo money($movement['amount']); ?></td><td><?php echo e(ps_contact_for($actor, $movement['note'])); ?></td><td><?php echo ['pending' => '待财务审核'
    , 'approved' => '已审核', 'rejected' => '已驳回'][$movement['review_status']] ?? e($movement['review_status']); ?></td><td><?php if ($actor['role'] === 'finance' && $canEdit
    && $movement['review_status'] === 'pending'): ?><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="review_cash"><input type="hidden" name="cash_id" value="<?php
    echo (int)$movement['id']; ?>"><button class="btn btn-success btn-sm mr-1" name="decision" value="approved">通过</button><button class="btn btn-outline-danger btn-sm" name="decision" value="rejected">驳回</button></form><?php
    endif; ?></td></tr><?php endforeach; ?>
<?php if (!$cashMovements): ?><tr><td colspan="6" class="text-center text-muted">暂无记录</td></tr><?php endif; ?></tbody></table></div>
<?php if ($canEdit && in_array($actor['role'], ['finance','customer_service'], true)): ?><div class="card-body border-top"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php
    echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="add_cash"><div class="form-group col-md-2"><label>类型</label><select name="movement_type" class="form-control"><option value="receipt">收款</option><option value="refund">退款</option></select></div><div class="form-group col-md-2"><label>金额</label><input name="amount" type="number" min="0.01" step="0.01" class="form-control" required></div><div class="form-group col-md-6"><label>说明（退款必填）</label><input name="note" maxlength="500" class="form-control" placeholder="付款批次、退款原因等"></div><div class="form-group col-md-2"><button class="btn btn-outline-primary btn-block"><?php
    echo $actor['role'] === 'finance' ? '记录并确认' : '提交财务审核'; ?></button></div></form></div><?php endif; ?></div>

<?php require (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/project_order_items_view.php'; ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>项目直接成本</span><span class="text-muted">技术可录入，财务审核特殊成本</span></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>类型</th><th>项目</th><th>数量</th><th class="text-right">单价</th><th class="text-right">小计</th><th>周期</th><th>凭证</th><th>审核</th></tr></thead><tbody>
