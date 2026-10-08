<?php
trait CalcMiniProgramTrait
{
    private static function calcMiniProgramCommission($cfg, $c, $moduleName = '')
    {
        $commissionRate = (float)($cfg['commission_rate'] ?? 0);
        $serviceFeeRate = (float)($cfg['service_fee_rate'] ?? 0);
        $filterColumn    = trim($cfg['filter_column'] ?? '');
        $filterValue     = trim($cfg['filter_value'] ?? '');
        $customerSubsidy = (float)($cfg['customer_subsidy'] ?? 0);

        // 使用 $c['orders']（已由 loadEmployeeOrdersWithDept 加载，包含个人订单 + 部门虚拟拆分订单）
        // 不能直接查库（employee_id=?），否则部门订单（employee_id=0）的虚拟拆分行会被遗漏
        $orders = [];
        foreach (($c['orders'] ?? []) as $o) {
            if ($moduleName !== '' && trim($o['project'] ?? '') !== $moduleName) continue;
            $orders[] = $o;
        }

        // ===== 第一部分：利润项目分成 =====
        // 公式：((订单金额 - 成本) - 订单金额 × 服务费比例) × 项目分成比例
        $totalPrice = 0;
        $totalCost  = 0;
        $count      = 0;

        foreach ($orders as $o) {
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            if (!is_array($rawData)) $rawData = [];

            // 排除退款订单
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            if ($isRefund) continue;

            // 从 raw_data 提取成本
            $orderAmt = (float)($o['order_amount'] ?? 0);
            $cost = 0;
            foreach ($rawData as $k => $v) {
                if (mb_strpos($k, '成本') !== false) {
                    $cost = extract_amount($v);
                }
            }

            $totalPrice += $orderAmt;
            $totalCost  += $cost;
            $count++;
        }

        $profitCommission = (($totalPrice - $totalCost) - $totalPrice * $serviceFeeRate) * $commissionRate;

        // ===== 第二部分：新老客户补助 =====
        // 按指定字段名和字段值筛选订单，根据订单号和付款人去重
        $subsidyCount    = 0;
        $subsidyAmount   = 0;
        $dedupedOrders   = []; // key = "订单号|付款人"

        if ($filterColumn !== '' && $filterValue !== '' && $customerSubsidy > 0) {
            foreach ($orders as $o) {
                $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rawData)) $rawData = [];

                // 排除退款订单
                $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
                if ($isRefund) continue;

                // 模糊匹配字段名：优先精确匹配，找不到则用包含匹配
                $fieldVal = '';
                if (isset($rawData[$filterColumn])) {
                    $fieldVal = trim($rawData[$filterColumn]);
                } else {
                    foreach ($rawData as $k => $v) {
                        if (mb_strpos($k, $filterColumn) !== false) {
                            $fieldVal = trim($v);
                            break;
                        }
                    }
                }

                // 字段值不匹配则跳过
                if ($fieldVal === '' || mb_strpos($fieldVal, $filterValue) === false) continue;

                // 提取订单号和付款人用于去重
                $orderNo = trim($o['order_no'] ?? '');
                if ($orderNo === '') {
                    $orderNo = extract_order_no($rawData);
                }
                $payer = '';
                // 查找付款人字段（支持多种列名）
                foreach ($rawData as $k => $v) {
                    $lowerK = strtolower($k);
                    if (strpos($lowerK, '付款人') !== false || 
                        strpos($lowerK, '买家') !== false || 
                        strpos($lowerK, '客户') !== false) {
                        $payer = trim($v);
                        break;
                    }
                }

                // 去重：订单号+付款人
                $dedupKey = $orderNo . '|' . $payer;
                if ($orderNo !== '' && isset($dedupedOrders[$dedupKey])) continue;
                if ($orderNo !== '') $dedupedOrders[$dedupKey] = true;

                $subsidyCount++;
            }
            $subsidyAmount = $subsidyCount * $customerSubsidy;
        }

        $totalAmount = $profitCommission + $subsidyAmount;

        // 构建公式说明
        $formulaParts = [];
        if ($count > 0) {
            $formulaParts[] = sprintf('利润项目分成((%.2f-%.2f)-%.2f×%.2f%%)×%.2f%%=%.2f',
                $totalPrice, $totalCost, $totalPrice, $serviceFeeRate * 100, $commissionRate * 100, $profitCommission);
        }
        if ($subsidyCount > 0 && $customerSubsidy > 0) {
            $formulaParts[] = sprintf('新客户%d单×¥%g(每单补助)=%.2f', $subsidyCount, $customerSubsidy, $subsidyAmount);
        }

        $formula = implode(' + ', $formulaParts);
        if ($formula === '') {
            $formula = sprintf('%.2f（无匹配订单）', $totalAmount);
        }

        return [
            'amount' => round($totalAmount, 2),
            'formula' => $formula,
            'type' => 'miniprogram_commission',
        ];
    }

}
