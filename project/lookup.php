<?php
// 录入订单号时的即时查询：是否已建档（仅参与人和财务可见详情）以及店铺/ETMLL 流水中的买家信息。
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
$actor = ps_require_actor();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$orderNo = trim((string)($_GET['order_no'] ?? ''));
if ($orderNo === '' || strlen($orderNo) > 100) { echo json_encode(['ok' => false]); exit; }

$result = ['ok' => true, 'project' => null, 'shop_orders' => []];
$q = db()->prepare('SELECT id,project_type FROM project_orders WHERE order_no=?');
$q->execute([$orderNo]);
$existing = $q->fetch();
if ($existing) {
    $accessible = $actor['role'] === 'finance';
    if (!$accessible) {
        $access = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
        $access->execute([(int)$existing['id'], $actor['employee_id']]);
        $accessible = (bool)$access->fetchColumn();
    }
    // 无权限时不返回业务类型与订单编号以外的任何信息。
    $result['project'] = $accessible ? ['id' => (int)$existing['id'], 'business' => $existing['project_type'], 'url' => BASE_URL . '/project/order.php?id=' . (int)$existing['id']] : ['id' => null];
    if (!$accessible) { echo json_encode($result, JSON_UNESCAPED_UNICODE); exit; }
}
foreach (ps_shop_order_lookup($orderNo) as $match) {
    $result['shop_orders'][] = ['shop' => $match['shop'], 'nickname' => $match['nickname'], 'price' => $match['price'], 'status' => $match['status'], 'date' => $match['date'], 'refund' => $match['refund'], 'refund_amount' => round($match['refund_amount'], 2), 'source' => $match['source']];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
