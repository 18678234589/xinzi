<?php
// 仅限本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/software_tester_join_smoke.php
if (PHP_SAPI !== 'cli' || getenv('DB_NAME') !== 'xinzi_test' || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
$pdo = db();
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; };
$run = function ($file, array $post, array $actor, $orderId) use ($pdo) {
    $_POST = $post; $id = $orderId; $finance = false;
    $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $q->execute([$orderId]); $order = $q->fetch();
    try { include __DIR__ . '/../project/order/actions/participants/' . $file; return null; } catch (RuntimeException $e) { return $e->getMessage(); }
};
$ids = []; $ruleIds = [];
try {
    foreach (['tester', 'cs', 'other'] as $label) {
        $pdo->prepare('INSERT INTO employees (name,department,password) VALUES (?,?,?)')->execute(['jointest_' . $label . bin2hex(random_bytes(4)), '软件测试QA', bin2hex(random_bytes(16))]);
        $ids[$label] = (int)$pdo->lastInsertId();
    }
    foreach (['AI网站定制', '小程序开发', '网站模板'] as $business) {
        $pdo->prepare("INSERT INTO project_commission_rules (commission_group,project_type,role_name,order_kind,calc_mode,rate,service_fee_rate,per_order_subsidy,min_cost_rate,allow_negative,effective_from,is_extra) VALUES ('technical',?,'软件测试','*','individual',0.05,0,0,0,1,'2026-10-01',0)")->execute([$business]);
        $ruleIds[] = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO project_employee_roles (employee_id,business_name,commission_group,role_name) VALUES (?,'*','technical','软件测试')")->execute([$ids['tester']]);
    $mk = function ($business) use ($pdo) {
        $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status) VALUES (?,'测试',?,'测试',1000,1000,'2026-10-02','unfinished','draft')")->execute(['JOINTEST-' . bin2hex(random_bytes(5)), $business]);
        return (int)$pdo->lastInsertId();
    };
    $site = $mk('AI网站定制'); $tpl = $mk('网站模板');
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$site, $ids['cs']]);
    $actorOf = function ($key, $role) use ($ids) { return ['type' => 'employee', 'id' => 0, 'employee_id' => $ids[$key], 'role' => $role]; };
    $parts = function ($oid) use ($pdo) { $q = $pdo->prepare('SELECT employee_id,role_name,group_weight FROM project_participants WHERE order_id=? ORDER BY id'); $q->execute([$oid]); return $q->fetchAll(); };

    $check(ps_software_tester_can_join($ids['tester']) && !ps_software_tester_can_join($ids['cs']), '仅开通软件测试的人可自行加入');
    $check(ps_software_testing_pick_business('小程序开发') && ps_software_testing_pick_business('AI网站定制') && !ps_software_testing_pick_business('网站模板'), '只开放 AI网站定制 / 小程序开发');
    $o = ps_order($site, $actorOf('tester', 'customer_service'));
    $check((int)$o['id'] === $site, '测试员无需参与也能打开 AI网站定制 订单');

    $check($run('add_software_tester.php', ['employee_id' => $ids['tester']], $actorOf('other', 'technical'), $site) === '无权限添加软件测试员', '无关人员不能给订单加测试员');
    $check($run('add_software_tester.php', ['employee_id' => $ids['tester']], $actorOf('tester', 'customer_service'), $tpl) === '此业务暂不支持添加软件测试员', '其他业务不开放');
    $check($run('add_software_tester.php', ['employee_id' => $ids['cs']], $actorOf('cs', 'customer_service'), $site) === '该人员未开通本业务的软件测试角色', '不能把未开通的人加为测试员');
    $check($run('add_software_tester.php', ['employee_id' => $ids['tester']], $actorOf('cs', 'customer_service'), $site) === null, '本单客服可添加测试员');
    $p = $parts($site);
    $check(count($p) === 2 && $p[1]['role_name'] === '软件测试' && (float)$p[1]['group_weight'] === 0.0, '测试员以 0 权重加入，不占组池');
    $check($run('add_software_tester.php', ['employee_id' => $ids['tester']], $actorOf('tester', 'customer_service'), $site) === null && count($parts($site)) === 2, '重复添加不产生重复记录');

    $site2 = $mk('小程序开发');
    $check($run('add_software_tester.php', ['employee_id' => $ids['tester']], $actorOf('tester', 'customer_service'), $site2) === null && $parts($site2)[0]['role_name'] === '软件测试', '测试员搜到小程序开发订单后可自行加入');
} finally {
    // 测试数据（DDL 可能已隐式提交，因此显式清理）
    foreach ($ids as $e) { $pdo->prepare('DELETE FROM project_participants WHERE employee_id=?')->execute([$e]); $pdo->prepare('DELETE FROM project_employee_roles WHERE employee_id=?')->execute([$e]); }
    $pdo->exec("DELETE FROM project_orders WHERE order_no LIKE 'JOINTEST-%'");
    if ($ruleIds) $pdo->exec('DELETE FROM project_commission_rules WHERE id IN (' . implode(',', $ruleIds) . ')');
    if ($ids) $pdo->exec('DELETE FROM employees WHERE id IN (' . implode(',', $ids) . ')');
}
echo "完成，测试数据已清理\n";
