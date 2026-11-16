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
    $old = db()->prepare('SELECT receipt_amount,settlement_status FROM project_orders WHERE id=?'); $old->execute([(int)$orderId]); $before = $old->fetch();
    $update = db()->prepare('UPDATE project_orders SET receipt_amount=?,refund_amount=?,row_version=row_version+1 WHERE id=?');
    $update->execute([$totals['receipt'], $totals['refund'], (int)$orderId]);
    // 已审核订单的实收被改了（财务手动改 / 参与人更正）：分成快照不动，按快照口径重算差额，自动记为调整（计入下一个未锁定月份），不用财务再去结算单手工调
    if ($before && in_array($before['settlement_status'], ['approved', 'locked'], true) && abs((float)$before['receipt_amount'] - (float)$totals['receipt']) >= 0.005) {
        try {
            require_once __DIR__ . '/adjustment.php';
            $has = db()->prepare('SELECT COUNT(*) FROM project_commission_snapshots WHERE order_id=?'); $has->execute([(int)$orderId]);
            if ((int)$has->fetchColumn() > 0) {
                // 默认计入月份：原分成所在月份还没核算发放（晚于“已核算月份”）且未锁定的，更正记回那个月（多记的在哪个月多发，就在哪个月扣）；已发放的才顺延到下一个未锁定月份
                $month = !empty($GLOBALS['ps_receipt_adjust_month']) ? (string)$GLOBALS['ps_receipt_adjust_month'] : ps_next_open_month();
                if (empty($GLOBALS['ps_receipt_adjust_month'])) {
                    $sm = db()->prepare('SELECT MAX(payroll_month) FROM project_commission_snapshots WHERE order_id=?'); $sm->execute([(int)$orderId]); $snapMonth = (string)$sm->fetchColumn();
                    $settledThrough = (string)ps_setting_get('refund_settled_through', '');
                    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $snapMonth) && $snapMonth < $month && ($settledThrough === '' || $snapMonth > $settledThrough) && ps_next_open_month($snapMonth) === $snapMonth) $month = $snapMonth;
                }
                ps_post_adjustment((int)$orderId, ['type' => 'system', 'id' => 0, 'role' => 'finance'], 0, 0, '实收变更：¥' . number_format((float)$before['receipt_amount'], 2, '.', '') . ' → ¥' . number_format((float)$totals['receipt'], 2, '.', ''), $month, true);
            }
        } catch (Throwable $e) {
            ps_audit('order', (int)$orderId, 'receipt_adjust_failed', ['type' => 'system', 'id' => 0, 'role' => 'finance'], ['error' => $e->getMessage()]);
        }
    }
}

function ps_participants($orderId)
{
    $q = db()->prepare('SELECT p.*, e.name, e.department FROM project_participants p JOIN employees e ON e.id=p.employee_id WHERE p.order_id=? ORDER BY p.commission_group,p.id');
    $q->execute([(int)$orderId]);
    return $q->fetchAll();
}
