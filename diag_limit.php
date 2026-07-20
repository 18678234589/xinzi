<?php
@ini_set('memory_limit', '128M');
require_once __DIR__ . '/config/database.php';
$pdo = db();

echo "<pre>\n=== 检查 department 订单总数和 LIMIT 问题 ===\n\n";

$cnt = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_scope='department' AND order_no<>'' AND (is_deleted=0 OR is_deleted IS NULL)")->fetchColumn();
echo "department 订单总数: {$cnt}\n";

// 检查 58684967301N 这两条的 ID 排在哪
$stmt = $pdo->query("SELECT id, shop, order_no FROM orders WHERE order_scope='department' AND order_no<>'' AND (is_deleted=0 OR is_deleted IS NULL) ORDER BY id ASC LIMIT 5000");
$lastId = 0;
$count = 0;
while ($r = $stmt->fetch()) {
    $lastId = $r['id'];
    $count++;
}
echo "LIMIT 5000 拉取了 {$count} 条，最后一条 ID={$lastId}\n";
echo "订单 58684967301N 的 department 订单 ID=88464, 91121\n";
echo "88464 > {$lastId}? " . ($lastId < 88464 ? 'YES - 被LIMIT截断了！' : 'NO') . "\n";
echo "91121 > {$lastId}? " . ($lastId < 91121 ? 'YES - 被LIMIT截断了！' : 'NO') . "\n";

echo "\n</pre>\n";
