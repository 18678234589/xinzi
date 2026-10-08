<?php
trait CalcTrademarkTrait
{
    private static function calcTrademarkCommission($cfg, $c, $moduleName = '')
    {
        $commissionRate = (float)($cfg['commission_rate'] ?? 0);
        $serviceFeeRate = (float)($cfg['service_fee_rate'] ?? 0);

        $totalPrice  = 0;
        $totalCost   = 0;
        $count       = 0;

        foreach (($c['orders'] ?? []) as $o) {
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);

            // 模块名过滤
            if ($moduleName !== '' && trim($o['project'] ?? '') !== $moduleName) continue;

            // 从 raw_data 提取售价（价格列）
            $price = 0;
            foreach ($rawData as $k => $v) {
                if (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false) {
                    $price = extract_amount($v);
                    if ($price != 0) break;
                }
            }
            // 找不到售价，用 order_amount
            if ($price == 0) {
                $price = (float)($o['order_amount'] ?? 0);
            }

            // 从 raw_data 提取成本
            $cost = 0;
            foreach ($rawData as $k => $v) {
                if (mb_strpos($k, '成本') !== false) {
                    $cost = extract_amount($v);
                }
            }

            // 售价和成本（包括负数）都正常累加
            $totalPrice  += $price;
            $totalCost   += $cost;
            $count++;
        }

        // 公式：[(售价-成本) - (售价×服务费比例)] × 项目分成比例
        $amt = (($totalPrice - $totalCost) - $totalPrice * $serviceFeeRate) * $commissionRate;

        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('((售价¥%.2f - 成本¥%.2f) - 售价¥%.2f×%.2f%%) ×%.2f%% = ¥%.2f', $totalPrice, $totalCost, $totalPrice, $serviceFeeRate*100, $commissionRate
    *100, $amt),
            'type' => 'trademark_commission',
        ];
    }

    // ---- 商标部小额返现项目分成 ----
    private static function calcTrademarkCashback($cfg, $c, $moduleName = '')
    {
        $perAmount = (float)($cfg['per_amount'] ?? 0);
        $employeeId = $c['employee']['id'] ?? 0;

        $count = 0;

        foreach (($c['orders'] ?? []) as $o) {
            if ($o['employee_id'] != $employeeId) continue;

            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);

            // 模块名过滤
            if ($moduleName !== '' && trim($o['project'] ?? '') !== $moduleName) continue;

            // 获取备注内容
            $remark = trim($o['remark'] ?? '');
            $rawRemark = '';
            if (is_array($rawData)) {
                foreach ($rawData as $key => $value) {
                    $lowerKey = mb_strtolower(trim($key));
                    if (mb_strpos($lowerKey, '备注') !== false) {
                        $rawRemark = trim((string)$value);
                        break;
                    }
                }
            }

            // 检查备注是否包含"小额返"
            if (mb_strpos($remark, '小额返') !== false || mb_strpos($rawRemark, '小额返') !== false) {
                $count++;
            }
        }

        $amt = $count * $perAmount;

        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('小额返现%d单×¥%.2f=¥%.2f', $count, $perAmount, $amt),
            'type' => 'trademark_cashback',
        ];
    }

}
