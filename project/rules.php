<?php
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
$actor = ps_require_finance();
$error = '';
$success = '';
$activeBusinesses = array_keys(array_filter(ps_business_catalog(), function ($d) { return empty($d['legacy']); }));
$month = (string)($_GET['month'] ?? $_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
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
$employees = db()->query('SELECT id,name,department FROM employees ORDER BY department,name,id')->fetchAll();
$employeeNames = [];
foreach ($employees as $emp) $employeeNames[(int)$emp['id']] = $emp['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'import_rules') {
            $effective = (string)($_POST['effective_from'] ?? '2026-09-01');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) throw new RuntimeException('生效日期无效');
            $added = ps_apply_preset_rules($actor, $effective);
            $success = $added ? '已按部门核算表新增 ' . $added . ' 条逐单分成规则' : '核算表中的逐单分成规则均已配置';
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
            db()->prepare('INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_contract_amount,min_cost_rate,note,effective_from) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$group, $type, $role, $kind, $mode, $rate, $fee, $amount($_POST['subsidy'] ?? '', '每单补助'), $amount($_POST['min_contract'] ?? '', '最低售价'), $percent($_POST['min_cost_percent'] ?? '', '成本下限', true), $note, $date]);
            ps_audit('rule', (int)db()->lastInsertId(), 'create', $actor, ['group' => $group, 'rate' => $rate, 'project_type' => $type, 'role' => $role, 'kind' => $kind, 'mode' => $mode, 'fee' => $fee]);
            $success = '逐单分成规则已添加，未审核订单立即按新规则预估';
        } elseif ($action === 'rule_update') {
            // 直接修改即时生效：未审核订单马上按新参数预估；已审核订单保存了审核时的比例与服务费，不受影响。
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $rate = $percent($_POST['rate_percent'] ?? '', '分成比例');
            $fee = $percent($_POST['fee_percent'] ?? '', '服务费率', true);
            $mode = ($_POST['calc_mode'] ?? '') === 'individual' ? 'individual' : 'pool';
            $note = trim((string)($_POST['note'] ?? ''));
            if (mb_strlen($note) > 200) throw new RuntimeException('说明过长');
            $before = db()->prepare('SELECT rate,service_fee_rate,per_order_subsidy,min_contract_amount,calc_mode FROM project_commission_rules WHERE id=?');
            $before->execute([$ruleId]);
            $old = $before->fetch();
            if (!$old) throw new RuntimeException('规则不存在');
            db()->prepare('UPDATE project_commission_rules SET rate=?,service_fee_rate=?,per_order_subsidy=?,min_contract_amount=?,min_cost_rate=?,calc_mode=?,note=? WHERE id=?')
                ->execute([$rate, $fee, $amount($_POST['subsidy'] ?? '', '每单补助'), $amount($_POST['min_contract'] ?? '', '最低售价'), $percent($_POST['min_cost_percent'] ?? '', '成本下限', true), $mode, $note, $ruleId]);
            ps_audit('rule', $ruleId, 'update', $actor, ['before' => $old, 'rate' => $rate, 'fee' => $fee, 'mode' => $mode]);
            $success = '规则已修改并立即生效（已审核订单按审核时的比例，不受影响）';
        } elseif ($action === 'php_bands') {
            // PHPweb 程序成本区间：保存即生效，未审核订单马上按新区间预估；已审核订单保留审核时的成本。
            $bands = [];
            foreach ((array)($_POST['band_from'] ?? []) as $i => $from) {
                $from = trim((string)$from); $cost = trim((string)($_POST['band_cost'][$i] ?? ''));
                if ($from === '' && $cost === '') continue;
                $bands[] = ['from' => $amount($from, '售价起点'), 'cost' => $amount($cost, '成本')];
            }
            if (!$bands) throw new RuntimeException('至少保留一档售价区间');
            usort($bands, function ($x, $y) { return $x['from'] <=> $y['from']; });
            $roles = array_values(array_filter(array_map('trim', preg_split('/[,，、\s]+/u', (string)($_POST['exclude_roles'] ?? '')))));
            $config = ['enabled' => !empty($_POST['enabled']), 'bands' => $bands, 'tech_from' => $amount($_POST['tech_from'] ?? '', '技术加价起点'), 'tech_extra' => $amount($_POST['tech_extra'] ?? '', '技术加价'), 'exclude_roles' => $roles];
            ps_setting_set('php_cost_bands', $config, $actor['id']);
            ps_audit('setting', 0, 'php_cost_bands', $actor, $config);
            $success = 'PHPweb 成本区间已保存并立即生效（已审核订单不受影响）';
        } elseif ($action === 'toggle_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            db()->prepare('UPDATE project_commission_rules SET is_active=1-is_active WHERE id=?')->execute([$ruleId]);
            ps_audit('rule', $ruleId, 'toggle', $actor, []);
            $success = '规则状态已切换';
        } elseif ($action === 'monthly_import') {
            [$added, $skipped] = ps_monthly_apply_presets($actor, $month);
            $success = '已导入 ' . $added . ' 条月度规则（自 ' . $month . ' 起）' . ($skipped ? '；未导入：' . implode('、', $skipped) : '');
        } elseif ($action === 'monthly_save') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $types = ps_monthly_types();
            $type = (string)($_POST['rule_type'] ?? '');
            if (!isset($types[$type])) throw new RuntimeException('请选择规则类型');
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) throw new RuntimeException('请填写规则名称（100 字以内）');
            $businesses = array_values(array_filter((array)($_POST['scope_business'] ?? []), function ($b) use ($activeBusinesses) { return in_array($b, $activeBusinesses, true); }));
            $scopeBusiness = $businesses ? implode(',', $businesses) : '*';
            $scopeGroup = in_array($_POST['scope_group'] ?? '', ['technical', 'customer_service'], true) ? $_POST['scope_group'] : '*';
            $scopeRole = trim((string)($_POST['scope_role'] ?? '')) ?: '*';
            $employeeId = (int)($_POST['employee_id'] ?? 0) ?: null;
            if ($employeeId !== null && !isset($employeeNames[$employeeId])) throw new RuntimeException('合作人员不存在');
            if (in_array($type, ['dept_share', 'fixed', 'base_fee', 'attendance_bonus'], true) && $employeeId === null) throw new RuntimeException('“' . $types[$type] . '”请选择发给哪位合作人员');
            $metric = in_array($_POST['metric'] ?? '', ['profit', 'sales', 'commission', 'manual'], true) ? $_POST['metric'] : 'profit';
            if ($metric === 'manual' && $type !== 'ranking') $metric = 'profit';
            $from = (string)($_POST['effective_from'] ?? '');
            $to = trim((string)($_POST['effective_to'] ?? ''));
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from) || ($to !== '' && (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $to) || $to < $from))) throw new RuntimeException('生效月份无效');
            $params = ps_monthly_params_from_input($type, $_POST);
            $note = trim((string)($_POST['note'] ?? ''));
            if (mb_strlen($note) > 300 || mb_strlen($scopeRole) > 80) throw new RuntimeException('说明或岗位过长');
            $values = [$name, $type, $scopeBusiness, $scopeGroup, $scopeRole, $employeeId, $metric, json_encode($params, JSON_UNESCAPED_UNICODE), $from, $to === '' ? null : $to, $note, $actor['id']];
            if ($ruleId > 0) {
                db()->prepare('UPDATE project_monthly_rules SET name=?,rule_type=?,scope_business=?,scope_group=?,scope_role=?,employee_id=?,metric=?,params_json=?,effective_from=?,effective_to=?,note=?,updated_by_admin=? WHERE id=?')->execute(array_merge($values, [$ruleId]));
                ps_audit('monthly_rule', $ruleId, 'update', $actor, ['name' => $name, 'params' => $params]);
                $success = '月度规则“' . $name . '”已保存，未锁定月份立即按新规则计算';
            } else {
                db()->prepare('INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,effective_to,note,updated_by_admin) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
                ps_audit('monthly_rule', (int)db()->lastInsertId(), 'create', $actor, ['name' => $name, 'params' => $params]);
                $success = '月度规则“' . $name . '”已添加，立即生效';
            }
        } elseif ($action === 'monthly_toggle') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            db()->prepare('UPDATE project_monthly_rules SET is_active=1-is_active WHERE id=?')->execute([$ruleId]);
            ps_audit('monthly_rule', $ruleId, 'toggle', $actor, []);
            $success = '月度规则状态已切换';
        } elseif ($action === 'monthly_input_add' || $action === 'monthly_input_delete') {
            $period = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
            $period->execute([$month]);
            if ($period->fetchColumn() === 'locked') throw new RuntimeException($month . ' 已锁定，不能再修改本月填写项');
            $ruleId = (int)($_POST['rule_id'] ?? 0);
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            if ($action === 'monthly_input_delete') {
                db()->prepare('DELETE FROM project_monthly_inputs WHERE payroll_month=? AND rule_id=? AND employee_id=?')->execute([$month, $ruleId, $employeeId]);
                ps_audit('monthly_input', $ruleId, 'delete', $actor, ['month' => $month, 'employee_id' => $employeeId]);
                $success = '已删除该填写项，结果已更新';
            } else {
                $ruleRow = db()->prepare('SELECT rule_type,metric,employee_id FROM project_monthly_rules WHERE id=?');
                $ruleRow->execute([$ruleId]);
                $ruleInfo = $ruleRow->fetch();
                if (!$ruleInfo) throw new RuntimeException('请选择规则');
                if ($ruleInfo['employee_id'] !== null) $employeeId = (int)$ruleInfo['employee_id'];
                if ($ruleInfo['rule_type'] !== 'dept_share' && !isset($employeeNames[$employeeId])) throw new RuntimeException('请选择合作人员');
                if ($ruleInfo['rule_type'] === 'dept_share') $employeeId = 0;
                $value = trim((string)($_POST['value'] ?? ''));
                if (!is_numeric($value) || abs((float)$value) > 999999999) throw new RuntimeException('请填写有效数值');
                if ((float)$value < 0 && $ruleInfo['rule_type'] !== 'manual') throw new RuntimeException('只有“手工调整”可以填写负数（扣款）');
                $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
                if ($ruleInfo['rule_type'] === 'manual' && $note === '') throw new RuntimeException('手工调整请写明原因');
                db()->prepare('INSERT INTO project_monthly_inputs (payroll_month,rule_id,employee_id,value,note,updated_by_admin) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),note=VALUES(note),updated_by_admin=VALUES(updated_by_admin)')
                    ->execute([$month, $ruleId, $employeeId, round((float)$value, 2), $note, $actor['id']]);
                ps_audit('monthly_input', $ruleId, 'save', $actor, ['month' => $month, 'employee_id' => $employeeId, 'value' => $value]);
                $success = $month . ' 填写项已保存，结果已更新';
            }
        } elseif ($action === 'monthly_inputs') {
            $period = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
            $period->execute([$month]);
            if ($period->fetchColumn() === 'locked') throw new RuntimeException($month . ' 已锁定，不能再修改件数和费用');
            $save = db()->prepare('INSERT INTO project_monthly_inputs (payroll_month,rule_id,employee_id,value,note,updated_by_admin) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),note=VALUES(note),updated_by_admin=VALUES(updated_by_admin)');
            $count = 0;
            foreach ((array)($_POST['input'] ?? []) as $ruleId => $byEmployee) {
                foreach ((array)$byEmployee as $employeeId => $value) {
                    $value = trim((string)$value);
                    if ($value === '') $value = '0';
                    if (!is_numeric($value) || (float)$value < 0) throw new RuntimeException('件数和费用须为非负数');
                    $save->execute([$month, (int)$ruleId, (int)$employeeId, round((float)$value, 2), mb_substr(trim((string)($_POST['input_note'][$ruleId][$employeeId] ?? '')), 0, 200), $actor['id']]);
                    $count++;
                }
            }
            ps_audit('monthly_input', 0, 'save', $actor, ['month' => $month, 'rows' => $count]);
            $success = $month . ' 的件数与费用已保存，结果已更新';
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $error = $e instanceof PDOException ? '保存失败，请确认已执行数据库迁移' : $e->getMessage(); }
}

$rules = db()->query('SELECT * FROM project_commission_rules ORDER BY is_active DESC,project_type,commission_group,role_name,order_kind,effective_from DESC,id DESC')->fetchAll();
$presetRules = ps_preset_rules();
$presetRulePending = count(array_filter(array_map('ps_preset_rule_exists', $presetRules), function ($state) { return $state !== 'same'; }));
$monthlyRules = db()->query('SELECT * FROM project_monthly_rules ORDER BY is_active DESC,id')->fetchAll();
foreach ($monthlyRules as $i => $rule) $monthlyRules[$i]['params'] = json_decode((string)$rule['params_json'], true) ?: [];
$editRule = null;
if (isset($_GET['edit'])) foreach ($monthlyRules as $rule) if ((int)$rule['id'] === (int)$_GET['edit']) $editRule = $rule;
$periodQuery = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
$periodQuery->execute([$month]);
$monthLocked = $periodQuery->fetchColumn() === 'locked';
$results = ps_monthly_results($month);
$resultsByEmployee = [];
foreach ($results as $row) $resultsByEmployee[(int)$row['employee_id']][] = $row;
$inputs = ps_monthly_inputs($month);
$activeMonthly = ps_monthly_rules_for($month);
// 可按月填写的规则：计件、部门其他费用、固定服务费 / 全勤 / 固定补助的本月覆盖金额、手工调整、按名次的排名奖。
$inputRules = array_values(array_filter($activeMonthly, function ($r) { return in_array($r['rule_type'], ['per_unit', 'dept_share', 'base_fee', 'attendance_bonus', 'fixed', 'manual'], true) || ($r['rule_type'] === 'ranking' && $r['metric'] === 'manual'); }));
$inputRows = [];
$inputRuleNames = [];
foreach ($activeMonthly as $r) $inputRuleNames[(int)$r['id']] = $r;
foreach ($inputs as $ruleId => $byEmployee) foreach ($byEmployee as $employeeId => $row) $inputRows[] = ['rule_id' => $ruleId, 'employee_id' => $employeeId] + $row;
$types = ps_monthly_types();
$metrics = ps_monthly_metrics();
$roleSuggestions = ['前端','外包前端','后端','售后','模板技术','资料员','技术','客服','定制客服','定制技术'];
$pct = function ($value) { return rtrim(rtrim(number_format((float)$value * 100, 4), '0'), '.') . '%'; };
$describe = function ($rule) use ($pct) {
    $p = $rule['params'];
    switch ($rule['rule_type']) {
        case 'tier_rate': return implode('；', array_map(function ($t) use ($pct) { return '≥¥' . money($t['from']) . ' → ' . $pct($t['rate']); }, $p['tiers'] ?? []));
        case 'threshold_bonus': return '超过 ¥' . money($p['threshold'] ?? 0) . ' 的部分 × ' . $pct($p['rate'] ?? 0);
        case 'ranking': return '前 ' . count($p['awards'] ?? []) . ' 名：' . implode(' / ', array_map(function ($a) { return '¥' . money($a); }, $p['awards'] ?? []));
        case 'dept_share': return '部门' . (($p['base'] ?? '') === 'revenue' ? '收入（扣服务费）' : '毛利') . (!empty($p['deduct_commissions']) ? ' − 部门提成' : '') . ' − 其他费用，× ' . $pct($p['rate'] ?? 0) . ' × 分配 ' . $pct($p['share'] ?? 1);
        case 'fixed': return '每月 ¥' . money($p['amount'] ?? 0) . (!empty($p['separate']) ? '（另行支付，不计入应结算）' : '');
        case 'per_unit': return '每个 ¥' . money($p['amount'] ?? 0) . '（件数每月在下方填写）';
        case 'base_fee': return (float)($p['amount'] ?? 0) > 0 ? '每月 ¥' . money($p['amount']) . '，按考勤折算' : '每月金额在下方填写，按考勤折算';
        case 'attendance_bonus': return '¥' . money($p['amount'] ?? 0) . '（请假 <4h 全额、≥4h 减半、≥8h 不发）';
        case 'manual': return '每月在下方逐人填写';
    }
    return '';
};
$page_title = '规则中心';
include __DIR__ . '/../includes/header.php';
$csrf = e(ps_csrf_token());
$form = $editRule ?: ['id' => 0, 'name' => '', 'rule_type' => 'threshold_bonus', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee_id' => null, 'metric' => 'profit', 'params' => [], 'effective_from' => $month, 'effective_to' => null, 'note' => ''];
$formBusinesses = $form['scope_business'] === '*' ? [] : explode(',', $form['scope_business']);
$tiers = $form['params']['tiers'] ?? [];
while (count($tiers) < 7) $tiers[] = ['from' => '', 'rate' => '', 'base' => ''];
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 财务配置</div><h2>规则中心</h2><p>逐单分成和月度奖励都在这里设置。修改后未审核订单与未锁定月份立即按新规则计算；已审核订单、已锁定月份保留当时的结果。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="#order-rules">逐单分成</a><a class="btn btn-outline-light" href="#monthly">月度规则</a><a class="btn btn-outline-light" href="#preview">本月试算</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>

<div id="order-rules" class="card mb-3"><div class="card-header">逐单分成规则（审核订单时计算并保存快照）</div><div class="card-body">
<div class="project-preset mb-3"><div><strong><i class="fas fa-calculator mr-1"></i> 《网站核算》《8月提成核对》《小程序部门核算标准》</strong><div class="small text-muted">共 <?php echo count($presetRules); ?> 条：模板技术 13%、资料员 10%、模板客服 8%、定制客服 10%、定制前端 13% / 外包前端 20% / 后端 10% / 售后 15%、环境配置 15%、小程序新订单 5% + 20 元等。待新增 <?php echo $presetRulePending; ?> 条。</div></div><form method="post" class="form-inline flex-nowrap"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="import_rules"><label class="small mr-2 text-nowrap" for="presetEffective">生效</label><input type="date" id="presetEffective" name="effective_from" value="2026-09-01" class="form-control form-control-sm mr-2"><button class="btn btn-success text-nowrap" <?php echo $presetRulePending ? '' : 'disabled'; ?>>一键导入</button></form></div>
<details class="mb-2"><summary class="font-weight-bold" style="cursor:pointer">＋ 新增逐单分成规则</summary>
<form method="post" class="form-row align-items-end mt-3"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="rule">
<div class="form-group col-md-2"><label>分成组</label><select name="commission_group" class="form-control"><option value="technical">技术</option><option value="customer_service">客服</option></select></div>
<div class="form-group col-md-2"><label>业务</label><select name="project_type" id="ruleBusiness" class="form-control"><option value="*">全部业务（兜底）</option><?php foreach ($activeBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>"><?php echo e($businessName); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>岗位（留空 = 全部）</label><input name="role_name" class="form-control" list="ruleRoleNames" placeholder="如 前端 / 外包前端"><datalist id="ruleRoleNames"><?php foreach ($roleSuggestions as $roleOption): ?><option value="<?php echo e($roleOption); ?>"><?php endforeach; ?></datalist></div>
<div class="form-group col-md-2"><label>订单类型</label><select name="order_kind" id="ruleKind" class="form-control"><option value="">全部</option></select></div>
<div class="form-group col-md-2"><label>计算方式</label><select name="calc_mode" class="form-control"><option value="pool">组池 × 组内权重</option><option value="individual">个人独立计提</option></select></div>
<div class="form-group col-md-2"><label>生效日期</label><input type="date" name="effective_from" value="<?php echo date('Y-m-d'); ?>" class="form-control" required></div>
<div class="form-group col-md-2"><label>分成比例 %</label><input type="number" step="0.0001" min="0" max="100" name="rate_percent" class="form-control" required></div>
<div class="form-group col-md-2"><label>服务费率 %</label><input type="number" step="0.0001" min="0" max="100" name="fee_percent" class="form-control" placeholder="留空 = 业务默认"></div>
<div class="form-group col-md-2"><label>每单补助 ¥</label><input type="number" step="0.01" min="0" name="subsidy" class="form-control" placeholder="0"></div>
<div class="form-group col-md-2"><label>最低售价 ¥</label><input type="number" step="0.01" min="0" name="min_contract" class="form-control" placeholder="低于此价不计"></div>
<div class="form-group col-md-2"><label>成本下限 %（按售价）</label><input type="number" step="0.01" min="0" max="100" name="min_cost_percent" class="form-control" placeholder="如 65"></div>
<div class="form-group col-md-2"><label>说明</label><input name="note" maxlength="200" class="form-control"></div>
<div class="form-group col-md-2"><button class="btn btn-primary btn-block">添加</button></div></form></details>
<div class="small text-muted">匹配顺序：具体业务 &gt; 全部业务，指定岗位 &gt; 全部岗位，指定订单类型 &gt; 全部类型。组池：max(收入 − 直接成本 − 售价×服务费率, 0) × 比例 × 组内权重；个人独立：max(收入 − 直接成本×本人权重 − 售价×服务费率, 0) × 比例。</div></div>
<div class="table-responsive"><table class="table table-sm mb-0 project-rule-table"><thead><tr><th>业务 · 组别</th><th>岗位 / 订单类型</th><th>方式</th><th>比例 %</th><th>服务费 %</th><th>补助 ¥</th><th>最低售价 ¥</th><th>成本下限 %</th><th>说明</th><th></th></tr></thead><tbody>
<?php foreach ($rules as $r): $formId = 'rule' . (int)$r['id']; ?><tr class="<?php echo $r['is_active'] ? '' : 'text-muted'; ?>">
<td><?php echo e($r['project_type'] === '*' ? '全部业务' : $r['project_type']); ?><div class="small text-muted"><?php echo e(ps_label('group', $r['commission_group'])); ?> · <?php echo e($r['effective_from']); ?> 起<?php echo $r['is_active'] ? '' : ' · 已停用'; ?></div></td>
<td><?php echo e($r['role_name'] === '*' ? '全部岗位' : $r['role_name']); ?><div class="small text-muted"><?php echo e($r['order_kind'] === '*' ? '全部类型' : $r['order_kind']); ?></div></td>
<td><select form="<?php echo $formId; ?>" name="calc_mode" class="form-control form-control-sm"><option value="pool">组池</option><option value="individual" <?php echo $r['calc_mode'] === 'individual' ? 'selected' : ''; ?>>独立</option></select></td>
<td><input form="<?php echo $formId; ?>" name="rate_percent" type="number" step="0.0001" min="0" max="100" class="form-control form-control-sm" style="width:84px" value="<?php echo e(rtrim(rtrim(number_format($r['rate'] * 100, 4, '.', ''), '0'), '.')); ?>"></td>
<td><input form="<?php echo $formId; ?>" name="fee_percent" type="number" step="0.0001" min="0" max="100" class="form-control form-control-sm" style="width:84px" value="<?php echo $r['service_fee_rate'] === null ? '' : e(rtrim(rtrim(number_format($r['service_fee_rate'] * 100, 4, '.', ''), '0'), '.')); ?>" placeholder="默认"></td>
<td><input form="<?php echo $formId; ?>" name="subsidy" type="number" step="0.01" min="0" class="form-control form-control-sm" style="width:80px" value="<?php echo (float)$r['per_order_subsidy'] > 0 ? e($r['per_order_subsidy']) : ''; ?>"></td>
<td><input form="<?php echo $formId; ?>" name="min_contract" type="number" step="0.01" min="0" class="form-control form-control-sm" style="width:80px" value="<?php echo (float)$r['min_contract_amount'] > 0 ? e($r['min_contract_amount']) : ''; ?>"></td>
<td><input form="<?php echo $formId; ?>" name="min_cost_percent" type="number" step="0.01" min="0" max="100" class="form-control form-control-sm" style="width:72px" value="<?php echo $r['min_cost_rate'] === null ? '' : e(rtrim(rtrim(number_format($r['min_cost_rate'] * 100, 2, '.', ''), '0'), '.')); ?>"></td>
<td><input form="<?php echo $formId; ?>" name="note" maxlength="200" class="form-control form-control-sm" value="<?php echo e($r['note']); ?>"></td>
<td class="text-nowrap"><form method="post" id="<?php echo $formId; ?>" class="d-inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="rule_update"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-outline-primary btn-sm">保存</button></form> <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-outline-secondary btn-sm"><?php echo $r['is_active'] ? '停用' : '启用'; ?></button></form></td></tr><?php endforeach; ?>
<?php if (!$rules): ?><tr><td colspan="10" class="text-center text-muted py-4">尚无分成规则，可先“一键导入”核算表口径。</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php $phpCfg = ps_php_cost_config(); ?>
<div id="php-cost" class="card mb-3"><div class="card-header d-flex justify-content-between flex-wrap" style="gap:8px"><span>PHPweb 程序成本区间（算提成时按售价调整）</span><span class="small text-muted">来自《PHPweb程序成本区间表》；只影响个人提成的成本，订单毛利仍按实际成本</span></div><div class="card-body">
<form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="php_bands">
<p class="small text-muted mb-2">客服与资料员：售价低于第一档时按实际成本（空间 90 + 域名首年 80 / 次年 90）；达到某档起点即按该档成本。技术：在此基础上，售价达到“技术加价起点”再加价。</p>
<div class="table-responsive"><table class="table table-sm mb-2" style="max-width:560px"><thead><tr><th>售价 ≥ ¥</th><th>算提成的 PHP 成本 ¥</th></tr></thead><tbody>
<?php foreach (array_merge($phpCfg['bands'], [['from' => '', 'cost' => ''], ['from' => '', 'cost' => '']]) as $band): ?><tr><td><input name="band_from[]" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?php echo e($band['from']); ?>" aria-label="售价起点"></td><td><input name="band_cost[]" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?php echo e($band['cost']); ?>" aria-label="成本"></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="small text-muted">清空一行的两个数字即删除该档；最后一档适用于更高的售价。</p>
<div class="form-row align-items-end">
<div class="form-group col-md-3"><label>技术加价起点：售价 ≥ ¥</label><input name="tech_from" type="number" step="0.01" min="0" class="form-control" value="<?php echo e($phpCfg['tech_from']); ?>"></div>
<div class="form-group col-md-2"><label>技术加价 ¥</label><input name="tech_extra" type="number" step="0.01" min="0" class="form-control" value="<?php echo e($phpCfg['tech_extra']); ?>"></div>
<div class="form-group col-md-3"><label>不加价的技术岗位</label><input name="exclude_roles" class="form-control" value="<?php echo e(implode('、', $phpCfg['exclude_roles'])); ?>" placeholder="如 资料员"></div>
<div class="form-group col-md-2"><label class="mb-2"><input type="checkbox" name="enabled" value="1" <?php echo $phpCfg['enabled'] ? 'checked' : ''; ?>> 启用区间</label></div>
<div class="form-group col-md-2"><button class="btn btn-success btn-block">保存区间</button></div>
</div></form></div></div>

<div id="monthly" class="card mb-3"><div class="card-header">月度规则（按月汇总计算，计入项目报酬结算中心）</div><div class="card-body">
<div class="project-preset mb-3"><div><strong><i class="fas fa-calendar-alt mr-1"></i> 部门核算表的月度口径</strong><div class="small text-muted">模板技术超额奖金（1 万以上 × 1.5%，光君 / 孙妍 / 张强）、网站客服超额奖金（2 万以上 × 0.8%）与排名奖（500 / 300 / 200）、刘帅利润阶梯、外包前端售价阶梯、于洋环境配置主管提成（5% × 50%）、经理 / 主管补助、优站模板奖励（每个 15 元）。底薪、全勤仍在原系统结算。</div></div><form method="post" class="form-inline flex-nowrap"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="monthly_import"><input type="hidden" name="month" value="<?php echo e($month); ?>"><button class="btn btn-success text-nowrap" onclick="return confirm('从 <?php echo e($month); ?> 起导入核算表的月度规则？同名规则不会重复导入。')">一键导入（自 <?php echo e($month); ?> 起）</button></form></div>
<form method="post" id="monthlyForm" class="project-monthly-form"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="monthly_save"><input type="hidden" name="rule_id" value="<?php echo (int)$form['id']; ?>"><input type="hidden" name="month" value="<?php echo e($month); ?>">
<div class="project-mini-title"><?php echo $editRule ? '修改月度规则：' . e($editRule['name']) : '＋ 新增月度规则'; ?><?php if ($editRule): ?> <a class="small ml-2" href="?month=<?php echo e($month); ?>#monthly">取消修改</a><?php endif; ?></div>
<div class="form-row">
<div class="form-group col-md-3"><label>规则名称</label><input name="name" class="form-control" maxlength="100" required value="<?php echo e($form['name']); ?>" placeholder="如 模板技术超额奖金"></div>
<div class="form-group col-md-3"><label>规则类型</label><select name="rule_type" id="monthlyType" class="form-control"><?php foreach ($types as $key => $label): ?><option value="<?php echo e($key); ?>" <?php echo $form['rule_type'] === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2" data-for="tier_rate threshold_bonus ranking"><label>按什么指标</label><select name="metric" class="form-control"><?php foreach ($metrics as $key => $label): ?><option value="<?php echo e($key); ?>" <?php echo $form['metric'] === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>生效月份</label><input type="month" name="effective_from" class="form-control" required value="<?php echo e($form['effective_from']); ?>"></div>
<div class="form-group col-md-2"><label>截止月份（可空）</label><input type="month" name="effective_to" class="form-control" value="<?php echo e($form['effective_to'] ?? ''); ?>"></div>
</div>
<div class="form-row">
<div class="form-group col-md-4"><label>适用业务（不选 = 全部）</label><div class="project-check-row"><?php foreach ($activeBusinesses as $businessName): ?><label class="mb-0 mr-3"><input type="checkbox" name="scope_business[]" value="<?php echo e($businessName); ?>" <?php echo in_array($businessName, $formBusinesses, true) ? 'checked' : ''; ?>> <?php echo e($businessName); ?></label><?php endforeach; ?></div></div>
<div class="form-group col-md-2" data-for="tier_rate threshold_bonus ranking per_unit profit_pool"><label>分成组</label><select name="scope_group" class="form-control"><option value="*">全部</option><option value="technical" <?php echo $form['scope_group'] === 'technical' ? 'selected' : ''; ?>>技术</option><option value="customer_service" <?php echo $form['scope_group'] === 'customer_service' ? 'selected' : ''; ?>>客服</option></select></div>
<div class="form-group col-md-2" data-for="tier_rate threshold_bonus ranking"><label>岗位（空 = 全部）</label><input name="scope_role" class="form-control" list="ruleRoleNames" value="<?php echo $form['scope_role'] === '*' ? '' : e($form['scope_role']); ?>"></div>
<div class="form-group col-md-4"><label id="monthlyEmployeeLabel">指定合作人员（空 = 范围内所有人）</label><select name="employee_id" class="form-control"><option value="0">不指定</option><?php foreach ($employees as $emp): ?><option value="<?php echo (int)$emp['id']; ?>" <?php echo (int)$form['employee_id'] === (int)$emp['id'] ? 'selected' : ''; ?>><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
</div>
<div class="form-row" data-for="threshold_bonus"><div class="form-group col-md-3"><label>门槛 ¥</label><input name="threshold" type="number" step="0.01" min="0" class="form-control" value="<?php echo e($form['params']['threshold'] ?? ''); ?>"></div><div class="form-group col-md-3"><label>超出部分比例 %</label><input name="rate" type="number" step="0.0001" min="0" class="form-control" value="<?php echo isset($form['params']['rate']) ? e($form['params']['rate'] * 100) : ''; ?>"></div></div>
<div class="form-row" data-for="dept_share"><div class="form-group col-md-3"><label>计算基数</label><select name="dept_base" class="form-control"><option value="profit">部门订单毛利</option><option value="revenue" <?php echo ($form['params']['base'] ?? '') === 'revenue' ? 'selected' : ''; ?>>部门收入（扣服务费）</option></select><label class="small mt-1 mb-0"><input type="checkbox" name="deduct_commissions" value="1" <?php echo !empty($form['params']['deduct_commissions']) ? 'checked' : ''; ?>> 扣除部门客服 / 技术提成</label></div><div class="form-group col-md-2"><label>提成比例 %</label><input name="rate" type="number" step="0.0001" min="0" class="form-control" value="<?php echo isset($form['params']['rate']) ? e($form['params']['rate'] * 100) : ''; ?>"></div><div class="form-group col-md-3"><label>分配比例 %</label><input name="share" type="number" step="0.01" min="0" max="100" class="form-control" value="<?php echo isset($form['params']['share']) ? e($form['params']['share'] * 100) : '100'; ?>"></div><div class="col-md-6 small text-muted pt-4">部门毛利取所选业务当月已审核订单（每单计一次），当月员工底薪、推广费等“其他费用”在下方本月试算里填写后扣除。</div></div>
<div class="form-row" data-for="profit_pool"><div class="form-group col-md-2"><label>扣除额 ¥</label><input name="deduction" type="number" step="0.01" min="0" class="form-control" value="<?php echo e($form['params']['deduction'] ?? ''); ?>" placeholder="如 1000×6 = 6000"></div><div class="form-group col-md-2"><label>提成比例 %</label><input name="rate" type="number" step="0.0001" min="0" class="form-control" value="<?php echo isset($form['params']['rate']) ? e($form['params']['rate'] * 100) : ''; ?>"></div><div class="form-group col-md-2"><label>成员分配 %</label><input name="members_share" type="number" step="0.0001" min="0" class="form-control" value="<?php echo isset($form['params']['members_share']) ? e($form['params']['members_share'] * 100) : ''; ?>" placeholder="如 68"></div><div class="form-group col-md-6"><label>固定分成人员（每行“姓名=比例%”）</label><textarea name="pool_fixed" rows="2" class="form-control" placeholder="姚鹏=13%&#10;李雪=13%"><?php echo e(implode("
", array_map(function ($m) { return ($m['name'] ?? '') . '=' . round((float)$m['share'] * 100, 4) . '%'; }, $form['params']['fixed'] ?? []))); ?></textarea></div><div class="col-12 small text-muted mb-2">池 = (范围内订单毛利合计 − 扣除额) × 提成比例；固定人员按比例分池，其余范围内人员分“成员分配”，按各自毛利占比。</div></div>
<div class="form-row" data-for="ranking"><div class="form-group col-md-6"><label>各名次奖金 ¥（逗号分隔）</label><input name="awards" class="form-control" value="<?php echo e(implode(',', $form['params']['awards'] ?? [])); ?>" placeholder="500,300,200"></div></div>
<div class="form-row" data-for="fixed per_unit base_fee attendance_bonus order_count"><div class="form-group col-md-3"><label id="monthlyAmountLabel">金额 ¥</label><input name="amount" type="number" step="0.01" min="0" class="form-control" value="<?php echo e($form['params']['amount'] ?? ''); ?>"></div><div class="form-group col-md-6 pt-4" data-for="fixed"><label class="mb-0"><input type="checkbox" name="separate" value="1" <?php echo !empty($form['params']['separate']) ? 'checked' : ''; ?>> 另行支付（如法人补助），单列展示、不计入应结算金额</label></div><div class="form-group col-md-6 pt-4" data-for="base_fee"><label class="mb-0"><input type="checkbox" name="no_prorate" value="1" <?php echo !empty($form['params']['no_prorate']) ? 'checked' : ''; ?>> 每月固定，不按考勤折算（如管理岗）</label></div></div>
<div data-for="tier_rate"><label>阶梯（按指标从低到高，落在哪档全部业绩按该档比例；底薪仅作提示，底薪在原系统结算）</label><div class="table-responsive"><table class="table table-sm mb-2" style="max-width:560px"><thead><tr><th>指标 ≥ ¥</th><th>比例 %</th><th>对应底薪（可空）</th></tr></thead><tbody><?php foreach ($tiers as $tier): ?><tr><td><input name="tier_from[]" type="number" step="0.01" min="0" class="form-control form-control-sm" value="<?php echo e($tier['from']); ?>"></td><td><input name="tier_rate[]" type="number" step="0.0001" min="0" class="form-control form-control-sm" value="<?php echo $tier['rate'] === '' ? '' : e((float)$tier['rate'] * 100); ?>"></td><td><input name="tier_base[]" class="form-control form-control-sm" value="<?php echo e($tier['base'] ?? ''); ?>"></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="form-row"><div class="form-group col-md-9"><label>说明</label><input name="note" maxlength="300" class="form-control" value="<?php echo e($form['note']); ?>"></div><div class="form-group col-md-3"><button class="btn btn-primary btn-block"><?php echo $editRule ? '保存修改' : '添加月度规则'; ?></button></div></div>
</form></div>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>规则</th><th>类型</th><th>范围</th><th>计算</th><th>生效</th><th></th></tr></thead><tbody>
<?php foreach ($monthlyRules as $rule): ?><tr class="<?php echo $rule['is_active'] ? '' : 'text-muted'; ?>"><td><strong><?php echo e($rule['name']); ?></strong><?php if ($rule['note'] !== ''): ?><div class="small text-muted"><?php echo e($rule['note']); ?></div><?php endif; ?></td><td class="small"><?php echo e($types[$rule['rule_type']] ?? $rule['rule_type']); ?></td>
<td class="small"><?php echo e($rule['scope_business'] === '*' ? '全部业务' : str_replace(',', '、', $rule['scope_business'])); ?> · <?php echo e($rule['scope_group'] === '*' ? '全部组' : ps_label('group', $rule['scope_group'])); ?><?php echo $rule['scope_role'] !== '*' ? ' · ' . e($rule['scope_role']) : ''; ?><?php echo $rule['employee_id'] ? '<div>' . e($employeeNames[(int)$rule['employee_id']] ?? ('#' . $rule['employee_id'])) . '</div>' : ''; ?></td>
<td class="small"><?php echo in_array($rule['rule_type'], ['tier_rate', 'threshold_bonus', 'ranking'], true) ? '按' . e($metrics[$rule['metric']] ?? '') . '：' : ''; ?><?php echo e($describe($rule)); ?></td>
<td class="small text-nowrap"><?php echo e($rule['effective_from']); ?> 起<?php echo $rule['effective_to'] ? '<br>至 ' . e($rule['effective_to']) : ''; ?><?php echo $rule['is_active'] ? '' : '<br>已停用'; ?></td>
<td class="text-nowrap"><a class="btn btn-outline-primary btn-sm" href="?month=<?php echo e($month); ?>&edit=<?php echo (int)$rule['id']; ?>#monthly">修改</a> <form method="post" class="d-inline"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="monthly_toggle"><input type="hidden" name="rule_id" value="<?php echo (int)$rule['id']; ?>"><input type="hidden" name="month" value="<?php echo e($month); ?>"><button class="btn btn-outline-secondary btn-sm"><?php echo $rule['is_active'] ? '停用' : '启用'; ?></button></form></td></tr><?php endforeach; ?>
<?php if (!$monthlyRules): ?><tr><td colspan="6" class="text-center text-muted py-4">尚无月度规则，可先“一键导入”部门核算表口径。</td></tr><?php endif; ?>
</tbody></table></div></div>

<div id="preview" class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px"><span>本月试算：月度规则结果<?php echo $monthLocked ? '（已锁定，显示冻结结果）' : '（实时）'; ?></span><form method="get" class="form-inline"><input type="month" name="month" class="form-control form-control-sm mr-2" value="<?php echo e($month); ?>"><button class="btn btn-sm btn-outline-primary">切换月份</button></form></div>
<?php if ($inputRules): ?><div class="card-body border-bottom">
<div class="project-mini-title"><?php echo e($month); ?> 填写项 <small>补单提成、排名名次、计件数量、部门其他费用、本月覆盖金额（如在家上班全勤 0）、手工调整</small></div>
<?php if ($inputRows): ?><div class="table-responsive mb-3"><table class="table table-sm mb-0"><thead><tr><th>规则</th><th>合作人员</th><th class="text-right">填写值</th><th>说明</th><th></th></tr></thead><tbody><?php foreach ($inputRows as $row): $ruleInfo = $inputRuleNames[(int)$row['rule_id']] ?? null; ?><tr><td><?php echo e($ruleInfo['name'] ?? ('#' . $row['rule_id'])); ?></td><td><?php echo (int)$row['employee_id'] === 0 ? '部门费用' : e($employeeNames[(int)$row['employee_id']] ?? ''); ?></td><td class="text-right"><?php echo e(rtrim(rtrim(money_plain($row['value']), '0'), '.')); ?><?php echo ($ruleInfo['rule_type'] ?? '') === 'ranking' ? ' 名' : (($ruleInfo['rule_type'] ?? '') === 'per_unit' ? ' 个' : ' 元'); ?></td><td class="small"><?php echo e($row['note']); ?></td><td><?php if (!$monthLocked): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="monthly_input_delete"><input type="hidden" name="month" value="<?php echo e($month); ?>"><input type="hidden" name="rule_id" value="<?php echo (int)$row['rule_id']; ?>"><input type="hidden" name="employee_id" value="<?php echo (int)$row['employee_id']; ?>"><button class="btn btn-outline-secondary btn-sm">删除</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if (!$monthLocked): ?><form method="post" class="form-row align-items-end"><input type="hidden" name="csrf" value="<?php echo $csrf; ?>"><input type="hidden" name="action" value="monthly_input_add"><input type="hidden" name="month" value="<?php echo e($month); ?>">
<div class="form-group col-md-4"><label>规则</label><select name="rule_id" class="form-control" required><option value="">选择要填写的规则</option><?php foreach ($inputRules as $rule): ?><option value="<?php echo (int)$rule['id']; ?>"><?php echo e($rule['name'] . ' · ' . ($rule['rule_type'] === 'ranking' ? '填名次' : ($rule['rule_type'] === 'per_unit' ? '填件数' : ($rule['rule_type'] === 'dept_share' ? '填部门其他费用' : ($rule['rule_type'] === 'manual' ? '填金额（可负）' : '填本月金额'))))); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-3"><label>合作人员</label><select name="employee_id" class="form-control"><option value="0">（部门费用 / 规则已指定人员）</option><?php foreach ($employees as $emp): ?><option value="<?php echo (int)$emp['id']; ?>"><?php echo e($emp['name'] . ' · ' . $emp['department']); ?></option><?php endforeach; ?></select></div>
<div class="form-group col-md-2"><label>数值</label><input name="value" type="number" step="0.01" class="form-control" required></div>
<div class="form-group col-md-2"><label>说明</label><input name="note" maxlength="200" class="form-control" placeholder="手工调整必填"></div>
<div class="form-group col-md-1"><button class="btn btn-primary btn-block">保存</button></div>
</form><?php else: ?><div class="small text-muted">本月已锁定，填写项不可修改。</div><?php endif; ?>
</div><?php endif; ?>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>合作人员</th><th>规则</th><th class="text-right">金额</th><th>计算过程</th></tr></thead><tbody>
<?php foreach ($resultsByEmployee as $employeeId => $items): $sum = array_sum(array_column($items, 'amount')); foreach ($items as $i => $item): ?><tr><?php if ($i === 0): ?><td rowspan="<?php echo count($items); ?>"><a href="<?php echo BASE_URL; ?>/project/payroll.php?month=<?php echo e($month); ?>&employee_id=<?php echo (int)$employeeId; ?>"><strong><?php echo e($employeeNames[$employeeId] ?? ('#' . $employeeId)); ?></strong></a><div class="small text-muted">合计 ¥<?php echo money($sum); ?></div></td><?php endif; ?><td><?php echo e($item['rule_name']); ?></td><td class="text-right font-weight-bold <?php echo (float)$item['amount'] < 0 ? 'text-danger' : ''; ?>">¥<?php echo money($item['amount']); ?></td><td class="small text-muted"><?php echo e($item['detail']); ?></td></tr><?php endforeach; endforeach; ?>
<?php if (!$results): ?><tr><td colspan="4" class="text-center text-muted py-4"><?php echo e($month); ?> 暂无月度规则结果（需要有已审核订单，或填写计件 / 固定补助规则）。</td></tr><?php endif; ?>
</tbody></table></div></div>
</div>
<script>
(function(){
  var kinds=<?php echo json_encode(array_map(function ($d) { return $d['order_kinds'] ?? []; }, ps_business_catalog()), JSON_UNESCAPED_UNICODE); ?>;
  var ruleBusiness=document.getElementById('ruleBusiness'),ruleKind=document.getElementById('ruleKind');
  function refreshKinds(){var list=kinds[ruleBusiness.value]||[];ruleKind.innerHTML='<option value="">全部</option>';list.forEach(function(k){var o=document.createElement('option');o.value=k;o.textContent=k;ruleKind.appendChild(o);});ruleKind.disabled=!list.length;}
  if(ruleBusiness){ruleBusiness.addEventListener('change',refreshKinds);refreshKinds();}
  var type=document.getElementById('monthlyType');
  function refreshType(){var t=type.value;document.querySelectorAll('#monthlyForm [data-for]').forEach(function(el){var show=el.getAttribute('data-for').split(' ').indexOf(t)!==-1;el.hidden=!show;el.querySelectorAll('input,select').forEach(function(i){i.disabled=!show;});});
    document.getElementById('monthlyAmountLabel').textContent=t==='per_unit'?'每件金额 ¥':(t==='base_fee'?'每月固定服务费 ¥（0 = 每月填写）':(t==='attendance_bonus'?'全勤奖 ¥':'每月金额 ¥'));
    document.getElementById('monthlyEmployeeLabel').textContent=(['fixed','dept_share','base_fee','attendance_bonus'].indexOf(t)!==-1)?'发给哪位合作人员（必选）':'指定合作人员（空 = 范围内所有人）';}
  if(type){type.addEventListener('change',refreshType);refreshType();}
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
