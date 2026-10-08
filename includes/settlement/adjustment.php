<?php

/** 从指定月份（默认本月）起第一个未锁定的项目分成月份。 */
function ps_next_open_month($from = null)
{
    $month = $from ?: date('Y-m');
    $q = db()->prepare("SELECT status FROM project_payroll_periods WHERE period=?");
    for ($i = 0; $i < 36; $i++) {
        $q->execute([$month]);
        if ($q->fetchColumn() !== 'locked') return $month;
        $month = date('Y-m', strtotime($month . '-01 +1 month'));
    }
    throw new RuntimeException('未来 36 个月均已锁定，请检查结算月份');
}

/**
 * 已审核订单发生售后退款或成本增减：登记退款（财务确认）与成本调整，按审核时的规则参数重算每人应得分成，
 * 与“原快照 + 以往调整”的差额写入调整单，计入指定月份（默认下一个未锁定月）。原快照保持不变。
 * 全额退款（可结算收入 ≤ 0）时每单补助一并收回，与小程序结算表“-350 / -20”口径一致。
 */
function ps_post_adjustment($orderId, $actor, $refundAmount, $costDelta, $reason, $payrollMonth)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('只有财务可以登记售后调整');
    $refundAmount = round((float)$refundAmount, 2);
    $costDelta = round((float)$costDelta, 2);
    $reason = trim((string)$reason);
    if ($refundAmount < 0) throw new RuntimeException('退款金额不能为负数');
    if ($refundAmount == 0 && $costDelta == 0) throw new RuntimeException('请填写退款金额或成本调整');
    if ($reason === '' || mb_strlen($reason) > 300) throw new RuntimeException('请填写调整原因（300 字以内）');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth)) throw new RuntimeException('调整计入月份无效');
    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_adjustment'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([(int)$orderId]);
        $order = $q->fetch();
        if (!$order || !in_array($order['settlement_status'], ['approved', 'locked'], true)) throw new RuntimeException('只有已审核的订单需要走售后调整；未审核订单请直接修改');
        $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$payrollMonth]);
        $period = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
        $period->execute([$payrollMonth]);
        if ($period->fetchColumn() === 'locked') throw new RuntimeException($payrollMonth . ' 已锁定，请选择未锁定的月份');
        $cashId = null;
        if ($refundAmount > 0) {
            $pdo->prepare("INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,'refund',?,?,'approved',?,?,?,NOW())")
                ->execute([(int)$orderId, $refundAmount, '售后调整：' . $reason, $actor['type'], $actor['id'], $actor['id']]);
            $cashId = (int)$pdo->lastInsertId();
            ps_recalculate_cash((int)$orderId);
        }
        if ($costDelta != 0) {
            $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,reviewed_by_admin,review_note) VALUES (?,'other',?,1,'项',?,?,'one_time',1,?,'approved',?,?)")
                ->execute([(int)$orderId, $costDelta > 0 ? '售后补录成本' : '售后冲减成本', $costDelta, $costDelta, $reason, $actor['id'], '售后调整']);
        }
        $q->execute([(int)$orderId]);
        $order = $q->fetch();
        $income = round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2);
        $directCost = 0.0;
        $phpCost = 0.0;
        foreach (ps_costs((int)$orderId) as $cost) if ($cost['review_status'] === 'approved') { $directCost += (float)$cost['amount']; if (ps_is_php_cost($cost)) $phpCost += (float)$cost['amount']; }
        $businessFeeRate = ps_business_service_fee_rate($order['project_type']);
        $snapshots = $pdo->prepare('SELECT s.*,r.service_fee_rate AS r_fee,r.min_contract_amount AS r_min,r.min_cost_rate AS r_min_cost,r.allow_negative AS r_allow_negative,r.id AS live_rule_id FROM project_commission_snapshots s LEFT JOIN project_commission_rules r ON r.id=s.rule_id WHERE s.order_id=? ORDER BY s.commission_group,s.id');
        $snapshots->execute([(int)$orderId]);
        $prior = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM project_commission_adjustments WHERE order_id=? AND employee_id=? AND commission_group=?');
        $insert = $pdo->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,cash_movement_id,created_by_admin) VALUES (?,?,?,?,?,?,?,?,?)');
        $groups = [];
        foreach ($snapshots->fetchAll() as $snap) {
            // 比例、方式、补助、服务费全部取自审核快照（服务费率 = 快照服务费 ÷ 售价），规则之后被修改不影响已审核订单口径。
            $feeRate = (float)$order['contract_amount'] > 0 ? round((float)$snap['service_fee'] / (float)$order['contract_amount'], 6) : 0;
            $rule = ['id' => (int)$snap['rule_id'], 'rate' => $snap['rate'], 'calc_mode' => $snap['calc_mode'], 'service_fee_rate' => $feeRate, 'per_order_subsidy' => $snap['subsidy_amount'], 'min_contract_amount' => $snap['r_min'] ?? 0, 'min_cost_rate' => $snap['r_min_cost'] ?? null, 'allow_negative' => $snap['r_allow_negative'] ?? 0];
            [$phpAdjusted, $phpNote] = $phpCost > 0 ? ps_php_cost_for((float)$order['contract_amount'], $snap['commission_group'], (string)$snap['role_name'], $phpCost) : [0.0, ''];
            $calc = ps_calc_person($rule, $income, round($directCost - $phpCost + $phpAdjusted, 2), $order['contract_amount'], $snap['group_weight'], $businessFeeRate, $phpNote);
            if ($income <= 0) { $calc['share'] = 0.0; $calc['subsidy'] = 0.0; }
            $groups[$snap['commission_group']][] = ['employee_id' => (int)$snap['employee_id'], 'group_weight' => $snap['group_weight'], 'rule' => $rule, 'calc' => $calc, 'snapshot' => $snap];
        }
        $created = [];
        foreach ($groups as $group => $people) {
            $shares = ps_group_share_cents($people);
            foreach ($people as $i => $person) {
                $newCents = $shares[$i] + (int)round($person['calc']['subsidy'] * 100);
                $prior->execute([(int)$orderId, $person['employee_id'], $group]);
                $oldCents = (int)round(((float)$person['snapshot']['commission_amount'] + (float)$prior->fetchColumn()) * 100);
                $delta = $newCents - $oldCents;
                if ($delta === 0) continue;
                $note = '重算后 ¥' . money_plain($newCents / 100) . '，原 ¥' . money_plain($oldCents / 100) . ($refundAmount > 0 ? '；退款 ¥' . money_plain($refundAmount) : '') . ($costDelta != 0 ? '；成本 ' . ($costDelta > 0 ? '+' : '') . money_plain($costDelta) : '');
                $insert->execute([(int)$orderId, $person['employee_id'], $group, $delta / 100, $payrollMonth, $reason, mb_substr($note, 0, 500), $cashId, $actor['id']]);
                $created[] = ['employee_id' => $person['employee_id'], 'group' => $group, 'amount' => $delta / 100];
            }
        }
        ps_audit('order', (int)$orderId, 'adjustment', $actor, ['refund' => $refundAmount, 'cost_delta' => $costDelta, 'month' => $payrollMonth, 'reason' => $reason, 'adjustments' => $created]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_adjustment'); else $pdo->commit();
        return $created;
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_adjustment'); else $pdo->rollBack();
        throw $e;
    }
}

/** 财务纠正已审核订单类型：保留原快照，差额计入指定未锁定月份。 */
function ps_reclassify_order_kind($orderId, $kind, $actor, $payrollMonth, $applyFuture = false)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务可纠正订单类型');
    $pdo = db(); $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_reclassify_kind'); else $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $q->execute([(int)$orderId]); $order = $q->fetch();
        if (!$order) throw new RuntimeException('订单不存在');
        $kind = ps_order_kind_valid(ps_business_normalize($order['project_type']), $kind);
        if ($kind === '') throw new RuntimeException('请选择正确订单类型');
        $before = (string)$order['order_kind'];
        if ($before === $kind) throw new RuntimeException('订单类型没有变化');
        $approved = in_array($order['settlement_status'], ['approved', 'locked'], true);
        if ($approved) {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$payrollMonth)) throw new RuntimeException('请选择调整计入月份');
            $pdo->prepare('INSERT INTO project_payroll_periods (period) VALUES (?) ON DUPLICATE KEY UPDATE period=period')->execute([$payrollMonth]);
            $period = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
            $period->execute([$payrollMonth]);
            if ($period->fetchColumn() === 'locked') throw new RuntimeException($payrollMonth . ' 已锁定，请选择未锁定月份');
            $order['order_kind'] = $kind;
            $summary = ps_summary($order, ps_costs($orderId), ps_participants($orderId));
            $snap = $pdo->prepare('SELECT employee_id,commission_group,SUM(commission_amount) amount FROM project_commission_snapshots WHERE order_id=? GROUP BY employee_id,commission_group');
            $snap->execute([(int)$orderId]); $old = [];
            foreach ($snap->fetchAll() as $s) $old[$s['commission_group'] . ':' . $s['employee_id']] = (float)$s['amount'];
            if (!$old) throw new RuntimeException('原审核快照不存在，不能自动重算，请财务核对');
            $prior = $pdo->prepare('SELECT commission_group,employee_id,SUM(amount) amount FROM project_commission_adjustments WHERE order_id=? GROUP BY commission_group,employee_id');
            $prior->execute([(int)$orderId]);
            foreach ($prior->fetchAll() as $p) $old[$p['commission_group'] . ':' . $p['employee_id']] = ($old[$p['commission_group'] . ':' . $p['employee_id']] ?? 0) + (float)$p['amount'];
            $ins = $pdo->prepare('INSERT INTO project_commission_adjustments (order_id,employee_id,commission_group,amount,payroll_month,reason,calc_note,created_by_admin) VALUES (?,?,?,?,?,?,?,?)');
            $reason = '财务纠正订单类型：' . ($before ?: '未分类') . ' → ' . $kind;
            foreach ($summary['groups'] as $group => $data) {
                if (!$data['people']) continue;
                if ($data['missing_rule'] || abs($data['weight'] - 1.0) > 0.000001) throw new RuntimeException('新类型的分成规则或参与人权重未配置完整，不能自动重算');
                $shares = ps_group_share_cents($data['people']);
                foreach ($data['people'] as $i => $person) {
                    $key = $group . ':' . $person['employee_id'];
                    $newCents = $summary['income'] <= 0 ? 0 : $shares[$i] + (int)round($person['calc']['subsidy'] * 100);
                    $oldCents = (int)round(($old[$key] ?? 0) * 100);
                    $delta = $newCents - $oldCents;
                    if ($delta) $ins->execute([(int)$orderId, $person['employee_id'], $group, $delta / 100, $payrollMonth, $reason, '按“' . $kind . '”重算 ¥' . money_plain($newCents / 100) . '，已计 ¥' . money_plain($oldCents / 100), $actor['id']]);
                    unset($old[$key]);
                }
            }
            if ($old) throw new RuntimeException('原快照参与人与当前不一致，请财务核对后再调整');
        }
        $pdo->prepare('UPDATE project_orders SET order_kind=?,row_version=row_version+1 WHERE id=?')->execute([$kind, (int)$orderId]);
        if ($applyFuture) {
            $people = $pdo->prepare('SELECT DISTINCT employee_id FROM project_participants WHERE order_id=?');
            $people->execute([(int)$orderId]);
            $default = $pdo->prepare("INSERT INTO project_import_kind_preferences (employee_id,business_name,layout_signature,order_kind,source) VALUES (?,?,'*',?,'finance') ON DUPLICATE KEY UPDATE order_kind=VALUES(order_kind),source='finance',confirmed_count=confirmed_count+1,updated_at=NOW()");
            foreach ($people->fetchAll(PDO::FETCH_COLUMN) as $employeeId) $default->execute([(int)$employeeId, ps_business_normalize($order['project_type']), $kind]);
        }
        ps_audit('order', (int)$orderId, 'reclassify_kind', $actor, ['before' => $before, 'after' => $kind, 'month' => $approved ? $payrollMonth : null, 'future_default' => (bool)$applyFuture]);
        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_reclassify_kind'); else $pdo->commit();
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_reclassify_kind');
        elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* ---------- 订单交付凭证申请与产品升级补差申请 ---------- */
