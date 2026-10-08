<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order_id'])) {
    ps_check_csrf();
    if (!$canDeleteOrders) { http_response_code(403); exit('无权限'); }
    $deleteId = (int)$_POST['delete_order_id'];
    $orderNo = '#' . $deleteId;
    try {
        db()->beginTransaction();
        $q = db()->prepare('SELECT order_no, settlement_status FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([$deleteId]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('订单不存在');
        $orderNo = $row['order_no'];
        if (in_array($row['settlement_status'], ['approved', 'locked'], true)) throw new RuntimeException('订单已审核生成分成，不可删除');
        // 售后部员工只能删本人可见的订单（参与人或部门代录上传人）
        if ($actor['role'] !== 'finance') {
            $partQuery = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? UNION SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
            $partQuery->execute([$deleteId, (int)$actor['employee_id'], $deleteId, (int)$actor['employee_id']]);
            if (!$partQuery->fetchColumn()) throw new RuntimeException('只能删除本人参与的订单');
        }
        $deleteOrderRows($deleteId);
        ps_audit('order', $deleteId, 'delete_order', $actor, ['order_no' => $orderNo]);
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
