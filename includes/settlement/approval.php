<?php

function ps_approve_order($orderId, $actor, $payrollMonth)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth)) throw new RuntimeException('项目分成归属月份无效');
    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_order_approval');
    else $pdo->beginTransaction();
    try {
        require_once (dirname(__DIR__, 1)) . '/ProjectSiteProjects.php';
        psp_approve_guard($orderId);
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([$orderId]);
        $order = $q->fetch();
        if (!$order || !in_array($order['settlement_status'], ['draft','review'], true)) throw new RuntimeException('当前订单不可审核');
        $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$payrollMonth]);
        $periodQuery = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
        $periodQuery->execute([$payrollMonth]);
        if ($periodQuery->fetchColumn() === 'locked') throw new RuntimeException('该项目分成月份已锁定，请选择未锁月份');
        $costs = ps_costs($orderId);
        $participants = ps_participants($orderId);
        $sum = ps_summary($order, $costs, $participants);
        $resourceQuery = $pdo->prepare('SELECT domain_mode,ssl_expected_amount FROM project_order_resources WHERE order_id=?');
        $resourceQuery->execute([$orderId]);
        $resource = $resourceQuery->fetch();
        $needsResources = !empty(ps_business_catalog()[ps_business_normalize($order['project_type'])]['resources']);
        if ($needsResources && (!$resource || $resource['domain_mode'] === 'pending')) throw new RuntimeException('资源使用尚未由技术确认，不能生成项目分成');
        require_once (dirname(__DIR__, 1)) . '/ProjectOrderItems.php';
        foreach (poi_items($orderId) as $item) if (!$item['cost_id'] || $item['cost_status'] !== 'approved') throw new RuntimeException('商品“' . $item['item_name'] . '”成本未完整核对，不能生成项目分成');
        $sslExpected = (float)($resource['ssl_expected_amount'] ?? 0);
        if ($sslExpected > 0) {
            $sslApprovedCents = 0;
            foreach ($costs as $cost) if ($cost['category'] === 'certificate' && $cost['review_status'] === 'approved') $sslApprovedCents += (int)round((float)$cost['amount'] * 100);
            if ($sslApprovedCents < (int)round($sslExpected * 100)) throw new RuntimeException('SSL 报备成本尚未按凭证补录并审核，不能生成项目分成');
        }
        if ($order['delivery_status'] !== 'finished') throw new RuntimeException('项目尚未完成');
        if (!empty(ps_business_catalog()[ps_business_normalize($order['project_type'])]['kind_required']) && trim((string)($order['order_kind'] ?? '')) === '') throw new RuntimeException('请先选择订单类型（新订单 / 定制 / 续费），它决定分成比例和每单补助');
        // 补助规则存在但被“补助限定员工”取消发放时仍视为可结算：网站续费等部门单实收可为 0（无流水单），靠补助规则维持可审核。
        $hasSubsidy = $sum['groups']['technical']['subsidy'] > 0 || $sum['groups']['customer_service']['subsidy'] > 0
            || !empty($sum['groups']['technical']['subsidy_rule']) || !empty($sum['groups']['customer_service']['subsidy_rule']);
        $allowsNegative = false;
        foreach ($sum['groups'] as $group) foreach ($group['people'] as $person) if (!empty($person['rule']['allow_negative'])) $allowsNegative = true;
        if ($sum['income'] <= 0 && !$hasSubsidy && !($allowsNegative && $sum['income'] < 0)) throw new RuntimeException('没有可结算的实收收入');
        if ($sum['service_fee_rate'] > 0 && (float)$order['contract_amount'] <= 0 && $sum['income'] > 0) throw new RuntimeException($order['project_type'] . '订单须先核对售价，才能计算 ' . round($sum['service_fee_rate'] * 100, 2) . '% 店铺服务费');
        $cashPending = $pdo->prepare("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=? AND review_status='pending'");
        $cashPending->execute([$orderId]);
        if ((int)$cashPending->fetchColumn() > 0) throw new RuntimeException('还有待审核的收款或退款');
        foreach ($costs as $cost) if ($cost['review_status'] === 'pending') throw new RuntimeException('还有待审核成本');
        $hasGroup = false;
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $hasGroup = true;
            if ($group['missing_rule']) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组有参与人未匹配到项目分成规则（按业务/岗位/订单类型），请先在成本中心配置');
            if (abs($group['weight'] - 1.0) > 0.000001) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组权重合计必须为100%');
        }
        if (!$hasGroup) throw new RuntimeException('请先添加技术或客服参与人');
        $insert = $pdo->prepare('INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,service_fee,contribution_profit,rule_id,calc_mode,rate,group_weight,commission_amount,commission_exact,subsidy_amount,calc_note,payroll_month) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $shares = ps_group_share_cents($group['people']);
            foreach ($group['people'] as $i => $person) {
                $calc = $person['calc'];
                $subsidyCents = (int)round($calc['subsidy'] * 100);
                $insert->execute([$orderId, $person['employee_id'], $label, mb_substr((string)$person['role_name'], 0, 80), $sum['income'], $calc['cost_basis'], $calc['fee'], $calc['base'], $person['rule']['id'], $calc['mode'], $calc['rate'], $person['group_weight'], ($shares[$i] + $subsidyCents) / 100, round(($calc['share_exact'] ?? $calc['share']) + $calc['subsidy'], 6), $subsidyCents / 100, mb_substr($calc['note'], 0, 500), $payrollMonth]);
            }
        }
        $pdo->prepare("UPDATE project_orders SET settlement_status='approved',row_version=row_version+1 WHERE id=?")->execute([$orderId]);
        ps_audit('order', $orderId, 'approve', $actor, ['summary' => $sum['profit'], 'payroll_month' => $payrollMonth]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_order_approval');
        else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_order_approval');
        else $pdo->rollBack();
        throw $e;
    }
}
