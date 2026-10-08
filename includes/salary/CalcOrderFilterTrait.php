<?php
trait CalcOrderFilterTrait
{
    private static function filterOrderTotal($c, $moduleName, $minAmount = null, $maxAmount = null, $shopKeyword = null)
    {
        $sum = 0;
        foreach (($c['orders'] ?? []) as $o) {
            // 模块名过滤
            if ($moduleName !== '' && trim($o['project'] ?? '') !== $moduleName) continue;

            $orderAmt = (float)($o['order_amount'] ?? 0);

            // 排除退款订单（负数金额），退款订单单独在"退款扣除"模块处理
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            if ($isRefund) continue; // 跳过退款订单

            // 金额范围过滤
            if ($minAmount !== null && $orderAmt < $minAmount) continue;
            if ($maxAmount !== null && $orderAmt > $maxAmount) continue;

            // 店铺关键字过滤（用于老客户等场景）
            if ($shopKeyword !== null) {
                $shop = '';
                // 优先查找与关键字同名的列（如"老客户"列）
                if (isset($rawData[$shopKeyword])) {
                    $shop = trim($rawData[$shopKeyword]);
                } else {
                    // 如果没有同名列，则查找"店铺"或"店名"列的值
                    foreach ($rawData as $k => $v) {
                        if (mb_strpos($k, '店铺') !== false || mb_strpos($k, '店名') !== false) {
                            $shop = trim($v);
                            break;
                        }
                    }
                }
                // 如果字段值为空，跳过
                if ($shop === '') continue;
            }

            $sum += $orderAmt;
        }
        return $sum;
    }

    // 辅助：按模块名筛选订单笔数
    private static function filterOrderCount($c, $moduleName, $minAmount = null, $maxAmount = null, $shopKeyword = null)
    {
        $cnt = 0;
        foreach (($c['orders'] ?? []) as $o) {
            // 模块名过滤
            if ($moduleName !== '' && trim($o['project'] ?? '') !== $moduleName) continue;

            $orderAmt = (float)($o['order_amount'] ?? 0);

            // 排除退款订单（负数金额），退款订单单独在"退款扣除"模块处理
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            if ($isRefund) continue; // 跳过退款订单

            // 金额范围过滤
            if ($minAmount !== null && $orderAmt < $minAmount) continue;
            if ($maxAmount !== null && $orderAmt > $maxAmount) continue;

            // 店铺关键字过滤（用于老客户等场景）
            if ($shopKeyword !== null) {
                $shop = '';
                // 优先查找与关键字同名的列（如"老客户"列）
                if (isset($rawData[$shopKeyword])) {
                    $shop = trim($rawData[$shopKeyword]);
                } else {
                    // 如果没有同名列，则查找"店铺"或"店名"列的值
                    foreach ($rawData as $k => $v) {
                        if (mb_strpos($k, '店铺') !== false || mb_strpos($k, '店名') !== false) {
                            $shop = trim($v);
                            break;
                        }
                    }
                }
                // 如果字段值为空，跳过
                if ($shop === '') continue;
            }

            $cnt++;
        }
        return $cnt;
    }

}
