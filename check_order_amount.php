<?php
require_once __DIR__ . '/config/database.php';

// 检查 order_amount 字段定义
$cols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'order_amount'")->fetchAll();
echo "<h3>order_amount 字段定义：</h3><pre>";
print_r($cols);
echo "</pre>";

// 查找最大和最小的 order_amount 值
$stats = db()->query("SELECT MAX(order_amount) as max_amt, MIN(order_amount) as min_amt, COUNT(*) as total FROM `orders`")->fetch();
echo "<h3>order_amount 统计：</h3><pre>";
print_r($stats);
echo "</pre>";

// 查找异常大的值
$large = db()->query("SELECT id, order_amount, shop, order_date FROM `orders` ORDER BY ABS(order_amount) DESC LIMIT 10")->fetchAll();
echo "<h3>绝对值最大的10条记录：</h3><pre>";
print_r($large);
echo "</pre>";

// 建议的修复方案
echo "<h3>修复方案：</h3>";
echo "<p>如果需要更大的数值范围，执行以下 SQL：</p>";
echo "<pre>ALTER TABLE `orders` MODIFY COLUMN `order_amount` DECIMAL(15,2) DEFAULT NULL COMMENT '订单金额';</pre>";

// 执行修复
if (isset($_GET['fix']) && $_GET['fix'] == '1') {
    try {
        db()->exec("ALTER TABLE `orders` MODIFY COLUMN `order_amount` DECIMAL(15,2) DEFAULT NULL COMMENT '订单金额'");
        echo "<p style='color:green'>✅ 已将 order_amount 改为 DECIMAL(15,2)，支持最大 999,999,999,999.99</p>";
        
        // 验证修改结果
        $cols2 = db()->query("SHOW COLUMNS FROM `orders` LIKE 'order_amount'")->fetchAll();
        echo "<h3>修改后字段定义：</h3><pre>";
        print_r($cols2);
        echo "</pre>";
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ 修改失败: " . $e->getMessage() . "</p>";
    }
}