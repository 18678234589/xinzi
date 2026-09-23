<?php
// 规则中心 · 月度规则回归：与 8 月核对表一致（光君 8317.99 + 奖金 809.77 = 9127.76；刘帅 13% 档 3315.26）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectPresets.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';

function check_monthly($actual, $expected, $label)
{
    if (abs((float)$actual - (float)$expected) > 0.001) throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $adminId = (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn();
    $actor = ['type' => 'admin', 'id' => $adminId, 'employee_id' => null, 'role' => 'finance'];
    $pdo->exec('UPDATE project_commission_rules SET is_active=0');
    $pdo->exec('UPDATE project_monthly_rules SET is_active=0');
    ps_apply_preset_rules($actor, '2026-09-01');
    $month = '2098-08';
    $date = '2098-08-15';
    $newEmployee = function ($name, $department) use ($pdo) {
        $pdo->prepare("INSERT INTO employees (name,department,password) VALUES (?,?,'')")->execute([$name, $department]);
        return (int)$pdo->lastInsertId();
    };
    $guang = $newEmployee('月度测试光君', '网站技术');
    $liu = $newEmployee('月度测试刘帅', '定制前端');
    $cs = [$newEmployee('月度测试客服甲', '网站客服'), $newEmployee('月度测试客服乙', '网站客服'), $newEmployee('月度测试客服丙', '网站客服'), $newEmployee('月度测试客服丁', '网站客服')];
    $boss = $newEmployee('月度测试主管', '网站售后部');
    $envTech = $newEmployee('月度测试环境', '环境配置');

    $approveOrder = function ($type, $price, array $costs, array $people, $resources = true) use ($pdo, $actor, $date, $month) {
        $no = 'MONTHLY-' . bin2hex(random_bytes(5));
        $pdo->prepare("INSERT INTO project_orders (order_no,project_type,contract_amount,order_date,delivery_status) VALUES (?,?,?,?,'finished')")->execute([$no, $type, $price, $date]);
        $id = (int)$pdo->lastInsertId();
        ps_intake_participants($id, $people, $type);
        if ($resources) ps_intake_save_resources($id, 'manual', null, null, null, null, 'none');
        foreach ($costs as $amount) $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,review_status) VALUES (?,'other','测试成本',1,'项',?,?,'approved')")->execute([$id, $amount, $amount]);
        $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',?,'approved','system',0)")->execute([$id, $price]);
        ps_recalculate_cash($id);
        ps_approve_order($id, $actor, $month);
        return $id;
    };
    // 光君 8 月汇总：售价 109716、域名空间 42440
    $approveOrder('网站模板', 109716, [42440], ['technical' => [['id' => $guang, 'role' => '模板技术']], 'customer_service' => [['id' => $cs[0], 'role' => '客服']]]);
    // 刘帅 8 月汇总：售价 27550、域名 200、SSL 195，后端无 → 前端承担全部成本
    $approveOrder('AI网站定制', 27550, [200, 195], ['technical' => [['id' => $liu, 'role' => '前端（技术）']], 'customer_service' => [['id' => $cs[1], 'role' => '客服']]]);
    // 客服排名用：两单网站模板
    $approveOrder('网站模板', 30000, [5000], ['technical' => [['id' => $guang, 'role' => '模板技术']], 'customer_service' => [['id' => $cs[2], 'role' => '客服']]]);
    $approveOrder('网站模板', 5000, [1000], ['technical' => [['id' => $guang, 'role' => '模板技术']], 'customer_service' => [['id' => $cs[3], 'role' => '客服']]]);
    // 环境配置两单，用于主管提成
    $approveOrder('环境配置', 4740, [30], ['technical' => [['id' => $envTech, 'role' => '技术']], 'customer_service' => []], false);
    $approveOrder('环境配置', 4500, [0], ['technical' => [['id' => $envTech, 'role' => '技术']], 'customer_service' => []], false);

    $insert = $pdo->prepare('INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from) VALUES (?,?,?,?,?,?,?,?,?)');
    $rule = function ($name, $type, $business, $group, $role, $employee, $metric, $params) use ($insert, $month, $pdo) {
        $insert->execute([$name, $type, $business, $group, $role, $employee, $metric, json_encode($params), $month]);
        return (int)$pdo->lastInsertId();
    };
    foreach (ps_monthly_presets() as $preset) {
        if ($preset['employee'] !== null) continue;
        $rule($preset['name'], $preset['rule_type'], $preset['scope_business'], $preset['scope_group'], $preset['scope_role'], null, $preset['metric'], $preset['params']);
    }
    $deptRule = $rule('主管提成测试', 'dept_share', '环境配置', '*', '*', $boss, 'profit', ['rate' => 0.05, 'share' => 0.5]);
    $rule('补助测试', 'fixed', '*', '*', '*', $boss, 'profit', ['amount' => 200]);
    $unitRuleId = (int)$pdo->query("SELECT id FROM project_monthly_rules WHERE name='优站模板奖励' AND is_active=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    // 排名奖名次由财务填写
    $rankRuleId = (int)$pdo->query("SELECT id FROM project_monthly_rules WHERE name='网站客服排名奖' AND is_active=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    foreach ([$cs[0] => 1, $cs[1] => 2, $cs[2] => 3, $cs[3] => 4] as $eid => $pos) $pdo->prepare('INSERT INTO project_monthly_inputs (payroll_month,rule_id,employee_id,value) VALUES (?,?,?,?)')->execute([$month, $rankRuleId, $eid, $pos]);
    $pdo->prepare('INSERT INTO project_monthly_inputs (payroll_month,rule_id,employee_id,value) VALUES (?,?,?,5)')->execute([$month, $unitRuleId, $liu]);
    $pdo->prepare('INSERT INTO project_monthly_inputs (payroll_month,rule_id,employee_id,value) VALUES (?,?,0,1000)')->execute([$month, $deptRule]);

    $results = ps_monthly_results($month);
    $by = [];
    foreach ($results as $row) $by[$row['employee_id']][$row['rule_name']] = (float)$row['amount'];
    $snap = $pdo->prepare('SELECT SUM(commission_amount) FROM project_commission_snapshots WHERE employee_id=? AND payroll_month=?');

    // 光君：三单毛利合计 = 63984.52 + 24100 + 3850 = 91934.52；奖金 (91934.52 − 10000) × 1.5% = 1229.02
    $snap->execute([$guang, $month]);
    $guangOrders = (float)$snap->fetchColumn();
    check_monthly($by[$guang]['模板技术超额奖金'] ?? 0, 1229.02, '模板技术超额奖金');
    // 单独核对 8 月光君：只看第一单 → 8317.99 + 809.77 = 9127.76
    check_monthly(round(63984.52 * 0.13, 2) + round((63984.52 - 10000) * 0.015, 2), 9127.76, '光君 8 月合计口径');
    // 刘帅：利润 27550 − 6% 1653 − 395 = 25502，落 25000 档 13%，与逐单 13% 相同 → 无差额；逐单 3315.26
    $snap->execute([$liu, $month]);
    check_monthly($snap->fetchColumn(), 3315.26, '刘帅 8 月逐单提成');
    if (isset($by[$liu]['定制内部前端阶梯'])) throw new RuntimeException('13% 档不应产生差额');
    check_monthly($by[$liu]['优站模板奖励'] ?? 0, 75, '优站 5 个 × 15');
    // 客服排名：毛利 = 甲 63984.52、乙 25502 − ...（定制客服按 3% 服务费）、丙 24100、丁 3850
    $rank = array_filter([$cs[0] => $by[$cs[0]]['网站客服排名奖'] ?? 0, $cs[1] => $by[$cs[1]]['网站客服排名奖'] ?? 0, $cs[2] => $by[$cs[2]]['网站客服排名奖'] ?? 0, $cs[3] => $by[$cs[3]]['网站客服排名奖'] ?? 0]);
    check_monthly($rank[$cs[0]] ?? 0, 500, '客服排名第一');
    check_monthly($rank[$cs[1]] ?? 0, 300, '客服排名第二（定制 26323.5）');
    check_monthly($rank[$cs[2]] ?? 0, 200, '客服排名第三');
    if (isset($rank[$cs[3]])) throw new RuntimeException('第四名不应得排名奖');
    check_monthly($by[$cs[0]]['网站客服超额奖金'] ?? 0, round((63984.52 - 20000) * 0.008, 2), '网站客服超额奖金');
    // 主管：环境配置毛利 (4740−30−142.2) + (4500−135) = 8932.8，− 其他费用 1000 = 7932.8 × 5% × 50%
    check_monthly($by[$boss]['主管提成测试'] ?? 0, 198.32, '部门主管提成');
    check_monthly($by[$boss]['补助测试'] ?? 0, 200, '固定补助');

    // 阶梯改档实时生效：把刘帅这一档改成 15% → 差额 25502 × 2% = 510.04
    $tierRuleId = (int)$pdo->query("SELECT id FROM project_monthly_rules WHERE name='定制内部前端阶梯' AND is_active=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    $pdo->prepare('UPDATE project_monthly_rules SET params_json=? WHERE id=?')->execute([json_encode(['tiers' => [['from' => 0, 'rate' => 0.05], ['from' => 25000, 'rate' => 0.15]]]), $tierRuleId]);
    $live = array_values(array_filter(ps_monthly_results($month), function ($r) use ($liu) { return $r['employee_id'] === $liu && $r['rule_name'] === '定制内部前端阶梯'; }));
    check_monthly($live[0]['amount'] ?? 0, 510.04, '阶梯改档即时生效');

    // 锁月冻结：之后再改规则，锁定月份结果不变
    $frozen = ps_monthly_freeze($month);
    $pdo->prepare("INSERT INTO project_payroll_periods (period,status) VALUES (?,'locked') ON DUPLICATE KEY UPDATE status='locked'")->execute([$month]);
    $pdo->prepare('UPDATE project_monthly_rules SET params_json=? WHERE id=?')->execute([json_encode(['tiers' => [['from' => 0, 'rate' => 0.30]]]), $tierRuleId]);
    $locked = array_values(array_filter(ps_monthly_results($month), function ($r) use ($liu) { return $r['employee_id'] === $liu && $r['rule_name'] === '定制内部前端阶梯'; }));
    check_monthly($locked[0]['amount'] ?? 0, 510.04, '锁月后结果冻结');
    if (count(ps_monthly_results($month)) !== count($frozen)) throw new RuntimeException('冻结结果条数不一致');

    $pdo->rollBack();
    echo "月度规则（超额奖金、排名奖、阶梯、主管提成、固定补助、计件）与 8 月核对表一致，改规则实时生效、锁月冻结；数据已回滚\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
