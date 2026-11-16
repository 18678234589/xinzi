<?php
/**
 * 列表页直接快捷修改订单：改类型、改成本、改到期时间、加客户联系方式
 * 权限：未审核订单，由参与客服、制作技术或财务直接修改；已审核订单须财务审核。
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'quick_update_order')) {
    ps_check_csrf();
    require_once (dirname(__DIR__, 3)) . '/includes/ProjectDeptHead.php';
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['ajax']);

    try {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new RuntimeException('订单ID无效');
        }

        $orderQ = db()->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $orderQ->execute([$orderId]);
        $order = $orderQ->fetch();
        if (!$order) {
            throw new RuntimeException('订单不存在');
        }

        $isFinance = $actor['role'] === 'finance';
        $isLocked = $order['settlement_status'] === 'locked';
        $isApproved = $order['settlement_status'] === 'approved';

        if ($isLocked) {
            throw new RuntimeException('订单所属月份已归档锁定，无法修改');
        }

        $isParticipant = false;
        if (!$isFinance) {
            $partQ = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? UNION SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
            $partQ->execute([$orderId, (int)$actor['employee_id'], $orderId, (int)$actor['employee_id']]);
            $isParticipant = (bool)$partQ->fetchColumn() || pdh_can_view_order($orderId, $actor);
        }

        if ($isApproved && !$isFinance && !$isParticipant) {
            throw new RuntimeException('订单已审核，只有本单参与人或财务可以修改成本');
        }

        if (!in_array($actor['role'], ['customer_service', 'technical', 'finance'], true) && !ps_can_mark_delivery_finished($actor)) {
            throw new RuntimeException('无权限修改此订单');
        }

        if (!$isFinance) {
            $canFinish = ps_can_mark_delivery_finished($actor);
            $isOnlyDeliveryStatus = isset($_POST['delivery_status']) && !isset($_POST['direct_cost']) && !isset($_POST['order_kind']) && empty($_POST['customer_phone']) && empty($_POST['customer_wechat']) && empty($_POST['contact_note']) && empty($_POST['server_expiry']);
            if (!$isParticipant && !($canFinish && $isOnlyDeliveryStatus)) {
                throw new RuntimeException('只能修改本人参与或录入的订单（部门主管可改本部门成员的订单）');
            }
        }

        db()->beginTransaction();
        $changes = [];

        // 1. 改类型 (order_kind)
        if (isset($_POST['order_kind'])) {
            $newKind = trim((string)$_POST['order_kind']);
            if ($newKind !== '') {
                $validKind = ps_order_kind_valid(ps_business_normalize($order['project_type']), $newKind);
                if ($validKind !== $order['order_kind']) {
                    if ($isApproved && !$isFinance) {
                        throw new RuntimeException('已审核订单不能更改订单类型，如需修改请联系财务');
                    }
                    $beforeKind = $order['order_kind'];
                    psw_change_kind($orderId, $beforeKind, $validKind, $actor);
                    ps_audit('order', $orderId, 'quick_order_kind', $actor, ['before' => $beforeKind, 'order_kind' => $validKind]);
                    $changes[] = '类型改为「' . $validKind . '」';
                    $order['order_kind'] = $validKind;
                }
            }
        }

        // 2. 改成本 (direct_cost)
        $costModified = false;
        if (isset($_POST['direct_cost']) && trim((string)$_POST['direct_cost']) !== '') {
            if (psw_is_editor_order($order) && (float)$_POST['direct_cost'] != 0) throw new RuntimeException('编辑对接只记录单量，稿费成本请在原销售单录入');
            $costAmount = round((float)$_POST['direct_cost'], 2);
            $costReason = trim((string)($_POST['cost_reason'] ?? '快捷录入成本')) ?: '成本';

            // 查找该订单原有的手动录入/外包成本
            $cq = db()->prepare("SELECT id, amount, review_status FROM project_costs WHERE order_id=? AND (category='outsourcing' OR is_custom=1 OR reason LIKE '%录入%' OR item_name LIKE '%成本%') ORDER BY id LIMIT 1");
            $cq->execute([$orderId]);
            $existCost = $cq->fetch();
            if (!$existCost) {
                $cq2 = db()->prepare("SELECT id, amount, review_status FROM project_costs WHERE order_id=? ORDER BY id LIMIT 1");
                $cq2->execute([$orderId]);
                $existCost = $cq2->fetch();
            }

            if ($existCost) {
                if ($costAmount <= 0) {
                    db()->prepare("DELETE FROM project_costs WHERE id=?")->execute([(int)$existCost['id']]);
                    $changes[] = '成本已清零';
                    $costModified = true;
                } else {
                    $status = ($isApproved || $isFinance || abs($costAmount) <= 500) ? 'approved' : 'pending';
                    db()->prepare("UPDATE project_costs SET amount=?, unit_price=?, reason=?, review_status=? WHERE id=?")
                        ->execute([$costAmount, $costAmount, $costReason, $status, (int)$existCost['id']]);
                    $changes[] = '成本改为 ¥' . money($costAmount) . ($status === 'pending' ? '（待财务审核）' : '');
                    $costModified = true;
                }
            } elseif ($costAmount > 0) {
                $status = ($isApproved || $isFinance || abs($costAmount) <= 500) ? 'approved' : 'pending';
                $business = ps_business_catalog()[ps_business_normalize($order['project_type'])] ?? null;
                $costLabel = $business['cost_label'] ?? '成本';
                db()->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'outsourcing',?,1,'项',?,?,'one_time',1,?,?,?)")
                    ->execute([$orderId, $costLabel, $costAmount, $costAmount, $costReason, $status, $actor['employee_id'] ?? null]);
                $changes[] = '新增成本 ¥' . money($costAmount) . ($status === 'pending' ? '（待财务审核）' : '');
                $costModified = true;
            }
            ps_audit('order', $orderId, 'quick_cost', $actor, ['amount' => $costAmount, 'reason' => $costReason]);

            if ($costModified && $isApproved) {
                $newCosts = ps_costs($orderId);
                $newPeople = ps_participants($orderId);
                $orderQ2 = db()->prepare('SELECT * FROM project_orders WHERE id=?');
                $orderQ2->execute([$orderId]);
                $freshOrder = $orderQ2->fetch();

                $sum = ps_summary($freshOrder, $newCosts, $newPeople);

                $mQ = db()->prepare('SELECT payroll_month FROM project_commission_snapshots WHERE order_id=? LIMIT 1');
                $mQ->execute([$orderId]);
                $payrollMonth = $mQ->fetchColumn() ?: date('Y-m', strtotime($freshOrder['order_date']));

                db()->prepare('DELETE FROM project_commission_snapshots WHERE order_id=?')->execute([$orderId]);

                $insertSnap = db()->prepare('INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,service_fee,contribution_profit,rule_id,calc_mode,rate,group_weight,commission_amount,commission_exact,subsidy_amount,calc_note,payroll_month) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                foreach ($sum['groups'] as $label => $group) {
                    if (!$group['people']) continue;
                    $shares = ps_group_share_cents($group['people']);
                    foreach ($group['people'] as $i => $person) {
                        $calc = $person['calc'];
                        $subsidyCents = (int)round(($calc['subsidy'] ?? 0) * 100);
                        $insertSnap->execute([
                            $orderId,
                            $person['employee_id'],
                            $label,
                            mb_substr((string)$person['role_name'], 0, 80),
                            $sum['income'],
                            $calc['cost_basis'],
                            $calc['fee'],
                            $calc['base'],
                            $person['rule']['id'] ?? null,
                            $calc['mode'] ?? 'profit',
                            $calc['rate'] ?? 0,
                            $person['group_weight'] ?? 100,
                            ($shares[$i] + $subsidyCents) / 100,
                            round(($calc['share_exact'] ?? $calc['share']) + ($calc['subsidy'] ?? 0), 6),
                            $subsidyCents / 100,
                            mb_substr($calc['note'] ?? '', 0, 500),
                            $payrollMonth
                        ]);
                    }
                }
                $changes[] = '分成快照已自动重新核算';
            }
        }

        // 2.5 改实收（网站类订单：客服 / 技术可改自己参与的未审核单；已审核的只有财务能改，差额记为调整）
        if (isset($_POST['receipt_amount']) && trim((string)$_POST['receipt_amount']) !== '') {
            require_once (dirname(__DIR__, 3)) . '/includes/ProjectReceiptEdit.php';
            [$okReceipt, $whyNot] = pre_can_edit($order, $actor, $isFinance || $isParticipant);
            if (!$okReceipt) throw new RuntimeException($whyNot);
            $rr = ps_set_receipt($orderId, trim((string)$_POST['receipt_amount']), $actor, trim((string)($_POST['receipt_note'] ?? '')), $isFinance ? trim((string)($_POST['adjust_month'] ?? '')) : '');
            if (abs($rr['new'] - $rr['old']) >= 0.005) $changes[] = '实收由 ¥' . money($rr['old']) . ' 改为 ¥' . money($rr['new']) . ($rr['adjusted'] ? '（原分成快照保留，差额已记为调整）' : '');
        }

        // 3. 改到期时间 (server_expiry)
        if (isset($_POST['server_expiry']) && trim((string)$_POST['server_expiry']) !== '') {
            $expiry = trim((string)$_POST['server_expiry']);
            require_once (dirname(__DIR__, 3)) . '/includes/ProjectSheetEdit.php';
            pse_set_server_expiry($orderId, $expiry, $actor);
            $changes[] = '到期日设为 ' . $expiry;
        }

        // 4. 加客户联系方式 (customer_phone, customer_wechat, contact_note)
        $custPhone = trim((string)($_POST['customer_phone'] ?? ''));
        $custWechat = trim((string)($_POST['customer_wechat'] ?? ''));
        $contactNote = trim((string)($_POST['contact_note'] ?? ''));

        if ($custPhone !== '' || $custWechat !== '') {
            require_once (dirname(__DIR__, 3)) . '/includes/ProjectSheetEdit.php';
            if ($custPhone !== '') {
                pse_set_phone($orderId, $custPhone, $actor);
                $changes[] = '手机号已保存';
            }
            if ($custWechat !== '') {
                pse_set_wechat($orderId, $custWechat, $actor);
                $changes[] = '微信号已保存';
            }
        }

        // 同步更新 project_order_details 和 project_orders.note
        if ($custPhone !== '' || $custWechat !== '' || $contactNote !== '' || !empty($_POST['server_expiry'])) {
            $detQ = db()->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
            $detQ->execute([$orderId]);
            $currentDetails = json_decode((string)($detQ->fetchColumn() ?: '{}'), true) ?: [];
            if ($custPhone !== '') $currentDetails['customer_phone'] = $custPhone;
            if ($custWechat !== '') $currentDetails['customer_wechat'] = $custWechat;
            if (!empty($_POST['server_expiry'])) $currentDetails['server_expiry'] = trim((string)$_POST['server_expiry']);
            if ($contactNote !== '') $currentDetails['contact_note'] = $contactNote;
            ps_save_business_details($orderId, ps_business_normalize($order['project_type']), $currentDetails);

            if ($contactNote !== '') {
                $oldNote = trim((string)$order['note']);
                $newNote = $oldNote !== '' ? ($oldNote . '；' . $contactNote) : $contactNote;
                db()->prepare("UPDATE project_orders SET note=?, row_version=row_version+1 WHERE id=?")->execute([$newNote, $orderId]);
                $changes[] = '备注已追加';
            }
        }

        // 5. 改交付状态 (delivery_status)
        if (isset($_POST['delivery_status'])) {
            $newDel = trim((string)$_POST['delivery_status']);
            if (in_array($newDel, ['finished', 'unfinished'], true) && $newDel !== $order['delivery_status']) {
                if ($isApproved && !$isFinance) {
                    throw new RuntimeException('已审核订单交付状态已锁定，如需修改请联系财务');
                }
                if (!ps_can_mark_delivery_finished($actor)) {
                    throw new RuntimeException('无权限修改交付状态');
                }
                if ($newDel === 'finished' && !ps_can_mark_order_finished($actor, $orderId)) throw new RuntimeException('网站业务的完成情况只有本订单的技术或财务可以标记；客服请通知技术确认');
                db()->prepare("UPDATE project_orders SET delivery_status=?, row_version=row_version+1 WHERE id=?")->execute([$newDel, $orderId]);
                if ($newDel === 'finished') {
                    db()->prepare("UPDATE project_order_requests SET status='approved', reviewer_id=?, reviewed_at=NOW(), review_note='快捷修改标记交付完成时自动通过' WHERE order_id=? AND request_type='delivery_completion' AND status='pending'")
                        ->execute([$actor['id'], $orderId]);
                }
                ps_audit('order', $orderId, 'update_delivery_status', $actor, ['before' => $order['delivery_status'], 'after' => $newDel]);
                $changes[] = '交付状态改为「' . ($newDel === 'finished' ? '已完成' : '未完成') . '」';
                $order['delivery_status'] = $newDel;
            }
        }

        db()->commit();

        // 触发自动核算更新
        require_once (dirname(__DIR__, 3)) . '/includes/ProjectAutoReview.php';
        pa_after_save([$orderId]);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'msg' => $changes ? implode('，', $changes) : '信息已保存',
                'order_id' => $orderId,
            ]);
            if (PHP_SAPI !== 'cli') exit;
            return;
        }

        $returnQuery = (string)($_POST['return_query'] ?? '');
        header('Location: ' . BASE_URL . '/project/index.php?' . $returnQuery . '&saved=1');
        if (PHP_SAPI !== 'cli') exit;
        return;

    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            if (PHP_SAPI !== 'cli') exit;
            return;
        }
        $error = $e->getMessage();
    }
}
