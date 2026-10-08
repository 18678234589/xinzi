<?php

function ps_audit($entityType, $entityId, $action, $actor, $details)
{
    $q = db()->prepare('INSERT INTO project_audit_logs (entity_type,entity_id,action,actor_type,actor_id,details_json) VALUES (?,?,?,?,?,?)');
    $q->execute([$entityType, $entityId, $action, $actor['type'], $actor['id'], json_encode($details, JSON_UNESCAPED_UNICODE)]);
}

function ps_costs($orderId)
{
    $q = db()->prepare('SELECT c.*, e.name AS submitter FROM project_costs c LEFT JOIN employees e ON e.id=c.submitted_by_employee WHERE c.order_id=? ORDER BY c.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

function ps_cash_movements($orderId)
{
    $q = db()->prepare('SELECT * FROM project_cash_movements WHERE order_id=? ORDER BY created_at,id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}

function ps_recalculate_cash($orderId)
{
    $q = db()->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type='receipt' THEN amount ELSE 0 END),0) AS receipt, COALESCE(SUM(CASE WHEN movement_type='refund' THEN amount ELSE 0 END),0) AS refund FROM project_cash_movements WHERE order_id=? AND review_status='approved'"
    );
    $q->execute([(int)$orderId]);
    $totals = $q->fetch();
    // 退款冲减单（代写换写手、上月退款）以负数实收登记，不受“退款不超过实收”限制。
    // 尚未录入收款的订单（目前大多数）：退款按售价预估可退金额，不超过售价即可；财务登记实收后再按实收复核。
    $cs = db()->prepare('SELECT contract_amount FROM project_orders WHERE id=?'); $cs->execute([(int)$orderId]);
    $refundCap = (float)$totals['receipt'] > 0 ? (float)$totals['receipt'] : (float)$cs->fetchColumn();
    if ((float)$totals['refund'] > 0 && (float)$totals['refund'] > $refundCap + 0.004) throw new RuntimeException('累计退款不能超过已审核实收（尚未录入收款时不能超过售价）'
    );
    $update = db()->prepare('UPDATE project_orders SET receipt_amount=?,refund_amount=?,row_version=row_version+1 WHERE id=?');
    $update->execute([$totals['receipt'], $totals['refund'], (int)$orderId]);
}

function ps_participants($orderId)
{
    $q = db()->prepare('SELECT p.*, e.name, e.department FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=? ORDER BY p.commission_group,p.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}
