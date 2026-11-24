<?php
// 分成更正申请：合作人员提交 → 财务通过后记分成调整 / 手工项；只能为自己的订单申请；驳回不改金额。测试数据结束后清理。
// DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/fix_request_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectMonthly.php';
require_once __DIR__ . '/../includes/ProjectFixRequest.php';
$pdo = db(); $made = [];
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function ($fn, $needle, $label) use ($check) { try { $fn(); $check(false, $label . '（应报错）'); } catch (RuntimeException $e) { $check(strpos($e->getMessage(), $needle) !== false, $label . '：' . $e->getMessage()); } };
try {
    pfr_ensure();
    $finance = ['type' => 'admin', 'id' => (int)$pdo->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetchColumn(), 'employee_id' => null, 'role' => 'finance'];
    $emps = $pdo->query('SELECT id FROM employees ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN); $emp = (int)$emps[0]; $other = (int)$emps[1];
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,settlement_status,note) VALUES ('FIX-T1','测试','网站模板','新订单','美呀美旗舰店',400,400,'2027-05-05','finished','draft','测试')")->execute();
    $oid = (int)$pdo->lastInsertId(); $made[] = $oid;
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$oid, $emp]);
    $pdo->prepare("INSERT INTO project_order_resources (order_id,source_type,domain_mode) VALUES (?,'manual','none')")->execute([$oid]);
    $partner = ['type' => 'employee', 'id' => 0, 'role' => 'customer_service', 'employee_id' => $emp];
    $stranger = ['type' => 'employee', 'id' => 0, 'role' => 'customer_service', 'employee_id' => $other];
    $throws(function () use ($stranger, $oid, $emp) { pfr_submit($stranger, $oid, $emp, '2027-05', '10', '', '', '原因原因原因'); }, '只能为自己', '不能为别人的订单申请');
    $throws(function () use ($partner, $oid, $emp) { pfr_submit($partner, $oid, $emp, '2027-05', '10', '', '', '原因原因原因'); }, '还没有审核生成分成', '订单没审核不能直接改金额');
    ps_approve_order($oid, $finance, '2027-05');
    $cur = pfr_current($oid, $emp); $now = array_values($cur)[0];
    $check($now > 0, '订单审核后有分成：¥' . $now);
    $throws(function () use ($partner, $oid, $emp) { pfr_submit($partner, $oid, $emp, '2027-05', '', '', '', '原因原因原因'); }, '至少填写', '金额和其他项都不填会拦住');
    $throws(function () use ($partner, $oid, $emp) { pfr_submit($partner, $oid, $emp, '2027-05', '10', '', '', '短'); }, '原因', '原因太短会拦住');
    $id = pfr_submit($partner, $oid, $emp, '2027-05', '10.24', '定制补助', '20', '成本按售价 65%、服务费 3%，应为 10.24；另有定制补助 20');
    $throws(function () use ($partner, $oid, $emp) { pfr_submit($partner, $oid, $emp, '2027-05', '9', '', '', '原因原因原因'); }, '待财务处理', '同一订单已有待处理申请时不能重复提交');
    $throws(function () use ($partner, $id) { pfr_handle($partner, $id, 'approved', ''); }, '仅财务', '合作人员不能自己处理');
    $res = pfr_handle($finance, $id, 'approved', '');
    $adj = (float)$pdo->query("SELECT SUM(amount) FROM project_commission_adjustments WHERE order_id=$oid AND employee_id=$emp")->fetchColumn();
    $check(abs(($now + $adj) - 10.24) < 0.005, '通过后分成更正为 10.24（调整 ' . number_format($adj, 2) . '）：' . $res);
    $item = $pdo->query("SELECT amount FROM project_payroll_manual_items WHERE employee_id=$emp AND payroll_month='2027-05' AND label='定制补助'")->fetchColumn();
    $check($item !== false && (float)$item === 20.0, '其他项记为手工项 +20');
    $check($pdo->query("SELECT status FROM project_fix_requests WHERE id=$id")->fetchColumn() === 'approved', '申请标为已通过');
    $id2 = pfr_submit($partner, $oid, $emp, '2027-05', '5', '', '', '我觉得应该是 5');
    $before = (float)$pdo->query("SELECT SUM(amount) FROM project_commission_adjustments WHERE order_id=$oid AND employee_id=$emp")->fetchColumn();
    $throws(function () use ($finance, $id2) { pfr_handle($finance, $id2, 'rejected', ''); }, '驳回请写明原因', '驳回必须写原因');
    pfr_handle($finance, $id2, 'rejected', '按规则 10% 没错');
    $after = (float)$pdo->query("SELECT SUM(amount) FROM project_commission_adjustments WHERE order_id=$oid AND employee_id=$emp")->fetchColumn();
    $check(abs($after - $before) < 0.005 && $pdo->query("SELECT status FROM project_fix_requests WHERE id=$id2")->fetchColumn() === 'rejected', '驳回不改金额');
    // 网站业务的“已完成”只认本订单技术和财务
    $pdo->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','模板技术',1)")->execute([$oid, $other]);
    $techActor = ['type' => 'employee', 'id' => 0, 'role' => 'technical', 'employee_id' => $other];
    $check(ps_can_mark_order_finished($partner, $oid), '客服可以随手给网站订单标已完成（汇总表登记未完成的除外）');
    $check(ps_can_mark_order_finished($techActor, $oid), '本订单的技术可以标已完成');
    $check(ps_can_mark_order_finished($finance, $oid), '财务可以标已完成');
    $check(!ps_can_mark_order_finished(['type' => 'employee', 'id' => 0, 'role' => 'technical', 'employee_id' => 999999], $oid), '不是本订单的技术不能标');
    // 撤销审核：仅财务；已核算月份不能撤；撤销后快照删除、订单回草稿
    $throws(function () use ($partner, $oid) { ps_revoke_approval($oid, $partner, '实际未完成'); }, '仅财务', '合作人员不能撤销审核');
    $throws(function () use ($finance, $oid) { ps_revoke_approval($oid, $finance, '短'); }, '撤销原因', '撤销必须写原因');
    $prevSettled = ps_setting_get('refund_settled_through', ''); ps_setting_set('refund_settled_through', '2027-05', 1);
    try { $throws(function () use ($finance, $oid) { ps_revoke_approval($oid, $finance, '实际未完成，撤销审核'); }, '已核算或锁定', '分成月份已核算：不能撤销'); } finally { ps_setting_set('refund_settled_through', (string)$prevSettled, 1); }
    $n = ps_revoke_approval($oid, $finance, '实际未完成，撤销审核');
    $st = $pdo->query("SELECT settlement_status,delivery_status FROM project_orders WHERE id=$oid")->fetch();
    $check($n >= 1 && $st['settlement_status'] === 'draft' && $st['delivery_status'] === 'unfinished' && (int)$pdo->query("SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=$oid")->fetchColumn() === 0, '撤销审核：分成快照删除、订单回到草稿、交付改为未完成');
    echo "全部通过\n";
} finally {
    foreach ($made as $oid) {
        foreach (['project_fix_requests', 'project_commission_adjustments', 'project_commission_snapshots', 'project_cash_movements', 'project_costs', 'project_order_resources', 'project_order_sources', 'project_participants', 'project_auto_reviews'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id=$oid"); } catch (Throwable $e) {} }
        $pdo->exec("DELETE FROM project_payroll_manual_items WHERE note LIKE '%FIX-T1%'");
        $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=$oid"); $pdo->exec("DELETE FROM project_orders WHERE id=$oid");
    }
}
