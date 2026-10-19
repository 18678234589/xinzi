<?php

            // 已审核订单补指定 / 更换后端：财务专用。撤回分成快照（备份进审计日志）→ 指定后端 → 按原月份重新审核；任何一步失败整体回滚。
            if (!$finance) throw new RuntimeException('只有财务可以对已审核订单指定后端');
            require_once (dirname(__DIR__, 3)) . '/../includes/ProjectCompletionSync.php';
            $backendId = (int)($_POST['backend_employee_id'] ?? 0);
            if ($backendId <= 0) throw new RuntimeException('请选择后端技术人员');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $row = $pdo->prepare('SELECT settlement_status,delivery_status FROM project_orders WHERE id=? FOR UPDATE'); $row->execute([$id]); $cur = $row->fetch();
                if (!$cur || $cur['settlement_status'] !== 'approved') throw new RuntimeException('订单不是“已审核”状态，请直接指定后端');
                if ($why = pcs_reopen_block($id)) throw new RuntimeException('不能重新核算：' . $why);
                $snap = $pdo->prepare('SELECT * FROM project_commission_snapshots WHERE order_id=?'); $snap->execute([$id]); $backup = $snap->fetchAll(PDO::FETCH_ASSOC);
                $month = $backup ? (string)$backup[0]['payroll_month'] : substr((string)$order['order_date'], 0, 7);
                $pdo->prepare('DELETE FROM project_commission_snapshots WHERE order_id=?')->execute([$id]);
                $pdo->prepare("UPDATE project_orders SET settlement_status='draft',row_version=row_version+1 WHERE id=?")->execute([$id]);
                ps_order_assign_backend($id, $backendId, 'colleague', $actor);
                ps_approve_order($id, $actor, $month);
                ps_audit('order', $id, 'reassign_backend_after_approval', $actor, ['backend_id' => $backendId, 'month' => $month, 'snapshots_backup' => $backup]);
                $pdo->commit();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
