<?php
@ini_set('memory_limit', '128M');
require_once __DIR__ . '/config/database.php';
$pdo = db();
$ono = '58684967301N';

echo "<pre>\n=== 诊断 match_shop_name 流程 ===\n\n";

// 模拟 get_abnormal_orders 的 SQL 查询
$empStmt = $pdo->prepare(
    "SELECT e.id, e.order_no, e.order_amount, emp.name AS emp_name,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"__original_price__\"')) AS emp_orig_price,
            COALESCE(
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店铺\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店铺名称\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店铺名\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店名\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.shop'))
            ) AS emp_shop_raw
     FROM orders e LEFT JOIN employees emp ON emp.id = e.employee_id
     WHERE e.order_scope = 'personal' AND e.order_no = ?
     LIMIT 5"
);
$empStmt->execute([$ono]);
$empRows = $empStmt->fetchAll();

echo "员工订单 SQL 结果：\n";
foreach ($empRows as $r) {
    echo "  ID={$r['id']} emp_shop_raw=[{$r['emp_shop_raw']}] emp_orig_price=[{$r['emp_orig_price']}] order_amount={$r['order_amount']}\n";
}

// shops 表
$allShops = $pdo->query("SELECT id, name FROM shops ORDER BY sort ASC, id ASC")->fetchAll();
$shopNames = array_column($allShops, 'name');
echo "\nshops 表店铺名：\n";
foreach ($shopNames as $n) echo "  [{$n}]\n";

// 模拟 match_shop_name
require_once __DIR__ . '/includes/functions.php';
$empShopRaw = trim($empRows[0]['emp_shop_raw'] ?? '');
echo "\nempShopRaw=[{$empShopRaw}]\n";
$matched = match_shop_name($empShopRaw, $shopNames);
echo "match_shop_name 结果=[{$matched}]\n";

// 确认 shopMap 里的 key
$shopStmt = $pdo->query("SELECT DISTINCT shop FROM orders WHERE order_scope='department' AND shop<>''");
$deptShops = [];
foreach ($shopStmt as $r) $deptShops[] = $r['shop'];
echo "\ndepartment 订单 shop 列去重：\n";
foreach ($deptShops as $s) echo "  [{$s}]\n";

$knownShopNames = array_unique(array_merge($deptShops, $shopNames));
echo "\nknownShopNames（合并去重）：\n";
foreach ($knownShopNames as $n) echo "  [{$n}]\n";

$matched2 = match_shop_name($empShopRaw, $knownShopNames);
echo "\n用 knownShopNames 匹配结果=[{$matched2}]\n";
echo "\n</pre>\n";
