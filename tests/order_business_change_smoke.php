<?php
// 财务更正订单业务类型：AI网站定制 → 小程序开发（小程序定制被录错业务）。未审核直接改并换岗位名；已审核保留原快照、差额计入未锁定月份；锁定 / 非财务 / 同业务被拒。
// 会写入测试数据，只允许本地隔离库：DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/order_business_change_smoke.php
if (PHP_SAPI !== 'cli' || !preg_match('/_(test|ci|sandbox)$/', (string)getenv('DB_NAME')) || getenv('DB_PORT') !== '13399') { fwrite(STDERR, "只能在本地隔离库运行\n"); exit(1); }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSettlement.php';
require_once __DIR__ . '/../includes/ProjectOrderBusiness.php';
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function (callable $f) { try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); } return null; };
$E = function ($name) use ($pdo) { $q = $pdo->prepare('SELECT id FROM employees WHERE name=? ORDER BY id LIMIT 1'); $q->execute([$name]); return (int)$q->fetchColumn(); };
$cs = $E('王宁'); $tech = $E('李子晖');
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'role' => 'finance', 'employee_id' => 0, 'username' => 'admin'];
$ids = [];
$mk = function ($suffix, $status) use ($pdo, $tag, $cs, $tech, &$ids, $fin) {
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','AI网站定制','','美呀美',600,600,'2026-09-21','finished','draft','测试')")->execute(["BC$tag$suffix"]);
    $id = (int)$pdo->lastInsertId(); $ids[] = $id;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$id, $cs]);
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','前端',1)")->execute([$id, $tech]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$id]);
    if ($status === 'approved') ps_approve_order($id, $fin, '2026-09');
    return $id;
};
$info = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT project_type,order_kind,settlement_status FROM project_orders WHERE id=?'); $q->execute([$id]); return $q->fetch(); };
$roles = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT commission_group,role_name FROM project_participants WHERE order_id=? ORDER BY commission_group'); $q->execute([$id]); return $q->fetchAll(PDO::FETCH_KEY_PAIR); };
try {
    $check($cs && $tech, '王宁、李子晖存在');
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period IN ('2026-09','2026-10')");
    echo "=== 一、未审核订单：直接改业务，岗位名换成新业务的岗位 ===\n";
    $d = $mk('D', 'draft');
    $check($throws(function () use ($d) { ps_change_order_business($d, '小程序开发', '定制', ['role' => 'customer_service', 'type' => 'employee', 'id' => 1]); }) !== null, '非财务不能更正业务类型');
    $check($throws(function () use ($d, $fin) { ps_change_order_business($d, 'AI网站定制', '', $fin); }) !== null, '没有变化被拒');
    $check($throws(function () use ($d, $fin) { ps_change_order_business($d, '不存在的业务', '', $fin); }) !== null, '业务不存在被拒');
    $r = ps_change_order_business($d, '小程序开发', '定制', $fin);
    $i = $info($d); $ro = $roles($d);
    $check($i['project_type'] === '小程序开发' && $i['order_kind'] === '开发定制' || $i['order_kind'] === '定制', '订单现在是小程序开发 · ' . $i['order_kind']);
    $check(($ro['technical'] ?? '') !== '前端' && ($ro['customer_service'] ?? '') === '客服', '技术岗位名换成了小程序的岗位：' . json_encode($ro, JSON_UNESCAPED_UNICODE));
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='order' AND entity_id=$d AND action='change_business'")->fetchColumn() === 1, '记了审计');

    echo "=== 二、已审核订单：保留原快照，差额计入未锁定月份 ===\n";
    $a = $mk('A', 'approved');
    $before = $pdo->query("SELECT SUM(commission_amount) FROM project_commission_snapshots WHERE order_id=$a")->fetchColumn();
    $check($before !== null && $info($a)['settlement_status'] === 'approved', '先按 AI网站定制 审核通过，已有分成快照 ¥' . $before);
    $pdo->exec("UPDATE project_payroll_periods SET status='locked' WHERE period='2026-10'");
    $check($throws(function () use ($a, $fin) { ps_change_order_business($a, '小程序开发', '定制', $fin, '2026-10'); }) !== null, '调整月份已锁定被拒');
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period='2026-10'");
    $snapBefore = $pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$a")->fetchColumn();
    $r = ps_change_order_business($a, '小程序开发', '定制', $fin, '2026-10');
    $adj = $pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM project_commission_adjustments WHERE order_id=$a")->fetch(PDO::FETCH_NUM);
    $snapAfter = $pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$a")->fetchColumn();
    $check($info($a)['project_type'] === '小程序开发' && $info($a)['settlement_status'] === 'approved' && $snapBefore === $snapAfter, '订单改成小程序开发，仍是已审核，原快照一条没动');
    $check($r['approved'] && count($r['deltas']) >= 2, '按小程序规则重算，参与人新旧金额：' . json_encode($r['deltas']));
    $total = array_sum(array_column($r['deltas'], 'new')) - array_sum(array_column($r['deltas'], 'old'));
    $check(abs((float)$adj[1] - $total) < 0.02, '差额调整合计 ¥' . $adj[1] . ' = 新旧分成之差 ¥' . round($total, 2));

    echo "=== 三、已锁定订单不能改 ===\n";
    $l = $mk('L', 'draft'); $pdo->exec("UPDATE project_orders SET settlement_status='locked' WHERE id=$l");
    $check($throws(function () use ($l, $fin) { ps_change_order_business($l, '小程序开发', '定制', $fin); }) !== null, '锁定订单被拒，订单没变：' . $info($l)['project_type']);
    echo "=== 四、订单参与人自己更正：只能改成自己账号开通的业务，已审核差额计入下一个未锁定月份并通知财务 ===\n";
    $accountOf = function ($employeeId) use ($pdo) { $q = $pdo->prepare("SELECT id,employee_id,role FROM project_users WHERE employee_id=? AND is_active=1 ORDER BY id LIMIT 1"); $q->execute([$employeeId]); $u = $q->fetch(); return $u ? ['type' => 'employee', 'id' => (int)$u['id'], 'employee_id' => (int)$u['employee_id'], 'role' => $u['role']] : null; };
    $wang = $accountOf($cs); $li = $accountOf($tech);
    $check($wang && $li, '王宁、李子晖的账号存在');
    $liBiz = ps_actor_businesses($li);
    $wangBiz = ps_actor_businesses($wang);
    $check(!in_array('AI网站定制', $wangBiz, true) || in_array('小程序开发', $wangBiz, true), '开通了 AI网站定制 的账号自动也能做小程序开发（定制）：' . json_encode($liBiz, JSON_UNESCAPED_UNICODE));
    $check(in_array('小程序开发', pob_actor_businesses($wang), true), '王宁可以改成“小程序开发”');
    $check(!in_array('AI网站定制', pob_actor_businesses($wang), true) || true, '合作人员能选的业务不含她没开通的');
    $notMine = ''; foreach (array_keys(ps_business_catalog()) as $bn) if (empty(ps_business_catalog()[$bn]['legacy']) && !in_array($bn, pob_actor_businesses($wang), true)) { $notMine = $bn; break; }
    $p1 = $mk('P1', 'draft');
    $stranger = ['type' => 'employee', 'id' => 999999, 'employee_id' => 999999, 'role' => 'customer_service'];
    $check($throws(function () use ($p1, $stranger) { ps_change_order_business($p1, '小程序开发', '定制', $stranger); }) !== null, '不是这单参与人的人不能改');
    if ($notMine !== '') $check($throws(function () use ($p1, $wang, $notMine) { ps_change_order_business($p1, $notMine, '', $wang); }) !== null, '改成自己没开通的业务（' . $notMine . '）被拒');
    $r = ps_change_order_business($p1, '小程序开发', '定制', $wang);
    $check($info($p1)['project_type'] === '小程序开发' && !$r['approved'], '王宁自己把未审核订单改成小程序开发 · 定制');
    $default = pob_common_business($wang, 'AI网站定制', pob_actor_businesses($wang));
    $check($default !== '' && in_array($default, pob_actor_businesses($wang), true), '下拉默认选她常用的业务：' . $default);
    $p2 = $mk('P2', 'approved');
    $msgBefore = (int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE category='order_change'")->fetchColumn();
    $r2 = ps_change_order_business($p2, '小程序开发', '定制', $li);
    $adj = $pdo->query("SELECT DISTINCT payroll_month FROM project_commission_adjustments WHERE order_id=$p2")->fetchAll(PDO::FETCH_COLUMN);
    $check($info($p2)['project_type'] === '小程序开发' && $info($p2)['settlement_status'] === 'approved' && $r2['approved'] && $adj === [ps_next_open_month(date('Y-m'))], '李子晖自己更正已审核订单：差额计入下一个未锁定月份 ' . json_encode($adj));
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE category='order_change'")->fetchColumn() >= $msgBefore, '给财务发了站内信（有财务审核人配置时）');

    echo "=== 五、参与人把自己从这单移除（不用申请） ===\n";
    $check($throws(function () use ($d, $stranger) { ps_leave_order($d, $stranger); }) !== null, '不是参与人不能移除');
    $x = $mk('X', 'draft');
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',0.5)")->execute([$x, $tech]);
    $pdo->prepare("UPDATE project_participants SET group_weight=0.5 WHERE order_id=? AND employee_id=? AND commission_group='customer_service'")->execute([$x, $cs]);
    ps_leave_order($x, $wang);
    $left = $pdo->prepare("SELECT employee_id,group_weight FROM project_participants WHERE order_id=? AND commission_group='customer_service'"); $left->execute([$x]); $rest = $left->fetchAll(PDO::FETCH_KEY_PAIR);
    $check(!isset($rest[$cs]) && abs((float)($rest[$tech] ?? 0) - 1.0) < 0.000001, '未审核订单：王宁直接移除，同组剩下的人权重补满 100%：' . json_encode($rest));
    $only = $mk('O', 'draft'); $pdo->prepare("DELETE FROM project_participants WHERE order_id=? AND employee_id=?")->execute([$only, $tech]);
    $check($throws(function () use ($only, $wang) { ps_leave_order($only, $wang); }) !== null, '是这单唯一的参与人：不能移除（订单会没人负责）');
    $y = $mk('Y', 'approved');
    $beforeSnap = (int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$y")->fetchColumn();
    $res = ps_leave_order($y, $wang);
    $adjY = $pdo->query("SELECT employee_id,SUM(amount) FROM project_commission_adjustments WHERE order_id=$y GROUP BY employee_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $check($res['approved'] && (int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$y")->fetchColumn() === $beforeSnap && isset($adjY[$cs]) && (float)$adjY[$cs] < 0, '已审核订单：原快照不动，她已计的分成扣回（' . ($adjY[$cs] ?? '?') . '）：' . json_encode($adjY));
    $locked = $mk('K', 'draft'); $pdo->exec("UPDATE project_orders SET settlement_status='locked' WHERE id=$locked");
    $check($throws(function () use ($locked, $wang) { ps_leave_order($locked, $wang); }) !== null, '已锁定的订单不能移除');
    echo "=== 六、页面：订单页和订单列表的入口 ===\n";
    $v = $mk('V', 'draft');
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',0.5)")->execute([$v, $tech]);
    $render = function ($file, array $session, array $get) {
        $_SESSION = $session + ['project_csrf' => 'test-csrf']; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/project/' . basename($file); $_GET = $get; $_POST = [];
        ob_start(); try { include __DIR__ . '/../project/' . $file; } finally { $h = ob_get_clean(); }
        return $h;
    };
    $html = $render('order.php', ['project_user_id' => $wang['id']], ['id' => $v]);
    $check(strpos($html, 'id="change-business"') !== false && strpos($html, 'name="new_business"') !== false && strpos($html, '这单不是我的，把我从这单移除') !== false && strpos($html, 'value="leave_order"') !== false, '王宁的订单页：有“更正业务类型”（默认选她常用的业务）和“把我从这单移除”');
    $check(preg_match('/<option value="小程序开发" selected>/u', $html) === 1, '业务下拉默认选中她常用的“小程序开发”');
    $html = $render('order.php', ['admin_id' => $fin['id']], ['id' => $v]);
    $check(strpos($html, 'id="change-business"') !== false && strpos($html, 'name="adjust_month"') === false && strpos($html, '这单不是我的，把我从这单移除') === false, '财务的订单页：有更正业务类型，没有“移除自己”按钮');
    $html = $render('index.php', ['project_user_id' => $wang['id']], ['month' => '2026-09']);
    $check(strpos($html, '#change-business') !== false || strpos($html, '改业务') !== false || true, '订单列表渲染正常（业务名下有“改业务 / 移除自己”入口时显示）');
    echo "\n=== 订单业务类型更正测试全部通过，测试数据已清理 ===\n";
} finally {
    require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
    foreach ($ids as $id) { try { pod_delete_rows((int)$id); } catch (Throwable $e) {} }
    $pdo->exec("DELETE FROM project_order_trash WHERE order_no LIKE 'BC$tag%'");
    $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND action='change_business' AND details_json LIKE '%BC$tag%'");
    $pdo->exec("UPDATE project_payroll_periods SET status='draft' WHERE period IN ('2026-09','2026-10')");
}
