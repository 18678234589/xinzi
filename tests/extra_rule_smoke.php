<?php
// 补贴规则（叠加）：备案-提成 = 提成规则（全部类型 20%）+ 补贴规则（拍链接每单 0.5）。提成和补贴分开配置，补贴不参与提成规则的竞争。
// 会写入测试数据（含给规则表加 is_extra 列），只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/extra_rule_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectExtraRules.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
pxr_ensure_column();
$fang = (int)$pdo->query("SELECT id FROM employees WHERE name='房烁'")->fetchColumn();
$saved = $pdo->query("SELECT id,is_active FROM project_commission_rules WHERE project_type='备案-提成'")->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec("UPDATE project_commission_rules SET is_active=0 WHERE project_type='备案-提成'");
$ruleIds = []; $orderIds = [];
$addRule = function ($kind, $rate, $subsidy, $extra, $note) use ($pdo, &$ruleIds) {
    $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,per_order_subsidy,note,effective_from,is_active,is_extra) VALUES ('customer_service','备案-提成','*',?,'pool',?,?,?,'2026-09-01',1,?)")->execute([$kind, $rate, $subsidy, $note, $extra]);
    return $ruleIds[] = (int)$pdo->lastInsertId();
};
$summary = function ($kind, $date) use ($pdo, $tag, $fang, &$orderIds) {
    static $n = 0; $n++;
    $no = "EX$tag$n";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','备案-提成',?,'美呀美',100,?,'unfinished','draft','测试')")->execute([$no, $kind, $date]);
    $id = (int)$pdo->lastInsertId(); $orderIds[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $fang]);
    $order = $pdo->query("SELECT * FROM project_orders WHERE id=$id")->fetch(); $order['receipt_amount'] = 100; $order['refund_amount'] = 0;
    $sum = ps_summary($order, [], ps_participants($id));
    return $sum['groups']['customer_service'];
};
// 同一进程里规则匹配按“业务+日期”缓存，每个场景换一个订单日期，等于重新读规则。
try {
    $mainId = $addRule('*', 0.2, 0, 0, '提成：(售价−成本−服务费)×20%');
    $g = $summary('拍链接', '2026-09-21'); $p = $g['people'][0];
    $check(!$g['missing_rule'] && abs($p['estimated_calc']['share'] - 20.0) < 0.001 && $p['estimated_calc']['subsidy'] == 0.0, '只有提成规则：拍链接订单提成 20，没有补贴');
    $extraId = $addRule('拍链接', 0, 0.5, 1, '拍建站链接每单补贴 0.5');
    $g = $summary('拍链接', '2026-09-22'); $p = $g['people'][0];
    $check(abs($p['estimated_calc']['share'] - 20.0) < 0.001 && abs($p['estimated_calc']['subsidy'] - 0.5) < 0.001, '加上补贴规则：拍链接订单 提成 20 + 补贴 0.5');
    $check(abs($p['calc']['subsidy'] - 0.5) < 0.001 && strpos($p['estimated_calc']['note'], '补贴 0.50') !== false, '核算口径和预估口径都带补贴，计算说明里写明补贴');
    $check($g['subsidy_rule'] === 1 && $p['rule']['id'] == $mainId, '订单标记为有补贴规则，提成规则仍是主规则（补贴规则不参与竞争）');
    $g = $summary('备案', '2026-09-22'); $p = $g['people'][0];
    $check(abs($p['estimated_calc']['share'] - 20.0) < 0.001 && $p['estimated_calc']['subsidy'] == 0.0, '备案类型订单：只有提成，没有补贴');
    $pdo->exec("UPDATE project_commission_rules SET is_active=0 WHERE id=$extraId");
    $g = $summary('拍链接', '2026-09-23'); $p = $g['people'][0];
    $check(abs($p['estimated_calc']['share'] - 20.0) < 0.001 && $p['estimated_calc']['subsidy'] == 0.0, '补贴规则停用后：拍链接订单只剩提成');
    $pdo->exec("UPDATE project_commission_rules SET is_active=1 WHERE id=$extraId");
    $pdo->exec("UPDATE project_commission_rules SET is_active=0 WHERE id=$mainId");
    $g = $summary('拍链接', '2026-09-24');
    $check($g['missing_rule'] === true, '只有补贴规则、没有提成规则：订单仍是“待配置”（补贴规则不能代替提成规则）');
    echo "全部通过
";
} finally {
    foreach ($orderIds as $id) { $pdo->exec("DELETE FROM project_participants WHERE order_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    foreach ($ruleIds as $id) $pdo->exec("DELETE FROM project_commission_rules WHERE id=$id");
    foreach ($saved as $id => $act) $pdo->prepare('UPDATE project_commission_rules SET is_active=? WHERE id=?')->execute([$act, $id]);
}
