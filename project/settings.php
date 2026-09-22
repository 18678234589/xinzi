<?php
require_once __DIR__ . '/../includes/ProjectBusiness.php';
$actor = ps_require_finance();
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'template') {
            $category = (string)($_POST['category'] ?? '');
            $kind = (string)($_POST['cost_kind'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $price = (string)($_POST['price'] ?? '');
            if (!in_array($category, ['domain','server','certificate','certification','api','plugin','outsourcing','other'], true) || !in_array($kind, ['one_time','annual','monthly'], true) || $name === '' || !is_numeric($price) || (float)$price < 0) throw new RuntimeException('请检查成本模板信息');
            $spec = trim((string)($_POST['specification'] ?? ''));
            $unit = trim((string)($_POST['unit'] ?? '项')) ?: '项';
            $versionQuery = db()->prepare('SELECT COALESCE(MAX(version),0)+1 FROM project_cost_templates WHERE category=? AND name=? AND specification=? AND unit=?');
            $versionQuery->execute([$category, $name, $spec, $unit]);
            $version = (int)$versionQuery->fetchColumn();
            $q = db()->prepare('INSERT INTO project_cost_templates (category,name,specification,unit,cost_kind,price,requires_proof,auto_approve,version) VALUES (?,?,?,?,?,?,?,?,?)');
            $q->execute([$category, $name, $spec, $unit, $kind, round((float)$price, 2), isset($_POST['requires_proof']) ? 1 : 0, isset($_POST['auto_approve']) ? 1 : 0, $version]);
            ps_audit('template', (int)db()->lastInsertId(), 'create', $actor, ['name' => $name, 'price' => $price]);
            $success = '成本模板已添加';
        } elseif ($action === 'disable_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            db()->prepare('UPDATE project_cost_templates SET is_active=0 WHERE id=?')->execute([$templateId]);
            ps_audit('template', $templateId, 'disable', $actor, []);
            $success = '旧模板已停用，历史成本快照不受影响';
        } elseif ($action === 'rule') {
            $group = (string)($_POST['commission_group'] ?? '');
            $rate = (string)($_POST['rate_percent'] ?? '');
            $date = (string)($_POST['effective_from'] ?? '');
            if (!in_array($group, ['technical','customer_service'], true) || !is_numeric($rate) || (float)$rate < 0 || (float)$rate > 100 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('请检查项目分成规则');
            $type = trim((string)($_POST['project_type'] ?? '*')) ?: '*';
            $q = db()->prepare('INSERT INTO project_commission_rules (commission_group,project_type,rate,effective_from) VALUES (?,?,?,?)');
            $q->execute([$group, $type, round((float)$rate / 100, 6), $date]);
            ps_audit('rule', (int)db()->lastInsertId(), 'create', $actor, ['group' => $group, 'rate_percent' => $rate, 'project_type' => $type]);
            $success = '项目分成规则已添加，旧订单的已审核快照不会改变';
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
        } elseif ($action === 'account_update') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $role = (string)($_POST['role'] ?? '');
            $businessName = (string)($_POST['business_name'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            $password = (string)($_POST['password'] ?? '');
            if (!in_array($role, ['technical','customer_service'], true) || !isset(ps_business_catalog()[$businessName]) || ($password !== '' && strlen($password) < 10)) throw new RuntimeException('角色或业务无效；新密码至少10位');
            db()->beginTransaction();
            try {
            if ($password === '') {
                $q = db()->prepare('UPDATE project_users SET role=?,is_active=? WHERE id=?');
                $q->execute([$role, $active, $userId]);
            } else {
                $q = db()->prepare('UPDATE project_users SET role=?,is_active=?,password_hash=? WHERE id=?');
                $q->execute([$role, $active, password_hash($password, PASSWORD_DEFAULT), $userId]);
            }
            db()->prepare('DELETE FROM project_user_businesses WHERE user_id=?')->execute([$userId]);
            db()->prepare('INSERT INTO project_user_businesses (user_id,business_name,is_default) VALUES (?,?,1)')->execute([$userId, $businessName]);
            ps_audit('account', $userId, 'update', $actor, ['role' => $role, 'business' => $businessName, 'is_active' => $active, 'password_changed' => $password !== '']);
            db()->commit();
            } catch (Throwable $e) { db()->rollBack(); throw $e; }
            $success = '账户已更新';
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '保存失败：合作人员、用户名或模板可能已存在' : $e->getMessage(); }
}
$templates = db()->query('SELECT * FROM project_cost_templates ORDER BY is_active DESC,category,name,id DESC')->fetchAll();
$rules = db()->query('SELECT * FROM project_commission_rules ORDER BY commission_group,project_type,effective_from DESC,id DESC')->fetchAll();
$employees = db()->query('SELECT e.id,e.name,e.department,u.username FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id ORDER BY e.department,e.name,e.id')->fetchAll();
$accounts = db()->query('SELECT u.id,u.username,u.role,u.is_active,e.name,e.department,(SELECT b.business_name FROM project_user_businesses b WHERE b.user_id=u.id ORDER BY b.is_default DESC,b.business_name LIMIT 1) business_name FROM project_users u JOIN employees e ON e.id=u.employee_id ORDER BY e.department,e.name')->fetchAll();
$page_title = '项目结算配置';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0">项目结算配置</h4><a class="btn btn-outline-secondary btn-sm" href="<?php echo BASE_URL; ?>/project/index.php">返回订单</a></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<div id="cost-center" class="card mb-3"><div class="card-header">成本中心 · 标准成本模板库</div><div class="card-body"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="template">
<div class="form-group col-md-2"><label>类别</label><select name="category" class="form-control"><option value="domain">域名</option><option value="server">服务器</option><option value="certificate">SSL</option><option value="certification">认证</option><option value="api">API</option><option value="plugin">插件</option><option value="outsourcing">外包</option><option value="other">其他</option></select></div>
<div class="form-group col-md-2"><label>名称</label><input name="name" class="form-control" required placeholder="基础VPS"></div><div class="form-group col-md-2"><label>规格</label><input name="specification" class="form-control" placeholder="2核4G/1年"></div><div class="form-group col-md-1"><label>单位</label><input name="unit" class="form-control" value="项"></div><div class="form-group col-md-1"><label>标准价</label><input type="number" step="0.01" min="0" name="price" class="form-control" required></div><div class="form-group col-md-2"><label>周期</label><select name="cost_kind" class="form-control"><option value="one_time">一次性</option><option value="annual">年度</option><option value="monthly">月度</option></select></div><div class="form-group col-md-1"><label>凭证</label><div><input type="checkbox" name="requires_proof" value="1"> 必填</div></div><div class="form-group col-md-1"><label>自动审</label><div><input type="checkbox" name="auto_approve" value="1" checked> 是</div></div><div class="col-12"><button class="btn btn-primary">添加模板</button><small class="ml-3 text-muted">改价时停用旧模板并创建新模板，历史订单保留原单价。</small></div>
</form><div class="alert alert-light border mt-3 mb-0 small">用于自动带入的 .com 域名示例：类别“域名”、名称“.com 域名”、规格“1年”、单位“年”、周期“年度”，标准价请填写真实采购价。模板未配置前系统不会猜测 ¥75；需凭证的模板仍由技术在结算单中上传，不在快速录入中自动入账。</div></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>类别</th><th>名称/规格</th><th>版本</th><th>单价</th><th>周期</th><th>凭证</th><th>自动审</th><th>状态</th><th></th></tr></thead><tbody><?php foreach ($templates as $t): ?><tr><td><?php echo e($t['category']); ?></td><td><?php echo e($t['name'] . ' ' . $t['specification']); ?></td><td>v<?php echo (int)$t['version']; ?></td><td>¥<?php echo money($t['price']); ?>/<?php echo e($t['unit']); ?></td><td><?php echo e($t['cost_kind']); ?></td><td><?php echo $t['requires_proof'] ? '必填' : '可选'; ?></td><td><?php echo $t['auto_approve'] ? '是' : '否'; ?></td><td><?php echo $t['is_active'] ? '启用' : '停用'; ?></td><td><?php if ($t['is_active']): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="disable_template"><input type="hidden" name="template_id" value="<?php echo (int)$t['id']; ?>"><button class="btn btn-outline-secondary btn-sm">停用</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card mb-3"><div class="card-header">项目利润返佣规则</div><div class="card-body"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="rule"><div class="form-group col-md-3"><label>项目分成组</label><select name="commission_group" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div><div class="form-group col-md-3"><label>业务类型（* 为全部）</label><input name="project_type" value="*" class="form-control"></div><div class="form-group col-md-2"><label>利润返佣 %</label><input type="number" step="0.0001" min="0" max="100" name="rate_percent" class="form-control" required></div><div class="form-group col-md-2"><label>生效日期</label><input type="date" name="effective_from" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div><div class="form-group col-md-2"><button class="btn btn-primary btn-block">添加规则</button></div></form></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>组别</th><th>业务类型</th><th>比例</th><th>生效日期</th></tr></thead><tbody><?php foreach ($rules as $r): ?><tr><td><?php echo $r['commission_group'] === 'technical' ? '技术' : '客服'; ?></td><td><?php echo e($r['project_type']); ?></td><td><?php echo money($r['rate'] * 100); ?>%</td><td><?php echo e($r['effective_from']); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card mb-3"><div class="card-header">合作人员项目账户</div><div class="card-body"><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="account"><div class="form-group col-md-3"><label>合作人员</label><select name="employee_id" id="newProjectEmployee" class="form-control" required><option value="">选择未开通合作人员</option><?php foreach ($employees as $emp): if ($emp['username']) continue; ?><option value="<?php echo (int)$emp['id']; ?>" data-business="<?php echo e(ps_business_fallback($emp['department']) ?? ''); ?>"><?php echo e($emp['name'] . ' · ' . $emp['department'] . ' · ID ' . $emp['id']); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-2"><label>账户角色</label><select name="role" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div><div class="form-group col-md-2"><label>默认业务</label><select name="business_name" id="newProjectBusiness" class="form-control" required><option value="">选择业务</option><?php foreach (ps_business_catalog() as $businessName => $definition): ?><option value="<?php echo e($businessName); ?>"><?php echo e($businessName); ?></option><?php endforeach; ?></select></div><div class="form-group col-md-2"><label>登录名</label><input name="username" class="form-control" required></div><div class="form-group col-md-2"><label>初始密码（至少10位）</label><input type="password" name="password" class="form-control" minlength="10" required autocomplete="new-password"></div><div class="form-group col-md-1"><button class="btn btn-primary btn-block">开通</button></div></form><small class="text-muted">默认业务按部门建议，财务可调整；合作人员只可访问本人参与的项目订单及项目分成。</small></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>合作人员</th><th>登录名</th><th>角色、默认业务、状态</th></tr></thead><tbody><?php foreach ($accounts as $account): $currentBusiness = $account['business_name'] ?: ps_business_fallback($account['department']); ?><tr><td><?php echo e($account['name'] . ' · ' . $account['department']); ?></td><td><?php echo e($account['username']); ?></td><td><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="account_update"><input type="hidden" name="user_id" value="<?php echo (int)$account['id']; ?>"><select name="role" class="form-control form-control-sm mr-2"><option value="technical" <?php echo $account['role'] === 'technical' ? 'selected' : ''; ?>>技术</option><option value="customer_service" <?php echo $account['role'] === 'customer_service' ? 'selected' : ''; ?>>客服</option></select><select name="business_name" class="form-control form-control-sm mr-2" required><option value="">选择业务</option><?php foreach (ps_business_catalog() as $businessName => $definition): ?><option value="<?php echo e($businessName); ?>" <?php echo $currentBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select><label class="mr-2"><input type="checkbox" name="is_active" value="1" <?php echo $account['is_active'] ? 'checked' : ''; ?>> 启用</label><input type="password" name="password" class="form-control form-control-sm mr-2" placeholder="新密码（可留空）" autocomplete="new-password"><button class="btn btn-outline-primary btn-sm">保存</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
<script>(function(){var employee=document.getElementById('newProjectEmployee');var business=document.getElementById('newProjectBusiness');if(employee&&business)employee.addEventListener('change',function(){var option=employee.options[employee.selectedIndex];business.value=option.dataset.business||'';});})();</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
