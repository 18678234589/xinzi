<?php

if (!ps_can_mark_delivery_finished($actor)) {
    throw new RuntimeException('无权限修改交付状态');
}

$status = trim((string)($_POST['delivery_status'] ?? 'finished'));
if (!in_array($status, ['finished', 'unfinished'], true)) {
    $status = 'finished';
}

$q = db()->prepare('UPDATE project_orders SET delivery_status=?, row_version=row_version+1 WHERE id=?');
$q->execute([$status, $id]);

if ($status === 'finished') {
    db()->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='标注交付完成时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")
        ->execute([$actor['id'], $id]);
}

ps_audit('order', $id, 'mark_delivery_' . $status, $actor, ['delivery_status' => $status]);
