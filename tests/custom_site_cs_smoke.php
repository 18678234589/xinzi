<?php
// AI网站定制客服：每单补助 10 元（2026-09 起订单）、每月第一名奖 500（2026-10 起为综合考评、人工确认名次）。事务内执行，结束回滚。php tests/custom_site_cs_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';

$pdo = db();
$pdo->beginTransaction();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
try {
    echo "=== 每单补助 ===\n";
    $aug = ps_rule_for('customer_service', 'AI网站定制', '2026-08-20');
    $sep = ps_rule_for('customer_service', 'AI网站定制', '2026-09-10');
    $check((float)$aug['per_order_subsidy'] === 0.0 && (float)$sep['per_order_subsidy'] === 10.0, '8 月订单无补助，9 月起每单 10 元');
    $check((float)$sep['rate'] === 0.1 && (float)$sep['service_fee_rate'] === 0.03 && (float)$sep['min_cost_rate'] === 0.65, '比例 10%、服务费 3%、成本下限 65% 不变');

    echo "=== 月度第一名 500 ===\n";
    $finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    $month = '2027-02';
    $orders = [['董旭', 10000, 3000], ['宋倩倩', 6000, 1000]];
    foreach ($orders as $i => [$name, $price, $cost]) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,receipt_amount,order_date,delivery_status,note) VALUES (?,'测试','AI网站定制','测试店',?,?,'2027-02-05','finished','测试')")->execute(['CSTEST-' . $i, $price, $price]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$id]);
        $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status) VALUES (?,'outsourcing','外包',1,'项',?,?,'one_time',1,'测试','approved')")->execute([$id, $cost, $cost]);
        $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $E($name)]);
        ps_approve_order($id, $finance, $month);
    }
    $snap = $pdo->prepare("SELECT subsidy_amount,commission_amount FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE o.order_no='CSTEST-0'");
    $snap->execute();
    $row = $snap->fetch();
    // 董旭：成本取 max(实际 3000, 售价 × 65% = 6500) = 6500；(10000 − 6500 − 300) × 10% = 320 + 每单补助
    // 每单补助随规则变化（2026-09 起 10 元，2026-10-01 起 20 元）：按订单日期当天生效的规则取
    $sub = (float)ps_rule_for('customer_service', 'AI网站定制', '2027-02-05')['per_order_subsidy'];
    $check($sub > 0 && (float)$row['subsidy_amount'] === $sub && abs((float)$row['commission_amount'] - (320.0 + $sub)) < 0.01, '订单分成 = (10000 − 6500 − 300) × 10% + 每单补助 ' . $sub . ' = ' . (320.0 + $sub) . '（实际 ' . $row['commission_amount'] . '）');
    $awards = array_values(array_filter(ps_monthly_results($month, true), function ($r) { return $r['rule_name'] === '定制客服月度第一名奖'; }));
    // 2026-10 起第一名奖改为“单量 / 收入 / 利润综合考评”，名次由财务 / 主管人工确认（metric=manual，见 ProjectWebsiteRewards.php）：未确认名次前不自动发 500
    $rule = $pdo->query("SELECT metric FROM project_monthly_rules WHERE name='定制客服月度第一名奖' AND scope_business='AI网站定制' AND is_active=1 ORDER BY effective_from DESC,id DESC LIMIT 1")->fetchColumn();
    $check($rule === 'manual' && count($awards) === 0, '第一名奖为人工确认名次：未确认前不自动发放（规则 metric=' . $rule . '，结果 ' . count($awards) . ' 条）');

    $pdo->rollBack();
    echo "\n=== 定制客服补助与第一名奖全部通过，测试数据已回滚 ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
