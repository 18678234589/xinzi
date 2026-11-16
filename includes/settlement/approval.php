<?php

/**
 * 分成归属月份：往月已经核算（≤ 财务设置的“已核算月份”）或已锁定的，订单之后才审核 / 到账的，自动记入最早还没核算的月份，
 * 不会再把分成记进已经发放过的月份（财务手工发过工资的月份不能再多出一笔）。所有审核入口（自动结算、批量审核、订单页、列表批量）都走这里。
 */
function ps_payroll_month_floor($month)
{
    if (!function_exists('ps_next_open_month')) require_once __DIR__ . '/adjustment.php';
    $settled = (string)ps_setting_get('refund_settled_through', '');
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $settled)) {
        $floor = date('Y-m', strtotime($settled . '-01 +1 month'));
        if ($month < $floor) $month = $floor;
    }
    return ps_next_open_month($month);
}

/**
 * 撤销审核（仅财务）：订单审核后发现其实没完成 / 单号填错等，把订单退回草稿，删除分成快照（该订单的分成不再计入工资）。
 * 不允许的情况：分成月份已核算（≤ “已核算月份”）或已锁定——工资已发放，请改用“售后调整”；合作人员已申请结算的订单。
 * 保留收款、成本；交付状态按 $delivery 设置（默认改为未完成）。返回被删除的分成快照条数。
 */
function ps_revoke_approval($orderId, $actor, $reason, $delivery = 'unfinished')
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可撤销审核');
    $reason = trim((string)$reason);
    if (mb_strlen($reason) < 4 || mb_strlen($reason) > 300) throw new RuntimeException('请写明撤销原因（4～300 字）');
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_revoke_approval'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE'); $q->execute([(int)$orderId]); $order = $q->fetch();
        if (!$order) throw new RuntimeException('订单不存在');
        if ($order['settlement_status'] !== 'approved') throw new RuntimeException($order['settlement_status'] === 'locked' ? '订单已锁定，不能撤销审核' : '订单还没有审核');
        $settled = (string)ps_setting_get('refund_settled_through', '');
        $months = $pdo->prepare('SELECT DISTINCT payroll_month FROM project_commission_snapshots WHERE order_id=?'); $months->execute([(int)$orderId]);
        $lockQ = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
        foreach ($months->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $lockQ->execute([$m]);
            if ($lockQ->fetchColumn() === 'locked' || ($settled !== '' && $m <= $settled)) throw new RuntimeException('分成月份（' . $m . '）已核算或锁定，工资已发放，不能撤销审核；请用“售后调整”更正金额');
        }
        try {
            $req = $pdo->prepare("SELECT COUNT(*) FROM project_settlement_request_items i JOIN project_settlement_requests r ON r.id=i.request_id WHERE i.order_id=? AND r.status<>'rejected'"); $req->execute([(int)$orderId]);
            if ((int)$req->fetchColumn() > 0) throw new RuntimeException('合作人员已申请结算这张订单，请先在结算申请里处理');
        } catch (PDOException $e) { /* 结算申请表还没建：没有申请 */ }
        $snap = $pdo->prepare('SELECT employee_id,commission_group,payroll_month,commission_amount FROM project_commission_snapshots WHERE order_id=?'); $snap->execute([(int)$orderId]); $snaps = $snap->fetchAll();
        $n = $pdo->prepare('DELETE FROM project_commission_snapshots WHERE order_id=?'); $n->execute([(int)$orderId]);
        $pdo->prepare("UPDATE project_orders SET settlement_status='draft',delivery_status=?,row_version=row_version+1 WHERE id=?")->execute([$delivery === 'finished' ? 'finished' : 'unfinished', (int)$orderId]);
        ps_audit('order', (int)$orderId, 'revoke_approval', $actor, ['reason' => $reason, 'snapshots' => $snaps]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_revoke_approval'); else $pdo->commit();
        return $n->rowCount();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_revoke_approval'); else $pdo->rollBack(); }
        throw $e;
    }
}

function ps_approve_order($orderId, $actor, $payrollMonth)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth)) throw new RuntimeException('项目分成归属月份无效');
    $payrollMonth = ps_payroll_month_floor((string)$payrollMonth);
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
        require_once dirname(__DIR__) . '/ProjectMiniappDelivery.php';
        if (ps_deferred_delivery_order($order)) {
            $shipping = $pdo->prepare('SELECT trade_status FROM project_order_sources WHERE order_id=? FOR UPDATE');
            $shipping->execute([$orderId]);
            if (ps_deferred_order_unshipped($order, $shipping->fetch() ?: [])) {
                $typeName = in_array($order['project_type'] ?? '', ['小程序开发', '小程序客服'], true) ? '小程序定制' : ($order['project_type'] . '业务');
                throw new RuntimeException($typeName . '未发货或未到账，不能结算；到账不代表已发货');
            }
        }
        $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$payrollMonth]);
        $periodQuery = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
        $periodQuery->execute([$payrollMonth]);
        if ($periodQuery->fetchColumn() === 'locked') throw new RuntimeException('该项目分成月份已锁定，请选择未锁月份');
        $costs = ps_costs($orderId);
        if ($order['project_type'] === '商标') {
            require_once (dirname(__DIR__, 1)) . '/ProjectTrademarkCost.php';
            ptc_approval_guard($orderId, $costs);
        }
        $participants = ps_participants($orderId);
        $sum = ps_summary($order, $costs, $participants);
        $resourceQuery = $pdo->prepare('SELECT domain_mode,ssl_expected_amount FROM project_order_resources WHERE order_id=?');
        $resourceQuery->execute([$orderId]);
        $resource = $resourceQuery->fetch();
        $needsResources = !empty(ps_business_catalog()[ps_business_normalize($order['project_type'])]['resources']);
        if ($needsResources && (!$resource || $resource['domain_mode'] === 'pending')) throw new RuntimeException('资源使用尚未由技术确认，不能生成项目分成');
        require_once (dirname(__DIR__, 1)) . '/ProjectOrderItems.php';
        foreach (poi_items($orderId) as $item) {
            if (!empty($item['template_id']) || ((float)($item['reported_cost'] ?? 0) > 0)) {
                if (!$item['cost_id'] || $item['cost_status'] !== 'approved') {
                    throw new RuntimeException('商品“' . $item['item_name'] . '”成本未完整核对，不能生成项目分成');
                }
            }
        }
        $sslExpected = (float)($resource['ssl_expected_amount'] ?? 0);
        if ($sslExpected > 0) {
            $sslApprovedCents = 0;
            foreach ($costs as $cost) if ($cost['category'] === 'certificate' && $cost['review_status'] === 'approved') $sslApprovedCents += (int)round((float)$cost['amount'] * 100
    );
            if ($sslApprovedCents < (int)round($sslExpected * 100)) throw new RuntimeException('SSL 报备成本尚未按凭证补录并审核，不能生成项目分成');
        }
        if ($order['delivery_status'] !== 'finished') {
            // 完成状态目前仅针对网站类业务；小程序定制按发货状态；其他业务不因未完成拦截，审核时自动标记交付完成
            if (ps_is_website_order($order['project_type'])) {
                throw new RuntimeException('网站业务交付尚未完成，不能结算');
            } elseif (ps_miniapp_custom_order($order)) {
                $shipRow = $pdo->prepare('SELECT trade_status FROM project_order_sources WHERE order_id=?'); $shipRow->execute([$orderId]);
                if (!ps_miniapp_custom_shipped($order, $shipRow->fetch() ?: [])) throw new RuntimeException('小程序定制尚未发货，不能结算');
            }
            $pdo->prepare("UPDATE project_orders SET delivery_status='finished',row_version=row_version+1 WHERE id=?")->execute([$orderId]);
            $order['delivery_status'] = 'finished';
        }
        if (!empty(ps_business_catalog()[ps_business_normalize($order['project_type'])]['kind_required']) && trim((string)($order['order_kind'] ?? '')) === '') throw new RuntimeException
    ('请先选择订单类型（新订单 / 定制 / 续费），它决定分成比例和每单补助');
        // 补助规则存在但被“补助限定员工”取消发放时仍视为可结算：网站续费等部门单实收可为 0（无流水单），靠补助规则维持可审核。
        $hasSubsidy = $sum['groups']['technical']['subsidy'] > 0 || $sum['groups']['customer_service']['subsidy'] > 0
            || !empty($sum['groups']['technical']['subsidy_rule']) || !empty($sum['groups']['customer_service']['subsidy_rule']);
        $allowsNegative = false;
        foreach ($sum['groups'] as $group) foreach ($group['people'] as $person) if (!empty($person['rule']['allow_negative'])) $allowsNegative = true;
        if ($sum['income'] <= 0 && !$hasSubsidy && !($allowsNegative && $sum['income'] < 0)) throw new RuntimeException('没有可结算的实收收入');
        // 商标：资料 / 提交专员按件计酬，没有收入也有“补助”，但只有到账且已发货的订单才结算；未到账的不能靠补助规则放行。
        if (ps_business_normalize($order['project_type']) === '商标' && $sum['income'] <= 0) throw new RuntimeException('商标订单需先确认到账（收款），到账并发货后才结算');
        if ($sum['service_fee_rate'] > 0 && (float)$order['contract_amount'] <= 0 && $sum['income'] > 0) throw new RuntimeException($order['project_type'] . '订单须先核对售价，才能计算 '
    . round($sum['service_fee_rate'] * 100, 2) . '% 店铺服务费');
        $cashPending = $pdo->prepare("SELECT COUNT(*) FROM project_cash_movements WHERE order_id=? AND review_status='pending'");
        $cashPending->execute([$orderId]);
        if ((int)$cashPending->fetchColumn() > 0) throw new RuntimeException('还有待审核的收款或退款');
        foreach ($costs as $cost) if ($cost['review_status'] === 'pending') throw new RuntimeException('还有待审核成本');
        $hasGroup = false;
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $hasGroup = true;
            if ($group['missing_rule']) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组有参与人未匹配到项目分成规则（按业务/岗位/订单类型），请先在成本中心配置'
    );
            if (!ps_group_weight_valid($group)) throw new RuntimeException(($label === 'technical' ? '技术' : '客服') . '组权重合计必须为100%（软件测试独立核算，不占组池权重）');
        }
        if (!$hasGroup) throw new RuntimeException('请先添加技术或客服参与人');
        $insert = $pdo->prepare('INSERT INTO project_commission_snapshots (order_id,employee_id,commission_group,role_name,income_amount,direct_cost,service_fee,contribution_profit,rule_id,calc_mode,rate,group_weight,commission_amount,commission_exact,subsidy_amount,calc_note,payroll_month) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
        foreach ($sum['groups'] as $label => $group) {
            if (!$group['people']) continue;
            $shares = ps_group_share_cents($group['people']);
            foreach ($group['people'] as $i => $person) {
                $calc = $person['calc'];
                $subsidyCents = (int)round($calc['subsidy'] * 100);
                $insert->execute([$orderId, $person['employee_id'], $label, mb_substr((string)$person['role_name'], 0, 80), $sum['income'], $calc['cost_basis'], $calc['fee'], $calc
    ['base'], $person['rule']['id'], $calc['mode'], $calc['rate'], $person['group_weight'], ($shares[$i] + $subsidyCents) / 100, round(($calc['share_exact'] ?? $calc['share']) + $calc
    ['subsidy'], 6), $subsidyCents / 100, mb_substr($calc['note'], 0, 500), $payrollMonth]);
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
