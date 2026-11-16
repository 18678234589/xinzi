<?php
// 补建原订单并扣减：退款对应订单客服当时没报单、店铺流水里有 → 财务按流水补建订单（锁定）并走正常退款审核。
// 会写入测试数据，只允许专用测试库：DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/refund_rebuild_order_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectRefundImport.php';
require_once __DIR__ . '/../includes/ProjectRefundMatch.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$throws = function ($fn, $needle, $label) use ($check) {
    try { $fn(); $check(false, $label . '（应报错）'); } catch (RuntimeException $e) { $check(strpos($e->getMessage(), $needle) !== false, $label); }
};
$finance = ['type' => 'admin', 'id' => 1, 'role' => 'finance'];
$no = '5127' . mt_rand(100000, 999999) . '000888';
$orderDate = date('Y-m-d', strtotime('first day of -3 months')); $refundDate = date('Y-m-d', strtotime('first day of last month'));
$flowId = $empId = $refundId = 0; $prevSettled = ps_setting_get('refund_settled_through', '');
try {
    $pdo->prepare("INSERT INTO employees (name,department,password) VALUES (?,?,?)")->execute(['rb_' . $tag, '网站客服', bin2hex(random_bytes(8))]); $empId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO orders (employee_id,order_amount,order_date,shop,order_no,raw_data,is_abnormal,abnormal_reason,order_scope) VALUES (0,300,?,'美呀美旗舰店',?,?,0,'','department')")
        ->execute([$orderDate, $no, json_encode(['__financial_source__' => 'shop_statement'], JSON_UNESCAPED_UNICODE)]); $flowId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_refund_import_rows (fingerprint,order_id,order_no,refund_date,amount,payment_method,source_payment_reference,payment_reference,reason,review_status,submitted_by_type,submitted_by_id) VALUES (?,NULL,?,?,275,'待核渠道','','','测试','pending','admin',1)")
        ->execute(['rb' . $tag, $no, $refundDate]); $refundId = (int)$pdo->lastInsertId();
    ps_setting_set('refund_settled_through', '', 1);

    $throws(function () use ($refundId, $empId) { prm_rebuild_order($refundId, '网站模板', $empId, '店铺', ['type' => 'employee', 'id' => 5, 'role' => 'customer_service']); }, '仅财务', '非财务不能补建');
    $throws(function () use ($refundId, $empId, $finance) { prm_rebuild_order($refundId, '设计', $empId, '店铺', $finance); }, '业务类型', '业务只能是网站类');
    $throws(function () use ($refundId, $finance) { prm_rebuild_order($refundId, '网站模板', 0, '店铺', $finance); }, '接单客服', '必须选客服');
    $throws(function () use ($refundId, $empId, $finance) { prm_rebuild_order($refundId, '网站模板', $empId, '待核渠道', $finance); }, '渠道', '必须确认渠道');

    $res = prm_rebuild_order($refundId, '网站模板', $empId, '店铺', $finance);
    $o = $pdo->query("SELECT * FROM project_orders WHERE id=" . (int)$res['order_id'])->fetch();
    $check($o && $o['order_no'] === $no && $o['project_type'] === '网站模板' && (float)$o['contract_amount'] === 300.0 && $o['order_date'] === $orderDate, '按流水建出订单（订单号/业务/金额/日期）');
    $check($o['settlement_status'] === 'locked', '补建的订单已锁定，不进所属月份报酬');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_participants WHERE order_id=" . (int)$o['id'] . " AND employee_id=$empId AND commission_group='customer_service'")->fetchColumn() === 1, '挂上了接单客服');
    $r = $pdo->query("SELECT review_status,order_id FROM project_refund_import_rows WHERE id=$refundId")->fetch();
    $check($r['review_status'] === 'approved' && (int)$r['order_id'] === (int)$o['id'], '退款已核准并关联到补建订单');
    $check((float)$pdo->query("SELECT refund_amount FROM project_orders WHERE id=" . (int)$o['id'])->fetchColumn() === 275.0, '订单记了退款 275');
    $check($res['adjustment'] <= 0 && $res['month'] === substr($refundDate, 0, 7), '补扣记在退款当月（金额非正）');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='order' AND entity_id=" . (int)$o['id'] . " AND action='rebuild_for_refund'")->fetchColumn() === 1, '记了审计');
    $throws(function () use ($refundId, $empId, $finance) { prm_rebuild_order($refundId, '网站模板', $empId, '店铺', $finance); }, '已处理', '不能重复补建');
    echo "全部通过\n";
} finally {
    ps_setting_set('refund_settled_through', (string)$prevSettled, 1);
    $oids = $pdo->query("SELECT id FROM project_orders WHERE order_no=" . $pdo->quote($no))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($oids as $oid) foreach (['project_commission_adjustments', 'project_participants', 'project_cash_movements', 'project_order_sources'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE order_id=" . (int)$oid); } catch (Throwable $e) {} }
    foreach ($oids as $oid) { $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=" . (int)$oid); $pdo->exec("DELETE FROM project_orders WHERE id=" . (int)$oid); }
    if ($refundId) { $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='refund_import' AND entity_id=$refundId"); $pdo->exec("DELETE FROM project_refund_import_rows WHERE id=$refundId"); }
    if ($flowId) $pdo->exec("DELETE FROM orders WHERE id=$flowId");
    if ($empId) { foreach (['project_messages', 'project_commission_adjustments'] as $t) { try { $pdo->exec("DELETE FROM $t WHERE employee_id=$empId"); } catch (Throwable $e) {} } $pdo->exec("DELETE FROM employees WHERE id=$empId"); }
}
