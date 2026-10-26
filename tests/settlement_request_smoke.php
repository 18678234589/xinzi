<?php
// 合作人员申请结算：只能申请本人本月已审核订单、同一订单不能重复申请、财务“已结算”后标注已结算、退回释放订单。只允许本地隔离库（会提交数据）。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectSettlementRequest.php';
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399') throw new RuntimeException('只允许本地隔离库');
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function (callable $f) { try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); } return null; };
psr_ensure();
$row = $pdo->query("SELECT c.employee_id,c.payroll_month,COUNT(DISTINCT c.order_id) n FROM project_commission_snapshots c JOIN project_users u ON u.employee_id=c.employee_id AND u.is_active=1 GROUP BY c.employee_id,c.payroll_month HAVING n>=4 AND c.payroll_month < DATE_FORMAT(CURDATE(),'%Y-%m') AND NOT EXISTS (SELECT 1 FROM project_settlement_requests r WHERE r.employee_id=c.employee_id AND r.payroll_month=c.payroll_month) ORDER BY n DESC LIMIT 1")->fetch();
$check($row !== false, '找到有已审核订单的合作人员');
$eid = (int)$row['employee_id']; $month = $row['payroll_month'];
$user = $pdo->query("SELECT id FROM project_users WHERE employee_id=$eid AND is_active=1 LIMIT 1")->fetchColumn();
$actor = ['type' => 'employee', 'id' => (int)$user, 'employee_id' => $eid, 'role' => 'customer_service'];
$other = ['type' => 'employee', 'id' => 999999, 'employee_id' => $eid + 100000, 'role' => 'customer_service'];
$fin = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT MIN(id) FROM admins')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
ppf_save($fin, $eid, $month, 'green', '测试核对无异议', '', false); // 财务标绿后本人才能申请结算
$orders = psr_orders($eid, $month);
$check(count($orders) >= 1 && count(array_filter($orders, function ($o) { return $o['state'] !== 'available'; })) === 0, '本月项目都可申请：' . count($orders));
$ids = array_column($orders, 'order_id');
$check($throws(function () use ($fin, $month, $ids) { psr_submit($fin, $month, $ids); }) !== null, '财务账号不能代替本人申请');
$check($throws(function () use ($actor, $month) { psr_submit($actor, $month, []); }) !== null, '没选订单不能提交');
$check($throws(function () use ($other, $month, $ids) { psr_submit($other, $month, $ids); }) !== null, '别人不能申请不属于自己的订单');
$home = psr_home_expected($eid, $month);
// 已删除“分段申请金额与首页应结算不一致时提交被拒”：现行流程提交时放行，金额不一致改在财务核对时作为问题提示（见 psr_check，申请金额与首页扣减后实结算不一致）
// 整月申请：金额 = 首页实结算（扣除其他申请），申请与首页一致
$r1 = pspr_submit($actor, $month);
$rid = $r1['id'];
$req = $pdo->query("SELECT * FROM project_settlement_requests WHERE id=$rid")->fetch();
$check($req['status'] === 'pending' && abs((float)$req['amount'] - $home['expected']) < 0.005, "整月申请金额 {$req['amount']} = 首页应结算 {$home['expected']}");
$chk = psr_check($req);
$check($chk['ok'], '申请与首页一致，无异议：' . implode('；', $chk['issues']));
$check($throws(function () use ($actor, $month) { pspr_submit($actor, $month); }) !== null, '同月不能重复申请整月');
$check(psr_pending_count() >= 1, '财务待处理数 ≥ 1');
// 申请后首页金额变化 → 申请与首页不一致，被标异议，不能导出 / 批量结算
$pdo->prepare('UPDATE project_settlement_requests SET amount=amount+20 WHERE id=?')->execute([$rid]);
$chk = psr_check($pdo->query("SELECT * FROM project_settlement_requests WHERE id=$rid")->fetch());
$check(!$chk['ok'] && mb_strpos(implode('；', $chk['issues']), '与首页扣减后实结算不一致') !== false, '金额与首页不一致被标为异议：' . implode('；', $chk['issues']));
$d = psr_export_data([$rid]);
$check(!$d['people'] && count($d['held']) === 1, '有异议的申请不进批量打款表');
$res = psr_settle_many([$rid], $fin);
$check($res['ok'] === 0 && count($res['fail']) === 1, '有异议的申请不能批量已结算');
$pdo->prepare('UPDATE project_settlement_requests SET amount=amount-20 WHERE id=?')->execute([$rid]);
// 退回后可重新申请
psr_reject($rid, $fin, '测试退回');
$check($throws(function () use ($rid, $fin) { psr_settle($rid, $fin); }) !== null, '已退回的申请不能再点已结算');
$rid2 = pspr_submit($actor, $month)['id'];
psr_settle($rid2, $fin, '测试打款');
$check($pdo->query("SELECT status FROM project_settlement_requests WHERE id=$rid2")->fetchColumn() === 'settled', '财务点已结算');
$check($throws(function () use ($rid2, $fin) { psr_settle($rid2, $fin); }) !== null, '同一申请不能重复结算');
$check($throws(function () use ($actor, $month) { pspr_submit($actor, $month); }) !== null, '已结算的月份不能再次申请（避免重复结算）');
$check($throws(function () use ($rid2, $fin) { psr_reject($rid2, $fin, '已结算不能退回'); }) !== null, '已结算的申请不能退回');
// ---- 申请更正：多选订单一起提交，主管和财务都能审核 ----
require_once __DIR__ . '/../includes/commission_explain.php';
$cand = $pdo->query("SELECT c.employee_id,c.payroll_month,e.department FROM project_commission_snapshots c JOIN project_users u ON u.employee_id=c.employee_id AND u.is_active=1 JOIN employees e ON e.id=c.employee_id JOIN project_dept_heads h ON h.department=e.department AND h.employee_id<>c.employee_id WHERE c.employee_id<>$eid AND c.payroll_month < DATE_FORMAT(CURDATE(),'%Y-%m') AND NOT EXISTS (SELECT 1 FROM project_commission_corrections k WHERE k.target_employee_id=c.employee_id AND k.status='pending') AND NOT EXISTS (SELECT 1 FROM project_settlement_requests r WHERE r.employee_id=c.employee_id AND r.payroll_month=c.payroll_month) GROUP BY c.employee_id,c.payroll_month HAVING COUNT(DISTINCT c.order_id)>=3 LIMIT 1")->fetch();
$check($cand !== false, '找到所在部门有主管的合作人员');
$e2 = (int)$cand['employee_id']; $m2 = $cand['payroll_month']; $dept = $cand['department'];
$u2 = $pdo->query("SELECT id FROM project_users WHERE employee_id=$e2 AND is_active=1 LIMIT 1")->fetchColumn();
$act2 = ['type' => 'employee', 'id' => (int)$u2, 'employee_id' => $e2, 'role' => 'customer_service'];
ppf_save($fin, $e2, $m2, 'green', '测试核对无异议', '', false);
$ord2 = psr_orders($e2, $m2); $ids2 = array_slice(array_column($ord2, 'order_id'), 0, 3);
$check($throws(function () use ($act2, $m2, $ids2) { psr_correct($act2, $m2, $ids2, '短'); }) !== null, '更正理由太短被拒');
$check($throws(function () use ($act2, $m2) { psr_correct($act2, $m2, [], '这几单的成本填错了应为更低'); }) !== null, '没勾选订单被拒');
$res = psr_correct($act2, $m2, $ids2, '这几单的成本填错了，应该更低，请核对');
$check($res['submitted'] >= 3 && count($res['notified']) >= 1, '提交更正 ' . $res['submitted'] . ' 条，通知主管：' . implode('、', $res['notified']));
$st = array_column(psr_orders($e2, $m2), 'state', 'order_id');
$check($st[$ids2[0]] === 'correcting', '更正中的订单不能同时申请结算');
$check($throws(function () use ($act2, $m2, $ids2) { psr_submit($act2, $m2, [$ids2[0]]); }) !== null, '更正中的订单提交结算被拒');
$check($throws(function () use ($act2, $m2, $ids2) { psr_correct($act2, $m2, $ids2, '这几单的成本填错了，应该更低，请核对'); }) !== null, '重复提交更正被拒（已有待处理）');
$heads = $pdo->query("SELECT employee_id FROM project_dept_heads WHERE department=" . $pdo->quote($dept) . " AND employee_id<>$e2")->fetchAll(PDO::FETCH_COLUMN);
$msgs = (int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE category='commission_correction' AND dedupe_key LIKE 'settle-corr:$e2:$m2:%' AND employee_id IN (" . implode(',', array_map('intval', $heads)) . ")")->fetchColumn();
$check($msgs >= 1, '主管收到站内信');
$corrId = (int)$pdo->query("SELECT id FROM project_commission_corrections WHERE target_employee_id=$e2 AND order_id={$ids2[0]} AND status='pending'")->fetchColumn();
$row = $pdo->query("SELECT * FROM project_commission_corrections WHERE id=$corrId")->fetch();
$headActor = ['type' => 'employee', 'id' => 1, 'employee_id' => (int)$heads[0], 'role' => 'customer_service'];
$stranger = ['type' => 'employee', 'id' => 2, 'employee_id' => $eid, 'role' => 'customer_service'];
$check(ps_corr_can_handle($headActor, $row) && ps_corr_can_handle($fin, $row) && !ps_corr_can_handle($stranger, $row), '主管和财务可处理，无关的人不行');
$check($throws(function () use ($corrId, $stranger) { ps_corr_handle($corrId, $stranger, 'resolved', '无权'); }) !== null, '无关人员处理被拒');
ps_corr_handle($corrId, $headActor, 'resolved', '已核对，按你说的调整');
$check($pdo->query("SELECT status FROM project_commission_corrections WHERE id=$corrId")->fetchColumn() === 'resolved', '主管处理成功');
$check((int)$pdo->query("SELECT COUNT(*) FROM project_messages WHERE employee_id=$e2 AND dedupe_key='corr_done:$corrId'")->fetchColumn() === 1, '申请人收到处理结果');
$st = array_column(psr_orders($e2, $m2), 'state', 'order_id');
$check($st[$ids2[0]] === 'available', '更正处理后该订单恢复可申请结算');
echo "\n=== 结算申请测试全部通过 ===\n";
