<?php
@ini_set('memory_limit', '256M');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
$pdo = db();
$ono = '58684967301N';

echo "<pre>\n=== 模拟 get_abnormal_orders 对订单 {$ono} 的处理 ===\n\n";

// 1. 取员工订单（和函数里一样的SQL）
$empStmt = $pdo->prepare(
    "SELECT e.id, e.employee_id, e.order_no, e.order_amount, e.order_date, emp.name AS emp_name,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"__original_price__\"')) AS emp_orig_price,
            COALESCE(
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店铺\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店铺名称\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店铺名\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.\"店名\"')),
              JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '\$.shop'))
            ) AS emp_shop_raw
     FROM orders e LEFT JOIN employees emp ON emp.id = e.employee_id
     WHERE e.order_scope = 'personal' AND e.order_no <> ''
       AND (e.is_deleted = 0 OR e.is_deleted IS NULL)
       AND e.order_no = ?
     LIMIT 5"
);
$empStmt->execute([$ono]);
$empOrders = $empStmt->fetchAll();

// 2. 取所有 department 订单建 shopMap
$shopStmt = $pdo->query(
    "SELECT o.id, o.shop, o.order_no, o.order_amount, o.order_date,
            JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '\$.\"__original_price__\"')) AS shop_orig_price
     FROM orders o
     WHERE o.order_scope = 'department' AND o.order_no <> ''
       AND (o.is_deleted = 0 OR o.is_deleted IS NULL)
     ORDER BY o.id ASC LIMIT 5000"
);
$shopMap = [];
$deptByNo = [];
foreach ($shopStmt as $so) {
    $sn = $so['shop'] !== '' ? $so['shop'] : '未归属店铺';
    if (!isset($shopMap[$sn][$so['order_no']])) {
        $shopMap[$sn][$so['order_no']] = $so;
    }
    if (!isset($deptByNo[$so['order_no']])) {
        $deptByNo[$so['order_no']] = $so;
    }
}

// 3. 取 shops 表
$allShops = $pdo->query("SELECT id, name FROM shops ORDER BY sort ASC, id ASC")->fetchAll();
$knownShopNames = array_unique(array_merge(array_keys($shopMap), array_column($allShops, 'name')));

echo "knownShopNames: " . implode(', ', $knownShopNames) . "\n\n";

// 4. 逐条处理
foreach ($empOrders as $eo) {
    echo "--- 处理员工订单 ID={$eo['id']} ---\n";
    echo "  order_no={$eo['order_no']}\n";
    echo "  emp_shop_raw=[{$eo['emp_shop_raw']}]\n";
    
    $empShopRaw = trim($eo['emp_shop_raw'] ?? '');
    $empShop = $empShopRaw !== '' ? match_shop_name($empShopRaw, $knownShopNames) : '';
    echo "  match_shop_name('{$empShopRaw}') = [{$empShop}]\n";
    
    $empOriginalPrice = $eo['emp_orig_price'] !== null && (float)$eo['emp_orig_price'] > 0
        ? (float)$eo['emp_orig_price'] : (float)$eo['order_amount'];
    echo "  empOriginalPrice={$empOriginalPrice}\n";
    
    // 判断走哪个分支
    if ($empShop === '') {
        echo "  → empShop 为空，进入 deptByNo 反查分支\n";
        if (isset($deptByNo[$eo['order_no']])) {
            $so = $deptByNo[$eo['order_no']];
            $empShop = $so['shop'];
            echo "  → deptByNo 找到！empShop 改为 [{$empShop}]\n";
        } else {
            echo "  → deptByNo 也没找到 → 店铺缺失（孤儿）\n";
        }
    }
    
    if ($empShop !== '') {
        echo "  → empShop=[{$empShop}]，查 shopMap\n";
        $sOrders = $shopMap[$empShop] ?? [];
        echo "  → shopMap[{$empShop}] 有 " . count($sOrders) . " 条订单\n";
        
        if (isset($sOrders[$eo['order_no']])) {
            $so = $sOrders[$eo['order_no']];
            $shopOriginalPrice = $so['shop_orig_price'] !== null && (float)$so['shop_orig_price'] > 0
                ? (float)$so['shop_orig_price'] : (float)$so['order_amount'];
            $diff = round($empOriginalPrice - $shopOriginalPrice, 2);
            echo "  → shopMap 找到此订单号！\n";
            echo "  → 店铺售价={$shopOriginalPrice}, 员工售价={$empOriginalPrice}, 差异={$diff}\n";
            if (abs($diff) > 0.001) {
                echo "  → 结果：售价不一致\n";
            } else {
                echo "  → 结果：一致（不记录）\n";
            }
        } else {
            echo "  → shopMap[{$empShop}] 里没有订单号 {$eo['order_no']}\n";
            echo "  → shopMap[{$empShop}] 的所有订单号（前20个）:\n";
            $keys = array_keys($sOrders);
            for ($i = 0; $i < min(20, count($keys)); $i++) {
                echo "      [{$keys[$i]}]\n";
            }
            echo "  → 结果：店铺缺失\n";
        }
    }
    echo "\n";
}

// 5. 额外检查：这个订单号在 department 表里的 shop 值
echo "--- 直接查 department 订单中 {$ono} 的 shop 值 ---\n";
$dept = $pdo->prepare("SELECT id, shop, order_no, order_amount FROM orders WHERE order_no=? AND order_scope='department'");
$dept->execute([$ono]);
foreach ($dept as $r) {
    echo "  ID={$r['id']} shop=[{$r['shop']}] order_amount={$r['order_amount']}\n";
}

echo "\n</pre>\n";
