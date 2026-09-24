<?php
/**
 * 项目报酬计算 - 默认算法
 *
 * 计算公式：实发 = 固定服务费 + (订单总额 × 项目分成比例)
 *
 * 修改本文件会影响所有"未设置独立算法"的合作人员。
 * 若需为某合作人员单独定制算法，请在合作人员管理页面点击"算法设置"。
 *
 * 注意：本文件使用 return 闭包方式定义算法，支持多次加载不冲突。
 */

/**
 * 获取计算器闭包
 * @param array $context 上下文数据
 *   - employee: 合作人员记录(name, department, base_salary, commission_rate)
 *   - orders: 当月订单列表(二维数组)
 *   - order_total: 当月订单总额
 *   - order_count: 当月订单笔数
 *   - month: 结算月份
 * @return array
 *   - commission: 项目分成金额
 *   - net_pay: 实际结算金额
 *   - formula_text: 算法说明(展示用)
 *   - algorithm_name: 算法名称
 */
return function(array $context): array {
    $baseSalary     = (float)$context['employee']['base_salary'];
    $commissionRate = (float)$context['employee']['commission_rate'];
    $orderTotal     = (float)$context['order_total'];

    // 项目分成 = 订单总额 × 项目分成比例
    $commission = $orderTotal * $commissionRate;

    // 实发 = 固定服务费 + 项目分成
    $netPay = $baseSalary + $commission;

    $ratePercent = $commissionRate * 100;
    $formula = sprintf(
        '实发 = 固定服务费 + (订单总额 × 项目分成比例) = %s + (%s × %s%%) = %s',
        number_format($baseSalary, 2),
        number_format($orderTotal, 2),
        $ratePercent,
        number_format($netPay, 2)
    );

    return [
        'commission'    => round($commission, 2),
        'net_pay'       => round($netPay, 2),
        'formula_text'  => $formula,
        'algorithm_name'=> '默认算法',
    ];
};
