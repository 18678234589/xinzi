<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
$pdo = db();

$stmt = $pdo->query("SELECT d.order_id, d.business_name, d.details_json, o.order_no, o.customer_name FROM project_order_details d JOIN project_orders o ON o.id=d.order_id WHERE d.details_json LIKE '%website_url%' OR d.details_json LIKE '%.com%' OR d.details_json LIKE '%.cn%' LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "Order {$r['order_no']} ({$r['business_name']}): " . $r['details_json'] . "\n";
}
