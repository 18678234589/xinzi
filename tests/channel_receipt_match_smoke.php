<?php
// 收款渠道流水自动对上：订单表里店铺写“微信 / 对公”，流水店铺叫“科恒扫码收款 / 对公收款”；流水用“交易金额 / 金额”列、订单靠“检索号”对上。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/channel_receipt_match_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectAutoReview.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$oids = []; $lids = [];
$mkOrder = function ($suffix, $shop, $amount) use ($pdo, $tag, &$oids) {
    $no = "WX-{$tag}{$suffix}"; $ref = "9{$tag}{$suffix}N";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','森动备案','备案',?,?,'2026-09-07','finished','draft','测试')")->execute([$no, $shop, $amount]);
    $id = (int)$pdo->lastInsertId(); $oids[] = $id;
    $pdo->prepare("INSERT INTO project_order_sources (order_id,payment_nickname,payment_reference,price_source) VALUES (?,?,?,'manual')")->execute([$id, '测试昵称', $ref]);
    return [$id, $ref];
};
$mkFlow = function ($shop, $ref, $amountKey, $amount) use ($pdo, &$lids) {
    $raw = ['交易时间' => '2026-09-07 09:00:00', $amountKey => (string)$amount, '检索号' => $ref, '__shop__' => $shop, '__financial_source__' => 'shop_statement', '__statement_uploaded_at__' => date('Y-m-d H:i:s'), '__original_price__' => $amount, '__trade_time__' => '2026-09-07 09:00:00', '__order_status__' => '交易成功'];
    $pdo->prepare("INSERT INTO orders (employee_id,order_amount,order_date,shop,order_no,raw_data,is_abnormal,abnormal_reason,order_scope) VALUES (0,?,'2026-09-07',?,?,?,0,'','department')")->execute([$amount, $shop, $ref, json_encode($raw, JSON_UNESCAPED_UNICODE)]);
    $lids[] = (int)$pdo->lastInsertId();
};
$evidence = function ($id) use ($pdo) {
    $o = $pdo->query("SELECT * FROM project_orders WHERE id=$id")->fetch(); $s = $pdo->query("SELECT * FROM project_order_sources WHERE order_id=$id")->fetch() ?: [];
    return pa_payment_evidence(pa_sources($o, $s, false), trim((string)$o['shop']), date('Y-m-d H:i:s'));
};
try {
    [$id1, $ref1] = $mkOrder('A', '微信', 100); $mkFlow('科恒扫码收款', $ref1, '交易金额', 100);
    $ev = $evidence($id1);
    $check($ev['paid_cents'] === 10000, '订单店铺写“微信”、流水在“科恒扫码收款”（交易金额列）：自动认到实收 ¥100');
    [$id2, $ref2] = $mkOrder('B', '对公', 100); $mkFlow('对公收款', $ref2, '金额', 100);
    $check($evidence($id2)['paid_cents'] === 10000, '订单店铺写“对公”、流水在“对公收款”（金额列）：自动认到实收 ¥100');
    [$id3, $ref3] = $mkOrder('C', '美呀美旗舰店', 100); $mkFlow('科恒扫码收款', $ref3, '交易金额', 100);
    $check($evidence($id3)['paid_cents'] === null, '普通店铺的订单不会被扫码收款流水顶替（店铺不同仍不算）');
    [$id4, $ref4] = $mkOrder('D', '微信', 100); $mkFlow('对公收款', $ref4, '金额', 100);
    $check($evidence($id4)['paid_cents'] === null, '微信订单不会被对公流水认走（渠道不同）');
    echo "全部通过\n";
} finally {
    foreach ($oids as $id) { $pdo->exec("DELETE FROM project_order_sources WHERE order_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    foreach ($lids as $id) $pdo->exec("DELETE FROM orders WHERE id=$id");
}
