<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSheetEdit.php';

$pdo = db();
echo "Connected to DB: " . DB_HOST . ":" . DB_PORT . "\n";

// Find wangning
$stmt = $pdo->prepare("SELECT u.id, u.employee_id, u.role, e.name FROM project_users u JOIN employees e ON e.id = u.employee_id WHERE u.is_active = 1 AND (e.name = '王宁' OR u.role = 'customer_service') LIMIT 1");
$stmt->execute();
$wangning = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT id, order_no, project_type, order_kind, settlement_status FROM project_orders WHERE order_no = '3316902277030077882'");
$stmt->execute();
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order || !$wangning) {
    echo "Order or wangning not found\n";
    exit(1);
}

$orderId = (int)$order['id'];
$actor = [
    'type' => 'employee',
    'id' => (int)$wangning['id'],
    'employee_id' => (int)$wangning['employee_id'],
    'role' => $wangning['role'],
];

// Ensure wangning is a participant
$pCheck = $pdo->prepare("SELECT 1 FROM project_participants WHERE order_id = ? AND employee_id = ?");
$pCheck->execute([$orderId, $actor['employee_id']]);
if (!$pCheck->fetchColumn()) {
    $pdo->prepare("INSERT INTO project_participants (order_id, employee_id, commission_group) VALUES (?, ?, 'customer_service')")->execute([$orderId, $actor['employee_id']]);
}

// Test 1: quick_update_order changing kind, cost, expiry, phone, wechat, note
echo "--- Test 1: quick_update_order ---\n";
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['project_csrf'] = 'smoke_csrf';
$_POST = [
    'csrf' => 'smoke_csrf',
    'action' => 'quick_update_order',
    'order_id' => $orderId,
    'order_kind' => '开发定制',
    'direct_cost' => '150.00',
    'cost_reason' => '快速录入接口费',
    'server_expiry' => '2028-05-01',
    'customer_phone' => '13912345678',
    'customer_wechat' => 'wx_client_001',
    'contact_note' => '客户要求5月1日前上线',
];

// Execute without exit
try {
    // Run the inner logic of quick_update
    include __DIR__ . '/../project/index/actions/quick_update.php';
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

// Check DB
$kindInDb = $pdo->query("SELECT order_kind FROM project_orders WHERE id = $orderId")->fetchColumn();
echo "DB order_kind: " . $kindInDb . "\n";
assert($kindInDb === '开发定制', 'order_kind should be 开发定制');

$costInDb = $pdo->query("SELECT amount, reason FROM project_costs WHERE order_id = $orderId AND category = 'outsourcing'")->fetch(PDO::FETCH_ASSOC);
echo "DB cost: " . json_encode($costInDb, JSON_UNESCAPED_UNICODE) . "\n";
assert((float)$costInDb['amount'] === 150.0, 'cost amount should be 150.0');

$expiryInDb = $pdo->query("SELECT expires_on FROM project_renewal_items WHERE order_id = $orderId AND resource_type = 'server'")->fetchColumn();
echo "DB server expiry: " . $expiryInDb . "\n";
assert(strpos($expiryInDb, '2028-05-01') === 0, 'expiry should match 2028-05-01');

$detailsInDb = json_decode((string)$pdo->query("SELECT details_json FROM project_order_details WHERE order_id = $orderId")->fetchColumn(), true);
echo "DB details: " . json_encode($detailsInDb, JSON_UNESCAPED_UNICODE) . "\n";
assert($detailsInDb['customer_phone'] === '13912345678', 'phone should match');
assert($detailsInDb['customer_wechat'] === 'wx_client_001', 'wechat should match');

// Test 2: bulk action set_kind
echo "--- Test 2: bulk action set_kind ---\n";
$canDeleteOrders = false;
$_POST = [
    'csrf' => 'smoke_csrf',
    'bulk_action' => 'set_kind',
    'bulk_order_kind' => '新订单',
    'ids' => [$orderId],
];
include __DIR__ . '/../project/index/actions/bulk.php';

$kindAfterBulk = $pdo->query("SELECT order_kind FROM project_orders WHERE id = $orderId")->fetchColumn();
echo "DB order_kind after bulk: " . $kindAfterBulk . "\n";
assert($kindAfterBulk === '新订单', 'order_kind after bulk should be 新订单');

// Restore to 开发定制 for the user
$_POST = [
    'csrf' => 'smoke_csrf',
    'bulk_action' => 'set_kind',
    'bulk_order_kind' => '开发定制',
    'ids' => [$orderId],
];
include __DIR__ . '/../project/index/actions/bulk.php';
$kindRestored = $pdo->query("SELECT order_kind FROM project_orders WHERE id = $orderId")->fetchColumn();
echo "DB order_kind restored to: " . $kindRestored . "\n";
assert($kindRestored === '开发定制', 'order_kind should be restored to 开发定制');

echo "ALL TESTS PASSED SUCCESSFULLY!\n";
