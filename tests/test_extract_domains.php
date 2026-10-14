<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
$pdo = db();

$domains = [];

// 1. From renewal items
$stmt = $pdo->query("SELECT r.id, r.order_id, r.resource_name, r.expires_on, r.owner, r.note, o.order_no, o.customer_name, o.project_type FROM project_renewal_items r JOIN project_orders o ON o.id=r.order_id WHERE r.resource_type='domain' AND r.resource_name<>''");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $d = strtolower(trim($r['resource_name']));
    if (!isset($domains[$d])) {
        $domains[$d] = [
            'domain' => $d,
            'source' => 'renewal',
            'order_no' => $r['order_no'],
            'customer' => $r['customer_name'],
            'business' => $r['project_type'],
            'expiry' => $r['expires_on'],
            'owner' => $r['owner'],
        ];
    }
}
echo "Domains from renewal items: " . count($domains) . "\n";

// 2. From order details website_url
$stmt = $pdo->query("SELECT d.order_id, d.details_json, o.order_no, o.customer_name, o.project_type FROM project_order_details d JOIN project_orders o ON o.id=d.order_id WHERE d.details_json LIKE '%website_url%'");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $det = json_decode($r['details_json'], true) ?: [];
    $url = trim($det['website_url'] ?? '');
    if ($url !== '') {
        // extract domain pattern
        if (preg_match('/([a-z0-9][-a-z0-9]*\.(?:com\.cn|net\.cn|org\.cn|com|cn|net|org|cc|top|xyz|vip|shop|wang|site|ltd|co))/i', $url, $m)) {
            $d = strtolower($m[1]);
            if (!isset($domains[$d])) {
                $domains[$d] = [
                    'domain' => $d,
                    'source' => 'order_details',
                    'order_no' => $r['order_no'],
                    'customer' => $r['customer_name'],
                    'business' => $r['project_type'],
                    'expiry' => null,
                    'owner' => 'customer',
                ];
            }
        }
    }
}
echo "Total domains including order_details website_url: " . count($domains) . "\n";
$sample = array_slice($domains, 0, 10);
print_r($sample);
