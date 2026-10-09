<?php

$kind = ps_order_kind_valid(ps_business_normalize($order['project_type']), $_POST['order_kind'] ?? '');
if ($kind === '') {
    throw new RuntimeException('请选择订单类型');
}
if (!$finance && !in_array($actor['role'], ['customer_service', 'technical'], true)) {
    throw new RuntimeException('无权限修改订单类型');
}

$canEdit = !in_array($order['settlement_status'], ['approved', 'locked'], true);
if (!$canEdit && !$finance) {
    throw new RuntimeException('订单已审核锁定，如需更改请联系财务');
}

if ($finance && !$canEdit) {
    // 已审核订单走财务差额调整与跨月结算流程
    ps_reclassify_order_kind($id, $kind, $actor, (string)($_POST['adjust_month'] ?? date('Y-m')), !empty($_POST['apply_future']));
} else {
    // 未审核订单（可编辑）：客服、技术、财务均可直接更改
    $beforeKind = (string)$order['order_kind'];
    db()->prepare("UPDATE project_orders SET order_kind=?, row_version=row_version+1 WHERE id=?")->execute([$kind, $id]);
    if ($finance && !empty($_POST['apply_future'])) {
        $people = db()->prepare('SELECT DISTINCT employee_id FROM project_participants WHERE order_id=?');
        $people->execute([$id]);
        $default = db()->prepare("INSERT INTO project_import_kind_preferences (employee_id,business_name,layout_signature,order_kind,source) VALUES (?,?,'*',?,'finance') ON DUPLICATE KEY UPDATE order_kind=VALUES(order_kind),source='finance',confirmed_count=confirmed_count+1,updated_at=NOW()");
        foreach ($people->fetchAll(PDO::FETCH_COLUMN) as $employeeId) {
            $default->execute([(int)$employeeId, ps_business_normalize($order['project_type']), $kind]);
        }
    }
    ps_audit('order', $id, 'order_kind', $actor, ['before' => $beforeKind, 'order_kind' => $kind]);
}