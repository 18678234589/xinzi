<?php
trait CalcProfitTrait
{
    private static function calcProfitCommission($cfg, $c, $moduleName = '')
    {
        $commissionRate = (float)($cfg['commission_rate'] ?? 0);
        $serviceFeeRate = (float)($cfg['service_fee_rate'] ?? 0);

        $totalProfit = 0;
        $totalPrice  = 0;
        $totalCost   = 0;
        $count       = 0;

        foreach (($c['orders'] ?? []) as $o) {
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);

            // 排除退款订单
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            if ($isRefund) continue;

            // 模块名过滤
            if ($moduleName !== '' && trim($o['project'] ?? '') !== $moduleName) continue;

            // 从 raw_data 提取成本
            $orderAmt = (float)($o['order_amount'] ?? 0);
            $cost = 0;
            foreach ($rawData as $k => $v) {
                if (mb_strpos($k, '成本') !== false) {
                    $cost = extract_amount($v);
                }
            }

            $totalPrice  += $orderAmt;
            $totalCost   += $cost;
            $count++;
        }

        $amt = (($totalPrice - $totalCost) - $totalPrice * $serviceFeeRate) * $commissionRate;

        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('((订单金额¥%.2f - 成本¥%.2f) - 订单金额¥%.2f×%.2f%%) ×%.2f%% = %.2f', $totalPrice, $totalCost, $totalPrice, $serviceFeeRate*100, $commissionRate
    *100, $amt),
            'type' => 'profit_commission',
        ];
    }

}
