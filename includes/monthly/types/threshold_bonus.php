<?php

$threshold = (float)($p['threshold'] ?? 0);
$rate = (float)($p['rate'] ?? 0);

if (!empty($p['reconcile_base'])) {
    $baseRate = (float)($p['base_rate'] ?? 0.13);
    $feeRate = (float)($p['fee_rate'] ?? 0.03);
    // 获取当月此人的售后调整金额（如退款补扣、成本冲回等），统算时必须把已记调整一并纳入对齐
    $adjByEmp = [];
    try {
        $adjQ = db()->prepare('SELECT employee_id, COALESCE(SUM(amount), 0) FROM project_commission_adjustments WHERE payroll_month=? GROUP BY employee_id');
        $adjQ->execute([$month]);
        $adjByEmp = $adjQ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {}
    foreach ($people as $eid => $m) {
        $costs = (float)($m['costs'] ?? 0.0);
        $fee = round($m['sales'] * $feeRate, 2);
        $realProfit = round($m['sales'] - $costs - $fee, 2);
        $targetBase = round($realProfit * $baseRate, 2);
        $adj = (float)($adjByEmp[$eid] ?? 0.0);
        $diff = round($targetBase - ($m['share'] + $adj), 2);
        if (abs($diff) >= 0.005) {
            $diffRule = [
                'id' => $rule['id'],
                'name' => '模板技术月度统算差额',
                'rule_type' => 'threshold_bonus',
                'params' => $rule['params'],
            ];
            $add($eid, $diffRule, $diff, sprintf('月售价 ¥%s − 成本 ¥%s − %s%%服务费 ¥%s = 净毛利 ¥%s，%s%%基础提成 ¥%s，逐单已计 ¥%s%s，统算差额 ¥%s',
                money_plain($m['sales']), money_plain($costs), round($feeRate * 100, 2), money_plain($fee),
                money_plain($realProfit), round($baseRate * 100, 2), money_plain($targetBase), money_plain($m['share']),
                ($adj != 0 ? sprintf('（含调整 ¥%s）', money_plain($adj)) : ''), money_plain($diff)));
        }
        if ($realProfit > $threshold) {
            $bonus = round(($realProfit - $threshold) * $rate, 2);
            $add($eid, $rule, $bonus, sprintf('整月净毛利 ¥%s − 门槛 ¥%s = ¥%s，× %s%% = ¥%s',
                money_plain($realProfit), money_plain($threshold), money_plain($realProfit - $threshold), round($rate * 100, 4), money_plain($bonus)));
        }
    }
} else {
    foreach ($people as $eid => $m) {
        if ($m[$metric] <= $threshold) continue;
        $add($eid, $rule, ($m[$metric] - $threshold) * $rate, sprintf('月%s ¥%s − 门槛 ¥%s = ¥%s，× %s%%', $metricLabel, money_plain($m[$metric]), money_plain($threshold), money_plain($m[$metric] - $threshold), round($rate * 100, 4)));
    }
}
