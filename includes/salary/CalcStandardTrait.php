<?php
trait CalcStandardTrait
{
    private static function calcStandard($cfg, $c, $moduleName = '')
    {
        $rate = (float)($cfg['rate'] ?? 0.05);
        $serviceFeeRate = isset($cfg['service_fee_rate']) && $cfg['service_fee_rate'] !== '' && $cfg['service_fee_rate'] !== null ? (float)$cfg['service_fee_rate'] : 0;
        $priceSource = $cfg['price_source'] ?? 'order_amount';
        $filterColumn = isset($cfg['filter_column']) && trim($cfg['filter_column']) !== '' ? trim($cfg['filter_column']) : '';
        $filterValue  = isset($cfg['filter_value'])  && $cfg['filter_value']  !== '' ? trim($cfg['filter_value'])  : '';
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;

        // 如果配置了金额范围或店铺关键字，则忽略模块名筛选
        $useFilter = ($minAmount !== null || $maxAmount !== null || $shopKeyword !== null);
        $filterByName = $useFilter ? '' : $moduleName;

        // 列值精确筛选（如"技术"列值等于"纪鹏程"才计入）
        $hasColumnFilter = $filterColumn !== '' && $filterValue !== '';
        $matchColumnFilter = function ($rawData) use ($filterColumn, $filterValue) {
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
            return $fieldVal === $filterValue;
        };

        $total = self::filterOrderTotal($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);
        $count = self::filterOrderCount($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);
        if ($hasColumnFilter) {
            $total = 0;
            $count = 0;
            foreach (($c['orders'] ?? []) as $o) {
                if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;
                $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rawData)) $rawData = [];
                if (!$matchColumnFilter($rawData)) continue;
                if (isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1') continue;
                $orderAmt = (float)($o['order_amount'] ?? 0);
                if ($minAmount !== null && $orderAmt < $minAmount) continue;
                if ($maxAmount !== null && $orderAmt > $maxAmount) continue;
                if ($shopKeyword !== null) {
                    $shop = isset($rawData[$shopKeyword]) ? trim($rawData[$shopKeyword]) : '';
                    if ($shop === '') {
                        foreach ($rawData as $k => $v) {
                            if (mb_strpos($k, '店铺') !== false || mb_strpos($k, '店名') !== false) {
                                $shop = trim($v);
                                break;
                            }
                        }
                    }
                    if ($shop === '') continue;
                }
                $total += $orderAmt;
                $count++;
            }
        }
        $totalPrice = 0;
        $totalCost = 0;

        if ($priceSource === 'selling_price') {
            foreach (($c['orders'] ?? []) as $o) {
                if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;

                $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rawData)) $rawData = [];
                $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
                if ($isRefund) continue;

                if ($hasColumnFilter && !$matchColumnFilter($rawData)) continue;

                $orderAmt = (float)($o['order_amount'] ?? 0);
                if ($minAmount !== null && $orderAmt < $minAmount) continue;
                if ($maxAmount !== null && $orderAmt > $maxAmount) continue;

                if ($shopKeyword !== null) {
                    $shop = '';
                    if (isset($rawData[$shopKeyword])) {
                        $shop = trim($rawData[$shopKeyword]);
                    } else {
                        foreach ($rawData as $k => $v) {
                            if (mb_strpos($k, '店铺') !== false || mb_strpos($k, '店名') !== false) {
                                $shop = trim($v);
                                break;
                            }
                        }
                    }
                    if ($shop === '') continue;
                }

                $price = 0;
                foreach ($rawData as $k => $v) {
                    if (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false) {
                        $price = extract_amount($v);
                        if ($price != 0) break;
                    }
                }
                if ($price == 0 && isset($rawData['__original_price__'])) {
                    $price = (float)$rawData['__original_price__'];
                }
                if ($price == 0) {
                    $price = $orderAmt;
                }

                $cost = 0;
                foreach ($rawData as $k => $v) {
                    if (mb_strpos($k, '成本') !== false) {
                        $cost = extract_amount($v);
                    }
                }

                $totalPrice += $price;
                $totalCost += $cost;
            }
            $total = $totalPrice - $totalCost;
        }

        // 扣除手续费：项目分成 = (订单总额 - 手续费) × 项目分成比例
        $serviceFeeBase = $priceSource === 'selling_price' ? $totalPrice : $total;
        $serviceFee = $serviceFeeBase * $serviceFeeRate;
        $netTotal = $total - $serviceFee;

        // 域名成本分摊（与 calcTiered 一致）："域名使用=是"的订单按角色承担域名成本
        // frontend：后端有则分摊一半、后端无则全额；backend：后端有则分摊一半、后端无则不扣
        $domainTotalCost = isset($cfg['domain_total_cost']) && $cfg['domain_total_cost'] !== '' && $cfg['domain_total_cost'] !== null ? (float)$cfg['domain_total_cost'] : 0;
        $costRole = isset($cfg['cost_role']) && trim((string)$cfg['cost_role']) !== '' ? trim((string)$cfg['cost_role']) : 'frontend';
        $domainCostTotal = 0;
        if ($domainTotalCost > 0) {
            foreach (($c['orders'] ?? []) as $o) {
                if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // 域名已发生成本：退款订单同样计入（销售金额在其对应循环已排除）
                $orderAmt = (float)($o['order_amount'] ?? 0);
                if ($minAmount !== null && $orderAmt < $minAmount) continue;
                if ($maxAmount !== null && $orderAmt > $maxAmount) continue;
                if ($hasColumnFilter && !$matchColumnFilter($rd)) continue;

                // 后端是否标注（后端为空/"无"/"none" 视为无后端）
                $backendIsNone = true;
                foreach ($rd as $k => $v) {
                    if (mb_strpos((string)$k, '后端') !== false) {
                        $bv = trim((string)$v);
                        $backendIsNone = ($bv === '无' || $bv === '' || $bv === 'none');
                        break;
                    }
                }
                // 是否使用域名（域名年限按 "N年" / "是*N年" 识别，成本 × 年限）
                $domainYears = 0;
                foreach ($rd as $k => $v) {
                    if (mb_strpos((string)$k, '域名') !== false) {
                        $y = domain_years($v);
                        if ($y > 0) { $domainYears = $y; break; }
                    }
                }
                if ($domainYears <= 0) continue;
                if ($costRole === 'frontend') {
                    $domainCostTotal += $backendIsNone ? ($domainTotalCost * $domainYears) : (($domainTotalCost / 2) * $domainYears);
                } else { // backend
                    $domainCostTotal += $backendIsNone ? 0 : (($domainTotalCost / 2) * $domainYears);
                }
            }
        }

        // SSL证书成本分摊（与 calcTiered 一致）：订单SSL证书使用有金额/是 → 前端后端各担一半
        $sslCostPer = isset($cfg['ssl_cost_per']) && $cfg['ssl_cost_per'] !== '' && $cfg['ssl_cost_per'] !== null ? (float)$cfg['ssl_cost_per'] : 0;
        $sslFromRaw = !empty($cfg['ssl_from_rawdata']);
        $sslCostTotal = 0;
        if ($sslCostPer > 0 || $sslFromRaw) {
            foreach (($c['orders'] ?? []) as $o) {
                if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // SSL 已发生成本：退款订单同样计入
                $orderAmt = (float)($o['order_amount'] ?? 0);
                if ($minAmount !== null && $orderAmt < $minAmount) continue;
                if ($maxAmount !== null && $orderAmt > $maxAmount) continue;
                if ($hasColumnFilter && !$matchColumnFilter($rd)) continue;

                $backendIsNone = true;
                foreach ($rd as $k => $v) {
                    if (mb_strpos((string)$k, '后端') !== false) {
                        $bv = trim((string)$v);
                        $backendIsNone = ($bv === '无' || $bv === '' || $bv === 'none');
                        break;
                    }
                }
                // SSL证书成本分摊（与 calcTiered 一致）：订单SSL按新规则计费后前端后端各担一半
                // 规则：值含"通配符"→250元；有明确数字按数字；模糊标记(是/一年等)→30元
                foreach ($rd as $k => $v) {
                    if (mb_strpos((string)$k, 'SSL') !== false || mb_strpos((string)$k, 'ssl') !== false) {
                        $sslAmt = parse_ssl_amount($v);
                        if ($sslAmt !== null && $sslAmt > 0) {
                            if ($costRole === 'frontend') {
                                $sslCostTotal += $backendIsNone ? $sslAmt : ($sslAmt / 2);
                            } else {
                                $sslCostTotal += $backendIsNone ? 0 : ($sslAmt / 2);
                            }
                            break;
                        }
                    }
                }
            }
        }

        $amt = ($netTotal - $domainCostTotal - $sslCostTotal) * $rate;

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

        $label = $moduleName ? "{$moduleName}({$count}笔)" : "({$count}笔)";
        $costDetail = [];
        if ($domainCostTotal > 0) $costDetail[] = sprintf('域名成本%.2f', $domainCostTotal);
        if ($sslCostTotal > 0) $costDetail[] = sprintf('SSL成本%.2f', $sslCostTotal);
        $costSummary = !empty($costDetail) ? '  - ' . implode(' - ', $costDetail) : '';
        // 公式显示手续费扣除
        if ($priceSource === 'selling_price' && $serviceFeeRate > 0) {
            $formula = sprintf('%s售价¥%.2f-成本¥%.2f-手续费%.2f(售价%.1f%%)=¥%.2f%s，×%.2f%%=%.2f', $rangeLabel, $totalPrice, $totalCost, $serviceFee, $serviceFeeRate
    *100, $netTotal, $costSummary, $rate*100, $amt);
        } elseif ($serviceFeeRate > 0) {
            $formula = sprintf('%s%.2f-手续费%.2f(%.1f%%)=%.2f%s，×%.2f%%=%.2f', $rangeLabel, $total, $serviceFee, $serviceFeeRate*100, $netTotal, $costSummary, $rate*100, $amt
    );
        } else {
            $formula = sprintf('%s%.2f%s，×%.2f%%=%.2f', $rangeLabel, $total, $costSummary, $rate*100, $amt);
        }
        return [
            'amount' => round($amt, 2),
            'formula' => $formula,
            'type' => 'standard',
        ];
    }

}
