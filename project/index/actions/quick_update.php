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
        $isApproved = in_array($order['settlement_status'], ['approved', 'locked'], true);
        if ($isApproved && !$isFinance) {
            throw new RuntimeException('订单已审核锁定，如需修改请联系财务');
        }

        if (!in_array($actor['role'], ['customer_service', 'technical', 'finance'], true)) {
            throw new RuntimeException('无权限修改此订单');
        }

        if (!$isFinance) {
            $partQ = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? UNION SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=? LIMIT 1');
            $partQ->execute([$orderId, (int)$actor['employee_id'], $orderId, (int)$actor['employee_id']]);
            if (!$partQ->fetchColumn() && !pdh_can_view_order($orderId, $actor)) {
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
                    $beforeKind = $order['order_kind'];
                    db()->prepare("UPDATE project_orders SET order_kind=?, row_version=row_version+1 WHERE id=?")->execute([$validKind, $orderId]);
                    ps_audit('order', $orderId, 'quick_order_kind', $actor, ['before' => $beforeKind, 'order_kind' => $validKind]);
                    $changes[] = '类型改为「' . $validKind . '」';
                    $order['order_kind'] = $validKind;
                }
            }
        }

        // 2. 改成本 (direct_cost)
        if (isset($_POST['direct_cost']) && trim((string)$_POST['direct_cost']) !== '') {
            $costAmount = round((float)$_POST['direct_cost'], 2);
            $costReason = trim((string)($_POST['cost_reason'] ?? '快捷录入成本')) ?: '成本';

            // 查找该订单原有的手动录入/外包成本
            $cq = db()->prepare("SELECT id, amount, review_status FROM project_costs WHERE order_id=? AND (category='outsourcing' OR is_custom=1 OR reason LIKE '%录入%' OR item_name LIKE '%成本%') ORDER BY id LIMIT 1");
            $cq->execute([$orderId]);
            $existCost = $cq->fetch();

            if ($existCost) {
                if ($costAmount <= 0) {
                    db()->prepare("DELETE FROM project_costs WHERE id=?")->execute([(int)$existCost['id']]);
                    $changes[] = '成本已清零';
                } else {
                    $status = ($isFinance || abs($costAmount) <= 500) ? 'approved' : 'pending';
                    db()->prepare("UPDATE project_costs SET amount=?, unit_price=?, reason=?, review_status=? WHERE id=?")
                        ->execute([$costAmount, $costAmount, $costReason, $status, (int)$existCost['id']]);
                    $changes[] = '成本改为 ¥' . money($costAmount) . ($status === 'pending' ? '（待财务审核）' : '');
                }
            } elseif ($costAmount > 0) {
                $status = ($isFinance || abs($costAmount) <= 500) ? 'approved' : 'pending';
                $business = ps_business_catalog()[ps_business_normalize($order['project_type'])] ?? null;
                $costLabel = $business['cost_label'] ?? '成本';
                db()->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'outsourcing',?,1,'项',?,?,'one_time',1,?,?,?)")
                    ->execute([$orderId, $costLabel, $costAmount, $costAmount, $costReason, $status, $actor['employee_id'] ?? null]);
                $changes[] = '新增成本 ¥' . money($costAmount) . ($status === 'pending' ? '（待财务审核）' : '');
            }
            ps_audit('order', $orderId, 'quick_cost', $actor, ['amount' => $costAmount, 'reason' => $costReason]);
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
