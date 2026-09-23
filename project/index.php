<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
$actor = ps_require_actor();
$error = '';
$businessCatalog = ps_business_catalog();
$allowedBusinesses = ps_actor_businesses($actor);
$selectedBusiness = ps_business_choice($actor, (string)($_POST['project_type'] ?? $_GET['business'] ?? ''));
$shops = db()->query('SELECT name FROM shops ORDER BY sort,id')->fetchAll(PDO::FETCH_COLUMN);
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$employees = db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll();
$employeesById = [];
foreach ($employees as $employee) $employeesById[(int)$employee['id']] = $employee;
$activeTechnicalIds = array_map('intval', db()->query("SELECT employee_id FROM project_users WHERE role='technical' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
$activeCustomerServiceIds = array_map('intval', db()->query("SELECT employee_id FROM project_users WHERE role='customer_service' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN));
$technicalChoices = $actor['role'] === 'finance' ? $employees : array_values(array_filter($employees, function ($emp) use ($activeTechnicalIds) { return in_array((int)$emp['id'], $activeTechnicalIds, true); }));
$customerServiceChoices = $actor['role'] === 'finance' ? $employees : array_values(array_filter($employees, function ($emp) use ($activeCustomerServiceIds) { return in_array((int)$emp['id'], $activeCustomerServiceIds, true); }));
$domainTemplates = ps_intake_templates('domain');
$serverTemplates = ps_intake_templates('server');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    if (!in_array($actor['role'], ['finance', 'customer_service', 'technical'], true)) { http_response_code(403); exit('无权限'); }
    try {
        $no = trim((string)($_POST['order_no'] ?? ''));
        $date = trim((string)($_POST['order_date'] ?? '')) ?: date('Y-m-d');
        $contract = trim((string)($_POST['contract_amount'] ?? ''));
        $receipt = $actor['role'] === 'finance' ? (string)($_POST['receipt_amount'] ?? '') : '0';
        $projectType = (string)($_POST['project_type'] ?? '');
        $business = ps_require_business($actor, $projectType);
        $peopleLabels = ps_business_people_labels($projectType);
        $domainMode = $business['resources'] ? ($actor['role'] === 'customer_service' ? 'pending' : (string)($_POST['domain_mode'] ?? 'pending')) : 'none';
        $domainTemplate = $domainMode === 'template' ? ps_intake_template((int)($_POST['domain_template_id'] ?? 0), 'domain') : null;
        $serverTemplate = $business['resources'] && $actor['role'] !== 'customer_service' && (int)($_POST['server_template_id'] ?? 0) > 0 ? ps_intake_template((int)$_POST['server_template_id'], 'server') : null;
        if (!in_array($domainMode, ['pending', 'none', 'template'], true)) throw new RuntimeException('请选择待补充、无需域名或具体域名成本模板');
        if ($no === '' || strlen($no) > 100) throw new RuntimeException('请填写有效订单号');
        $existing = db()->prepare('SELECT id FROM project_orders WHERE order_no=?');
        $existing->execute([$no]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId) {
            if ($actor['role'] !== 'finance') {
                $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
                $access->execute([$existingId, $actor['employee_id']]);
                if (!$access->fetchColumn()) throw new RuntimeException('该订单编号不可重复创建，请联系财务核对归属');
            }
            header('Location: ' . BASE_URL . '/project/order.php?id=' . $existingId); exit;
        }
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) throw new RuntimeException('请选择有效日期');
        if (($contract !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $contract)) || !preg_match('/^\d+(?:\.\d{1,2})?$/', $receipt) || (float)$contract > 999999999999.99 || (float)$receipt > 999999999999.99) throw new RuntimeException('金额须为非负数，最多两位小数');
        $sslCost = $business['resources'] && $actor['role'] !== 'customer_service' ? trim((string)($_POST['ssl_cost'] ?? '')) : '';
        if ($sslCost !== '' && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $sslCost) || (float)$sslCost > 999999999999.99)) throw new RuntimeException('SSL 实际成本最多两位小数');
        $customer = trim((string)($_POST['customer_name'] ?? ''));
        $shop = trim((string)($_POST['shop'] ?? ''));
        if ($shop !== '' && !in_array($shop, $shops, true)) throw new RuntimeException('请选择店铺列表中的店铺');
        $details = ps_business_details($projectType, $actor['role'] === 'customer_service' && $projectType === '网站模板' ? [] : ($_POST['details'] ?? []));
        if (mb_strlen($customer) > 200 || mb_strlen($shop) > 150 || mb_strlen($projectType) > 100) throw new RuntimeException('客户、店铺或业务类型过长');
        $paymentNickname = trim((string)($_POST['payment_nickname'] ?? ''));
        $tradeStatus = trim((string)($_POST['trade_status'] ?? ''));
        $contactNote = trim((string)($_POST['contact_note'] ?? ''));
        $resourceNote = trim((string)($_POST['resource_note'] ?? ''));
        if (mb_strlen($paymentNickname) > 200 || mb_strlen($tradeStatus) > 100 || mb_strlen($contactNote) > 500 || mb_strlen($resourceNote) > 500) throw new RuntimeException('备注内容过长');
        $groups = ['technical' => [], 'customer_service' => []];
        foreach (['customer_service_id' => ['customer_service', '客服'], 'frontend_id' => ['technical', $peopleLabels['frontend']], 'backend_id' => ['technical', $peopleLabels['backend']]] as $field => $target) {
            $personId = (int)($_POST[$field] ?? 0);
            if ($personId <= 0) continue;
            if (!isset($employeesById[$personId])) throw new RuntimeException('所选合作人员不存在');
            $group = $target[0];
            if (isset($groups[$group][$personId])) $groups[$group][$personId]['role'] .= '/' . $target[1];
            else $groups[$group][$personId] = ['id' => $personId, 'role' => $target[1]];
        }
        if ($actor['role'] !== 'finance') {
            $selfGroup = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
            $selfId = (int)$actor['employee_id'];
            if (!isset($groups[$selfGroup][$selfId])) $groups[$selfGroup][$selfId] = ['id' => $selfId, 'role' => $selfGroup === 'technical' ? '技术' : '客服'];
        }
        if (!$groups['technical'] && !$groups['customer_service']) throw new RuntimeException('请至少选择一位客服或技术参与人');
        if ($actor['role'] === 'customer_service' && ps_is_website_order($projectType)) {
            if (!$groups['technical']) throw new RuntimeException('请指定接收此单的技术，保存后会进入对方的项目订单');
            foreach ($groups['technical'] as $person) if (!ps_active_employee_for_business($person['id'], 'technical', $projectType)) throw new RuntimeException('指定的技术未开通当前业务的有效账号，请联系财务配置');
        }
        if ($actor['role'] === 'technical' && ps_is_website_order($projectType)) {
            foreach ($groups['customer_service'] as $person) if (!ps_active_employee_for_business($person['id'], 'customer_service', $projectType)) throw new RuntimeException('指定的客服未开通当前业务的有效账号，请联系财务配置');
        }
        $noteParts = [];
        if ($paymentNickname !== '') $noteParts[] = '付款昵称：' . $paymentNickname;
        if ($contactNote !== '') $noteParts[] = '客户联系方式：' . $contactNote;
        if ($business['resources'] && $domainMode !== 'pending') $noteParts[] = $domainMode === 'none' ? '域名：无需域名' : '域名：' . $domainTemplate['name'] . ' ' . $domainTemplate['specification'];
        if ($resourceNote !== '') $noteParts[] = '域名/空间说明：' . $resourceNote;
        if ($sslCost !== '' && (float)$sslCost > 0) $noteParts[] = 'SSL 实际成本报备：¥' . $sslCost . '（待技术补充成本凭证）';
        db()->beginTransaction();
        $q = db()->prepare('INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,receipt_amount,order_date,delivery_status,note,created_by_admin) VALUES (?,?,?,?,?,0,?,?,?,?)');
        $q->execute([$no, $customer, $projectType, $shop, $contract === '' ? 0 : round((float)$contract, 2), $date, ($_POST['delivery_status'] ?? '') === 'finished' ? 'finished' : 'unfinished', implode('；', $noteParts), $actor['role'] === 'finance' ? $actor['id'] : null]);
        $id = (int)db()->lastInsertId();
        ps_source_record($id, $contract === '' ? 'missing' : 'manual', $paymentNickname, $tradeStatus);
        ps_save_business_details($id, $projectType, $details);
        if ($actor['role'] === 'finance' && (float)$receipt > 0) {
            db()->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'receipt',?,'新建订单初始实收','approved','admin',?,?,NOW())")
                ->execute([$id, round((float)$receipt, 2), $actor['id'], $actor['id']]);
            ps_audit('cash', (int)db()->lastInsertId(), 'create', $actor, ['order_id' => $id, 'type' => 'receipt', 'amount' => round((float)$receipt, 2), 'status' => 'approved']);
            ps_recalculate_cash($id);
        }
        ps_intake_participants($id, $groups);
        ps_intake_save_resources($id, 'manual', null, $domainTemplate, $serverTemplate, $sslCost !== '' ? $sslCost : null, $domainMode);
        if ($domainTemplate) ps_intake_add_template_cost($id, $domainTemplate, $actor, '手动录入：域名');
        if ($serverTemplate) ps_intake_add_template_cost($id, $serverTemplate, $actor, '手动录入：服务器');
        ps_audit('order', $id, 'create', $actor, ['order_no' => $no, 'domain_template_id' => $domainTemplate['id'] ?? null, 'server_template_id' => $serverTemplate['id'] ?? null, 'receipt_unconfirmed' => $actor['role'] !== 'finance']);
        ps_sync_existing_shop_order($id, $no, $shop);
        db()->commit();
        header('Location: ' . BASE_URL . '/project/order.php?id=' . $id); exit;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e instanceof PDOException ? '订单号已存在或数据保存失败' : $e->getMessage(); }
}

if ($actor['role'] === 'finance') {
    $q = db()->prepare('SELECT o.*, (SELECT COUNT(*) FROM project_costs c WHERE c.order_id=o.id AND c.review_status=\'pending\') pending_costs FROM project_orders o WHERE o.order_date>=? AND o.order_date<? ORDER BY o.order_date DESC,o.id DESC');
    $q->execute([$month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))]);
} else {
    $q = db()->prepare('SELECT DISTINCT o.*, (SELECT COUNT(*) FROM project_costs c WHERE c.order_id=o.id AND c.review_status=\'pending\') pending_costs FROM project_orders o JOIN project_participants p ON p.order_id=o.id WHERE p.employee_id=? AND o.order_date>=? AND o.order_date<? ORDER BY o.order_date DESC,o.id DESC');
    $q->execute([$actor['employee_id'], $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))]);
}
$orders = $q->fetchAll();
$page_title = $actor['role'] === 'finance' ? '项目订单结算' : '我的项目订单';
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 订单入口</div><h2><?php echo e($page_title); ?></h2><p><?php echo $actor['role'] === 'customer_service' ? '客服按网站模板或网站定制建单并指定技术；技术在同一订单号补资源和成本。' : ($actor['role'] === 'technical' ? '打开本人参与的订单补技术资料与成本；先建单时可在结算单关联客服。' : '客服与技术共用一张订单结算单。按业务切换模板，标准资源从成本中心带入。'); ?> 实收由财务确认。</p></div><div class="project-hero-actions"><?php if ($allowedBusinesses): ?><button class="btn btn-light" type="button" id="manualOrderToggle" aria-controls="manual-order" aria-expanded="<?php echo $error ? 'true' : 'false'; ?>"><i class="fas fa-pen mr-1"></i> <span><?php echo $error ? '收起手动录入' : '手动录入订单'; ?></span></button><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/import.php?business=<?php echo rawurlencode($selectedBusiness); ?>"><i class="fas fa-file-excel mr-1"></i> 拖拽上传 Excel</a><?php endif; ?><?php if ($actor['role'] === 'finance'): ?><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/settings.php#cost-center">成本中心</a><?php endif; ?></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if (!$allowedBusinesses): ?><div class="alert alert-warning">当前账户尚未匹配业务类型，请联系财务在项目结算配置中分配。</div><?php endif; ?>
<?php if ($allowedBusinesses): ?>
<div id="manual-order" class="card project-form-card mb-4<?php echo $error ? '' : ' d-none'; ?>"><div class="card-body">
  <div class="project-section-title"><span class="project-step">01</span><div><h5>手动录入订单</h5><p>先填订单号即可建档；付款昵称、售价、交易状态可由店铺订单上传后自动补空。客服指定技术后双方共用同一张结算单。</p></div></div>
  <form method="post" id="projectManualForm">
    <input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>">
    <div class="project-quick-entry mb-3"><i class="fas fa-heart mr-2"></i> 急着建档？只填订单号，其他信息可以稍后补齐；已选业务会自动保留。</div>
    <div class="form-row"><div class="form-group col-md-3"><label>订单编号 *</label><input class="form-control" name="order_no" maxlength="100" value="<?php echo e($_POST['order_no'] ?? ''); ?>" placeholder="如 202609220018" required></div><div class="form-group col-md-3"><label>日期</label><input class="form-control" type="date" name="order_date" value="<?php echo e($_POST['order_date'] ?? date('Y-m-d')); ?>"></div><div class="form-group col-md-3"><label>店铺（可后补）</label><select class="form-control" name="shop"><option value="">暂不确定，待上传匹配</option><?php foreach ($shops as $shopName): ?><option value="<?php echo e($shopName); ?>" <?php echo ($_POST['shop'] ?? '') === $shopName ? 'selected' : ''; ?>><?php echo e($shopName); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><label>业务类型 *</label><select class="form-control" id="intakeBusiness" name="project_type" required><?php foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $selectedBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group col-md-3"><label>付款昵称（可后补）</label><input class="form-control" name="payment_nickname" maxlength="200" value="<?php echo e($_POST['payment_nickname'] ?? ''); ?>"></div><div class="form-group col-md-3"><label>客户 / 公司</label><input class="form-control" name="customer_name" maxlength="200" value="<?php echo e($_POST['customer_name'] ?? ''); ?>"></div><div class="form-group col-md-3"><label>售价（可后补）</label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text">¥</span></div><input class="form-control" type="number" step="0.01" min="0" name="contract_amount" value="<?php echo e($_POST['contract_amount'] ?? ''); ?>" placeholder="待订单上传补全"></div></div><div class="form-group col-md-3"><label>交易状态（可后补）</label><input class="form-control" name="trade_status" maxlength="100" value="<?php echo e($_POST['trade_status'] ?? ''); ?>" placeholder="如交易成功"></div></div>
    <div class="form-row"><div class="form-group col-md-3"><label>项目交付状态</label><select class="form-control" name="delivery_status"><option value="unfinished">未完成</option><option value="finished" <?php echo ($_POST['delivery_status'] ?? '') === 'finished' ? 'selected' : ''; ?>>已完成</option></select><small class="text-muted">与店铺交易状态不同</small></div></div>
    <div class="form-row"><div class="form-group col-md-<?php echo $actor['role'] === 'finance' ? '9' : '12'; ?>"><label>备注 / 客户电话或微信</label><input class="form-control" name="contact_note" maxlength="500" value="<?php echo e($_POST['contact_note'] ?? ''); ?>" placeholder="仅参与本订单的合作人员和财务可见"></div><?php if ($actor['role'] === 'finance'): ?><div class="form-group col-md-3"><label>已确认实收</label><input class="form-control" type="number" step="0.01" min="0" name="receipt_amount" value="<?php echo e($_POST['receipt_amount'] ?? '0'); ?>" required></div><?php endif; ?></div>
    <div class="project-divider"></div><div class="project-mini-title">参与人员 <small>本人会自动加入对应组；多人合作先均分，财务可在结算单调整权重</small></div>
    <div class="form-row"><div class="form-group col-md-4"><label id="intakeCustomerServiceLabel"><?php echo ps_is_website_order($selectedBusiness) ? '网站客服' : '客服'; ?></label><select class="form-control" name="customer_service_id"><option value="0">待关联，可在结算单补</option><?php foreach ($customerServiceChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['customer_service_id'] ?? ($actor['role'] === 'customer_service' ? $actor['employee_id'] : 0)) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-4"><label id="intakeFrontendLabel"><?php echo e(ps_business_people_labels($selectedBusiness)['frontend']); ?></label><select class="form-control" name="frontend_id"><option value="0">待指定</option><?php foreach ($technicalChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['frontend_id'] ?? ($actor['role'] === 'technical' ? $actor['employee_id'] : 0)) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-4"><label id="intakeBackendLabel"><?php echo e(ps_business_people_labels($selectedBusiness)['backend']); ?></label><select class="form-control" name="backend_id"><option value="0">无 / 待指定</option><?php foreach ($technicalChoices as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)($_POST['backend_id'] ?? 0) === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div></div>
    <?php foreach ($allowedBusinesses as $businessName): $specificFields = $businessCatalog[$businessName]['fields']; if (!$specificFields || ($actor['role'] === 'customer_service' && $businessName === '网站模板')) continue; ?>
    <div class="project-business-fields" data-business="<?php echo e($businessName); ?>"><div class="project-divider"></div><div class="project-mini-title"><?php echo e($businessName); ?>专属信息</div><div class="form-row"><?php foreach ($specificFields as $fieldKey => $fieldLabel): ?><div class="form-group col-md-6"><label><?php echo e($fieldLabel); ?></label><input class="form-control" name="details[<?php echo e($fieldKey); ?>]" maxlength="300" value="<?php echo e($_POST['details'][$fieldKey] ?? ''); ?>" placeholder="填写<?php echo e($fieldLabel); ?>"></div><?php endforeach; ?></div></div>
    <?php endforeach; ?>
    <div id="intakeResourceFields"><div class="project-divider"></div><div class="project-mini-title">技术提交 · 资源与成本 <small>可以先留空，技术确认后再计成本；无需域名不产生域名成本</small></div>
    <div class="form-row"><div class="form-group col-md-4"><label>域名使用</label><select class="form-control" id="intakeDomainMode" name="domain_mode"><option value="pending" <?php echo ($_POST['domain_mode'] ?? 'pending') === 'pending' ? 'selected' : ''; ?>>待技术确认</option><option value="none" <?php echo ($_POST['domain_mode'] ?? '') === 'none' ? 'selected' : ''; ?>>无需域名</option><option value="template" <?php echo ($_POST['domain_mode'] ?? '') === 'template' ? 'selected' : ''; ?>>使用标准域名</option></select></div><div class="form-group col-md-4" id="intakeDomainTemplateWrap"><label>域名规格与周期</label><select class="form-control" id="intakeDomainTemplate" name="domain_template_id"><option value="">请选择标准模板</option><?php foreach ($domainTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['domain_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price']) . '/' . $t['unit']); ?></option><?php endforeach; ?></select><?php if (!$domainTemplates): ?><small class="text-warning">尚无可用域名成本模板，请财务先配置价格。</small><?php endif; ?></div><div class="form-group col-md-4"><label>服务器 / 空间（如使用）</label><select class="form-control" id="intakeServerTemplate" name="server_template_id"><option value="0">本单不选标准服务器</option><?php foreach ($serverTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" data-price="<?php echo e($t['price']); ?>" <?php echo (int)($_POST['server_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price']) . '/' . $t['unit']); ?></option><?php endforeach; ?></select></div></div>
    <div class="form-row"><div class="form-group col-md-8"><label>域名或空间说明</label><input class="form-control" name="resource_note" maxlength="500" value="<?php echo e($_POST['resource_note'] ?? ''); ?>" placeholder="如客户域名 example.com、服务器账户或续费提醒"></div><div class="form-group col-md-4"><label>SSL 证书真实成本（如有）</label><input class="form-control" type="number" step="0.01" min="0" name="ssl_cost" value="<?php echo e($_POST['ssl_cost'] ?? ''); ?>" placeholder="非标准成本，创建后补凭证"></div></div>
    <div class="project-cost-strip"><span><i class="fas fa-receipt mr-1"></i> 自动带入标准成本</span><strong id="intakeCostTotal">¥0.00</strong><small id="intakeCostHint">请先选择域名使用方式</small></div></div>
    <div class="project-form-footer"><p id="intakeFooterHint">合作人员提交的售价仅作订单申报，实收仍由财务审核。</p><button class="btn btn-success btn-lg" type="submit">保存订单并打开结算单 <i class="fas fa-arrow-right ml-1"></i></button></div>
  </form>
</div></div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body py-3"><form method="get" class="form-inline"><label class="mr-2" for="month">订单月份</label><input class="form-control mr-2" type="month" name="month" id="month" value="<?php echo e($month); ?>"><button class="btn btn-outline-primary">查看</button></form></div></div>
<div class="card"><div class="card-header">订单列表（<?php echo count($orders); ?>）</div><div class="table-responsive"><table class="table table-hover mb-0">
  <thead><tr><th>订单号</th><th>客户</th><th>业务类型</th><th>日期</th><th class="text-right">实收</th><th>成本待审</th><th>状态</th><th></th></tr></thead><tbody>
  <?php foreach ($orders as $order): ?><tr>
    <td><?php echo e($order['order_no']); ?></td><td><?php echo e($order['customer_name']); ?></td><td><?php echo e($order['project_type']); ?></td><td><?php echo e($order['order_date']); ?></td>
    <td class="text-right">¥<?php echo money($order['receipt_amount']); ?></td><td><?php echo (int)$order['pending_costs']; ?></td><td><?php echo e($order['settlement_status']); ?></td>
    <td><a class="btn btn-outline-primary btn-sm" href="<?php echo BASE_URL; ?>/project/order.php?id=<?php echo (int)$order['id']; ?>">打开结算单</a></td>
  </tr><?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="8" class="text-center text-muted py-4">本月暂无可查看的项目订单</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<script>
(function () {
  var toggle = document.getElementById('manualOrderToggle');
  var panel = document.getElementById('manual-order');
  if (toggle && panel) toggle.addEventListener('click', function () {
    var opening = panel.classList.contains('d-none');
    panel.classList.toggle('d-none', !opening);
    toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    toggle.querySelector('span').textContent = opening ? '收起手动录入' : '手动录入订单';
    if (opening) panel.scrollIntoView({behavior:'smooth',block:'start'});
  });
  var business = document.getElementById('intakeBusiness');
  var resourceFields = document.getElementById('intakeResourceFields');
  var canEditResources = <?php echo $actor['role'] === 'customer_service' ? 'false' : 'true'; ?>;
  var resourceBusinesses = <?php echo json_encode(array_values(array_keys(array_filter($businessCatalog, function ($item) { return $item['resources']; }))), JSON_UNESCAPED_UNICODE); ?>;
  var peopleLabels = <?php echo json_encode(array_reduce(array_keys($businessCatalog), function ($result, $name) { $result[$name] = ps_business_people_labels($name); return $result; }, []), JSON_UNESCAPED_UNICODE); ?>;
  var mode = document.getElementById('intakeDomainMode');
  if (!mode || !business) return;
  var domainWrap = document.getElementById('intakeDomainTemplateWrap');
  var domain = document.getElementById('intakeDomainTemplate');
  var server = document.getElementById('intakeServerTemplate');
  function price(select) { var option = select.options[select.selectedIndex]; return Number(option && option.dataset.price || 0); }
  function update() {
    var resources = resourceBusinesses.indexOf(business.value) !== -1;
    document.getElementById('intakeFrontendLabel').textContent = peopleLabels[business.value].frontend;
    document.getElementById('intakeBackendLabel').textContent = peopleLabels[business.value].backend;
    document.getElementById('intakeCustomerServiceLabel').textContent = business.value === '网站模板' || business.value === 'AI网站定制' ? '网站客服' : '客服';
    document.getElementById('intakeFooterHint').textContent = '合作人员提交的售价仅作订单申报，实收仍由财务审核。' + (resources ? ' SSL 非标准成本须补凭证后审核。' : ' 如有特殊成本，可在结算单中补录凭证。');
    resourceFields.hidden = !resources || !canEditResources;
    resourceFields.querySelectorAll('input,select').forEach(function (input) { input.disabled = !resources || !canEditResources; });
    document.querySelectorAll('.project-business-fields').forEach(function (section) {
      var active = section.dataset.business === business.value;
      section.hidden = !active;
      section.querySelectorAll('input').forEach(function (input) { input.disabled = !active; });
    });
    var usesDomain = resources && mode.value === 'template';
    domainWrap.hidden = !usesDomain;
    mode.required = resources && canEditResources;
    domain.required = usesDomain && canEditResources;
    var cost = (usesDomain ? price(domain) : 0) + price(server);
    document.getElementById('intakeCostTotal').textContent = '¥' + cost.toFixed(2);
    document.getElementById('intakeCostHint').textContent = mode.value === 'pending' ? '域名待技术确认，暂不计成本' : (usesDomain && !domain.value ? '选择域名规格后显示标准价' : '最终以保存时成本模板单价为准');
  }
  [business, mode, domain, server].forEach(function (el) { el.addEventListener('change', update); });
  update();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
