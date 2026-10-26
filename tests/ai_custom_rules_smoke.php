<?php
// AI网站定制 按订单类型的分成：AI定制 技术 15% + 每单 20（一人前后端）；博山定制 技术前后端统一 15%；AI定制 客服在现有基础上再加 20；华梦定制 客服成本按售价 80%。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/ai_custom_rules_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectExtraRules.php';
require_once __DIR__ . '/../tools/configure_ai_custom_rules.php';
$pdo = db(); $created = []; $temp = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$ins = function (array $d) use ($pdo, &$temp) { $c = array_keys($d); $pdo->prepare('INSERT INTO project_commission_rules (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($d)); $temp[] = (int)$pdo->lastInsertId(); };
try {
    pxr_ensure_column();
    $base = ['project_type' => 'AI网站定制', 'order_kind' => '*', 'effective_from' => '2026-07-01', 'is_active' => 1, 'is_extra' => 0, 'min_contract_amount' => 0, 'allow_negative' => 0];
    // 现行基准规则若测试库里没有就补临时的，结束后清理
    $has = function ($g, $role) use ($pdo) { $q = $pdo->prepare("SELECT 1 FROM project_commission_rules WHERE commission_group=? AND project_type='AI网站定制' AND role_name=? AND order_kind='*' AND is_extra=0 AND is_active=1 AND effective_from<='2026-10-01'"); $q->execute([$g, $role]); return (bool)$q->fetchColumn(); };
    if (!$has('technical', '前端')) $ins($base + ['commission_group' => 'technical', 'role_name' => '前端', 'calc_mode' => 'individual', 'rate' => 0.13, 'service_fee_rate' => 0.06, 'per_order_subsidy' => 0, 'note' => '测试前端']);
    if (!$has('technical', '后端')) $ins($base + ['commission_group' => 'technical', 'role_name' => '后端', 'calc_mode' => 'individual', 'rate' => 0.10, 'service_fee_rate' => 0.06, 'per_order_subsidy' => 0, 'note' => '测试后端']);
    if (!$has('customer_service', '*')) $ins($base + ['commission_group' => 'customer_service', 'role_name' => '*', 'calc_mode' => 'pool', 'rate' => 0.10, 'service_fee_rate' => 0.03, 'per_order_subsidy' => 20, 'min_cost_rate' => 0.65, 'effective_from' => '2026-10-01', 'note' => '测试客服']);
    [$new, $exist] = acr_plan(acr_definitions());
    $check(count($new) + count($exist) === 5, '规则定义 5 条（AI定制技术 / 博山前端 / 博山后端 / AI定制客服补贴 / 华梦客服）');
    $created = acr_apply($new);
    [$new2] = acr_plan(acr_definitions());
    $check(count($new2) === 0, '重复执行不会重复添加规则');
    // 计算：售价 1000、实际成本 100、服务费 6%（技术）→ (1000−100−60)×15% = 126 + 20
    $order = function ($kind) { return ['project_type' => 'AI网站定制', 'order_kind' => $kind, 'contract_amount' => 1000, 'receipt_amount' => 1000, 'refund_amount' => 0, 'order_date' => '2026-10-05', 'id' => 0]; };
    $costs = [['category' => 'outsourcing', 'item_name' => '外包', 'amount' => 100, 'review_status' => 'approved']];
    $people = function ($techRole) { return [['employee_id' => 1, 'commission_group' => 'technical', 'role_name' => $techRole, 'group_weight' => 1], ['employee_id' => 2, 'commission_group' => 'customer_service', 'role_name' => '客服', 'group_weight' => 1]]; };
    $calc = function ($kind, $techRole) use ($order, $costs, $people) { $s = ps_summary($order($kind), $costs, $people($techRole)); return [$s['groups']['technical']['people'][0]['calc'], $s['groups']['customer_service']['people'][0]['calc']]; };
    [$t1, $c1] = $calc('AI定制', '前端/后端');
    $check(abs($t1['share'] - 126.0) < 0.01 && abs($t1['subsidy'] - 20.0) < 0.01, 'AI定制 技术（前端/后端 一人）：(1000−100−60)×15% = 126，补助 20：' . json_encode([$t1['share'], $t1['subsidy']]));
    [$t1b] = $calc('AI定制', '前端');
    $check(abs($t1b['share'] - 126.0) < 0.01 && abs($t1b['subsidy'] - 20.0) < 0.01, 'AI定制 技术（只写前端）结果相同，不会叠成 25%');
    [$t2, $c2] = $calc('博山定制', '前端');
    [$t2b] = $calc('博山定制', '后端');
    $check(abs($t2['share'] - 126.0) < 0.01 && abs($t2b['share'] - 126.0) < 0.01 && abs($t2['subsidy']) < 0.01, '博山定制 技术前端、后端统一 15%：(1000−100−60)×15% = 126，无补助：' . json_encode([$t2['share'], $t2b['share']]));
    $check(abs($c1['subsidy'] - ($c2['subsidy'] + 20.0)) < 0.01, 'AI定制 客服补助 = 原单量补助 + 20（叠加）：' . json_encode([$c1['subsidy'], $c2['subsidy']]));
    [$t3, $c3] = $calc('华梦定制', '前端');
    $check(abs($c3['cost_basis'] - 800.0) < 0.01, '华梦定制 客服成本按售价 80% = 800（实际成本 100）：' . $c3['cost_basis']);
    $check(abs($c2['cost_basis'] - max(100, 650)) < 0.01, '博山定制 客服仍按现有规则（成本下限）：' . $c2['cost_basis']);
    $q = $pdo->prepare("SELECT COUNT(*) FROM project_commission_rules WHERE project_type='AI网站定制' AND effective_from='2026-10-01' AND order_kind IN ('AI定制','华梦定制','博山定制') AND id IN (" . implode(',', array_merge($created, array_column($exist, 1))) . ')'); $q->execute();
    $cntRules = (int)$q->fetchColumn(); $check($cntRules === 5, '新规则只在 2026-10-01 起生效：' . $cntRules . ' 条 ' . json_encode(array_merge($created, array_column($exist, 1))));
} finally {
    $all = array_merge($created, $temp);
    if ($all) { $pdo->exec('DELETE FROM project_commission_rules WHERE id IN (' . implode(',', $all) . ')'); $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='commission_rule' AND entity_id IN (" . implode(',', $all) . ')'); }
}
echo "全部通过，测试数据已清理\n";
