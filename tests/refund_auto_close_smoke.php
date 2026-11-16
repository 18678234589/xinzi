<?php
// 退款自动处理：没有项目订单、但店铺流水里有、过了 14 天 → 自动结案为“未报单”；订单后来录进来 → 自动重新打开；流水里也没有的不自动结案。
// 会写入测试数据，只允许专用测试库：DB_NAME=xinzi_test DB_HOST=127.0.0.1 DB_PORT=13399 php tests/refund_auto_close_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/require_isolated_database.php';
require_once __DIR__ . '/../includes/ProjectRefundImport.php';
require_once __DIR__ . '/../includes/ProjectRefundMatch.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); require_isolated_test_database($pdo); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$system = ps_refund_system_actor();
$old = date('Y-m-d', strtotime('-20 days')); $recent = date('Y-m-d', strtotime('-3 days'));
$num = (string)mt_rand(100000, 999999); $noInFlow = '5127' . $num . '000111'; $noRecent = '5127' . $num . '000222'; $noNoFlow = '5127' . $num . '000333';
$orderIds = []; $flowIds = []; $refundIds = [];
$flow = function ($no) use ($pdo, &$flowIds) {
    $pdo->prepare("INSERT INTO orders (employee_id,order_amount,order_date,shop,order_no,raw_data,is_abnormal,abnormal_reason,order_scope) VALUES (0,50,?,'美呀美旗舰店',?,?,0,'','department')")->execute([date('Y-m-d', strtotime('-30 days')), $no, json_encode(['__shop__' => '美呀美旗舰店', '__original_price__' => 50, '__order_status__' => '交易成功'], JSON_UNESCAPED_UNICODE)]);
    $flowIds[] = (int)$pdo->lastInsertId();
};
$refund = function ($no, $date) use ($pdo, $tag, &$refundIds) {
    $pdo->prepare("INSERT INTO project_refund_import_rows (fingerprint,order_id,order_no,refund_date,amount,payment_method,source_payment_reference,payment_reference,reason,review_status,submitted_by_type,submitted_by_id) VALUES (?,NULL,?,?,50,'待核渠道','','','','pending','admin',1)")->execute([sha1($no . $tag), $no, $date]);
    $refundIds[] = (int)$pdo->lastInsertId(); return (int)$pdo->lastInsertId();
};
$state = function ($id) use ($pdo) { return $pdo->query("SELECT review_status,order_id FROM project_refund_import_rows WHERE id=$id")->fetch(); };
try {
    $flow($noInFlow); $flow($noRecent);
    $r1 = $refund($noInFlow, $old); $r2 = $refund($noRecent, $recent); $r3 = $refund($noNoFlow, $old);
    $n = prm_auto_unreported($system, 14, 500);
    $check($state($r1)['review_status'] === 'approved' && $n >= 1, '流水里有、没有项目订单、过了 14 天：自动结案为未报单');
    $check($state($r2)['review_status'] === 'pending', '才退了 3 天的：先不结案，留时间给业务录订单');
    $check($state($r3)['review_status'] === 'pending', '流水里也没有的：不自动结案（可能是缺流水）');
    $check((int)$pdo->query("SELECT COUNT(*) FROM project_audit_logs WHERE entity_type='refund_import' AND entity_id=$r1 AND action='auto_unreported'")->fetchColumn() === 1, '自动结案记了审计');
    // 订单后来录进来 → 重新打开并关联
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','设计','','美呀美旗舰店',50,?,'unfinished','draft','测试')")->execute([$noInFlow, date('Y-m-d', strtotime('-30 days'))]);
    $orderIds[] = (int)$pdo->lastInsertId();
    $k = prm_auto_reopen($system, 500);
    $s = $state($r1);
    $check($k >= 1 && $s['review_status'] === 'pending' && (int)$s['order_id'] === $orderIds[0], '订单补录后：退款重新打开并关联到订单，等自动扣减');
    // 已核算月份：该月及以前、项目系统里没有订单的退款留档结案；已核算月份之后的不动
    $prevSettled = ps_setting_get('refund_settled_through', '');
    ps_setting_set('refund_settled_through', date('Y-m', strtotime('-2 months')), 1);
    try {
        $noHist = '5127' . $num . '000444'; $noAfter = '5127' . $num . '000555'; $noPartial = '5127' . $num . '000666';
        $flow($noHist); $flow($noAfter); $flow($noPartial);
        $h1 = $refund($noHist, date('Y-m-15', strtotime('-3 months'))); $h2 = $refund($noAfter, $recent); $h3 = $refund($noPartial, date('Y-m-15', strtotime('-3 months')));
        $pdo->exec("UPDATE project_refund_import_rows SET amount=10 WHERE id=$h3"); // 流水里订单 50 元：h1 / h2 整单退完，h3 只退 10 元 = 部分退款
        $a = prm_auto_archive_history($system, 500);
        $check($a >= 1 && $state($h1)['review_status'] === 'approved', 'archive: full refund in settled month without project order is closed');
        $check($state($h2)['review_status'] === 'pending', 'archive: refund after settled month untouched');
        $check($state($h3)['review_status'] === 'pending', 'archive: partial refund is never closed as unreported');
        // 已核算月份改早一个月后，被留档的退款重新打开
        ps_setting_set('refund_settled_through', date('Y-m', strtotime('-4 months')), 1);
        prm_auto_archive_history($system, 500);
        $check($state($h1)['review_status'] === 'pending', 'archive: settled month moved earlier -> archived refund reopened');
    } finally { ps_setting_set('refund_settled_through', (string)$prevSettled, 1); }
    // 小额返款：对不上订单的自动结案；订单号能对上项目订单的不动
    $noSmall = '5127' . $num . '000777';
    $s1 = $refund($noSmall, $recent); $pdo->exec("UPDATE project_refund_import_rows SET amount=10, reason='" . PRM_SMALL_TAG . "' WHERE id=$s1");
    $sc = prm_auto_close_small($system, 500);
    $check($sc >= 1 && $state($s1)['review_status'] === 'approved', 'small refund without project order is closed with reason');
    $sr = prm_auto_reopen($system, 500);
    $check($state($s1)['review_status'] === 'approved', 'closed small refund is not reopened by order-number reopen');
    echo "全部通过\n";
} finally {
    foreach ($refundIds as $id) { $pdo->exec("DELETE FROM project_refund_import_rows WHERE id=$id"); $pdo->exec("DELETE FROM project_audit_logs WHERE entity_type='refund_import' AND entity_id=$id"); }
    foreach ($orderIds as $id) $pdo->exec("DELETE FROM project_orders WHERE id=$id");
    foreach ($flowIds as $id) $pdo->exec("DELETE FROM orders WHERE id=$id");
}
