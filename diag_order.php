<?php
@ini_set('memory_limit', '128M');
require_once __DIR__ . '/config/database.php';
$pdo = db();

$ono = $_GET['no'] ?? '58831162147N';

echo "<pre>\n=== 查找订单号含 {$ono} 的所有记录 ===\n\n";

$stmt = $pdo->prepare("SELECT id, employee_id, order_scope, shop, order_no, order_amount, order_date, raw_data FROM orders WHERE order_no LIKE ? ORDER BY id ASC");
$stmt->execute(['%' . $ono . '%']);
$rows = $stmt->fetchAll();

foreach ($rows as $r) {
    echo "--- ID={$r['id']} scope={$r['order_scope']} shop={$r['shop']} ---\n";
    echo "order_no: {$r['order_no']}\n";
    echo "order_amount: {$r['order_amount']}\n";
    echo "order_date: {$r['order_date']}\n";
    $raw = json_decode($r['raw_data'], true);
    if (is_array($raw)) {
        echo "raw_data 所有列:\n";
        foreach ($raw as $k => $v) {
            echo "  [{$k}] = {$v}\n";
        }
    } else {
        echo "raw_data: {$r['raw_data']}\n";
    }
    echo "\n";
}
echo "</pre>\n";
