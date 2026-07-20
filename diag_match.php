<?php
/**
 * 诊断：对比穆楠的异常订单匹配结果
 * 访问 diag_match.php?emp=穆楠
 */
@ini_set('memory_limit', '256M');
require_once __DIR__ . '/config/database.php';
$pdo = db();

$emp = $_GET['emp'] ?? '穆楠';
$limit = (int)($_GET['limit'] ?? 30);

echo "<pre>\n=== 诊断异常订单匹配：{$emp} ===\n\n";

// 1. 取员工 personal 订单
$empStmt = $pdo->prepare(
    "SELECT e.id, e.employee_id, e.order_no, e.order_amount, e.order_date, emp.name AS emp_name,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"__original_price__\"')) AS emp_orig_price,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店铺\"')) AS rd_shop1,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店铺名称\"')) AS rd_shop2,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店铺名\"')) AS rd_shop3,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.\"店名\"')) AS rd_shop4,
            JSON_UNQUOTE(JSON_EXTRACT(e.raw_data, '$.shop')) AS rd_shop5
     FROM orders e LEFT JOIN employees emp ON emp.id = e.employee_id
     WHERE e.order_scope = 'personal' AND e.order_no <> '' AND COALESCE(e.is_deleted,0) = 0
       AND emp.name = ?
     ORDER BY e.order_date DESC, e.id DESC LIMIT $limit"
);
$empStmt->execute([$emp]);
$empOrders = $empStmt->fetchAll();

echo "员工订单数：" . count($empOrders) . "\n\n";

// 2. 取所有 department 订单建索引
$shopStmt = $pdo->query(
    "SELECT o.id, o.shop, o.order_no, o.order_amount, o.order_date,
            JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.\"__original_price__\"')) AS shop_orig_price
     FROM orders o
     WHERE o.order_scope = 'department' AND o.order_no <> '' AND COALESCE(o.is_deleted,0) = 0
     ORDER BY o.id ASC"
);
$shopMap = [];
$deptByNo = [];
foreach ($shopStmt as $so) {
    if (!isset($shopMap[$so['shop']][$so['order_no']])) {
        $shopMap[$so['shop']][$so['order_no']] = $so;
    }
    if (!isset($deptByNo[$so['order_no']])) {
        $deptByNo[$so['order_no']] = $so;
    }
}

echo "店铺订单数：" . count($deptByNo) . "（去重后）\n\n";

// 3. 逐条对比
$matched = 0; $mismatch = 0; $missing = 0;
foreach ($empOrders as $eo) {
    $ono = $eo['order_no'];
    $empShopRaw = '';
    foreach (['rd_shop1','rd_shop2','rd_shop3','rd_shop4','rd_shop5'] as $sf) {
        if (!empty($eo[$sf])) { $empShopRaw = trim($eo[$sf]); break; }
    }
    $empOP = $eo['emp_orig_price'] !== null && (float)$eo['emp_orig_price'] > 0
        ? (float)$eo['emp_orig_price'] : (float)$eo['order_amount'];

    // 找店铺订单
    $so = null;
    $matchedShop = '';
    if ($empShopRaw !== '' && isset($shopMap[$empShopRaw][$ono])) {
        $so = $shopMap[$empShopRaw][$ono];
        $matchedShop = $empShopRaw . '(精确匹配)';
    } elseif (isset($deptByNo[$ono])) {
        $so = $deptByNo[$ono];
        $matchedShop = $so['shop'] . '(按订单号反查)';
    }

    echo "订单号={$ono}\n";
    echo "  员工: order_amount={$eo['order_amount']}, __original_price__={$eo['emp_orig_price']}, 售价={$empOP}\n";
    echo "  员工填店铺=[{$empShopRaw}]\n";

    if ($so) {
        $shopOP = $so['shop_orig_price'] !== null && (float)$so['shop_orig_price'] > 0
            ? (float)$so['shop_orig_price'] : (float)$so['order_amount'];
        $diff = round($empOP - $shopOP, 2);
        echo "  店铺: shop={$so['shop']}, order_amount={$so['order_amount']}, __original_price__={$so['shop_orig_price']}, 售价={$shopOP}\n";
        echo "  匹配方式={$matchedShop}\n";
        echo "  售价差异={$diff}\n";
        if (abs($diff) > 0.001) {
            echo "  → <b style='color:orange'>不一致</b>\n";
            $mismatch++;
        } else {
            echo "  → <b style='color:green'>一致</b>\n";
            $matched++;
        }
    } else {
        echo "  → <b style='color:red'>店铺缺失</b>（所有department订单里查不到此订单号）\n";
        $missing++;
    }
    echo "\n";
}

echo "=== 汇总 ===\n";
echo "一致：{$matched}\n";
echo "不一致：{$mismatch}\n";
echo "店铺缺失：{$missing}\n";
echo "</pre>\n";
