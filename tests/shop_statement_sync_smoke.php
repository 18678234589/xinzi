<?php
// 店铺流水 → 项目订单自动补充：项目订单上手填的“对公转账 / 对公转款”等店铺，与流水店铺“对公收款”算同一来源；其它店铺仍须完全相同。
// 会写入测试数据，只允许本地临时库：DB_HOST=127.0.0.1 DB_PORT=13399 php tests/shop_statement_sync_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
if ((string)DB_PORT !== '13399' || DB_HOST !== '127.0.0.1') { fwrite(STDERR, "拒绝运行：本测试会写入数据，只能连本地临时库（DB_PORT=13399）\n"); exit(2); }
$pdo = db(); $tag = bin2hex(random_bytes(3));
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$ids = []; $legacy = [];
$mkProject = function ($suffix, $shop, $status = 'draft') use ($pdo, $tag, &$ids) {
    $no = "龙{$tag}{$suffix}";
    $pdo->prepare("INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status,note) VALUES (?,'测试','网站续费','续费',?,100,'2026-08-05','unfinished',?,'测试')")->execute([$no, $shop, $status]);
    $ids[] = (int)$pdo->lastInsertId();
    return [$no, (int)$pdo->lastInsertId()];
};
$mkStatement = function ($no, $shop, $nick, $status) use ($pdo, &$legacy) {
    $raw = ['时间' => '2026-08-05 10:00:00', '金额' => '100', '付款账户名称' => $nick, '订单编号' => $no, '__shop__' => $shop, '__original_price__' => 100, '__order_status__' => $status, '__trade_time__' => '2026-08-05 10:00:00'];
    $pdo->prepare("INSERT INTO orders (employee_id,order_amount,order_date,shop,order_no,raw_data,is_abnormal,abnormal_reason,order_scope) VALUES (0,100,'2026-08-05',?,?,?,0,'','department')")->execute([$shop, $no, json_encode($raw, JSON_UNESCAPED_UNICODE)]);
    $legacy[] = (int)$pdo->lastInsertId();
};
$src = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT * FROM project_order_sources WHERE order_id=?'); $q->execute([$id]); return $q->fetch() ?: []; };
try {
    $check(ps_shop_compatible('对公转账', '对公收款') && ps_shop_compatible('知网龙源对公转账', '对公收款') && ps_shop_compatible('', '对公收款') && ps_shop_compatible('对公收款', '对公收款'), '对公类店铺名互相兼容，空店铺也兼容');
    $check(!ps_shop_compatible('美呀美旗舰店', '对公收款') && !ps_shop_compatible('美呀美旗舰店', '清风易软件专营店'), '不同的普通店铺、或对公与普通店铺不算同一来源');
    [$no1, $id1] = $mkProject('A', '对公转账'); $mkStatement($no1, '对公收款', '甲公司' . $tag, '交易成功');
    $check(ps_sync_existing_shop_order($id1, $no1, '对公转账') === true, '项目订单店铺写“对公转账”：能同步到“对公收款”流水');
    $s = $src($id1);
    $check($s['payment_nickname'] === '甲公司' . $tag && $s['trade_status'] === '交易成功' && $s['status_source'] === 'shop_upload', '付款昵称和交易状态补进来了');
    [$no2, $id2] = $mkProject('B', '美呀美旗舰店'); $mkStatement($no2, '对公收款', '乙公司' . $tag, '交易成功');
    $check(ps_sync_existing_shop_order($id2, $no2, '美呀美旗舰店') === false && ($src($id2)['payment_nickname'] ?? '') === '', '店铺是普通店铺的订单不会被对公流水误同步');
    [$no3, $id3] = $mkProject('C', '对公订单', 'approved'); $mkStatement($no3, '对公收款', '丙公司' . $tag, '交易成功');
    $check(ps_sync_existing_shop_order($id3, $no3, '对公订单') === false, '已审核订单不动');
    // 之后流水里状态前进（交易成功 → 交易关闭）自动同步
    $check(ps_sync_project_status_latest($no1, '对公收款', '交易关闭') === true && $src($id1)['trade_status'] === '交易关闭', '流水状态变成交易关闭：项目订单的交易状态跟着前进');
    $check(ps_sync_project_status_latest($no1, '对公收款', '交易成功') === false, '状态不会回退');
    echo "全部通过\n";
} finally {
    foreach ($ids as $id) { $pdo->exec("DELETE FROM project_order_sources WHERE order_id=$id"); $pdo->exec("DELETE FROM project_orders WHERE id=$id"); }
    foreach ($legacy as $id) $pdo->exec("DELETE FROM orders WHERE id=$id");
}
