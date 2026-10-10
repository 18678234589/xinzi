<?php
// 订单列表“待配置 · 去对应”：参与人 / 主管 / 财务各自能做什么、改订单类型 / 岗位 / 启用停用规则、已审核订单不让改。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/rule_link_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectRuleLink.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$emp = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=?'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$fang = $emp('房烁'); $yu = $emp('于洋'); $other = $emp('宋倩倩');
$actorOf = function ($id, $role = 'customer_service') { return ['type' => 'employee', 'id' => 0, 'employee_id' => $id, 'role' => $role]; };
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance', 'employee_id' => 0];
$mk = function ($suffix, $status) use ($pdo, $tag, $fang) {
    $no = "RL$tag$suffix";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','备案-提成','拍链接','美呀美',100,'2026-09-23','unfinished',?,'测试')")->execute([$no, $status]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $fang]);
    return $id;
};
$rules = $pdo->query("SELECT id,is_active,order_kind FROM project_commission_rules WHERE project_type='备案-提成' AND commission_group='customer_service'")->fetchAll();
$byKind = []; foreach ($rules as $r) $byKind[$r['order_kind']] = $r;
$orig = []; foreach ($rules as $r) $orig[$r['id']] = (int)$r['is_active'];
$ids = [];
try {
    $check(isset($byKind['备案']) && isset($byKind['拍链接']), '规则中心里有备案-提成的“备案”“拍链接”两条规则');
    $off = (int)$byKind['拍链接']['id']; $on = (int)$byKind['备案']['id'];
    $pdo->prepare('UPDATE project_commission_rules SET is_active=0 WHERE id=?')->execute([$off]);
    $pdo->prepare('UPDATE project_commission_rules SET is_active=1 WHERE id=?')->execute([$on]);

    $o1 = $mk('A', 'draft'); $ids[] = $o1;
    $check(!ps_rules_for_person('customer_service', '备案-提成', '2026-09-23', '客服', '拍链接'), '拍链接规则停用后，这单没有有效规则（列表显示待配置）');
    $info = prl_info($o1, $actorOf($fang));
    $check(count($info['people']) === 1 && $info['can_edit'] && !$info['can_enable'], '参与人房烁：能改订单、不能启用规则');
    $cand = []; foreach ($info['people'][0]['rules'] as $c) $cand[$c['rule_id']] = $c;
    $check(isset($cand[$on]) && $cand[$on]['active'] && strpos($cand[$on]['action'], '订单类型改为「备案」') !== false, '候选里有启用中的“备案”规则：对应动作是把订单类型改为备案');
    $check(isset($cand[$off]) && !$cand[$off]['active'] && $cand[$off]['needs_enable'] && $cand[$off]['action'] === '启用这条规则', '候选里有已停用的“拍链接”规则：需要启用');

    // 参与人选启用中的规则：改订单类型，分成立刻匹配上
    $msg = prl_apply($o1, $on, $fang, $actorOf($fang));
    $kind = $pdo->query("SELECT order_kind FROM project_orders WHERE id=$o1")->fetchColumn();
    $check($kind === '备案' && strpos($msg, '备案') !== false, '参与人对应启用中的规则：订单类型改为备案（' . $msg . '）');
    $check((bool)ps_rules_for_person('customer_service', '备案-提成', '2026-09-23', '客服', $kind), '改完后规则已匹配');
    $check(prl_info($o1, $actorOf($fang))['people'] === [], '改完后不再有待配置的人');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='order' AND entity_id=$o1 AND action='rule_link_kind'")->fetchColumn() === 1, '改动记了审计');

    // 参与人不能启用停用的规则
    $pdo->prepare("UPDATE project_orders SET order_kind='拍链接' WHERE id=?")->execute([$o1]);
    $threw = false; try { prl_apply($o1, $off, $fang, $actorOf($fang)); } catch (RuntimeException $e) { $threw = strpos($e->getMessage(), '启用') !== false; }
    $check($threw && (int)$pdo->query("SELECT is_active FROM project_commission_rules WHERE id=$off")->fetchColumn() === 0, '参与人不能启用已停用的规则');

    // 主管（于洋，网站售后部）可以启用，订单上的人是同部门的房烁
    $info = prl_info($o1, $actorOf($yu));
    $check($info['can_edit'] && $info['can_enable'], '同部门主管于洋：能改订单、能启用规则');
    prl_apply($o1, $off, $fang, $actorOf($yu));
    $check((int)$pdo->query("SELECT is_active FROM project_commission_rules WHERE id=$off")->fetchColumn() === 1, '主管启用后，规则对同业务全部拍链接订单生效（规则匹配在同一请求内有缓存，这里直接看规则状态）');

    // 财务也可以
    $pdo->prepare('UPDATE project_commission_rules SET is_active=0 WHERE id=?')->execute([$off]);
    prl_apply($o1, $off, $fang, $finance);
    $check((int)$pdo->query("SELECT is_active FROM project_commission_rules WHERE id=$off")->fetchColumn() === 1, '财务可以启用规则');

    // 无关的人不能动
    $threw = false; try { prl_apply($o1, $on, $fang, $actorOf($other)); } catch (RuntimeException $e) { $threw = true; }
    $check($threw && !prl_info($o1, $actorOf($other))['can_edit'], '和这单无关的人：不能改');

    // 已审核订单不能在这里改
    $o2 = $mk('B', 'approved'); $ids[] = $o2;
    $threw = false; try { prl_apply($o2, $on, $fang, $actorOf($fang)); } catch (RuntimeException $e) { $threw = strpos($e->getMessage(), '已审核') !== false; }
    $check($threw, '已审核订单：参与人不能改');
    echo "全部通过\n";
} finally {
    foreach ($orig as $rid => $act) $pdo->prepare('UPDATE project_commission_rules SET is_active=? WHERE id=?')->execute([$act, $rid]);
    foreach ($ids as $id) {
        $pdo->exec("DELETE FROM project_participants WHERE order_id=$id");
        $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=$id");
        $pdo->exec("DELETE FROM project_orders WHERE id=$id");
    }
    $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='rule' AND details_json LIKE '%order_list%' AND created_at >= (NOW() - INTERVAL 10 MINUTE)");
}
