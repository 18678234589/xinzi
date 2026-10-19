<?php
// 按客户旺旺去重的单量补助：同旺旺多单只算 1 单；已有每单补助的旺旺不重复；全额退款不计；部分退款 / 旺旺很像的先不计，等财务标记。
// 会写入测试数据，只允许专用测试库：DB_NAME=xinzi_sandbox DB_HOST=127.0.0.1 DB_PORT=13399 php tests/unique_customer_rule_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectUniqueCustomer.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = (int)$pdo->query("SELECT id FROM employees WHERE name='阎泸琪'")->fetchColumn();
$ids = [];
$mk = function ($suffix, $nick, $refund = 0, $contract = 10) use ($pdo, $tag, &$ids) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,refund_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','设计','PPT','内部',?,?,'2026-09-12','finished','approved','uc-test')")->execute(["UC$tag$suffix", $contract, $refund]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare('INSERT INTO project_order_sources (order_id,payment_nickname) VALUES (?,?)')->execute([$id, $nick]);
    return $id;
};
try {
    $check($emp > 0, '测试员工存在');
    $a1 = $mk('A1', 'alpha 甲甲'); $a2 = $mk('A2', 'alpha甲甲'); $a3 = $mk('A3', 'ALPHA甲甲');
    $b = $mk('B', 'Kehu乙乙'); $b2 = $mk('B2', 'kehu乙乙'); $c = $mk('C', 'zz丙丙丙', 10); $d = $mk('D', 'omega丁丁丁');
    $snap = function ($oid, $subsidy = 0, $eid = null) use ($emp) { return ['order_id' => $oid, 'employee_id' => $eid ?? $emp, 'project_type' => '设计', 'commission_group' => 'customer_service', 'role_name' => '设计客服', 'subsidy_amount' => $subsidy, 'commission_exact' => null, 'direct_cost' => 0, 'income_amount' => 10, 'contribution_profit' => 10, 'commission_amount' => 0, 'group_weight' => 1, 'calc_mode' => 'pool', 'order_kind' => 'PPT']; };
    $rule = ['id' => 999991, 'name' => '测试旺旺去重', 'rule_type' => 'unique_customer', 'scope_business' => '设计', 'scope_group' => 'customer_service', 'scope_role' => '*', 'employee_id' => $emp, 'metric' => 'profit', 'params' => ['amount' => 0.5]];
    $run = function (array $snaps) use ($rule) {
        $rows = ps_monthly_results('2026-09', true, ['forecast' => true, 'rules' => [$rule], 'snapshots' => $snaps, 'inputs' => [], 'attendance' => []]);
        return array_values(array_filter($rows, function ($r) { return $r['rule_type'] === 'unique_customer'; }));
    };
    $r = $run([$snap($a1), $snap($a2), $snap($a3), $snap($b), $snap($b2), $snap($c), $snap($d)]);
    $check(count($r) === 1 && abs($r[0]['amount'] - 1.5) < 0.001, '甲×3、乙×2（大小写/空格不同）、丙全额退款、丁×1 → 甲 乙 丁 共 3 单 × 0.5 = 1.5：' . ($r ? $r[0]['amount'] : '无'));
    $r = $run([$snap($a1, 0.5), $snap($a2), $snap($b), $snap($d)]);
    $check(abs($r[0]['amount'] - 1.0) < 0.001, '甲已由逐单规则发过 0.5：只补乙、丁 = 1.0：' . $r[0]['amount']);
    $r = $run([$snap($a1, 0, $emp + 1000), $snap($b)]);
    $check(abs($r[0]['amount'] - 0.5) < 0.001, '别人的订单不计入本人');
    $e = $mk('E', 'omega丁丁丁丁'); $f = $mk('F', 'qq戊戊戊', 5, 20);
    $r = $run([$snap($a1), $snap($d), $snap($e), $snap($f)]);
    $check(abs($r[0]['amount'] - 1.0) < 0.001 && strpos($r[0]['detail'], '2 个旺旺待财务核对') !== false, '丁丁（和丁很像）、戊（部分退款）先不计，提醒财务：' . $r[0]['amount']);
    $ins = $pdo->prepare("INSERT INTO project_unique_customer_checks (rule_id,month,employee_id,nick_key,nick_text,reason,status) VALUES (999991,'2026-09',?,?,?,'t',?)");
    puc_ensure();
    $ins->execute([$emp, puc_key('omega丁丁丁丁'), 'omega丁丁丁丁', 'pay']); $ins->execute([$emp, puc_key('qq戊戊戊'), 'qq戊戊戊', 'skip']);
    $r = $run([$snap($a1), $snap($d), $snap($e), $snap($f)]);
    $check(abs($r[0]['amount'] - 1.5) < 0.001, '财务标记丁丁“可以结算”、戊“无需结算”：甲 丁 丁丁 = 1.5：' . $r[0]['amount']);
    echo "全部通过\n";
} finally {
    try { $pdo->exec('DELETE FROM project_unique_customer_checks WHERE rule_id=999991'); } catch (Throwable $x) {}
    foreach ($ids as $id) { $pdo->exec("DELETE FROM project_order_sources WHERE order_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
}
