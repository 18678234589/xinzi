<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order_id'])) {
    ps_check_csrf();
    if (!$canDeleteOrders) { http_response_code(403); exit('无权限'); }
    $deleteId = (int)$_POST['delete_order_id'];
    $orderNo = '#' . $deleteId;
    try {
        db()->beginTransaction();
        $q = db()->prepare('SELECT id, order_no, project_type, order_date, settlement_status FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([$deleteId]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('订单不存在');
        $orderNo = $row['order_no'];
        // 删除规则统一见 includes/ProjectOrderDelete.php（已审核订单：财务 / 售后部的售后业务可删，删前完整备份）
        if (($why = pod_blocker($row, $actor)) !== '') throw new RuntimeException($why);
        $backupFile = pod_backup($deleteId, $orderNo);
        $deleteOrderRows($deleteId);
        ps_audit('order', $deleteId, 'delete_order', $actor, ['order_no' => $orderNo, 'was_status' => $row['settlement_status'], 'backup' => basename($backupFile)]);
        db()->commit();
        $_SESSION['project_delete_result'] = ['ok' => true, 'order_no' => $orderNo];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['project_delete_result'] = ['ok' => false, 'order_no' => $orderNo, 'reason' => $e instanceof PDOException ? '删除失败' : $e->getMessage()];
    }
    $backQuery = (string)($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . BASE_URL . '/project/index.php' . ($backQuery !== '' ? '?' . $backQuery : '')); exit;
}

// 财务批量操作：证据核对 / 人工确认交付 / 人工审核。售价不作为收款依据。
