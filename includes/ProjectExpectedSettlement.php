<?php
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectMonthly.php';
require_once __DIR__ . '/ProjectRefundTrash.php';

/** 内存预期记录：只用于看板，不写入分成快照或收款。 */
function ps_expected_snapshot_rows($order, $summary, $source = [])
{
    $order = ps_order_asof($order);
    $rows = [];
    $closed = preg_match('/交易关闭|已关闭|取消订单|已取消/u', (string)($source['trade_status'] ?? '')) === 1;
    $income = (float)$order['receipt_amount'] > 0
        ? (float)$order['receipt_amount'] - (float)$order['refund_amount']
        : max((float)$order['contract_amount'] - (float)$order['refund_amount'], 0);
    if ($closed && (float)$order['receipt_amount'] <= 0) $income = 0.0;
    $deptFee = (float)$order['contract_amount'] * ps_business_service_fee_rate($order['project_type']);
    $deptCost = (float)$summary['direct_cost'] + (float)$summary['pending_cost'];
    foreach ($summary['groups'] as $group => $data) {
        $people = $data['people'];
        $weight = array_sum(array_map(function ($p) { return (float)$p['group_weight']; }, $people));
        if ($people && abs($weight - 1) > 0.00001) continue; // 分配无效不能把重复的 100% 算成真实预期收入。
        foreach ($people as &$person) {
            $person['calc'] = $person['estimated_calc'];
            // 取消 / 已全额退款的订单不再获得正分成；从没有过售价的按单量计费订单（备案-单量、二次备案）保留每单固定补助
            if (($closed || ($income <= 0 && (float)$order['contract_amount'] > 0)) && $person['calc']) {
                // 退款冲减可以是负分成，取消/全退订单不再获得正分成或计单补助。
                $person['calc']['share'] = min(0.0, (float)$person['calc']['share']);
                $person['calc']['base'] = min(0.0, (float)$person['calc']['base']);
                $person['calc']['subsidy'] = 0.0;
            }
        }
        unset($person);
        $shares = ps_group_share_cents($people);
        foreach ($people as $i => $person) {
            $calc = $person['calc'];
            if (!$calc) continue;
            $rule = $person['rule'];
            $rows[] = [
                'order_id' => (int)$order['id'], 'order_no' => $order['order_no'],
                'project_type' => $order['project_type'], 'order_kind' => $order['order_kind'],
                'employee_id' => (int)$person['employee_id'], 'commission_group' => $group,
                'role_name' => $person['role_name'], 'group_weight' => (float)$person['group_weight'],
                'income_amount' => $income, 'direct_cost' => $calc['cost_basis'],
                'service_fee' => $calc['fee_part'], 'contribution_profit' => $calc['base'],
                'calc_mode' => $calc['mode'], 'rule_id' => (int)$rule['id'], 'rate' => $calc['rate'],
                'commission_amount' => ($shares[$i] ?? 0) / 100 + $calc['subsidy'],
                'commission_exact' => (float)$calc['share'] + (float)$calc['subsidy'],
                'subsidy_amount' => $calc['subsidy'],
                'department_profit' => $income - $deptCost - $deptFee,
                'department_revenue' => $income - $deptFee,
            ];
        }
    }
    return $rows;
}

/** 同一套规则计算固定服务费、阶梯/部门分成、全勤奖与尾差，支持无订单的合作方。 */
function ps_expected_rollup($employeeId, $snapshots, $monthly)
{
    $out = ['fixed_fee' => 0.0, 'commission' => 0.0, 'attendance' => 0.0,
            'total' => 0.0, 'order_commission' => 0.0, 'monthly_commission' => 0.0, 'items' => []];
    foreach ($snapshots as $snap) {
        if ((int)$snap['employee_id'] !== (int)$employeeId) continue;
        $out['order_commission'] += (float)$snap['commission_amount'];
    }
    $out['commission'] = $out['order_commission'];
    foreach ($monthly as $item) {
        if ((int)$item['employee_id'] !== (int)$employeeId || !empty($item['paid_separately'])) continue;
        $amount = (float)$item['amount'];
        $type = $item['rule_type'];
        $bucket = in_array($type, ['base_fee', 'fixed'], true) ? 'fixed_fee'
            : ($type === 'attendance_bonus' ? 'attendance' : 'commission');
        // 营业额阶梯的保底/固定服务费与分成分开，避免保底计入“项目分成”。
        if ($type === 'sales_package' && strpos($item['rule_name'], ' · 底薪') !== false) $bucket = 'fixed_fee';
        $out[$bucket] += $amount;
        if ($bucket === 'commission') $out['monthly_commission'] += $amount;
        $out['items'][] = $item + ['bucket' => $bucket];
    }
    foreach (['fixed_fee', 'commission', 'attendance', 'order_commission', 'monthly_commission'] as $key) $out[$key] = round($out[$key], 2);
    $out['total'] = round($out['fixed_fee'] + $out['commission'] + $out['attendance'], 2);
    return $out;
}

function ps_expected_month($month)
{
    static $cache = [];
    if (isset($cache[$month])) return $cache[$month];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw new InvalidArgumentException('月份格式无效');
    $from = $month . '-01';
    $until = (new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');
    $q = db()->prepare("SELECT o.*,s.trade_status,r.domain_mode,(SELECT COUNT(*) FROM project_refund_import_rows ri WHERE ri.order_id=o.id AND ri.review_status='pending'" . prt_active_sql('ri.') . ") AS pending_refunds FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id LEFT JOIN project_order_resources r ON r.order_id=o.id WHERE o.order_date>=? AND o.order_date<? ORDER BY o.id");
    $q->execute([$from, $until]);
    $orders = $q->fetchAll();
    $q = db()->prepare('SELECT p.*,e.name,e.department FROM project_participants p JOIN employees e ON e.id=p.employee_id JOIN project_orders o ON o.id=p.order_id WHERE o.order_date>=? AND o.order_date<? ORDER BY p.order_id,p.id');
    $q->execute([$from, $until]);
    $participants = [];
    foreach ($q->fetchAll() as $p) $participants[(int)$p['order_id']][] = $p;
    $q = db()->prepare('SELECT c.* FROM project_costs c JOIN project_orders o ON o.id=c.order_id WHERE o.order_date>=? AND o.order_date<? ORDER BY c.order_id,c.id');
    $q->execute([$from, $until]);
    $costs = [];
    foreach ($q->fetchAll() as $c) $costs[(int)$c['order_id']][] = $c;
    $snapshots = []; $warnings = [];
    foreach ($orders as $order) {
        $oid = (int)$order['id'];
        $summary = ps_summary($order, $costs[$oid] ?? [], $participants[$oid] ?? []);
        foreach (ps_expected_snapshot_rows($order, $summary, $order) as $snap) $snapshots[] = $snap;
        foreach ($participants[$oid] ?? [] as $person) {
            $eid = (int)$person['employee_id'];
            $warning = $warnings[$eid][$oid] ?? ['cash' => false, 'cost' => false, 'rule' => false, 'weight' => false, 'refund' => false];
            $warning['cash'] = (float)$order['receipt_amount'] <= 0 && (float)$order['contract_amount'] > 0;
            $warning['cost'] = ($order['domain_mode'] ?? '') === 'pending' || $summary['pending_cost'] > 0;
            $g = $summary['groups'][$person['commission_group']];
            $warning['rule'] = $g['missing_rule'];
            $warning['weight'] = abs($g['weight'] - 1) > 0.00001 && count($g['people']) > 0;
            $warning['refund'] = (int)$order['pending_refunds'] > 0;
            $warnings[$eid][$oid] = $warning;
        }
    }
    $monthly = ps_monthly_results($month, true, ['forecast' => true, 'snapshots' => $snapshots]);
    // 本月订单已按当前退款/更正后的金额重算，不重复叠加同月调整；以前月份的冲减按入账月份计入。
    $q = db()->prepare('SELECT a.*,o.order_no FROM project_commission_adjustments a JOIN project_orders o ON o.id=a.order_id WHERE a.payroll_month=? AND (o.order_date<? OR o.order_date>=?) ORDER BY a.id');
    $q->execute([$month, $from, $until]);
    foreach ($q->fetchAll() as $adjustment) $monthly[] = ['employee_id' => (int)$adjustment['employee_id'], 'rule_id' => 0,
        'rule_name' => '跨月分成更正 · ' . $adjustment['order_no'], 'rule_type' => 'adjustment', 'paid_separately' => 0,
        'amount' => (float)$adjustment['amount'], 'detail' => trim((string)$adjustment['reason'] . ((string)($adjustment['calc_note'] ?? '') !== '' ? '｜' . $adjustment['calc_note'] : ''))];
    return $cache[$month] = ['snapshots' => $snapshots, 'monthly' => $monthly, 'warnings' => $warnings];
}

function ps_partner_expected_income($employeeId, $month)
{
    $data = ps_expected_month($month);
    $result = ps_expected_rollup($employeeId, $data['snapshots'], $data['monthly']);
    $result['warnings'] = ['cash' => 0, 'cost' => 0, 'rule' => 0, 'weight' => 0, 'refund' => 0];
    foreach ($data['warnings'][(int)$employeeId] ?? [] as $warning) {
        foreach ($warning as $key => $flag) if ($flag) $result['warnings'][$key]++;
    }
    return $result;
}
