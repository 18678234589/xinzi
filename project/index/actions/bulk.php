<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    ps_check_csrf();
    $bulkAction = (string)$_POST['bulk_action'];
    // 批量删除下放给网站售后部；批量修改类型允许客服、技术和财务；其余批量操作仍仅财务
    if ($bulkAction === 'delete') { if (!$canDeleteOrders) { http_response_code(403); exit('无权限'); } }
    elseif ($bulkAction === 'set_kind') { if (!in_array($actor['role'], ['customer_service', 'technical', 'finance'], true)) { http_response_code(403); exit('无权限'); } }
    elseif ($actor['role'] !== 'finance') { http_response_code(403); exit('无权限'); }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $payrollMonth = (string)($_POST['bulk_month'] ?? '');
    $done = 0;
    $failed = [];
    foreach (array_slice($ids, 0, 300) as $orderId) {
        $orderNo = '#' . $orderId;
        try {
            if ($bulkAction === 'approve') {
                $noQuery = db()->prepare('SELECT order_no FROM project_orders WHERE id=?');
                $noQuery->execute([$orderId]);
                $orderNo = (string)$noQuery->fetchColumn();
                ps_approve_order($orderId, $actor, $payrollMonth);
                $done++;
                continue;
            }
            db()->beginTransaction();
            $q = db()->prepare('SELECT o.*,COALESCE(s.price_source,\'missing\') price_source,COALESCE(s.trade_status,\'\') trade_status FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.id=? FOR UPDATE'
    );
            $q->execute([$orderId]);
            $row = $q->fetch();
            if (!$row) throw new RuntimeException('订单不存在');
            $orderNo = $row['order_no'];
            if ($bulkAction !== 'delete' && in_array($row['settlement_status'], ['approved', 'locked'], true)) throw new RuntimeException('已审核');
            if ($bulkAction === 'receipt') {
                $review = pa_check_order($orderId, true, true);
                if (!$review['applied']) throw new RuntimeException(implode('；', array_column($review['reasons'], 'text')) ?: '尚未满足自动结算条件');
            } elseif ($bulkAction === 'finish') {
                if ($row['delivery_status'] === 'finished') throw new RuntimeException('已是完成状态');
                db()->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=?")->execute([$orderId]);
                // 自动通过待审的交付完成申请
                db()->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='批量标记交付完成时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'"
    )
                    ->execute([$actor['id'], $orderId]);

                ps_audit('order', $orderId, 'bulk_finish', $actor, []);

                // 标记交付完成后，立即自动计算客服提成与技术提成，自动纳入该月的工资总表
                $targetMonth = $payrollMonth !== '' ? $payrollMonth : $month;
                if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $targetMonth)) {
                    $targetMonth = date('Y-m');
                }
                try {
                    pa_check_order($orderId, true, true);
                } catch (Throwable $approveEx) {
                    // 若前置条件（如域名待确认、SSL待补录）未满足，仅标记已交付完成，暂不锁定审核
                }
            } elseif ($bulkAction === 'set_kind') {
                $targetKind = trim((string)($_POST['bulk_order_kind'] ?? ''));
                if ($targetKind === '') throw new RuntimeException('请选择订单类型');
                if ($actor['role'] !== 'finance') {
                    $partQuery = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? UNION SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
                    $partQuery->execute([$orderId, (int)$actor['employee_id'], $orderId, (int)$actor['employee_id']]);
                    if (!$partQuery->fetchColumn()) throw new RuntimeException('只能修改本人参与的订单');
                }
                $validKind = ps_order_kind_valid(ps_business_normalize($row['project_type']), $targetKind);
                db()->prepare("UPDATE project_orders SET order_kind=?, row_version=row_version+1 WHERE id=?")->execute([$validKind, $orderId]);
                ps_audit('order', $orderId, 'bulk_set_order_kind', $actor, ['order_kind' => $validKind]);
            } elseif ($bulkAction === 'delete') {
                // 批量删除传错的订单；规则统一见 includes/ProjectOrderDelete.php（已审核订单：财务 / 售后部的售后业务可删，删前完整备份）
                if (($why = pod_blocker($row, $actor)) !== '') throw new RuntimeException($why);
                $backupFile = pod_backup($orderId, $orderNo, $actor);
                $deleteOrderRows($orderId);
                ps_audit('order', $orderId, 'bulk_delete', $actor, ['order_no' => $orderNo, 'was_status' => $row['settlement_status'], 'backup' => basename($backupFile)]);
            } else throw new RuntimeException('操作无效');
            db()->commit();
            $done++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $failed[] = $orderNo . '：' . ($e instanceof PDOException ? '保存失败' : $e->getMessage());
        }
    }
    if ($bulkAction === 'set_kind' && $ids) {
        try { require_once (dirname(__DIR__, 3)) . '/includes/ProjectAutoReview.php'; pa_after_save($ids); } catch (Throwable $e) {}
    }
    $_SESSION['project_bulk_result'] = ['action' => ['receipt' => '确认实收', 'finish' => '标记交付完成', 'approve' => '审核并生成分成', 'delete' => '删除', 'set_kind' => '修改订单类型'][$bulkAction
    ] ?? $bulkAction, 'done' => $done, 'failed' => $failed];
    header('Location: ' . BASE_URL . '/project/index.php?' . (string)($_POST['return_query'] ?? ''));
    if (PHP_SAPI !== 'cli') exit;
}

