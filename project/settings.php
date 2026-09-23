<?php
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';
$actor = ps_require_finance();
$error = '';
$success = '';
$categories = ['program' => '程序套餐', 'domain' => '域名', 'server' => '服务器', 'certificate' => 'SSL证书', 'plugin' => '功能插件', 'certification' => '认证', 'api' => 'API', 'outsourcing' => '外包', 'other' => '其他'];
$activeBusinesses = array_keys(array_filter(ps_business_catalog(), function ($d) { return empty($d['legacy']); }));
$percent = function ($value, $label, $allowBlank = false) {
    $value = trim((string)$value);
    if ($allowBlank && $value === '') return null;
    if (!is_numeric($value) || (float)$value < 0 || (float)$value > 100) throw new RuntimeException($label . '须为 0–100 之间的百分数');
    return round((float)$value / 100, 6);
};
$amount = function ($value, $label) {
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || (float)$value > 999999999999.99) throw new RuntimeException($label . '须为非负数，最多两位小数');
    return round((float)$value, 2);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'template') {
            $category = (string)($_POST['category'] ?? '');
            $kind = (string)($_POST['cost_kind'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $price = (string)($_POST['price'] ?? '');
            $scope = trim((string)($_POST['business_scope'] ?? ''));
            if (!isset($categories[$category]) || !in_array($kind, ['one_time','annual','monthly'], true) || $name === '' || !is_numeric($price) || (float)$price < 0) throw new RuntimeException('请检查成本模板信息');
            if ($scope !== '' && !in_array($scope, $activeBusinesses, true)) throw new RuntimeException('适用业务无效');
            $supplier = trim((string)($_POST['supplier_price'] ?? '')) === '' ? null : $amount($_POST['supplier_price'], '采购价');
            $spec = trim((string)($_POST['specification'] ?? ''));
            $unit = trim((string)($_POST['unit'] ?? '项')) ?: '项';
            $versionQuery = db()->prepare('SELECT COALESCE(MAX(version),0)+1 FROM project_cost_templates WHERE category=? AND name=? AND specification=? AND unit=?');
            $versionQuery->execute([$category, $name, $spec, $unit]);
            $version = (int)$versionQuery->fetchColumn();
            $priceMode = ($_POST['price_mode'] ?? '') === 'percent' ? 'percent' : 'fixed';
            if ($priceMode === 'percent' && ((float)$price <= 0 || (float)$price > 100)) throw new RuntimeException('按售价百分比计价时，请填写 0–100 之间的百分数');
            $q = db()->prepare('INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,supplier_price,requires_proof,auto_approve,version) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$category, $scope, $name, $spec, $unit, $priceMode, $kind, round((float)$price, 2), $supplier, isset($_POST['requires_proof']) ? 1 : 0, isset($_POST['auto_approve']) ? 1 : 0, $version]);
            ps_audit('template', (int)db()->lastInsertId(), 'create', $actor, ['name' => $name, 'price' => $price]);
            $success = '成本模板已添加';
        } elseif ($action === 'reprice_template') {
            // 改价 = 停用旧版本 + 新增版本；历史订单的成本快照保留原单价。
            $templateId = (int)($_POST['template_id'] ?? 0);
            $newPrice = $amount($_POST['price'] ?? '', '新价格');
            $newSupplier = trim((string)($_POST['supplier_price'] ?? '')) === '' ? null : $amount($_POST['supplier_price'], '采购价');
            db()->beginTransaction();
            try {
                $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND is_active=1 FOR UPDATE');
                $q->execute([$templateId]);
                $old = $q->fetch();
                if (!$old) throw new RuntimeException('模板已停用或不存在，请刷新');
                db()->prepare('UPDATE project_cost_templates SET is_active=0 WHERE id=?')->execute([$templateId]);
                if ($old['price_mode'] === 'percent' && ($newPrice <= 0 || $newPrice > 100)) throw new RuntimeException('按售价百分比计价的模板，新比例须在 0–100 之间');
                db()->prepare('INSERT INTO project_cost_templates (category,business_scope,name,specification,unit,price_mode,cost_kind,price,supplier_price,requires_proof,auto_approve,version) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$old['category'], $old['business_scope'], $old['name'], $old['specification'], $old['unit'], $old['price_mode'], $old['cost_kind'], $newPrice, $newSupplier, $old['requires_proof'], $old['auto_approve'], (int)$old['version'] + 1]);
                ps_audit('template', (int)db()->lastInsertId(), 'reprice', $actor, ['from_id' => $templateId, 'old_price' => $old['price'], 'new_price' => $newPrice]);
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            $success = '已生成新价格版本 v' . ((int)$old['version'] + 1) . '，历史订单成本不变';
        } elseif ($action === 'disable_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            db()->prepare('UPDATE project_cost_templates SET is_active=0 WHERE id=?')->execute([$templateId]);
            ps_audit('template', $templateId, 'disable', $actor, []);
            $success = '旧模板已停用，历史成本快照不受影响';
        } elseif ($action === 'import_templates') {
            $added = ps_apply_preset_templates($actor);
            $success = $added ? '已从《程序表记录》导入 ' . $added . ' 个标准成本模板' : '《程序表记录》中的模板均已存在，无需导入';
        } elseif ($action === 'import_rules') {
            $effective = (string)($_POST['effective_from'] ?? '2026-09-01');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) throw new RuntimeException('生效日期无效');
            $added = ps_apply_preset_rules($actor, $effective);
            $success = $added ? '已按部门核算表新增 ' . $added . ' 条分成规则版本' : '核算表中的分成规则均已配置，无需导入';
        } elseif ($action === 'rule') {
            $group = (string)($_POST['commission_group'] ?? '');
            $date = (string)($_POST['effective_from'] ?? '');
            if (!in_array($group, ['technical','customer_service'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('请检查项目分成规则');
            $rate = $percent($_POST['rate_percent'] ?? '', '分成比例');
            $fee = $percent($_POST['fee_percent'] ?? '', '服务费率', true);
            $type = trim((string)($_POST['project_type'] ?? '*')) ?: '*';
            if ($type !== '*') $type = ps_business_normalize($type);
            if ($type !== '*' && !in_array($type, $activeBusinesses, true)) throw new RuntimeException('请选择有效业务类型');
            $role = trim((string)($_POST['role_name'] ?? '')) ?: '*';
            $kind = trim((string)($_POST['order_kind'] ?? '')) ?: '*';
            if ($kind !== '*' && ($type === '*' || !in_array($kind, ps_business_order_kinds($type), true))) throw new RuntimeException('订单类型须属于所选业务');
            $mode = ($_POST['calc_mode'] ?? '') === 'individual' ? 'individual' : 'pool';
            $note = trim((string)($_POST['note'] ?? ''));
            if (mb_strlen($role) > 80 || mb_strlen($note) > 200) throw new RuntimeException('岗位或说明过长');
            $q = db()->prepare('INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,note,effective_from) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$group, $type, $role, $kind, $mode, $rate, $fee, $amount($_POST['subsidy'] ?? '', '每单补助'), $amount($_POST['min_contract'] ?? '', '最低售价'), $note, $date]);
            ps_audit('rule', (int)db()->lastInsertId(), 'create', $actor, ['group' => $group, 'rate' => $rate, 'project_type' => $type, 'role' => $role, 'kind' => $kind, 'mode' => $mode, 'fee' => $fee]);
            $success = '项目分成规则已添加，已审核订单的快照不会改变';
        } elseif ($action === 'disable_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            db()->prepare('UPDATE project_commission_rules SET is_active=0 WHERE id=?')->execute([$ruleId]);
            ps_audit('rule', $ruleId, 'disable', $actor, []);
            $success = '规则已停用，已审核快照不受影响';
        } elseif ($action === 'employee_role') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $businessName = (string)($_POST['business_name'] ?? '');
            $group = (string)($_POST['commission_group'] ?? '');
            $role = trim((string)($_POST['role_name'] ?? ''));
            if ($employeeId <= 0 || !in_array($businessName, $activeBusinesses, true) || !in_array($group, ['technical','customer_service'], true) || $role === '' || mb_strlen($role) > 80) throw new RuntimeException('请选择合作人员、业务、组别并填写岗位');
            db()->prepare('INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name)')->execute([$employeeId, $businessName, $group, $role]);
            ps_audit('employee_role', $employeeId, 'save', $actor, ['business' => $businessName, 'group' => $group, 'role' => $role]);
            $success = '默认岗位已保存，之后录入的新订单自动套用；已录入订单可在结算单调整岗位';
        } elseif ($action === 'delete_employee_role') {
            db()->prepare('DELETE FROM project_employee_roles WHERE employee_id=? AND business_name=? AND commission_group=?')->execute([(int)($_POST['employee_id'] ?? 0), (string)($_POST['business_name'] ?? ''), (string)($_POST['commission_group'] ?? '')]);
            ps_audit('employee_role', (int)($_POST['employee_id'] ?? 0), 'delete', $actor, ['business' => $_POST['business_name'] ?? '']);
            $success = '默认岗位已删除';
        } elseif ($action === 'account') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? '');
            $businessName = (string)($_POST['business_name'] ?? '');
            if ($employeeId <= 0 || $username === '' || strlen($username) > 100 || strlen($password) < 10 || !in_array($role, ['technical','customer_service'], true) || !isset(ps_business_catalog()[$businessName])) throw new RuntimeException('请选择合作人员、角色、业务类型，并设置至少10位密码');
            $adminName = db()->prepare('SELECT 1 FROM admins WHERE username=? LIMIT 1');
            $adminName->execute([$username]);
            if ($adminName->fetchColumn()) throw new RuntimeException('登录名与管理员账户重复，请换一个');
            db()->beginTransaction();
            try {
                $q = db()->prepare('INSERT INTO project_users (employee_id,username,password_hash,role) VALUES (?,?,?,?)');
                $q->execute([$employeeId, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
                $newUserId = (int)db()->lastInsertId();
                db()->prepare('INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,1)')->execute([$newUserId, $businessName]);
                ps_audit('account', $newUserId, 'create', $actor, ['employee_id' => $employeeId, 'role' => $role, 'business' => $businessName]);
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            $success = '合作人员项目账户已创建';
        } elseif ($action === 'account_bulk') {
            // 按部门批量开通：登录名默认用姓名（重名追加编号），随机初始密码只显示这一次。
            $department = (string)($_POST['department'] ?? '');
            $role = (string)($_POST['role'] ?? '');
            $businessName = (string)($_POST['business_name'] ?? '');
            if ($department === '' || !in_array($role, ['technical','customer_service'], true) || !isset(ps_business_catalog()[$businessName])) throw new RuntimeException('请选择部门、角色和默认业务');
            $pending = db()->prepare('SELECT e.id,e.name FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id WHERE e.department=? AND u.id IS NULL ORDER BY e.id');
            $pending->execute([$department]);
            $people = $pending->fetchAll();
            if (!$people) throw new RuntimeException('该部门人员都已开通账号');
            $taken = db()->prepare('SELECT (SELECT COUNT(*) FROM project_users WHERE username=?) + (SELECT COUNT(*) FROM admins WHERE username=?)');
            $created = [];
            db()->beginTransaction();
            try {
                foreach ($people as $person) {
                    $username = $person['name'];
                    $taken->execute([$username, $username]);
                    if ((int)$taken->fetchColumn() > 0) $username = $person['name'] . $person['id'];
                    $password = substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(12))), 0, 10);
                    db()->prepare('INSERT INTO project_users (employee_id,username,password_hash,role) VALUES (?,?,?,?)')->execute([$person['id'], $username, password_hash($password, PASSWORD_DEFAULT), $role]);
                    $newUserId = (int)db()->lastInsertId();
                    db()->prepare('INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,1)')->execute([$newUserId, $businessName]);
                    ps_audit('account', $newUserId, 'bulk_create', $actor, ['employee_id' => (int)$person['id'], 'role' => $role, 'business' => $businessName]);
                    $created[] = ['name' => $person['name'], 'username' => $username, 'password' => $password];
                }
                db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            $bulkAccounts = $created;
            $success = '已为“' . $department . '”开通 ' . count($created) . ' 个账号，请把下方初始密码发给本人，并提醒登录后妥善保管';
        } elseif ($action === 'account_update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $role = (string)($_POST['role'] ?? '');
            $businessName = (string)($_POST['business_name'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            $password = (string)($_POST['password'] ?? '');
            if (isset($_POST['reset_default'])) $password = '123456'; // 忘记密码时重置为默认，本人登录后可再修改
            elseif ($password !== '' && strlen($password) < 10) throw new RuntimeException('新密码至少10位（或勾选“重置为 123456”）');
            if (!in_array($role, ['technical','customer_service'], true) || !isset(ps_business_catalog()[$businessName])) throw new RuntimeException('角色或业务无效');
            db()->beginTransaction();
            try {
            if ($password === '') {
                $q = db()->prepare('UPDATE project_users SET role=?,is_active=? WHERE id=?');
                $q->execute([$role, $active, $userId]);
            } else {
                $q = db()->prepare('UPDATE project_users SET role=?,is_active=?,password_hash=?,password_changed_at=NULL WHERE id=?');
                $q->execute([$role, $active, password_hash($password, PASSWORD_DEFAULT), $userId]);
            }
            db()->prepare('DELETE FROM project_user_businesses WHERE user_id=?')->execute([$userId]);
            db()->prepare('INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,1)')->execute([$userId, $businessName]);
            ps_audit('account', $userId, 'update', $actor, ['role' => $role, 'business' => $businessName, 'is_active' => $active, 'password_changed' => $password !== '']);
            db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            $success = '账户已更新';
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e instanceof PDOException ? '保存失败：合作人员、用户名或模板可能已存在' : $e->getMessage(); }
}
$templates = db()->query("SELECT * FROM project_cost_templates ORDER BY is_active DESC,FIELD(category,'program','domain','server','certificate','plugin','certification','api','outsourcing','other'),name,specification,id DESC")->fetchAll();
$rules = db()->query('SELECT * FROM project_commission_rules ORDER BY is_active DESC,project_type,commission_group,role_name,order_kind,effective_from DESC,id DESC')->fetchAll();
$employees = db()->query('SELECT e.id,e.name,e.department,u.username FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id ORDER BY e.department,e.name,e.id')->fetchAll();
$accounts = db()->query('SELECT u.id,u.username,u.phone,u.password_changed_at,u.role,u.is_active,e.name,e.department,(SELECT b.business_name FROM project_user_businesses b WHERE b.user_id=u.id ORDER BY b.is_default DESC,b.business_name LIMIT 1) business_name FROM project_users u JOIN employees e ON e.id=u.employee_id ORDER BY e.department,e.name')->fetchAll();
$employeeRoles = db()->query('SELECT r.*,e.name,e.department FROM project_employee_roles r JOIN employees e ON e.id=r.employee_id ORDER BY r.business_name,r.commission_group,e.name')->fetchAll();
$presetTemplates = ps_preset_cost_templates();
$presetTemplateStates = array_map('ps_preset_template_exists', $presetTemplates);
$presetTemplateNew = count(array_keys($presetTemplateStates, 'new', true));
$presetTemplateDiffers = count(array_keys($presetTemplateStates, 'differs', true));
$presetRules = ps_preset_rules();
$presetRulePending = count(array_filter(array_map('ps_preset_rule_exists', $presetRules), function ($state) { return $state !== 'same'; }));
$activeTemplateCount = count(array_filter($templates, function ($t) { return (int)$t['is_active'] === 1; }));
$roleSuggestions = ['前端','外包前端','后端','售后','模板技术','资料员','技术','客服','定制客服','定制技术'];
$departmentsWithoutAccount = db()->query('SELECT e.department,COUNT(*) c FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id WHERE u.id IS NULL GROUP BY e.department ORDER BY e.department')->fetchAll();
$page_title = '成本中心';
include __DIR__ . '/../includes/header.php';
$csrf = e(ps_csrf_token());
$pct = function ($value) { return rtrim(rtrim(number_format((float)$value * 100, 4), '0'), '.') . '%'; };
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 财务配置</div><h2>成本中心</h2><p>标准成本（程序套餐、域名、服务器、SSL、插件）在这里定价，录入订单时自动带入；分成规则按“业务 › 岗位 › 订单类型”匹配。改价和改比例都会新建版本，已审核订单不受影响。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="#cost-center">成本模板</a><a class="btn btn-outline-light" href="<?php echo BASE_URL; ?>/project/rules.php">规则中心</a><a class="btn btn-outline-light" href="#roles">岗位对照</a><a class="btn btn-outline-light" href="#accounts">合作人员账户</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

<div id="cost-center" class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap"><span>成本中心 · 标准成本模板库（启用 <?php echo $activeTemplateCount; ?> 个）</span><input type="search" id="templateFilter" class="form-control form-control-sm" style="max-width:260px" placeholder="搜索名称 / 规格，如 JSP展示中级" aria-label="搜索成本模板"></div>
<div class="card-body">
<div class="project-preset mb-3"><div><strong><i class="fas fa-file-import mr-1"></i> 《程序表记录（成本中心记录）》</strong><div class="small text-muted">共 <?php echo count($presetTemplates); ?> 项：森动 / JSP / 青站 / 优站 / PHP 等程序套餐的“采购价 / 核算成本”，以及域名首年 80（次年 90）、SSL 30 / 泛域名 250、短信包等插件。待导入 <?php echo $presetTemplateNew; ?> 项<?php echo $presetTemplateDiffers ? '；另有 ' . $presetTemplateDiffers . ' 项现价与表格不同，保留现价（需要时用“改价”）' : ''; ?>。</div></div><form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="import_templates"><button class="btn btn-success text-nowrap" <?php echo $presetTemplateNew ? '' : 'disabled'; ?> onclick="return confirm('导入 <?php echo $presetTemplateNew; ?> 个标准成本模板？已存在的模板不会被覆盖。')">一键导入</button></form></div>
<form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="template">
<div class="form-group col-md-2"><label>类别</label><select name="category" class="form-control"><?php foreach ($categories as $key => $label): ?><option value="<?php echo e($key); ?>"><?php echo e($label); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>名称</label><input name="name" class="form-control" required placeholder="JSP展示中级版"></div><div class="form-group col-md-1"><label>规格 / 周期</label><input name="specification" class="form-control" placeholder="1年 空间+域名"></div><div class="form-group col-md-1"><label>单位</label><input name="unit" class="form-control" value="项"></div><div class="form-group col-md-1"><label>核算成本</label><input type="number" step="0.01" min="0" name="price" class="form-control" required></div><div class="form-group col-md-1"><label>计价</label><select name="price_mode" class="form-control"><option value="fixed">¥ 固定</option><option value="percent">% 售价</option></select></div><div class="form-group col-md-1"><label>采购价</label><input type="number" step="0.01" min="0" name="supplier_price" class="form-control" placeholder="选填"></div><div class="form-group col-md-2"><label>适用业务</label><select name="business_scope" class="form-control"><option value="">全部业务</option><?php foreach ($activeBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>"><?php echo e($businessName); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-1"><label>周期</label><select name="cost_kind" class="form-control"><option value="one_time">一次性</option><option value="annual">年度</option><option value="monthly">月度</option></select></div>
<div class="col-12 d-flex flex-wrap align-items-center" style="gap:16px"><label class="mb-0"><input type="checkbox" name="requires_proof" value="1"> 必须上传凭证</label><label class="mb-0"><input type="checkbox" name="auto_approve" value="1" checked> ≤¥500 自动通过</label><button class="btn btn-primary">添加模板</button><small class="text-muted">核算成本用于利润与分成；采购价（如“孙姐要成本”）只做对账参考。</small></div>
</form></div>
<div class="table-responsive" style="max-height:560px"><table class="table table-sm mb-0" id="templateTable"><thead class="thead-light" style="position:sticky;top:0;z-index:1"><tr><th>类别</th><th>名称 / 规格</th><th>适用业务</th><th>版本</th><th class="text-right">核算成本</th><th class="text-right">采购价</th><th>周期</th><th>凭证 / 自动审</th><th>状态</th><th>操作</th></tr></thead><tbody>
<?php foreach ($templates as $t): ?><tr class="<?php echo $t['is_active'] ? '' : 'text-muted'; ?>" data-search="<?php echo e(mb_strtolower($t['name'] . ' ' . $t['specification'] . ' ' . ($categories[$t['category']] ?? ''))); ?>"><td><?php echo e($categories[$t['category']] ?? $t['category']); ?></td><td><?php echo e($t['name']); ?><div class="small text-muted"><?php echo e($t['specification']); ?></div></td><td class="small"><?php echo e($t['business_scope'] ?: '全部'); ?></td><td>v<?php echo (int)$t['version']; ?></td><td class="text-right"><?php echo $t['price_mode'] === 'percent' ? '售价 × ' . e(rtrim(rtrim($t['price'], '0'), '.')) . '%' : '¥' . money($t['price']) . '/' . e($t['unit']); ?></td><td class="text-right"><?php echo $t['supplier_price'] !== null ? ($t['price_mode'] === 'percent' ? e(rtrim(rtrim($t['supplier_price'], '0'), '.')) . '%' : '¥' . money($t['supplier_price'])) : '—'; ?></td><td><?php echo e(ps_label('cost_kind', $t['cost_kind'])); ?></td><td class="small"><?php echo $t['requires_proof'] ? '必填' : '可选'; ?> / <?php echo $t['auto_approve'] ? '是' : '否'; ?></td><td><?php echo $t['is_active'] ? '启用' : '停用'; ?></td><td><?php if ($t['is_active']): ?><div class="d-flex" style="gap:6px"><form method="post" class="form-inline flex-nowrap"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="reprice_template"><input type="hidden" name="template_id" value="<?php echo (int)$t['id']; ?>"><input type="number" step="0.01" min="0" name="price" class="form-control form-control-sm mr-1" style="width:86px" placeholder="新成本" required aria-label="新核算成本"><input type="number" step="0.01" min="0" name="supplier_price" class="form-control form-control-sm mr-1" style="width:80px" placeholder="采购价" value="<?php echo $t['supplier_price'] !== null ? e($t['supplier_price']) : ''; ?>" aria-label="新采购价"><button class="btn btn-outline-primary btn-sm text-nowrap">改价</button></form><form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="disable_template"><input type="hidden" name="template_id" value="<?php echo (int)$t['id']; ?>"><button class="btn btn-outline-secondary btn-sm">停用</button></form></div><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$templates): ?><tr><td colspan="10" class="text-center text-muted py-4">尚无成本模板。可先用上方“一键导入”带入《程序表记录》。</td></tr><?php endif; ?>
</tbody></table></div></div>

<div id="rules" class="project-preset mb-3"><div><strong><i class="fas fa-percent mr-1"></i> 分成规则已移到“规则中心”</strong><div class="small text-muted">逐单分成比例、服务费、每单补助，以及月度阶梯、超额奖金、排名奖、主管提成、固定补助、计件奖励都在规则中心统一设置，修改即时生效。</div></div><a class="btn btn-success text-nowrap" href="<?php echo BASE_URL; ?>/project/rules.php">打开规则中心</a></div>

<div id="roles" class="card mb-3"><div class="card-header">岗位对照 · 合作人员默认岗位</div><div class="card-body"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="employee_role">
<div class="form-group col-md-3"><label>合作人员</label><select name="employee_id" class="form-control" required><option value="">选择合作人员</option><?php foreach ($employees as $emp): ?><option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>业务</label><select name="business_name" class="form-control" required><?php foreach ($activeBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>"><?php echo e($businessName); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>分成组</label><select name="commission_group" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div>
<div class="form-group col-md-3"><label>默认岗位</label><input name="role_name" class="form-control" list="ruleRoleNames" required placeholder="如 外包前端 / 售后 / 定制客服"><datalist id="ruleRoleNames"><?php foreach ($roleSuggestions as $roleOption): ?><option value="<?php echo e($roleOption); ?>"><?php endforeach; ?></datalist></div>
<div class="form-group col-md-2"><button class="btn btn-primary btn-block">保存</button></div></form><small class="text-muted">例如：李仁超、孙磊 → AI网站定制 · 技术 · 外包前端；李子晖 → 售后；谢文婷 → 网站模板 · 资料员。新订单录入或导入时自动写入岗位，从而命中对应规则。</small></div>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>合作人员</th><th>业务</th><th>分成组</th><th>默认岗位</th><th></th></tr></thead><tbody><?php foreach ($employeeRoles as $row): ?><tr><td><?php echo e($row['name'] . ' · ' . $row['department']); ?></td><td><?php echo e($row['business_name']); ?></td><td><?php echo e(ps_label('group', $row['commission_group'])); ?></td><td><?php echo e($row['role_name']); ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="delete_employee_role"><input type="hidden" name="employee_id" value="<?php echo (int)$row['employee_id']; ?>"><input type="hidden" name="business_name" value="<?php echo e($row['business_name']); ?>"><input type="hidden" name="commission_group" value="<?php echo e($row['commission_group']); ?>"><button class="btn btn-outline-secondary btn-sm">删除</button></form></td></tr><?php endforeach; ?><?php if (!$employeeRoles): ?><tr><td colspan="5" class="text-center text-muted">尚未配置。未配置的人员使用表单或表格里的岗位名（前端、后端、客服等）。</td></tr><?php endif; ?></tbody></table></div></div>

<div id="accounts" class="card mb-3"><div class="card-header">合作人员项目账户</div><div class="card-body">
<?php if (!empty($bulkAccounts)): ?><div class="alert alert-warning"><strong>初始密码只显示这一次</strong>，请复制后分别发给本人：<div class="table-responsive mt-2"><table class="table table-sm mb-0"><thead><tr><th>姓名</th><th>登录名</th><th>初始密码</th></tr></thead><tbody><?php foreach ($bulkAccounts as $item): ?><tr><td><?php echo e($item['name']); ?></td><td><code><?php echo e($item['username']); ?></code></td><td><code><?php echo e($item['password']); ?></code></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<form method="post" class="form-row align-items-end mb-3 pb-3 border-bottom"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="account_bulk"><div class="form-group col-md-4"><label>按部门批量开通</label><select name="department" class="form-control" required><option value="">选择部门（括号内为未开通人数）</option><?php foreach ($departmentsWithoutAccount as $dept): ?><option value="<?php echo e($dept['department']); ?>"><?php echo e($dept['department'] . '（' . $dept['c'] . ' 人）'); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-2"><label>账户角色</label><select name="role" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div><div class="form-group col-md-3"><label>默认业务</label><select name="business_name" class="form-control" required><option value="">选择业务</option><?php foreach (ps_business_catalog() as $businessName => $definition): ?><option value="<?php echo e($businessName); ?>"><?php echo e($businessName . (!empty($definition['legacy']) ? '（客服账户）' : '')); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-3"><button class="btn btn-outline-primary btn-block" onclick="return confirm('为该部门所有未开通人员创建账号？登录名为姓名，初始密码随机生成。')">一键开通</button></div><div class="col-12"><small class="text-muted">登录名用姓名（与已有账号重名时加编号），初始密码随机 10 位，只在开通后显示一次。同一部门里客服、技术混在一起时（如“标书小程序”），请用下面的单个开通。</small></div></form><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="account"><div class="form-group col-md-3"><label>合作人员</label><select name="employee_id" id="newProjectEmployee" class="form-control" required><option value="">选择未开通合作人员</option><?php foreach ($employees as $emp): if ($emp['username']) continue; ?><option value="<?php echo (int)$emp['id']; ?>" data-business="<?php echo e(ps_business_fallback($emp['department']) ?? ''); ?>"><?php echo e($emp['name'] . ' · ' . $emp['department'] . ' · ID ' . $emp['id']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-2"><label>账户角色</label><select name="role" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div><div class="form-group col-md-2"><label>默认业务</label><select name="business_name" id="newProjectBusiness" class="form-control" required><option value="">选择业务</option><?php foreach (ps_business_catalog() as $businessName => $definition): ?><option value="<?php echo e($businessName); ?>"><?php echo e($businessName . (!empty($definition['legacy']) ? '（客服账户）' : '')); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-2"><label>登录名</label><input name="username" class="form-control" required></div><div class="form-group col-md-2"><label>初始密码（至少10位）</label><input type="password" name="password" class="form-control" minlength="10" required autocomplete="new-password"></div><div class="form-group col-md-1"><button class="btn btn-primary btn-block">开通</button></div></form><small class="text-muted">默认业务按部门建议，财务可调整。“网站客服”客服账户可建网站模板与 AI 网站定制订单，“小程序客服”客服账户可建小程序开发与小额引流订单。合作人员只可访问本人参与的项目订单及项目分成。</small></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>合作人员</th><th>登录名</th><th>角色、默认业务、状态</th></tr></thead><tbody><?php foreach ($accounts as $account): $currentBusiness = $account['business_name'] ?: ps_business_fallback($account['department']); ?><tr><td><?php echo e($account['name'] . ' · ' . $account['department']); ?></td><td><?php echo e($account['username']); ?><div class="small text-muted"><?php echo $account['phone'] ? '手机 ' . e(substr($account['phone'], 0, 3) . '****' . substr($account['phone'], -4)) : '未绑定手机'; ?><?php echo $account['password_changed_at'] ? '' : ' · 默认密码'; ?></div></td><td><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="account_update"><input type="hidden" name="user_id" value="<?php echo (int)$account['id']; ?>"><select name="role" class="form-control form-control-sm mr-2"><option value="technical" <?php echo $account['role'] === 'technical' ? 'selected' : ''; ?>>技术</option><option value="customer_service" <?php echo $account['role'] === 'customer_service' ? 'selected' : ''; ?>>客服</option></select><select name="business_name" class="form-control form-control-sm mr-2" required><option value="">选择业务</option><?php foreach (ps_business_catalog() as $businessName => $definition): ?><option value="<?php echo e($businessName); ?>" <?php echo $currentBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select><label class="mr-2"><input type="checkbox" name="is_active" value="1" <?php echo $account['is_active'] ? 'checked' : ''; ?>> 启用</label><input type="password" name="password" class="form-control form-control-sm mr-2" placeholder="新密码（可留空）" autocomplete="new-password"><label class="mr-2 small"><input type="checkbox" name="reset_default" value="1"> 重置为 123456</label><button class="btn btn-outline-primary btn-sm">保存</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<script>
(function(){
  var employee=document.getElementById('newProjectEmployee');var business=document.getElementById('newProjectBusiness');
  if(employee&&business)employee.addEventListener('change',function(){var option=employee.options[employee.selectedIndex];business.value=option.dataset.business||'';});
  var kinds=<?php echo json_encode(array_map(function ($d) { return $d['order_kinds'] ?? []; }, ps_business_catalog()), JSON_UNESCAPED_UNICODE); ?>;
  var ruleBusiness=document.getElementById('ruleBusiness'),ruleKind=document.getElementById('ruleKind');
  function refreshKinds(){var list=kinds[ruleBusiness.value]||[];ruleKind.innerHTML='<option value="">全部</option>';list.forEach(function(k){var o=document.createElement('option');o.value=k;o.textContent=k;ruleKind.appendChild(o);});ruleKind.disabled=!list.length;}
  if(ruleBusiness){ruleBusiness.addEventListener('change',refreshKinds);refreshKinds();}
  var filter=document.getElementById('templateFilter');
  if(filter)filter.addEventListener('input',function(){var q=filter.value.trim().toLowerCase();document.querySelectorAll('#templateTable tbody tr[data-search]').forEach(function(tr){tr.hidden=q!==''&&tr.dataset.search.indexOf(q)===-1;});});
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
