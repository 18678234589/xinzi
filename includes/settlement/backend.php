<?php

/**
 * 旧任务兼容入口：改用证据自动核对，绝不按天数推断交付或按售价登记实收。
 * @param int|null $days 保留旧调用签名，不再作为审核依据
 * @return array ['finished' => int, 'approved' => int, 'orders' => array]
 */
function ps_auto_finish_trade_success_orders($days = null)
{
    // Compatibility for existing jobs. Age or sale amount is never payment/delivery evidence.
    require_once (dirname(__DIR__, 1)) . '/ProjectAutoReview.php';
    if (!pa_storage_available() || !ps_setting_get('auto_review_enabled', false)) return ['finished' => 0, 'approved' => 0, 'orders' => []];
    $result = pa_batch(200, true, true);
    return ['finished' => 0, 'approved' => $result['applied'], 'orders' => [], 'review' => $result];
}

/** 退款发生在订单所属月份之后的金额（按退款生效月份判断）；这部分在退款月补扣，不进入订单所属月份的分成。 */
function ps_refund_later($orderId, $orderDate)
{
    static $q = null;
    try {
        if ($q === null) $q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM project_cash_movements WHERE order_id=? AND movement_type='refund' AND review_status='approved' AND effective_month IS NOT NULL AND effective_month>?"
    );
        $q->execute([(int)$orderId, substr((string)$orderDate, 0, 7)]);
        return round((float)$q->fetchColumn(), 2);
    } catch (Throwable $e) {
        return 0.0; // 字段尚未迁移时按原口径
    }
}

/** 订单“所属月份当时”的口径：去掉后月退款。$order['_all_refunds'] 为真时保持全部退款（补扣计算用）。 */
function ps_order_asof($order)
{
    if (!empty($order['_all_refunds']) || empty($order['id']) || (float)($order['refund_amount'] ?? 0) <= 0) return $order;
    $later = ps_refund_later($order['id'], $order['order_date'] ?? '');
    if ($later > 0) $order['refund_amount'] = max(round((float)$order['refund_amount'] - $later, 2), 0.0);
    return $order;
}

/**
 * 为订单分配或更新后端技术人员。
 * 支持：
 * - 'colleague': 指定一名后端技术同事（双方权重各 50%）
 * - 'self_fullstack': 技术人员本人兼任前后端（角色更新为 前端/后端，权重 100%）
 */
function ps_order_assign_backend($orderId, $backendEmployeeId, $mode, $actor)
{
    $pdo = db();
    $order = ps_order($orderId, $actor);
    if (in_array($order['settlement_status'], ['approved', 'locked'], true)) {
        throw new RuntimeException('订单已审核，修改须走调整流程');
    }
    $isFinance = $actor['role'] === 'finance';
    $isCS = $actor['role'] === 'customer_service';
    $isTech = $actor['role'] === 'technical';
    $actorEid = (int)($actor['employee_id'] ?? 0);

    if (!$isFinance && !$isCS) {
        $chk = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
        $chk->execute([(int)$orderId, $actorEid]);
        $isParticipant = (bool)$chk->fetchColumn();
        if (!$isParticipant && $mode !== 'colleague') {
            throw new RuntimeException('您未参与此订单，无权分配后端技术');
        }
    }

    if ($mode === 'self_fullstack') {
        $targetTechId = ($isTech && $actorEid) ? $actorEid : (int)$backendEmployeeId;
        if (!$targetTechId) $targetTechId = (int)($_POST['frontend_employee_id'] ?? 0);
        if (!$targetTechId) throw new RuntimeException('未指定技术人员');
        $qRole = $pdo->prepare('SELECT id, role_name FROM project_participants WHERE order_id=? AND employee_id=? AND commission_group="technical" LIMIT 1');
        $qRole->execute([(int)$orderId, $targetTechId]);
        $row = $qRole->fetch();
        if (!$row) throw new RuntimeException('该员工尚未在技术组，请先添加');
        $currRole = (string)$row['role_name'];
        $isOutsourced = mb_strpos($currRole, '外包') !== false;
        $newRole = $isOutsourced ? '外包前端/后端' : '前端/后端';

        $pdo->prepare('UPDATE project_participants SET role_name=?, group_weight=1.0 WHERE id=?')
            ->execute([$newRole, (int)$row['id']]);

        $pdo->prepare('DELETE FROM project_participants WHERE order_id=? AND commission_group="technical" AND employee_id<>?')
            ->execute([(int)$orderId, $targetTechId]);

        ps_audit('order', (int)$orderId, 'assign_backend_fullstack', $actor, ['employee_id' => $targetTechId, 'role' => $newRole]);
        return ['ok' => true, 'mode' => 'self_fullstack', 'role' => $newRole];
    } else {
        $backendId = (int)$backendEmployeeId;
        if (!$backendId) throw new RuntimeException('请选择后端技术人员');
        $bizNorm = ps_business_normalize($order['project_type']);
        $hasActiveBiz = ps_active_employee_for_business($backendId, 'technical', $bizNorm);
        if (!$hasActiveBiz && ps_is_website_order($bizNorm)) {
            $hasActiveBiz = ps_active_employee_for_business($backendId, 'technical', 'AI网站定制')
                || ps_active_employee_for_business($backendId, 'technical', '网站模板');
        }
        if (!$hasActiveBiz) {
            throw new RuntimeException('所选后端技术人员未开通此业务的有效账号');
        }
        $techs = $pdo->prepare('SELECT id, employee_id, role_name FROM project_participants WHERE order_id=? AND commission_group="technical"');
        $techs->execute([(int)$orderId]);
        $existing = $techs->fetchAll();

        foreach ($existing as $t) {
            if ((int)$t['employee_id'] !== $backendId) {
                $fRole = $t['role_name'];
                if (mb_strpos($fRole, '后端') !== false) {
                    $fRole = (mb_strpos($fRole, '外包') !== false) ? '外包前端' : '前端';
                }
                $pdo->prepare('UPDATE project_participants SET role_name=?, group_weight=0.5 WHERE id=?')
                    ->execute([$fRole, (int)$t['id']]);
            }
        }
        $pdo->prepare('INSERT INTO project_participants (order_id, employee_id, commission_group, role_name, group_weight) VALUES (?, ?, "technical", "后端", 0.5) ON DUPLICATE KEY UPDATE role_name="后端", group_weight=0.5'
    )
            ->execute([(int)$orderId, $backendId]);

        ps_audit('order', (int)$orderId, 'assign_backend_colleague', $actor, ['backend_id' => $backendId]);
        return ['ok' => true, 'mode' => 'colleague', 'backend_id' => $backendId];
    }
}
