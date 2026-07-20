<?php
/**
 * 诊断：检查异常订单的 raw_data 里 __original_price__ 是否存在、值是否正确
 * 访问 diag_abnormal.php?emp=张宁  查看某员工的订单 raw_data 详情
 */
@ini_set('memory_limit', '256M');
require_once __DIR__ . '/config/database.php';
$pdo = db();

$emp = $_GET['emp'] ?? '';
$limit = (int)($_GET['limit'] ?? 20);

echo "<pre>\n";
echo "=== 诊断异常订单 raw_data ===\n\n";

// 统计有多少 personal 订单有/没有 __original_price__
$cnt = $pdo->query(
    "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN raw_data LIKE '%__original_price__%' THEN 1 ELSE 0 END) as has_op,
        SUM(CASE WHEN raw_data NOT LIKE '%__original_price__%' THEN 1 ELSE 0 END) as no_op
     FROM orders
     WHERE order_scope = 'personal' AND order_no <> '' AND COALESCE(is_deleted,0) = 0
       AND raw_data IS NOT NULL AND raw_data <> ''"
)->fetch();
echo "个人订单 raw_data 统计：\n";
echo "  总数: {$cnt['total']}\n";
echo "  有 __original_price__: {$cnt['has_op']}\n";
echo "  无 __original_price__: {$cnt['no_op']}\n\n";

// 看几条样本
$where = " WHERE e.order_scope = 'personal' AND e.order_no <> '' AND COALESCE(e.is_deleted,0) = 0 AND e.raw_data IS NOT NULL AND e.raw_data <> '' ";
$params = [];
if ($emp !== '') {
    $where .= " AND emp.name = ? ";
    $params[] = $emp;
}
$sql = "SELECT e.id, e.order_no, e.order_amount, e.raw_data, emp.name AS emp_name
        FROM orders e LEFT JOIN employees emp ON emp.id = e.employee_id
        $where ORDER BY e.id DESC LIMIT $limit";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

echo "样本（" . count($rows) . " 条" . ($emp ? "，员工=$emp" : "") . "）：\n\n";
foreach ($rows as $r) {
    $raw = json_decode($r['raw_data'], true);
    $hasOP = is_array($raw) && isset($raw['__original_price__']);
    $opVal = $hasOP ? $raw['__original_price__'] : '(无)';
    echo "ID={$r['id']} 员工={$r['emp_name']} 订单号={$r['order_no']}\n";
    echo "  order_amount(利润)={$r['order_amount']}\n";
    echo "  __original_price__={$opVal}\n";
    if (is_array($raw)) {
        // 列出所有非 __ 开头的键和值
        $cols = [];
        foreach ($raw as $k => $v) {
            if (strpos($k, '__') === 0) continue;
            $cols[] = "$k=$v";
        }
        echo "  原始列: " . implode(' | ', array_slice($cols, 0, 15)) . "\n";
    }
    echo "\n";
}
echo "</pre>\n";
