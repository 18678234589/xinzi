<?php
require_once __DIR__ . '/config/database.php';

// 检查 order_no 字段当前长度
$cols = db()->query("SHOW COLUMNS FROM `orders` LIKE 'order_no'")->fetchAll();
echo "<h3>order_no 字段信息：</h3><pre>";
print_r($cols);
echo "</pre>";

// 查找最长的 order_no 值
$longest = db()->query("SELECT order_no, LENGTH(order_no) as len FROM `orders` WHERE order_no != '' ORDER BY len DESC LIMIT 5")->fetchAll();
echo "<h3>最长的5条 order_no：</h3><pre>";
print_r($longest);
echo "</pre>";

// 执行修改
echo "<h3>正在修改字段长度为 VARCHAR(255)...</h3>";
try {
    db()->exec("ALTER TABLE `orders` MODIFY COLUMN `order_no` VARCHAR(255) DEFAULT '' COMMENT '订单号(从raw_data提取)'");
    echo "<p style='color:green'>✅ 修改成功！order_no 字段已扩展为 VARCHAR(255)</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ 修改失败: " . $e->getMessage() . "</p>";
}

// 验证修改结果
$cols2 = db()->query("SHOW COLUMNS FROM `orders` LIKE 'order_no'")->fetchAll();
echo "<h3>修改后字段信息：</h3><pre>";
print_r($cols2);
echo "</pre>";
