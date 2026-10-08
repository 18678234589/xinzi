<?php
trait CalcTieredTrait
{
    private static function calcTiered($cfg, $c, $moduleName = '')
    {
        $tiers = $cfg['tiers'] ?? [];
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;

        // 成本扣除参数（网站定制专用）
        $serviceFeeRate = isset($cfg['service_fee_rate']) && $cfg['service_fee_rate'] !== '' && $cfg['service_fee_rate'] !== null ? (float)$cfg['service_fee_rate'] : 0;
        $domainCostPer  = isset($cfg['domain_cost_per']) && $cfg['domain_cost_per'] !== '' && $cfg['domain_cost_per'] !== null ? (float)$cfg['domain_cost_per'] : 0;
        $sslCostPer     = isset($cfg['ssl_cost_per']) && $cfg['ssl_cost_per'] !== '' && $cfg['ssl_cost_per'] !== null ? (float)$cfg['ssl_cost_per'] : 0;
        // 成本分摊角色：frontend=前端（分摊50%，后端无则承担100%），backend=后端（分摊50%，后端无则0%）
        $costRole       = isset($cfg['cost_role']) && $cfg['cost_role'] !== '' ? $cfg['cost_role'] : 'frontend';
        // 域名总成本（前端40+后端40=80，后端无则前端承担80）
        $domainTotalCost = isset($cfg['domain_total_cost']) && $cfg['domain_total_cost'] !== '' && $cfg['domain_total_cost'] !== null ? (float)$cfg['domain_total_cost'] : 80;

        // 如果配置了金额范围或店铺关键字，则忽略模块名筛选
        $useFilter = ($minAmount !== null || $maxAmount !== null || $shopKeyword !== null);
        $filterByName = $useFilter ? '' : $moduleName;

        // 按模块名筛选订单，同时应用金额范围过滤
        $total = self::filterOrderTotal($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);
        $count = self::filterOrderCount($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);

        // 阶梯判断基于所有订单的总额（不受金额范围限制）
        $totalForTier = 0;
        foreach (($c['orders'] ?? []) as $o) {
            $totalForTier += (float)($o['order_amount'] ?? 0);
        }

        // DEBUG: 输出筛选后的订单明细
        $debugOrders = [];
        foreach (($c['orders'] ?? []) as $o) {
            if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;
            $orderAmt = (float)($o['order_amount'] ?? 0);

            // 排除退款订单
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            if ($isRefund) continue;

            if ($minAmount !== null && $orderAmt < $minAmount) continue;
            if ($maxAmount !== null && $orderAmt > $maxAmount) continue;
            $debugOrders[] = $orderAmt;
        }
        error_log("calcTiered [{$moduleName}]: min=$minAmount, max=$maxAmount, total=$total, totalForTier=$totalForTier, count=$count, orders=" . json_encode(array_slice($debugOrders
    , 0, 5)));

        usort($tiers, function($a, $b) {
            return ($b['threshold'] ?? 0) - ($a['threshold'] ?? 0);
        });
        $rate = 0;
        $subsidy = 0;
        foreach ($tiers as $t) {
            if ($totalForTier >= (float)($t['threshold'])) {
                $rate = (float)($t['rate']);
                $subsidy = (float)($t['subsidy'] ?? 0);
                break;
            }
        }

        // 成本扣除：服务费、域名、SSL
        $serviceFee = $total * $serviceFeeRate;

        // 统计有域名和SSL的订单数（从raw_data中读取）
        $domainCount = 0;
        $sslCount = 0;
        $domainCostTotal = 0;
        $sslCostTotal = 0;

        if ($domainCostPer > 0 || $sslCostPer > 0 || (isset($cfg['ssl_from_rawdata']) && $cfg['ssl_from_rawdata']) || $domainTotalCost > 0) {
            foreach (($c['orders'] ?? []) as $o) {
                // 模块名过滤
                if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;

                $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                // 域名/SSL 已发生成本：退款订单同样计入（销售金额在其对应计算中已排除）

                // 金额范围过滤
                $orderAmt = (float)($o['order_amount'] ?? 0);
                if ($minAmount !== null && $orderAmt < $minAmount) continue;
                if ($maxAmount !== null && $orderAmt > $maxAmount) continue;

                // 读取后端字段值
                $backendVal = '';
                foreach ($rawData as $k => $v) {
                    if (mb_strpos($k, '后端') !== false) {
                        $backendVal = trim(strval($v));
                        break;
                    }
                }
                $backendIsNone = ($backendVal === '无' || $backendVal === '' || $backendVal === 'none');

                // 检查域名使用（域名年限按 "N年" / "是*N年" 识别，成本 × 年限）
                if ($domainTotalCost > 0) {
                    $domainYears = 0;
                    foreach ($rawData as $k => $v) {
                        if (mb_strpos($k, '域名') !== false) {
                            $y = domain_years($v);
                            if ($y > 0) { $domainYears = $y; break; }
                        }
                    }
                    if ($domainYears > 0) {
                        $domainCount++;
                        if ($costRole === 'frontend') {
                            // 前端：后端有则分摊50%，后端无则承担100%
                            $domainCostTotal += $backendIsNone ? ($domainTotalCost * $domainYears) : (($domainTotalCost / 2) * $domainYears);
                        } elseif ($costRole === 'backend') {
                            // 后端：后端有则分摊50%，后端无则0%
                            $domainCostTotal += $backendIsNone ? 0 : ($domainTotalCost / 2);
                        }
                    }
                }

                // 检查SSL证书使用（新规则：通配符→250 / 明确数字按数字 / 模糊标记是、一年等→30）
                if ($sslCostPer > 0 || (isset($cfg['ssl_from_rawdata']) && $cfg['ssl_from_rawdata'])) {
                    foreach ($rawData as $k => $v) {
                        if (mb_strpos($k, 'SSL') !== false || mb_strpos($k, 'ssl') !== false) {
                            $sslAmt = parse_ssl_amount($v);
                            if ($sslAmt !== null && $sslAmt > 0) {
                                if ($costRole === 'frontend') {
                                    // 前端：后端有则平分，后端无则全部
                                    $sslCostTotal += $backendIsNone ? $sslAmt : ($sslAmt / 2);
                                } elseif ($costRole === 'backend') {
                                    // 后端：后端有则平分，后端无则0
                                    $sslCostTotal += $backendIsNone ? 0 : ($sslAmt / 2);
                                }
                                $sslCount++;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // 利润 = 售价总额 - 服务费 - 域名成本 - SSL成本
        $profit = $total - $serviceFee - $domainCostTotal - $sslCostTotal;

        $commissionAmt = $profit * $rate;
        $subsidyAmt = $count * $subsidy;
        $amt = $commissionAmt + $subsidyAmt;
        $label = $moduleName ? "{$moduleName}({$count}笔)" : "({$count}笔)";

        $rangeLabel = '';
        if ($minAmount !== null || $maxAmount !== null) {
            if ($minAmount !== null && $maxAmount !== null) {
                $rangeLabel = sprintf('[¥%.0f-¥%.0f]', $minAmount, $maxAmount);
            } elseif ($minAmount !== null) {
                $rangeLabel = sprintf('[≥¥%.0f]', $minAmount);
            } else {
                $rangeLabel = sprintf('[≤¥%.0f]', $maxAmount);
            }
        }
        if ($shopKeyword !== null) {
            $rangeLabel .= "[店铺含:{$shopKeyword}]";
        }

        $formulaParts = [];
        // 显示成本扣除明细
        $costLabel = '';
        $costDetailParts = [];
        if ($serviceFeeRate > 0) {
            $costLabel .= sprintf('服务费%.0f%%', $serviceFeeRate * 100);
            $costDetailParts[] = sprintf('%.2f', $serviceFee);
        }
        if ($domainCostTotal > 0) {
            $costLabel .= ($costLabel ? '+' : '') . sprintf('域名%d个×%s=%.2f', $domainCount, $costRole === 'frontend' ? '前端分摊' : '后端分摊', $domainCostTotal);
            $costDetailParts[] = sprintf('%.2f', $domainCostTotal);
        }
        if ($sslCostTotal > 0) {
            $costLabel .= ($costLabel ? '+' : '') . sprintf('SSL¥%.2f', $sslCostTotal);
            $costDetailParts[] = sprintf('%.2f', $sslCostTotal);
        }
        if ($commissionAmt > 0) {
            if (count($costDetailParts) > 0) {
                $formulaParts[] = sprintf('%.2f-%s=%.2f×%.2f%%=%.2f', $total, implode('-', $costDetailParts), $profit, $rate*100, $commissionAmt);
            } else {
                $formulaParts[] = sprintf('%.2f×%.2f%%=%.2f', $total, $rate*100, $commissionAmt);
            }
        }
        if ($subsidyAmt > 0) {
            $formulaParts[] = sprintf('%d单×¥%g=%.2f', $count, $subsidy, $subsidyAmt);
        }
        $formula = implode(' + ', $formulaParts);
        if (count($formulaParts) === 0) {
            $formula = '0.00';
        }
        return [
            'amount' => round($amt, 2),
            'formula' => $formula,
            'type' => 'tiered',
        ];
    }

}
