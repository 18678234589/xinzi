<?php
function applyProratedBaseSalary(&$result, $empId, $month) {
    $rawBase = (float)($result['base_salary'] ?? 0);
    // 检测需按出勤折算的固定服务费类模块（阶梯固定服务费 / 客服绩效固定服务费，其金额在 modules 里，不在 base_salary 字段）
    $baseModTypes = ['base_salary_tiered', 'cs_performance'];
    $baseModIdx = -1;
    $baseModType = '';
    $baseModAmount = 0;
    foreach (($result['modules'] ?? []) as $mi => $mod) {
        if (in_array(($mod['type'] ?? ''), $baseModTypes, true)) {
            $baseModIdx = $mi;
            $baseModType = (string)$mod['type'];
            $baseModAmount = (float)$mod['amount'];
            break;
        }
    }

    // 1. 合作人员表固定服务费单独折算
    $rawBaseInfo = calcProratedBaseSalary($empId, $month, $rawBase);
    $baseDiff = round($rawBaseInfo['prorated'] - $rawBase, 2);
    $result['base_salary'] = $rawBaseInfo['prorated'];
    $result['net_pay']  = round(($result['net_pay'] ?? 0) + $baseDiff, 2);

    // 2. 固定服务费类模块单独折算（阶梯固定服务费 / 客服绩效固定服务费）
    if ($baseModIdx >= 0) {
        $modBaseInfo = calcProratedBaseSalary($empId, $month, $baseModAmount);
        $proAmount = round($modBaseInfo['prorated'], 2);
        $result['modules'][$baseModIdx]['amount'] = $proAmount;
        if ($baseModAmount > 0) {
            if ($baseModType === 'cs_performance') {
                // 客服绩效固定服务费：保留达成率明细，追加出勤折算说明
                $result['modules'][$baseModIdx]['formula'] .= '；' . $modBaseInfo['status'] . '（原 ¥' . number_format($baseModAmount, 2) . ' → 折算后 ¥' . number_format($proAmount, 2) . '）';
            } else {
                $result['modules'][$baseModIdx]['formula'] = $modBaseInfo['status'] . '（原 ¥' . number_format($baseModAmount, 2) . '）';
            }
        } elseif ($baseModType === 'base_salary_tiered') {
            // 阶梯固定服务费模块金额为0，保持0，不把合作人员表固定服务费塞进去
            $result['modules'][$baseModIdx]['formula'] = '阶梯固定服务费¥0.00（未匹配阶梯或未配置base_amount）';
        }
        // 客服绩效固定服务费金额为0 时保留原公式（如"当月无绩效数据/不在绩效名单内"）以便诊断
        $result['module_total'] = round(array_sum(array_column($result['modules'], 'amount')), 2);
        $modDiff = round($proAmount - $baseModAmount, 2);
        $result['net_pay'] = round($result['net_pay'] + $modDiff, 2);
    }

    // 返回合作人员表固定服务费的折算信息（主固定服务费）
    return $rawBaseInfo;
}
