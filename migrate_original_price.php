<?php
/**
 * 历史数据迁移（快速版）：用单条SQL批量补存 __original_price__
 * 访问 migrate_original_price.php 即可，无需分页
 */
@ini_set('memory_limit', '128M');
@set_time_limit(120);
require_once __DIR__ . '/config/database.php';
$pdo = db();

echo "<pre>\n=== 快速迁移 __original_price__ ===\n\n";

// 统计迁移前
$before = $pdo->query(
    "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN raw_data LIKE '%__original_price__%' THEN 1 ELSE 0 END) as has_op,
        SUM(CASE WHEN raw_data NOT LIKE '%__original_price__%' THEN 1 ELSE 0 END) as no_op
     FROM orders
     WHERE COALESCE(is_deleted,0) = 0 AND raw_data IS NOT NULL AND raw_data <> ''"
)->fetch();
echo "迁移前：总 {$before['total']}，有 __original_price__ {$before['has_op']}，无 {$before['no_op']}\n\n";

// 单条SQL批量更新，DB内部执行，不需要PHP循环
// 逻辑：raw_data 里没有 __original_price__ 的，直接用 order_amount 作为售价
// （对于只有"金额"列的订单，order_amount 就是金额值，也是唯一的价格信息）
$affected = 0;
try {
    // MySQL 5.7+ 支持 JSON_SET
    $sql = "UPDATE orders
            SET raw_data = JSON_SET(raw_data, '$.\"__original_price__\"", CAST(order_amount AS DECIMAL(18,2)))
            WHERE COALESCE(is_deleted, 0) = 0
              AND raw_data IS NOT NULL AND raw_data <> ''
              AND raw_data NOT LIKE '%__original_price__%'";
    $affected = $pdo->exec($sql);
    echo "SQL批量更新完成，影响行数：$affected\n";
} catch (\Throwable $e) {
    // JSON_SET 不可用时回退到简单拼接
    echo "JSON_SET 不可用（{$e->getMessage()}），尝试拼接方式...\n";
    try {
        $sql = "UPDATE orders
                SET raw_data = CONCAT(raw_data, ',\"__original_price__\":', CAST(order_amount AS DECIMAL(18,2)), '}')
                WHERE COALESCE(is_deleted, 0) = 0
                  AND raw_data IS NOT NULL AND raw_data <> ''
                  AND raw_data NOT LIKE '%__original_price__%'
                  AND raw_data LIKE '%}'";
        $affected = $pdo->exec($sql);
        echo "拼接方式更新完成，影响行数：$affected\n";
    } catch (\Throwable $e2) {
        echo "拼接方式也失败：{$e2->getMessage()}\n";
    }
}

// 统计迁移后
$after = $pdo->query(
    "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN raw_data LIKE '%__original_price__%' THEN 1 ELSE 0 END) as has_op,
        SUM(CASE WHEN raw_data NOT LIKE '%__original_price__%' THEN 1 ELSE 0 END) as no_op
     FROM orders
     WHERE COALESCE(is_deleted,0) = 0 AND raw_data IS NOT NULL AND raw_data <> ''"
)->fetch();
echo "\n迁移后：总 {$after['total']}，有 __original_price__ {$after['has_op']}，无 {$after['no_op']}\n";
echo "\n完成！\n</pre>\n";
