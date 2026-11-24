<?php
// 网站客服每单 20 元只给 AI定制（新建站）：华梦定制、博山定制 / 未选类型不再有；已审核订单的差额作为调整计入下一个未锁定月份。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/website_cs_subsidy_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectExtraRules.php';
require_once __DIR__ . '/../tools/fix_website_cs_subsidy_202611.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$cs = $E('王宁'); $tech = $E('李子晖');
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'role' => 'finance', 'employee_id' => 0, 'username' => 'admin'];
$ids = []; $tempRules = []; $saved = [];
$ins = function (array $d) use ($pdo, &$tempRules) { $c = array_keys($d); $pdo->prepare('INSERT INTO project_commission_rules (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($d)); return $tempRules[] = (int)$pdo->lastInsertId(); };
$mk = function ($kind, $status, $date = '2026-10-05') use ($pdo, $tag, $cs, $tech, &$ids, $fin) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','AI网站定制',?,'美呀美',1000,1000,?,'finished','draft','测试')")->execute(["CS$tag" . count($ids), $kind, $date]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $cs]);
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端',1)")->execute([$id, $tech]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$id]);
    if ($status === 'approved') ps_approve_order($id, $fin, '2026-10');
    return $id;
};
$subsidyOf = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $q->execute([$id]); $o = $q->fetch(); $s = ps_summary($o, ps_costs($id), ps_participants($id)); return (float)$s['groups']['customer_service']['people'][0]['calc']['subsidy']; };
try {
    pxr_ensure_column();
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period IN ('2026-10','2026-11','2026-12')");
    // 把测试库里已有的 2026-10-01 起 AI网站定制 客服规则暂存，换成与线上相同的三条（基准 20 / 华梦 20 / AI定制 新建站 20）
    $q = $pdo->query("SELECT * FROM project_commission_rules WHERE commission_group='customer_service' AND project_type='AI网站定制' AND effective_from>='2026-10-01' AND is_active=1");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $saved[] = $r; $pdo->prepare('UPDATE project_commission_rules SET is_active=0 WHERE id=?')->execute([$r['id']]); }
    $base = ['commission_group' => 'customer_service', 'project_type' => 'AI网站定制', 'role_name' => '*', 'order_kind' => '*', 'calc_mode' => 'pool', 'rate' => 0.1, 'service_fee_rate' => 0.03, 'per_order_subsidy' => 20, 'min_cost_rate' => 0.65, 'effective_from' => '2026-10-01', 'is_active' => 1, 'is_extra' => 0, 'min_contract_amount' => 0, 'allow_negative' => 0, 'note' => '测试基准'];
    $ins($base);
    $ins(array_merge($base, ['order_kind' => '华梦定制', 'min_cost_rate' => 0.8, 'note' => '测试华梦']));
    $ins(array_merge($base, ['order_kind' => 'AI定制', 'is_extra' => 1, 'rate' => 0, 'min_cost_rate' => null, 'note' => '测试 AI定制 新建站奖励']));
    $pdo->exec("UPDATE project_commission_rules SET service_fee_rate=0.03 WHERE id IN (" . implode(',', $tempRules) . ")");

    $b = $mk('博山定制', 'draft'); $h = $mk('华梦定制', 'draft'); $a = $mk('AI定制', 'draft'); $n = $mk('', 'draft');
    $ab = $mk('博山定制', 'approved'); $aa = $mk('AI定制', 'approved');
    $check(abs($subsidyOf($b) - 20) < 0.01 && abs($subsidyOf($h) - 20) < 0.01 && abs($subsidyOf($a) - 40) < 0.01, '改前：博山 20、华梦 20、AI定制 40（基准 20 + 新建站奖励 20）');
    $check(count(fcs_rules()) === 2, '找到两条要改的规则（所有订单类型 / 华梦定制）');
    $check(count(fcs_apply_rules()) === 2 && !fcs_apply_rules(), '规则已改，重复执行不再改');
    // 规则在同一进程里按（组 / 业务 / 订单日期）缓存：改后的订单换一个日期，已审核订单的差额交给新进程里的工具算
    $b2 = $mk('博山定制', 'draft', '2026-10-06'); $h2 = $mk('华梦定制', 'draft', '2026-10-06'); $a2 = $mk('AI定制', 'draft', '2026-10-06'); $n2 = $mk('', 'draft', '2026-10-06');
    $check(abs($subsidyOf($b2)) < 0.01 && abs($subsidyOf($h2)) < 0.01 && abs($subsidyOf($n2)) < 0.01, '改后：博山、华梦、未选类型 都没有每单 20 元');
    $check(abs($subsidyOf($a2) - 20) < 0.01, '改后：AI定制 新建站仍有每单 20 元（只剩新建站奖励）');
    $run = function ($arg) { $out = []; exec('php ' . escapeshellarg(__DIR__ . '/../tools/fix_website_cs_subsidy_202611.php') . ' ' . $arg . ' 2>&1', $out); return implode("
", $out); };
    $o = $run('');
    $check(strpos($o, '这是预览，没有写库') !== false && strpos($o, 'CS' . $tag) !== false, '预览列出已审核订单的差额：' . substr($o, 0, 200));
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_adjustments WHERE order_id IN ($ab,$aa)")->fetchColumn() === 0, '预览没有写调整');
    $o = $run('--post-adjustments');
    $adj = $pdo->query("SELECT order_id,amount,payroll_month FROM project_commission_adjustments WHERE order_id IN ($ab,$aa) ORDER BY order_id")->fetchAll(PDO::FETCH_ASSOC);
    $check(count($adj) === 2 && abs(array_sum(array_column($adj, 'amount')) + 40) < 0.01, '已审核订单差额已记为调整：博山 −20、AI定制 −20：' . json_encode($adj) . ' ' . substr($o, 0, 200));
    $run('--post-adjustments');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_adjustments WHERE order_id IN ($ab,$aa)")->fetchColumn() === 2, '重复执行不会重复扣');
    $snap = $pdo->query("SELECT SUM(subsidy_amount) FROM project_commission_snapshots WHERE order_id IN ($ab,$aa) AND commission_group='customer_service'")->fetchColumn();
    $check(abs((float)$snap - 60) < 0.01, '已审核订单的原快照不动（20 + 40）');
    echo "\n=== 网站客服每单 20 元更正测试全部通过 ===\n";
} finally {
    if ($ids) {
        $in = implode(',', $ids);
        foreach (['project_commission_adjustments', 'project_commission_snapshots', 'project_participants', 'project_order_resources', 'project_audit_logs'] as $t) { try { $col = $t === 'project_audit_logs' ? "entity_type='order' AND entity_id" : 'order_id'; $pdo->exec("DELETE FROM $t WHERE $col IN ($in)"); } catch (Throwable $e) {} }
        $pdo->exec("DELETE FROM project_orders WHERE id IN ($in)");
    }
    if ($tempRules) { $pdo->exec('DELETE FROM project_commission_rules WHERE id IN (' . implode(',', $tempRules) . ')'); $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='commission_rule' AND entity_id IN (" . implode(',', $tempRules) . ')'); }
    foreach ($saved as $r) $pdo->prepare('UPDATE project_commission_rules SET is_active=1 WHERE id=?')->execute([$r['id']]);
}
