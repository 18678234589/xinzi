<?php
@ini_set('memory_limit', '128M');
require_once __DIR__ . '/config/database.php';
$pdo = db();
$ono = $_GET['no'] ?? '58684967301N';

echo "<pre>\n=== 诊断订单号 {$ono} ===\n\n";

// 员工订单
$emp = $pdo->prepare("SELECT id, order_no, order_amount, shop, raw_data FROM orders WHERE order_no=? AND order_scope='personal' LIMIT 5");
$emp->execute([$ono]);
$empRows = $emp->fetchAll();
echo "--- 员工 personal 订单 ---\n";
foreach ($empRows as $r) {
    $raw = json_decode($r['raw_data'], true);
    $shopRaw = '';
    foreach (['店铺','店铺名称','店铺名','店名','shop'] as $k) {
        if (is_array($raw) && isset($raw[$k]) && !empty($raw[$k])) { $shopRaw = $raw[$k]; break; }
    }
    echo "ID={$r['id']} shop列={$r['shop']} raw_data店铺列=[{$shopRaw}]\n";
    echo "  order_amount={$r['order_amount']}\n";
    if (is_array($raw)) {
        foreach ($raw as $k => $v) { if (strpos($k,'__')!==0) echo "  [{$k}]={$v}\n"; }
    }
}

// 店铺订单
$shop = $pdo->prepare("SELECT id, order_no, order_amount, shop, raw_data FROM orders WHERE order_no=? AND order_scope='department' LIMIT 5");
$shop->execute([$ono]);
$shopRows = $shop->fetchAll();
echo "\n--- 店铺 department 订单 ---\n";
foreach ($shopRows as $r) {
    echo "ID={$r['id']} shop列={$r['shop']} order_amount={$r['order_amount']}\n";
}

// shops表
echo "\n--- shops 表中含'科恒'的店铺 ---\n";
$shops = $pdo->query("SELECT id, name FROM shops WHERE name LIKE '%科恒%' OR name LIKE '%扫码%'")->fetchAll();
foreach ($shops as $s) echo "  id={$s['id']} name=[{$s['name']}]\n";

// department 订单中 shop 列的去重值（含科恒）
echo "\n--- department 订单中 shop 列含'科恒'的去重值 ---\n";
$deptShops = $pdo->query("SELECT DISTINCT shop FROM orders WHERE order_scope='department' AND shop LIKE '%科恒%'")->fetchAll();
foreach ($deptShops as $s) echo "  [{$s['shop']}]\n";

echo "\n</pre>\n";
