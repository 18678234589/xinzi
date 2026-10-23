<?php
// DB_HOST=127.0.0.1 DB_PORT=13399 DB_NAME=xinzi_test php tests/quick_update_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
if (DB_HOST !== '127.0.0.1' || (string)DB_PORT !== '13399' || !preg_match('/_(test|ci|sandbox)$/i', DB_NAME)) {
    fwrite(STDERR, "拒绝运行：仅允许本地 13399 隔离测试库\n"); exit(2);
}
require_once __DIR__ . '/require_isolated_database.php';
$pdo = db(); require_isolated_test_database($pdo);
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectSheetEdit.php';
require_once __DIR__ . '/../includes/ProjectOrderDelete.php';
function quick_check($okay, $message) { if (!$okay) throw new RuntimeException($message); }
$tag = bin2hex(random_bytes(5));
$employeeId = 0; $userId = 0; $orderId = 0;
$oldSession = $_SESSION; $oldPost = $_POST; $oldGet = $_GET; $oldServer = $_SERVER;
$runQuick = function (array $post, array $currentActor) {
    $actor = $currentActor; $error = '';
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['SCRIPT_NAME'] = '/project/index.php';
    $_SESSION['project_csrf'] = 'quick-sandbox-csrf';
    $_POST = $post + ['csrf'=>'quick-sandbox-csrf','action'=>'quick_update_order','ajax'=>1];
    ob_start();
    try { include __DIR__ . '/../project/index/actions/quick_update.php'; }
    finally { $body = ob_get_clean(); }
    $result = json_decode($body, true);
    quick_check(is_array($result), 'Quick edit must return JSON: ' . $body);
    return $result;
};
try {
    $pdo->prepare("INSERT INTO employees(name,department,password) VALUES (?,'小程序客服',MD5('sandbox'))")->execute(['QUICK-'.$tag]);
    $employeeId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_users(employee_id,username,password_hash,role,is_active,phone,password_changed_at) VALUES (?,?,?,'customer_service',1,'13000000000',NOW())")
        ->execute([$employeeId,'quick'.$tag,password_hash('sandbox-only-password',PASSWORD_DEFAULT)]);
    $userId = (int)$pdo->lastInsertId();
    $actor = ['type'=>'employee','id'=>$userId,'employee_id'=>$employeeId,'role'=>'customer_service'];
    $pdo->prepare("INSERT INTO project_orders(order_no,customer_name,project_type,order_kind,shop,contract_amount,order_date,delivery_status,settlement_status) VALUES (?,'sandbox customer','小程序开发','新订单','sandbox',1000,'2026-09-12','unfinished','draft')")
        ->execute(['QUICK-'.$tag]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_participants(order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'customer_service','客服',1)")->execute([$orderId,$employeeId]);
    $result = $runQuick(['order_id'=>$orderId,'order_kind'=>'开发定制','direct_cost'=>'650.00','cost_reason'=>'快速录入接口费','server_expiry'=>'2028-05-01','customer_phone'=>'13912345678','customer_wechat'=>'wx_client_001','contact_note'=>'客户要求5月1日前上线'], $actor);
    quick_check(!empty($result['ok']), 'Quick edit failed: ' . ($result['error'] ?? 'missing result'));
    $row = $pdo->query('SELECT order_kind,settlement_status FROM project_orders WHERE id='.$orderId)->fetch();
    quick_check($row['order_kind']==='开发定制' && $row['settlement_status']==='draft', 'Quick edit must save a valid kind without approving the order');
    $cost = $pdo->query("SELECT amount,review_status FROM project_costs WHERE order_id=$orderId AND category='outsourcing'")->fetch();
    quick_check($cost && (float)$cost['amount']===650.0 && $cost['review_status']==='pending', 'Costs above 500 must remain pending finance review');
    $small = $runQuick(['order_id'=>$orderId,'direct_cost'=>'150.00'], $actor);
    $cost = $pdo->query("SELECT amount,review_status FROM project_costs WHERE order_id=$orderId AND category='outsourcing'")->fetch();
    quick_check(!empty($small['ok']) && (float)$cost['amount']===150.0 && $cost['review_status']==='approved', 'Small costs must follow the existing automatic approval policy');
    $expiry = $pdo->query("SELECT expires_on FROM project_renewal_items WHERE order_id=$orderId AND resource_type='server'")->fetchColumn();
    quick_check($expiry==='2028-05-01', 'Server expiry must persist');
    $details = json_decode($pdo->query('SELECT details_json FROM project_order_details WHERE order_id='.$orderId)->fetchColumn(), true);
    quick_check($details['customer_phone']==='13912345678' && $details['customer_wechat']==='wx_client_001' && $details['contact_note']==='客户要求5月1日前上线', 'Contact fields must persist');
    $runBulk = function ($kind) use ($actor,$orderId) {
        $canDeleteOrders = false;
        $_POST = ['csrf'=>'quick-sandbox-csrf','bulk_action'=>'set_kind','bulk_order_kind'=>$kind,'ids'=>[$orderId]];
        ob_start();
        try { include __DIR__ . '/../project/index/actions/bulk.php'; }
        finally { ob_end_clean(); }
    };
    $runBulk('新订单');
    quick_check($pdo->query('SELECT order_kind FROM project_orders WHERE id='.$orderId)->fetchColumn()==='新订单', 'Bulk edit must save the new order kind');
    $runBulk('开发定制');
    quick_check($pdo->query('SELECT order_kind FROM project_orders WHERE id='.$orderId)->fetchColumn()==='开发定制', 'Bulk edit must support switching back to custom development');
    $bad = $runQuick(['order_id'=>$orderId,'order_kind'=>'无效测试类型'], $actor);
    quick_check(empty($bad['ok']) && $pdo->query('SELECT order_kind FROM project_orders WHERE id='.$orderId)->fetchColumn()==='开发定制', 'Invalid kinds must fail without changing the order');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($orderId) {
        $pdo->prepare('DELETE FROM project_messages WHERE employee_id=?')->execute([$employeeId]);
        $itemIds = $pdo->query('SELECT id FROM project_renewal_items WHERE order_id='.$orderId)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($itemIds as $id) {
            $pdo->prepare('DELETE FROM project_renewal_sms WHERE item_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM project_renewal_history WHERE item_id=?')->execute([$id]);
            $pdo->prepare("DELETE FROM project_audit_logs WHERE entity_type='renewal' AND entity_id=?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM project_renewal_items WHERE order_id=?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM project_auto_reviews WHERE order_id=?')->execute([$orderId]);
        pod_delete_rows($orderId);
        $pdo->prepare("DELETE FROM project_audit_logs WHERE entity_type='order' AND entity_id=?")->execute([$orderId]);
    }
    if ($userId) $pdo->prepare('DELETE FROM project_users WHERE id=?')->execute([$userId]);
    if ($employeeId) $pdo->prepare('DELETE FROM employees WHERE id=?')->execute([$employeeId]);
    $_SESSION=$oldSession; $_POST=$oldPost; $_GET=$oldGet; $_SERVER=$oldServer; http_response_code(200);
}
echo "PASS isolated quick edits, costs, contacts, expiry, bulk kind changes and invalid-input rollback\n";
