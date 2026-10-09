<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/renewal_due_widget.php'; ?>
<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/domain_missing_widget.php'; ?>
<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/renewal_info_popup.php'; ?>
<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/finance_taobao_card.php'; ?>
<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/rule_algo_card.php'; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($createdOrder): ?><div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap"><span><i class="fas fa-check-circle mr-1"></i> 订单 <strong><?php
    echo e($createdOrder['order_no']); ?></strong> 已保存，可以继续录入下一单。</span><a class="btn btn-sm btn-outline-success" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php
    echo (int)$createdOrder['id']; ?>">打开刚保存的结算单</a><?php if (in_array(ps_business_normalize($createdOrder['project_type'] ?? ''), ['小程序开发', 'AI网站定制'
    , '网站模板', '环境配置', '网站续费', '网站修改'], true)): ?> <a class="btn btn-sm btn-warning ml-1" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo
    (int)$createdOrder['id']; ?>#credentials"><i class="fas fa-key mr-1"></i>记录该项目账号密码</a><?php endif; ?></div><?php endif; ?>
<?php if ($deleteResult): ?><div class="alert alert-<?php echo $deleteResult['ok'] ? 'success' : 'danger'; ?>"><i class="fas fa-trash mr-1"></i>订单 <strong><?php echo e($deleteResult
    ['order_no']); ?></strong> <?php echo $deleteResult['ok'] ? '已删除，相关实收流水、成本、参与人和分成快照已一并清理。' : '删除失败：' . e($deleteResult
    ['reason']); ?></div><?php endif; ?>
<?php if ($bulkResult): ?><div class="alert alert-<?php echo $bulkResult['failed'] ? 'warning' : 'success'; ?>"><strong>批量<?php echo e($bulkResult['action']); ?>：</strong>成功 <?php
    echo (int)$bulkResult['done']; ?> 单<?php if ($bulkResult['failed']): ?>，<?php echo count($bulkResult['failed']); ?> 单未处理：<ul class="mb-0 mt-1 small"><?php foreach
    (array_slice($bulkResult['failed'], 0, 30) as $failure): ?><li><?php echo e($failure); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
<?php if ($autoFinishInfo): ?><div class="alert alert-info"><i class="fas fa-magic mr-1"></i><?php echo e($autoFinishInfo); ?></div><?php endif; ?>
<?php if (!$allowedBusinesses): ?><div class="alert alert-warning">当前账户尚未匹配业务类型，请联系财务在项目结算配置中分配。</div><?php endif; ?>
<?php if ($allowedBusinesses): ?>
<div id="manual-order" class="card project-form-card mb-4<?php echo $openEntry ? '' : ' d-none'; ?>"><div class="card-body">
  <div class="project-section-title"><span class="project-step">01</span><div><h5>在线录入订单</h5><p>有店铺订单号就填订单号；微信付款没有店铺单号，就填交易流水号或支付订单号。系统会关联同一结算单；其他资料和成本可以在结算单继续补充。</p></div></div>
  <form method="post" id="projectManualForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
    <?php if (ps_ai_ready()): ?>
    <div class="project-ai-box mb-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><strong><i class="fas fa-wand-magic-sparkles mr-1"></i> AI 智能识别</strong><small class="text-muted">把淘宝订单详情、微信聊天或客户需求整段粘贴进来，AI 帮你填好下面的空白栏，保存前可以再改。</small></div>
      <textarea class="form-control mt-2" id="aiPaste" rows="3" maxlength="4000" placeholder="例如：订单号 5127194379715039218，美呀美店铺，买家 tb_xxx 付款 998 元，JSP展示中级版，客户微信 abc123…"></textarea>
      <div class="d-flex align-items-center mt-2" style="gap:10px"><button type="button" class="btn btn-outline-success btn-sm" id="aiParseBtn"><i class="fas fa-magic mr-1"></i> 识别并填写</button><span id="aiStatus" class="small text-muted" aria-live="polite"></span></div>
    </div>
    <?php endif; ?>
    <div class="form-row">
      <div class="form-group col-md-4"><label for="intakeOrderNo">店铺订单号（有则填）</label><input class="form-control form-control-lg" id="intakeOrderNo" name="order_no" maxlength="100" value="<?php
    echo e($_POST['order_no'] ?? ''); ?>" placeholder="淘宝/店铺订单号" autofocus><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="no_order_no" value="1" id="noOrderNo" <?php
    echo !empty($_POST['no_order_no']) ? 'checked' : ''; ?>><label class="form-check-label small" for="noOrderNo">没有订单号（微信付款，客服也没拿到单号）</label></div></div>
      <div class="form-group col-md-3"><label for="intakeBusiness">业务类型 *</label><select class="form-control form-control-lg" id="intakeBusiness" name="project_type" required><?php
    foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $selectedBusiness === $businessName ? 'selected' : ''; ?>><?php echo
    e($businessName); ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-2" id="intakeKindWrap"><label for="intakeKind">订单类型</label><select class="form-control form-control-lg" id="intakeKind" name="order_kind"><option value="">—</option></select></div>
      <div class="form-group col-md-3"><label for="intakeDate">订单日期</label><input class="form-control form-control-lg" id="intakeDate" type="date" name="order_date" value="<?php
    echo e($_POST['order_date'] ?? ''); ?>"><small class="text-muted">店铺订单号已同步时可自动带入；微信付款请填写支付日期</small></div>
    </div>
    <div class="form-row" id="intakeRenewalInfo">
      <div class="form-group col-md-3" data-for="web"><label for="intakePhone">客户手机号（与微信号二选一）</label><input class="form-control" id="intakePhone" name="customer_phone" type="tel" maxlength="20" value="<?php
    echo e($_POST['customer_phone'] ?? ''); ?>" placeholder="手机号，海外号码可带 + 区号"></div>
      <div class="form-group col-md-3" data-for="web"><label for="intakeWechat">海外客户微信号（与手机号二选一）</label><input class="form-control" id="intakeWechat" name="customer_wechat" maxlength="60" value="<?php echo e($_POST['customer_wechat'] ?? ''); ?>" placeholder="填写实际微信号，不是昵称"><small class="text-muted">两者至少填写一项；微信号不发送短信</small></div>
      <div class="form-group col-md-3" data-for="web"><label for="intakeDomain">域名 <span class="text-danger info-star" data-role="technical" hidden>*</span></label><input class="form-control" id="intakeDomain" name="customer_domain" maxlength="120" value="<?php
    echo e($_POST['customer_domain'] ?? ''); ?>" placeholder="如 example.com"></div>
      <div class="form-group col-md-3" data-for="mini"><label for="intakeServerExpiry">服务器到期日 <span class="text-danger info-star" data-role="*" hidden>*</span></label><input class="form-control" id="intakeServerExpiry" type="date" name="server_expiry" value="<?php
    echo e($_POST['server_expiry'] ?? ''); ?>"></div>
      <div class="form-group col-md-6 d-flex align-items-end"><label class="mb-2 small text-muted" id="intakeInfoLaterWrap"><input type="checkbox" name="info_later" value="1" <?php
    echo !empty($_POST['info_later']) ? 'checked' : ''; ?>> 暂时拿不到，稍后补充（<strong class="text-danger">未补充将不会获得本订单的续费分成</strong>）</label></div>
    </div>
    <script><?php /* split: project/index/view/js_1.php */ include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_1.php'; ?></script>
    <div id="intakeLookup" class="project-lookup" hidden aria-live="polite"></div>
    <div class="form-row">
      <div class="form-group col-md-3"><label for="intakeShop">店铺（可后补）</label><input class="form-control" id="intakeShop" name="shop" list="intakeShopList" maxlength="150" value="<?php
    echo e($_POST['shop'] ?? ''); ?>" placeholder="可不填，待上传匹配" autocomplete="off"><datalist id="intakeShopList"><?php foreach ($shops as $shopName): ?><option value="<?php
    echo e($shopName); ?>"><?php endforeach; ?></datalist></div>
      <div class="form-group col-md-3"><label for="intakeNickname">付款昵称（可后补）</label><input class="form-control" id="intakeNickname" name="payment_nickname" maxlength="200" value="<?php
    echo e($_POST['payment_nickname'] ?? ''); ?>"></div>
      <div class="form-group col-md-3"><label for="intakePaymentReference">微信交易流水号 / 支付订单号</label><input class="form-control" id="intakePaymentReference" name="payment_reference" maxlength="200" value="<?php
    echo e($_POST['payment_reference'] ?? ''); ?>" placeholder="无店铺订单号时填这里"><small class="text-muted">用于生成可追溯的内部订单编号</small></div>
      <div class="form-group col-md-3"><label for="intakePrice">售价（可后补）</label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input class="form-control" id="intakePrice" type="number" step="0.01" min="0" name="contract_amount" value="<?php
    echo e($_POST['contract_amount'] ?? ''); ?>" placeholder="待订单上传补全"></div></div>
      <div class="form-group col-md-3" id="intakeDirectCostWrap" hidden><label for="intakeDirectCost" id="intakeDirectCostLabel">成本</label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input class="form-control" id="intakeDirectCost" type="number" step="0.01" name="direct_cost" value="<?php
    echo e($_POST['direct_cost'] ?? ''); ?>" placeholder="如写手稿费"></div><small class="text-muted">¥500 以内自动通过，超过由财务审核</small></div>
      <div class="form-group col-md-3"><label for="intakeTrade">店铺交易状态（可后补）</label><input class="form-control" id="intakeTrade" name="trade_status" maxlength="100" value="<?php
    echo e($_POST['trade_status'] ?? ''); ?>" placeholder="如交易成功"></div>
    </div>
    <div class="form-row">
      <div class="form-group col-md-3"><label>客户 / 公司</label><input class="form-control" name="customer_name" maxlength="200" value="<?php echo e($_POST['customer_name'] ??
    ''); ?>"></div>
      <div class="form-group col-md-3"><label>项目交付状态</label><select class="form-control" name="delivery_status"><option value="unfinished">未完成</option><option value="finished" <?php
    echo ($_POST['delivery_status'] ?? '') === 'finished' ? 'selected' : ''; ?>>已完成</option></select></div>
      <div class="form-group col-md-<?php echo $actor['role'] === 'finance' ? '4' : '6'; ?>"><label>备注 / 客户电话或微信</label><input class="form-control" name="contact_note" maxlength="500" value="<?php
    echo e($_POST['contact_note'] ?? ''); ?>" placeholder="仅参与本订单的合作人员和财务可见"></div>
      <?php if ($actor['role'] === 'finance'): ?><div class="form-group col-md-2"><label>已确认实收</label><input class="form-control" type="number" step="0.01" min="0" name="receipt_amount" value="<?php
    echo e($_POST['receipt_amount'] ?? ''); ?>" placeholder="0"></div><?php endif; ?>
    </div>
    <div class="project-divider"></div><div class="project-mini-title">参与人员 <small>本人会自动加入对应组；多人合作先均分，财务可在结算单调整权重。财务配置过默认岗位（如外包前端、售后）的人员会自动套用对应算法。</small></div>
    <div class="form-row">
      <div class="form-group col-md-4"><label id="intakeCustomerServiceLabel">客服</label><select class="form-control" name="customer_service_id"><option value="0">待关联，可在结算单补</option><?php
    foreach ($customerServiceChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['customer_service_id'] ?? ($actor['role'] === 'customer_service'
    ? $actor['employee_id'] : 0)) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-4"><label id="intakeFrontendLabel"><?php echo e(ps_business_people_labels($selectedBusiness)['frontend']); ?></label><select class="form-control" name="frontend_id"><option value="0">待指定</option><?php
    foreach ($technicalChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['frontend_id'] ?? ($actor['role'] === 'technical' ? $actor['employee_id'
    ] : 0)) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-4"><label id="intakeBackendLabel"><?php echo e(ps_business_people_labels($selectedBusiness)['backend']); ?></label><select class="form-control" name="backend_id"><option value="0">无 / 待指定</option><?php
    foreach ($technicalChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['backend_id'] ?? 0) === (int)$emp['id'] ? 'selected' : ''; ?>><?php
    echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
    </div>
    <details class="mb-3" style="border:1px solid #cfe3dc;border-radius:12px;padding:12px"><summary style="cursor:pointer;color:#286451;font-weight:600">＋添加共同接单客服（可多选）</summary><p class="small text-muted mt-2 mb-2">报单人不独占分成。选择共同接单的客服后，同组默认均分分成和按单补助，订单会同步到各参与人的项目订单。不要把制作技术选进客服组。</p><div style="display:flex;flex-wrap:wrap;gap:10px;max-height:200px;overflow:auto"><?php
    foreach ($customerServiceChoices as $emp): ?><label class="mb-0" style="border:1px solid #d8e7e1;border-radius:10px;padding:8px 12px;cursor:pointer"><input type="checkbox" name="co_customer_service_ids[]" value="<?php
    echo (int)$emp['id']; ?>" <?php echo in_array((int)$emp['id'], array_map('intval', is_array($_POST['co_customer_service_ids'] ?? null) ? $_POST['co_customer_service_ids'] : [])
    , true) ? 'checked' : ''; ?>> <?php echo e($emp['name'] . ' · ' . $emp['department']); ?></label><?php endforeach; ?></div></details>
    <?php if ($outsourceTemplates): ?><div class="form-row" id="intakeOutsourceRow"><div class="form-group col-md-8"><label for="intakeOutsource">外包给下游（不走本公司技术时选择）</label><select class="form-control" id="intakeOutsource" name="outsource_template_id"><option value="0">不外包</option><?php
    foreach ($outsourceTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-scope="<?php echo e($t['business_scope']); ?>" data-mode="<?php echo e($t['price_mode']
    ); ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['outsource_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name'] . ' · '
    . ($t['price_mode'] === 'percent' ? '成本 = 售价 × ' . rtrim(rtrim($t['price'], '0'), '.') . '%' : '成本 ¥' . money($t['price']))); ?></option><?php endforeach; ?></select><small class="text-muted">如华梦定制：成本按售价的比例自动计入，客服无需指定本公司技术，客服分成照常计算。</small></div></div><?php
    endif; ?>
    <?php foreach ($allowedBusinesses as $businessName): $specificFields = $businessCatalog[$businessName]['fields']; if (!$specificFields || ($actor['role'] === 'customer_service'
    && $businessName === '网站模板')) continue; ?>
    <div class="project-business-fields" data-business="<?php echo e($businessName); ?>"><div class="project-divider"></div><div class="project-mini-title"><?php echo e($businessName
    ); ?>专属信息</div><div class="form-row"><?php foreach ($specificFields as $fieldKey => $fieldLabel): ?><div class="form-group col-md-4"><label><?php echo e($fieldLabel); ?></label><input class="form-control" name="details[<?php
    echo e($fieldKey); ?>]" maxlength="300" value="<?php echo e($_POST['details'][$fieldKey] ?? ''); ?>" placeholder="填写<?php echo e($fieldLabel); ?>"></div><?php endforeach; ?></div></div>
    <?php endforeach; ?>
    <div id="intakeResourceFields"><div class="project-divider"></div><div class="project-mini-title">技术提交 · 资源与成本 <small>可先留空，技术确认后再计成本；无需域名不产生域名成本</small></div>
    <div class="form-row" id="intakeProgramRow">
      <div class="form-group col-md-8"><label for="intakeProgram">程序套餐（成本中心 · 含空间/域名/商投）</label><select class="form-control" id="intakeProgram" name="program_template_id"><option value="0">暂不选择 / 非套餐</option><?php
    $lastProgram = ''; foreach ($programTemplates as $t): if ($t['name'] !== $lastProgram): if ($lastProgram !== ''): ?></optgroup><?php endif; $lastProgram = $t['name']; ?><optgroup label="<?php
    echo e($t['name']); ?>"><?php endif; ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" data-scope="<?php echo e($t['business_scope']); ?>" <?php
    echo (int)($_POST['program_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name'] . ' · ' . $t['specification'] . ' · 成本 ¥' . money($t['price'
    ]) . ($t['supplier_price'] !== null ? '（采购 ¥' . money($t['supplier_price']) . '）' : '')); ?></option><?php endforeach; if ($lastProgram !== ''): ?></optgroup><?php endif
    ; ?></select><?php if (!$programTemplates): ?><small class="text-warning">成本中心尚未导入程序套餐，财务可在成本中心一键导入《程序表记录》。</small><?php
    endif; ?></div>
    </div>
    <div class="form-row"><div class="form-group col-md-4"><label>域名使用</label><select class="form-control" id="intakeDomainMode" name="domain_mode"><option value="pending" <?php
    echo ($_POST['domain_mode'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>>待技术确认</option><option value="none" <?php echo ($_POST['domain_mode'] ?? '') === 'none' ?
    'selected' : ''; ?>>无需域名</option><option value="template" <?php echo ($_POST['domain_mode'] ?? '') === 'template' ? 'selected' : ''; ?>>使用标准域名</option></select></div><div class="form-group col-md-4" id="intakeDomainTemplateWrap"><label>域名规格与周期</label><select class="form-control" id="intakeDomainTemplate" name="domain_template_id"><option value="">请选择标准模板</option><?php
    foreach ($domainTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['domain_template_id'] ?? 0)
    === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($resourceHint($t) . '/' . $t['unit']); ?></option><?php endforeach; ?></select><?php if (!$domainTemplates): ?><small class="text-warning">尚无可用域名成本模板，请财务先配置价格。</small><?php
    endif; ?></div><div class="form-group col-md-4"><label>服务器 / 空间（如使用）</label><select class="form-control" id="intakeServerTemplate" name="server_template_id"><option value="0">本单不选标准服务器</option><?php
    foreach ($serverTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['server_template_id'] ?? 0)
    === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($resourceHint($t) . '/' . $t['unit']); ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group col-md-8"><label>域名或空间说明</label><input class="form-control" name="resource_note" maxlength="500" value="<?php echo e(
    $_POST['resource_note'] ?? ''); ?>" placeholder="如客户域名 example.com、服务器账户或续费提醒"></div><div class="form-group col-md-4"><label>SSL 证书真实成本（如有）</label><input class="form-control" type="number" step="0.01" min="0" name="ssl_cost" value="<?php
    echo e($_POST['ssl_cost'] ?? ''); ?>" placeholder="非标准成本，创建后补凭证"></div></div>
    </div>
    <div class="project-cost-strip"><span><i class="fas fa-receipt mr-1"></i> 标准成本</span><strong id="intakeCostTotal">¥0.00</strong><span class="project-cost-strip-sep" aria-hidden="true"></span><span>服务费 <b id="intakeFeeRate">0%</b></span><strong id="intakeFee">¥0.00</strong><span class="project-cost-strip-sep" aria-hidden="true"></span><span>预计贡献利润</span><strong id="intakeProfit">—</strong><small id="intakeCostHint">请先选择域名使用方式</small></div>
    <div class="project-form-footer"><p id="intakeFooterHint">合作人员提交的售价仅作订单申报，实收仍由财务审核。</p><div class="d-flex flex-wrap" style="gap:10px"><button class="btn btn-outline-success btn-lg" type="submit" name="after_save" value="next">保存并录入下一单</button><button class="btn btn-success btn-lg" type="submit" name="after_save" value="open">保存并打开结算单 <i class="fas fa-arrow-right ml-1"></i></button></div></div>
  </form>
</div></div>
<?php endif; ?>
<?php if ($importFileId): ?><div class="alert alert-info">正在查看这份表格对应的订单，已跨月份核对；仅显示你有权限的订单。<a class="alert-link" href="<?php
    echo BASE_URL; ?>/project/index.php">返回全部项目订单</a></div><?php endif; ?>
<?php if ($participationOnly && $filterEmployeeId > 0): ?><div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span><i class="fas fa-chart-line mr-1" aria-hidden="true"></i><?php
    echo e($filterEmployeeName); ?> · <?php echo e($month); ?> · <?php echo e($filterBusiness ?: '全部业务'); ?> · 共 <?php echo (int)$totalOrders; ?> 单参与订单</span><a class="alert-link" href="<?php
    echo BASE_URL; ?>/project/dashboard.php?<?php echo e(http_build_query(['employee_id' => $filterEmployeeId, 'month' => $month])); ?>">返回经营看板</a></div><?php endif; ?>
<div class="card mb-3"><div class="card-body py-3"><form method="get" class="form-row align-items-end">
<?php if ($importFileId): ?><input type="hidden" name="import_file" value="<?php echo $importFileId; ?>"><?php endif; ?>
<?php if ($actor['role'] === 'finance'): ?><div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="filterFinance">核算财务</label><select class="form-control" name="filter_finance" id="filterFinance"><option value="all" <?php echo $filterFinance === 'all' ? 'selected' : ''; ?>>全部业务</option><?php foreach (ps_admin_reviewers() as $finance): $financeLogin = strtolower($finance['username']); ?><option value="<?php echo e($financeLogin); ?>" <?php echo $filterFinance === $financeLogin ? 'selected' : ''; ?>><?php echo e($finance['real_name']); ?>负责的业务</option><?php endforeach; ?></select></div><?php endif; ?>
<?php if ($participationOnly): ?><input type="hidden" name="participating" value="1"><?php if ($actor['role'] !== 'finance'): ?><input type="hidden" name="employee_id" value="<?php
    echo $filterEmployeeId; ?>"><?php endif; ?><?php endif; ?>
<?php if ($actor['role'] === 'finance' || ps_is_management($actor)): ?><div class="col-md-2 mb-2"><label class="small text-muted mb-1" for="filterEmployee">合作人员</label><select class="form-control" name="employee_id" id="filterEmployee"><option value="0">全部合作人员</option><?php
    foreach ($employees as $filterPerson): ?><option value="<?php echo (int)$filterPerson['id']; ?>" <?php echo $filterEmployeeId === (int)$filterPerson['id'] ? 'selected' : ''; ?>><?php
    echo e($filterPerson['name'] . ' · ' . $filterPerson['department']); ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="col-md-3 mb-2">
