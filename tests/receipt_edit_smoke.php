<?php
// 订单实收直接更正：网站类订单的客服 / 技术可改自己参与的未审核单；已审核的只有财务能改（差额记调整、原快照保留）；改小 / 改大 / 改 0 / 不改。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/receipt_edit_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectReceiptEdit.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function (callable $f) { try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); } return null; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$cs = $E('宋倩倩'); $tech = $E('李子晖');
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'role' => 'finance', 'employee_id' => 0, 'username' => 'admin'];
$csActor = ['type' => 'employee', 'id' => 1, 'employee_id' => $cs, 'role' => 'customer_service'];
$ids = [];
$mk = function ($type, $status, $contract, $receipt) use ($pdo, $tag, $cs, $tech, &$ids, $fin) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试',?,?,'美呀美',?,0,'2026-10-12','finished','draft','测试')")->execute(["RE$tag" . count($ids), $type, $type === '网站模板' ? '加购/纯利润' : '博山定制', $contract]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $cs]);
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical',?,1)")->execute([$id, $tech, $type === '网站模板' ? '模板技术' : '前端']);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$id]);
    if ($receipt > 0) { $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id) VALUES (?,'receipt',?,'测试收款','approved','system',0)")->execute([$id, $receipt]); ps_recalculate_cash($id); }
    if ($status === 'approved') ps_approve_order($id, $fin, '2026-10');
    return $id;
};
$recv = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT receipt_amount FROM project_orders WHERE id=?'); $q->execute([$id]); return (float)$q->fetchColumn(); };
$order = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=?'); $q->execute([$id]); return $q->fetch(); };
try {
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period IN ('2026-10','2026-11','2026-12')");
    echo "=== 一、权限 ===\n";
    $d = $mk('网站模板', 'draft', 36, 590);
    $check(pre_can_edit($order($d), $csActor, true)[0] === true, '参与客服可以改自己参与的未审核网站模板订单');
    $check(pre_can_edit($order($d), $csActor, false)[0] === false, '不是参与人不能改');
    $check(pre_can_edit(array_replace($order($d), ['project_type' => '商标']), $csActor, true)[0] === false, '非网站类业务的实收仍交财务');
    $techActor = ['type' => 'employee', 'id' => 2, 'employee_id' => $tech, 'role' => 'technical'];
    $check(pre_can_edit($order($d), $techActor, true)[0] === true, '参与技术（模板技术 / 定制技术）也可以改');
    $a = $mk('网站模板', 'approved', 36, 590);
    $check(pre_can_edit($order($a), $csActor, true)[0] === false && pre_can_edit($order($a), $fin, false)[0] === true, '已审核订单：参与人不能改，财务可以');
    echo "=== 二、未审核订单：改小 / 改大 / 改 0 / 不改 ===\n";
    $r = ps_set_receipt($d, '36', $csActor, '实付含别的部门的钱');
    $check(abs($recv($d) - 36) < 0.001 && $r['old'] == 590.0 && !$r['adjusted'], '590 → 36：实收 ' . $recv($d) . '，流水里的收款被减掉（留痕）');
    $notes = $pdo->query("SELECT note FROM project_cash_movements WHERE order_id=$d AND review_status='approved'")->fetchColumn();
    $check(strpos((string)$notes, '实收更正') !== false, '流水备注写了更正说明');
    ps_set_receipt($d, '100', $csActor, '客户补款'); $check(abs($recv($d) - 100) < 0.001, '36 → 100（大于售价写了原因）：补一笔收款，实收 100');
    $before = (int)$pdo->query("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=$d")->fetchColumn();
    ps_set_receipt($d, '100', $csActor, '客户补款'); $check((int)$pdo->query("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=$d")->fetchColumn() === $before, '金额没变：不新增流水');
    ps_set_receipt($d, '0', $csActor); $check(abs($recv($d)) < 0.001 && (int)$pdo->query("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=$d AND review_status='approved' AND movement_type='receipt'")->fetchColumn() === 0, '改成 0：流水全部作废，实收 0');
    $check($throws(function () use ($d, $csActor) { ps_set_receipt($d, '-5', $csActor); }) !== null && $throws(function () use ($d, $csActor) { ps_set_receipt($d, 'abc', $csActor); }) !== null, '负数 / 非数字被拒');
    $check($throws(function () use ($a, $csActor) { ps_set_receipt($a, '36', $csActor); }) !== null, '参与人直接改已审核订单被拒');
    $e1 = $throws(function () use ($d, $csActor) { ps_set_receipt($d, '500', $csActor, ''); });
    $check($e1 !== null && strpos($e1, '原因') !== false, '实收大于售价、不写原因：参与人被拒：' . $e1);
    ps_set_receipt($d, '500', $csActor, '客户补款 400');
    $check(abs($recv($d) - 500) < 0.001, '写明原因后可以：实收 500（售价 36）');
    $noteTxt = $pdo->query("SELECT GROUP_CONCAT(note) FROM project_cash_movements WHERE order_id=$d AND review_status='approved'")->fetchColumn();
    $check(strpos((string)$noteTxt, '客户补款') !== false, '原因写进了收款流水备注');
    ps_set_receipt($d, '36', $fin);
    echo "=== 三、已审核订单：财务更正，快照保留、差额记调整 ===\n";
    $snapBefore = (float)$pdo->query("SELECT SUM(commission_amount) FROM project_commission_snapshots WHERE order_id=$a")->fetchColumn();
    $r = ps_set_receipt($a, '36', $fin, '合并付款多记了', '2026-11');
    $snapAfter = (float)$pdo->query("SELECT SUM(commission_amount) FROM project_commission_snapshots WHERE order_id=$a")->fetchColumn();
    $adj = $pdo->query("SELECT SUM(amount) FROM project_commission_adjustments WHERE order_id=$a AND payroll_month='2026-11'")->fetchColumn();
    $check(abs($recv($a) - 36) < 0.001 && $r['adjusted'] && abs($snapBefore - $snapAfter) < 0.0001, '实收改成 36，原分成快照不动：' . $snapBefore);
    $check($adj !== null && (float)$adj < 0, '多算的分成已记为 2026-11 的负调整：' . $adj);
    echo "=== 四、财务用别的途径（直接改收款流水）改已审核订单的实收：分成自动同步 ===
";
    $f = $mk('网站模板', 'approved', 100, 1100);
    $q1 = (float)$pdo->query("SELECT SUM(commission_amount) FROM project_commission_snapshots WHERE order_id=$f")->fetchColumn();
    $pdo->prepare("UPDATE project_cash_movements SET amount=100 WHERE order_id=? AND movement_type='receipt'")->execute([$f]);
    ps_recalculate_cash($f);
    $adjF = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM project_commission_adjustments WHERE order_id=$f")->fetchColumn();
    $cnt1 = (int)$pdo->query("SELECT COUNT(*) FROM project_commission_adjustments WHERE order_id=$f")->fetchColumn();
    $check($adjF < 0 && $cnt1 >= 1, '实收 1100 → 100：自动记了分成扣回 ' . $adjF . '（原快照 ' . $q1 . ' 不动）');
    ps_recalculate_cash($f);
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_commission_adjustments WHERE order_id=$f")->fetchColumn() === $cnt1, '实收没再变：不会重复记调整');
    $pdo->prepare("UPDATE project_cash_movements SET amount=1100 WHERE order_id=? AND movement_type='receipt'")->execute([$f]);
    ps_recalculate_cash($f);
    $adjBack = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM project_commission_adjustments WHERE order_id=$f")->fetchColumn();
    $check(abs($adjBack) < 0.011, '实收改回 1100：调整合计回到 0（与原快照一致）：' . $adjBack);
    echo "\n=== 订单实收更正测试全部通过 ===\n";
} finally {
    if ($ids) {
        $in = implode(',', $ids);
        foreach (['project_commission_adjustments', 'project_commission_snapshots', 'project_participants', 'project_order_resources', 'project_cash_movements'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id IN ($in)"); } catch (Throwable $e) {} }
        try { $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id IN ($in)"); } catch (Throwable $e) {}
        $pdo->exec("DELETE FROM project_orders WHERE id IN ($in)");
    }
}
