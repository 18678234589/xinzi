<?php
// 备案-提成（房烁）提成不扣 3% 服务费：2026-10-01 起新规则；已审核订单的差额记为调整（可重复执行不重复补）；9 月订单不受影响。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/filing_commission_no_fee_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectExtraRules.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$fang = $E('房烁');
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'role' => 'finance', 'employee_id' => 0, 'username' => 'admin'];
$ids = []; $tempRules = []; $saved = [];
$ins = function (array $d) use ($pdo, &$tempRules) { $c = array_keys($d); $pdo->prepare('INSERT INTO project_commission_rules (`' . implode('`,`', $c) . '`) VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($d)); return $tempRules[] = (int)$pdo->lastInsertId(); };
$mk = function ($kind, $status, $date) use ($pdo, $tag, $fang, &$ids, $fin) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','备案-提成',?,'美呀美',100,100,?,'finished','draft','测试')")->execute(["FC$tag" . count($ids), $kind, $date]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $fang]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$id]);
    $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',100,'测试','approved','system',0)")->execute([$id]);
    if ($status === 'approved') ps_approve_order($id, $fin, substr($date, 0, 7));
    return $id;
};
$commOf = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $q->execute([$id]); $o = $q->fetch(); $s = ps_summary($o, ps_costs($id), ps_participants($id)); $p = $s['groups']['customer_service']['people'][0]['calc']; return round((float)$p['share'] + (float)$p['subsidy'], 2); };
try {
    pxr_ensure_column();
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period IN ('2026-09','2026-10','2026-11','2026-12')");
    foreach ($pdo->query("SELECT * FROM project_commission_rules WHERE project_type='备案-提成' AND is_active=1")->fetchAll(PDO::FETCH_ASSOC) as $r) { $saved[] = $r['id']; $pdo->prepare('UPDATE project_commission_rules SET is_active=0 WHERE id=?')->execute([$r['id']]); }
    $base = ['commission_group' => 'customer_service', 'project_type' => '备案-提成', 'role_name' => '*', 'order_kind' => '*', 'calc_mode' => 'pool', 'rate' => 0.2, 'service_fee_rate' => 0.03, 'per_order_subsidy' => 0, 'effective_from' => '2026-08-01', 'is_active' => 1, 'is_extra' => 0, 'min_contract_amount' => 0, 'allow_negative' => 0, 'note' => '测试'];
    $ins($base);
    $ins(array_merge($base, ['order_kind' => '拍链接', 'is_extra' => 1, 'rate' => 0, 'service_fee_rate' => null, 'per_order_subsidy' => 0.5]));
    $ins(array_merge($base, ['is_extra' => 1, 'rate' => 0, 'service_fee_rate' => null, 'per_order_subsidy' => 1]));
    $sep = $mk('拍链接', 'approved', '2026-09-20');
    $oct = $mk('拍链接', 'approved', '2026-10-20');
    $check(abs($commOf($oct) - ((100 - 3) * 0.2 + 1.5)) < 0.011, '改前：10 月拍链接订单 (100−3)×20% + 0.5 + 1 = 20.9：' . $commOf($oct));
    $run = function ($arg) { $out = []; exec('php ' . escapeshellarg(__DIR__ . '/../tools/set_filing_commission_no_fee_202611.php') . ' ' . $arg . ' 2>&1', $out); return implode("\n", $out); };
    $o = $run('');
    $check(strpos($o, '这是预览，没有写库') !== false && strpos($o, "FC$tag") !== false, '预览列出已审核订单的差额：' . substr($o, 0, 160));
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_rules WHERE project_type='备案-提成' AND effective_from='2026-10-01'")->fetchColumn() === 0, '预览没有写规则');
    $run('--apply-rule');
    $run('--apply-rule');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_rules WHERE project_type='备案-提成' AND effective_from='2026-10-01' AND is_active=1")->fetchColumn() === 1, '规则只新增一条（重复执行不重复）');
    $tempRules[] = (int)$pdo->query("SELECT id FROM project_commission_rules WHERE project_type='备案-提成' AND effective_from='2026-10-01' LIMIT 1")->fetchColumn();
    $draft = $mk('拍链接', 'draft', '2026-10-21');
    $check(abs($commOf($draft) - (100 * 0.2 + 1.5)) < 0.011, '改后：10 月草稿订单 100×20% + 1.5 = 21.5（不扣服务费）：' . $commOf($draft));
    $o = $run('--post-adjustments');
    $adj = $pdo->query("SELECT order_id,amount FROM project_commission_adjustments WHERE order_id IN ($sep,$oct)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(count($adj) === 1 && isset($adj[$oct]) && abs((float)$adj[$oct] - 0.6) < 0.011, '10 月已审核订单补差额 +0.6（100×3%×20%），9 月订单不动：' . json_encode($adj) . ' ' . substr($o, 0, 160));
    $run('--post-adjustments');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_adjustments WHERE order_id IN ($sep,$oct)")->fetchColumn() === 1, '重复执行不会重复补');
    echo "\n=== 备案提成不扣服务费测试全部通过 ===\n";
} finally {
    if ($ids) {
        $in = implode(',', $ids);
        foreach (['project_commission_adjustments', 'project_commission_snapshots', 'project_participants', 'project_order_resources', 'project_cash_movements'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id IN ($in)"); } catch (Throwable $e) {} }
        try { $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id IN ($in)"); } catch (Throwable $e) {}
        $pdo->exec("DELETE FROM project_orders WHERE id IN ($in)");
    }
    if ($tempRules) { $pdo->exec('DELETE FROM project_commission_rules WHERE id IN (' . implode(',', array_filter($tempRules)) . ')'); }
    foreach ($saved as $rid) $pdo->prepare('UPDATE project_commission_rules SET is_active=1 WHERE id=?')->execute([$rid]);
}
