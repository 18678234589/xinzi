<?php

if (!$finance && !ps_can_mark_delivery_finished($actor)) throw new RuntimeException('无权限');

$del = ($_POST['delivery_status'] ?? '') === 'finished' ? 'finished' : 'unfinished';

if (!$finance) {
    // 非财务人员（客服、售后、冯超等）仅更新交付状态
    $q = db()->prepare('UPDATE project_orders SET delivery_status=?, row_version=row_version+1 WHERE id=? AND row_version=?');
    $q->execute([$del, $id, (int)($_POST['row_version'] ?? 0)]);
    if (!$q->rowCount()) throw new RuntimeException('订单已被其他人修改，请刷新后重试');
    if ($del === 'finished') {
        db()->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='标记交付完成时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")
            ->execute([$actor['id'], $id]);
    }
    ps_audit('order', $id, 'update_delivery_status', $actor, ['delivery_status' => $del]);
} else {
    $contract = (string)($_POST['contract_amount'] ?? '');
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $contract) || (float)$contract > 999999999999.99) throw new RuntimeException('成交金额须为非负数，最多两位小数');
    $q = db()->prepare('UPDATE project_orders SET customer_name=?,shop=?,contract_amount=?,delivery_status=?,row_version=row_version+1 WHERE id=? AND row_version=?');
    $q->execute([trim((string)($_POST['customer_name'] ?? '')), trim((string)($_POST['shop'] ?? '')), round((float)$contract, 2), $del, $id, (int)($_POST['row_version'] ?? 0)]);
    if (!$q->rowCount()) throw new RuntimeException('订单已被其他人修改，请刷新后重试');
    if ($del === 'finished') {
        db()->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='标记交付完成时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")
            ->execute([$actor['id'], $id]);
    }
    db()->prepare("INSERT INTO project_order_sources (order_id,price_source) VALUES (?,'manual') ON DUPLICATE KEY UPDATE price_source='manual'")->execute([$id]);
    ps_audit('order', $id, 'update', $actor, ['contract' => $contract, 'delivery_status' => $del]);
}