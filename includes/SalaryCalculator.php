<?php
/**
 * 多模块组合式薪资算法加载器
 *
 * 设计理念：
 * - 底薪固定（从员工表读取），不参与算法配置
 * - 薪资 = 底薪 + Σ(各独立模块的计算结果)
 * - 每个员工可配置多个模块，每个模块有独立的算法类型和参数
 * - 支持的模块类型：standard / tiered / per_order / attendance_full / attendance_daily / attendance_deduct
 * - 配置以 JSON 格式保存在 algorithms/config_{employee_id}.json
 */

class SalaryCalculator
{
    private static $dir;
    private static $lastError = '';

    public static function getLastError()
    {
        return self::$lastError;
    }

    private static function dir()
    {
        if (self::$dir === null) {
            self::$dir = dirname(__DIR__) . '/algorithms';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0755, true);
            }
        }
        return self::$dir;
    }

    // ==================== 文件路径 ====================

    public static function getConfigFile($employeeId)
    {
        return self::dir() . '/config_' . (int)$employeeId . '.json';
    }

    public static function hasCustomConfig($employeeId)
    {
        return file_exists(self::getConfigFile($employeeId));
    }

    public static function getEmployeeAlgorithmFile($employeeId)
    {
        return self::getLegacyFile($employeeId);
    }

    private static function getLegacyFile($employeeId)
    {
        return self::dir() . '/employee_' . (int)$employeeId . '.php';
    }

    public static function hasCustomAlgorithm($employeeId)
    {
        return file_exists(self::getLegacyFile($employeeId));
    }

    /**
     * 判断是否有任何自定义配置（新版JSON或旧版PHP）
     */
    public static function hasAnyCustomConfig($employeeId)
    {
        return self::hasCustomConfig($employeeId) || self::hasCustomAlgorithm($employeeId);
    }

    // ==================== 核心：计算薪资 ====================
    
    /**
     * 计算薪资（多模块组合）
     * 
     * @return array
     *   base_salary   -> 底薪(固定)
     *   modules       -> [['name','amount','formula','type'], ...]  各模块结果
     *   module_total  -> 所有模块合计
     *   net_pay       -> 底薪 + 模块合计
     *   formula_text  -> 完整公式说明
     *   is_custom     -> 是否自定义了算法
     */
    public static function calculate($employee, $orders, $orderTotal, $month)
    {
        $baseSalary   = (float)$employee['base_salary'];
        $context = [
            'employee'     => $employee,
            'orders'       => $orders,
            'order_total'  => (float)$orderTotal,
            'order_count'  => count($orders),
            'month'        => $month,
            'base_salary'  => $baseSalary,
        ];

        // 读取当月考勤数据（供全勤奖等模块使用）
        $attAbsentHours = 0;
        $attWorkHours   = 0;
        $monthParts = explode('-', (string)$month);
        if (count($monthParts) === 2) {
            $att = get_attendance((int)$employee['id'], (int)$monthParts[0], (int)$monthParts[1]);
            if ($att) {
                $attAbsentHours = (float)$att['absent_hours'];
                $attWorkHours   = (float)$att['work_hours'];
            }
        }
        $context['absent_hours'] = $attAbsentHours;
        $context['work_hours']   = $attWorkHours;

        $configFile = self::getConfigFile($employee['id']);
        
        if (file_exists($configFile)) {
            // 新版：读取 JSON 多模块配置
            $raw = json_decode(file_get_contents($configFile), true);
            if ($raw && !empty($raw['modules'])) {
                $results = [];
                
                // DEBUG: 输出配置文件内容
                error_log("calculate: employee_id={$employee['id']}, order_total=$orderTotal, configFile=$configFile");
                
                file_put_contents(__DIR__ . '/../debug_config.txt', json_encode($raw['modules'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                // 先计算退款订单的独立扣除
                $refundDeduction = self::calcRefundDeduction($context);
                if ($refundDeduction !== null && $refundDeduction['amount'] != 0) {
                    $results[] = $refundDeduction;
                }
                
                // 再计算各个提成模块（排除退款订单）
                // 先找出 base_salary 模块（自定义底薪，覆盖员工表底薪）
                $customBase = null;
                $customBaseIdx = -1;
                foreach ($raw['modules'] as $mi => $mod) {
                    if (!($mod['enabled'] ?? true)) continue;
                    if ($mod['type'] === 'base_salary') {
                        $r = self::runModule($mod['type'], $mod['config'], $context, $mod['name']);
                        if ($r !== null) {
                            $customBase = $r;
                            $customBaseIdx = $mi;
                            $baseSalary = (float)$r['amount']; // 自定义底薪覆盖员工表底薪
                        }
                        break;
                    }
                }

                foreach ($raw['modules'] as $mi => $mod) {
                    if (!($mod['enabled'] ?? true)) continue;
                    if ($mi === $customBaseIdx) continue; // base_salary 已单独处理，不重复加入模块合计
                    $result = self::runModule($mod['type'], $mod['config'], $context, $mod['name']);
                    if ($result !== null) {
                        $results[] = array_merge($result, ['name' => $mod['name']]);
                    }
                }
                // 将 base_salary 模块加入明细列表（显示但不重复计入 module_total）
                if ($customBase !== null) {
                    array_unshift($results, array_merge($customBase, ['name' => $raw['modules'][$customBaseIdx]['name']]));
                }
                // module_total 不含 base_salary（base_salary 单独计入 net_pay）
                $moduleTotal = array_sum(array_column(array_filter($results, fn($r) => ($r['type'] ?? '') !== 'base_salary'), 'amount'));
                $formulaParts = [$baseSalary];
                foreach ($results as $r) {
                    $formulaParts[] = "+{$r['amount']}({$r['name']})";
                }

                return [
                    'base_salary'   => $baseSalary,
                    'modules'       => $results,
                    'module_total'  => round($moduleTotal, 2),
                    'net_pay'       => round($baseSalary + $moduleTotal, 2),
                    'formula_text'  => implode(' ', $formulaParts),
                    'algorithm_name'=> count($results) > 0 ? '多模块组合' : '仅底薪',
                    'is_custom'     => true,
                ];
            }
        }

        // 兼容旧版：PHP 单文件算法
        $legacyFile = self::getLegacyFile($employee['id']);
        if (file_exists($legacyFile)) {
            try {
                $closure = include $legacyFile;
                if (is_callable($closure)) {
                    $result = call_user_func($closure, $context);
                    if (is_array($result)) {
                        return array_merge($result, ['base_salary'=>$baseSalary,'is_custom'=>true]);
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 默认算法
        $commission = $orderTotal * (float)$employee['commission_rate'];
        $netPay = $baseSalary + $commission;
        return [
            'base_salary'   => $baseSalary,
            'modules'       => [[
                'name' => '默认提成',
                'amount' => round($commission, 2),
                'formula' => sprintf('%.2f × %.2f%%', $orderTotal, (float)$employee['commission_rate']*100),
                'type' => 'standard',
            ]],
            'module_total'  => round($commission, 2),
            'net_pay'       => round($netPay, 2),
            'formula_text'  => sprintf('%.2f(底薪)+%.2f(默认提成)=%.2f', $baseSalary, $commission, $netPay),
            'algorithm_name'=> '默认算法',
            'is_custom'     => false,
        ];
    }

    // ==================== 单模块执行引擎 ====================
    
    private static function runModule($type, $config, $ctx, $moduleName = '')
    {
        switch ($type) {
            case 'base_salary':
                return self::calcBaseSalary($config, $ctx, $moduleName);
            case 'base_salary_tiered':
                return self::calcBaseSalaryTiered($config, $ctx, $moduleName);
            case 'standard':   return self::calcStandard($config, $ctx, $moduleName);
            case 'tiered':     return self::calcTiered($config, $ctx, $moduleName);
            case 'per_order':  return self::calcPerOrder($config, $ctx, $moduleName);
            case 'profit_commission': return self::calcProfitCommission($config, $ctx, $moduleName);
            case 'referral_order':    return self::calcReferralOrder($config, $ctx, $moduleName);
            case 'attendance_full':   return self::calcAttendanceFull($config, $ctx);
            case 'attendance_daily':  return self::calcAttendanceDaily($config, $ctx);
            case 'attendance_deduct': return self::calcAttendanceDeduct($config, $ctx);
            default: return null;
        }
    }

    // ---- 底薪（自定义，覆盖员工表底薪）----
    private static function calcBaseSalary($cfg, $c, $moduleName = '')
    {
        $amount = (float)($cfg['base_amount'] ?? 0);
        $tableBase = (float)($c['base_salary'] ?? 0);
        $note = abs($amount - $tableBase) > 0.001 ? sprintf('（覆盖员工表底薪 %.2f）', $tableBase) : '';
        return [
            'amount' => round($amount, 2),
            'formula' => sprintf('底薪 %.2f%s', $amount, $note),
            'type' => 'base_salary',
        ];
    }

    // ---- 退款订单独立扣除 ----
    private static function calcRefundDeduction($c)
    {
        $refundTotal = 0;
        $refundCount = 0;
        
        $debugLog = "=== calcRefundDeduction DEBUG START ===\n";
        
        // 查找所有退款订单
        foreach (($c['orders'] ?? []) as $o) {
            $orderAmt = (float)($o['order_amount'] ?? 0);
            if ($orderAmt >= 0) continue; // 只处理负数金额
            
            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';
            
            if ($isRefund) {
                $refundTotal += $orderAmt; // 累加退款金额（负数）
                $refundCount++;
            }
        }
        
        $debugLog .= "退款订单统计: 共{$refundCount}笔, 总金额{$refundTotal}\n";
        
        if ($refundCount === 0) {
            return null; // 没有退款订单
        }
        
        // 根据扣款后总额（包含退款）匹配阶梯提成比例
        $configFile = self::getConfigFile($c['employee']['id']);
        $rate = 0.05; // 默认比例
        $subsidy = 0; // 每笔补贴
        $foundTiered = false; // 标记是否找到阶梯模块
        
        $debugLog .= "员工ID: {$c['employee']['id']}\n";
        $debugLog .= "订单总额(扣款后): {$c['order_total']}\n";
        $debugLog .= "配置文件: $configFile\n";
        
        if (file_exists($configFile)) {
            $raw = json_decode(file_get_contents($configFile), true);
            $debugLog .= "配置文件已加载, 模块数量: " . count($raw['modules'] ?? []) . "\n\n";
            
            if ($raw && !empty($raw['modules'])) {
                // 查找第一个启用的阶梯提成模块
                foreach ($raw['modules'] as $idx => $mod) {
                    $debugLog .= "检查模块[$idx]: 名称={$mod['name']}, 类型={$mod['type']}, 启用=" . (($mod['enabled'] ?? true) ? '是' : '否') . "\n";
                    
                    if (!($mod['enabled'] ?? true)) continue;
                    
                    if ($mod['type'] === 'tiered' && !empty($mod['config']['tiers'])) {
                        // 使用扣款后总额（包含退款）匹配阶梯
                        $totalForTier = (float)$c['order_total'];
                        
                        // 按阶梯从高到低匹配
                        $tiers = $mod['config']['tiers'];
                        usort($tiers, function($a, $b) {
                            return ((float)($b['threshold'] ?? 0)) - ((float)($a['threshold'] ?? 0));
                        });
                        
                        $debugLog .= "  → 找到阶梯模块: {$mod['name']}\n";
                        $debugLog .= "  → 匹配总额: $totalForTier\n";
                        $debugLog .= "  → 阶梯配置: " . json_encode($tiers, JSON_UNESCAPED_UNICODE) . "\n";
                        
                        foreach ($tiers as $tier) {
                            $threshold = (float)($tier['threshold'] ?? 0);
                            $debugLog .= "  → 测试阶梯: threshold=$threshold, rate={$tier['rate']}, subsidy={$tier['subsidy']}\n";
                            if ($totalForTier >= $threshold) {
                                $rate = (float)($tier['rate'] ?? 0.05);
                                $subsidy = (float)($tier['subsidy'] ?? 0);
                                $foundTiered = true; // 标记已找到
                                $debugLog .= "  ✓ 匹配成功! rate=$rate, subsidy=$subsidy\n";
                                break;
                            }
                        }
                        break; // 找到阶梯提成模块后停止
                    }
                }
                
                // 如果没有阶梯提成，查找标准提成
                if (!$foundTiered) {
                    $debugLog .= "未找到阶梯模块，尝试标准提成模块\n";
                    foreach ($raw['modules'] as $mod) {
                        if (!($mod['enabled'] ?? true)) continue;
                        if ($mod['type'] === 'standard' && isset($mod['config']['rate'])) {
                            $rate = (float)$mod['config']['rate'];
                            $debugLog .= "使用标准提成: rate=$rate\n";
                            break;
                        }
                    }
                }
            }
        } else {
            $debugLog .= "配置文件不存在!\n";
        }
        
        // 计算扣除：比例扣除 - 补贴扣除（退款时补贴也要扣回）
        $commissionDeduction = $refundTotal * $rate;
        $subsidyDeduction = $refundCount * $subsidy;
        $totalDeduction = $commissionDeduction - $subsidyDeduction; // 补贴要减去
        
        $debugLog .= "\n最终匹配结果:\n";
        $debugLog .= "  比例(rate): $rate (" . ($rate*100) . "%)\n";
        $debugLog .= "  补贴(subsidy): $subsidy\n";
        $debugLog .= "\n计算过程:\n";
        $debugLog .= "  提成扣除 = $refundTotal × $rate = $commissionDeduction\n";
        $debugLog .= "  补贴扣除 = $refundCount × $subsidy = $subsidyDeduction\n";
        $debugLog .= "  总扣除 = $commissionDeduction - $subsidyDeduction = $totalDeduction\n";
        $debugLog .= "=== calcRefundDeduction DEBUG END ===\n";
        
        // 将调试日志写入文件
        file_put_contents(__DIR__ . '/../debug_refund.txt', $debugLog);
        error_log($debugLog);
        
        $formula = sprintf('退款%d笔，¥%.2f×%.2f%%=%.2f', $refundCount, $refundTotal, $rate*100, $commissionDeduction);
        if ($subsidy > 0) {
            $formula .= sprintf(' - %d笔×¥%.2f=%.2f', $refundCount, $subsidy, $subsidyDeduction);
        }
        
        return [
            'amount' => round($totalDeduction, 2), // 负数
            'formula' => $formula,
            'type' => 'refund_deduction',
            'name' => '退款扣除'
        ];
    }

    // ---- 底薪（阶梯）----
    private static function calcBaseSalaryTiered($cfg, $c, $moduleName = '')
    {
        $tiers = $cfg['tiers'] ?? [];
        
        // 计算所有订单的总额
        $totalForTier = 0;
        foreach (($c['orders'] ?? []) as $o) {
            $totalForTier += (float)($o['order_amount'] ?? 0);
        }
        
        // 按阶梯匹配底薪金额
        rsort($tiers, SORT_DESC);
        $baseAmount = 0;
        foreach ($tiers as $t) {
            if ($totalForTier >= (float)($t['threshold'])) {
                $baseAmount = (float)($t['base_amount'] ?? 0);
                break;
            }
        }
        
        return [
            'amount' => round($baseAmount, 2),
            'formula' => sprintf('订单总额¥%.2f → 底薪¥%.2f', $totalForTier, $baseAmount),
            'type' => 'base_salary_tiered',
        ];
    }

    // ---- 标准比例 ----
    private static function calcStandard($cfg, $c, $moduleName = '')
    {
        $rate = (float)($cfg['rate'] ?? 0.05);
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;
        
        // 如果配置了金额范围或店铺关键字，则忽略模块名筛选
        $useFilter = ($minAmount !== null || $maxAmount !== null || $shopKeyword !== null);
        $filterByName = $useFilter ? '' : $moduleName;
        
        $total = self::filterOrderTotal($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);
        $count = self::filterOrderCount($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);
        
        $amt = $total * $rate;
        
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
        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('%s%.2f×%.2f%%=%.2f', $rangeLabel, $total, $rate*100, $amt),
            'type' => 'standard',
        ];
    }


    // ---- 成本比例提成 ----
    private static function calcProfitCommission($cfg, $c, $moduleName = '')
    {
        $commissionRate = (float)($cfg['commission_rate'] ?? 0);
        $serviceFeeRate = (float)($cfg['service_fee_rate'] ?? 0);
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;

        // 配置了金额范围或店铺关键字则忽略模块名筛选，与 calcStandard 一致
        $useFilter = ($minAmount !== null || $maxAmount !== null || $shopKeyword !== null);
        $filterByName = $useFilter ? '' : $moduleName;

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
            if ($filterByName !== '' && trim($o['project'] ?? '') !== $filterByName) continue;

            // 金额范围过滤（基于 order_amount）
            $orderAmt = (float)($o['order_amount'] ?? 0);
            if ($minAmount !== null && $orderAmt < $minAmount) continue;
            if ($maxAmount !== null && $orderAmt > $maxAmount) continue;

            // 店铺关键字过滤
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

            // 从 raw_data 模糊匹配列名提取售价/成本/利润
            $price = 0; $cost = 0; $profit = null; $hasProfitCol = false;
            foreach ($rawData as $k => $v) {
                if (mb_strpos($k, '利润') !== false) {
                    $profit = (float)preg_replace('/[^\d.\-]/', '', trim($v));
                    $hasProfitCol = true;
                } elseif (mb_strpos($k, '售价') !== false || mb_strpos($k, '价格') !== false) {
                    $price = (float)preg_replace('/[^\d.\-]/', '', trim($v));
                } elseif (mb_strpos($k, '成本') !== false) {
                    $cost = (float)preg_replace('/[^\d.\-]/', '', trim($v));
                }
            }
            // 无利润列时回退售价-成本
            if (!$hasProfitCol) {
                $profit = $price - $cost;
            }

            $totalProfit += $profit;
            $totalPrice  += $orderAmt;
            $totalCost   += $cost;
            $count++;
        }

        $amt = (($totalPrice - $totalCost) - $totalPrice * $serviceFeeRate) * $commissionRate;

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
            $rangeLabel .= "[店铺:{$shopKeyword}]";
        }

        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('%s((订单金额¥%.2f - 成本¥%.2f) - 订单金额¥%.2f×%.2f%%) ×%.2f%% = %.2f', $rangeLabel, $totalPrice, $totalCost, $totalPrice, $serviceFeeRate*100, $commissionRate*100, $amt),
            'type' => 'profit_commission',
        ];
    }

    // ---- 阶梯提成 ----
    private static function calcTiered($cfg, $c, $moduleName = '')
    {
        $tiers = $cfg['tiers'] ?? [];
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;
        
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
        error_log("calcTiered [{$moduleName}]: min=$minAmount, max=$maxAmount, total=$total, totalForTier=$totalForTier, count=$count, orders=" . json_encode(array_slice($debugOrders, 0, 5)));
        
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
        $commissionAmt = $total * $rate;
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
        if ($commissionAmt > 0) {
            $formulaParts[] = sprintf('%s%.2f×%.2f%%=%.2f', $rangeLabel, $total, $rate*100, $commissionAmt);
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

    // ---- 每笔固定 ----
    private static function calcPerOrder($cfg, $c, $moduleName = '')
    {
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;
        
        $useFilter = ($minAmount !== null || $maxAmount !== null || $shopKeyword !== null);
        $filterByName = $useFilter ? '' : $moduleName;
        
        $cnt  = self::filterOrderCount($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);
        $amt1 = $cnt * (float)($cfg['per_amount'] ?? 50);
        $amt2 = $cnt * (float)($cfg['per_reward'] ?? 0);
        return [
            'amount' => round($amt1 + $amt2, 2),
            'formula' => sprintf('%d×%g+%d×%g=%.2f', $cnt, $cfg['per_amount']??50, $cnt, $cfg['per_reward']??0, $amt1+$amt2),
            'type' => 'per_order',
        ];
    }

    // ---- 引流订单 ----
    private static function calcReferralOrder($cfg, $c, $moduleName = '')
    {
        $minAmount = isset($cfg['min_amount']) && $cfg['min_amount'] !== '' && $cfg['min_amount'] !== null ? (float)$cfg['min_amount'] : null;
        $maxAmount = isset($cfg['max_amount']) && $cfg['max_amount'] !== '' && $cfg['max_amount'] !== null ? (float)$cfg['max_amount'] : null;
        $shopKeyword = isset($cfg['shop_keyword']) && $cfg['shop_keyword'] !== '' && $cfg['shop_keyword'] !== null ? $cfg['shop_keyword'] : null;

        $useFilter = ($minAmount !== null || $maxAmount !== null || $shopKeyword !== null);
        $filterByName = $useFilter ? '' : $moduleName;

        $total = self::filterOrderTotal($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);
        $count = self::filterOrderCount($c, $filterByName, $minAmount, $maxAmount, $shopKeyword);

        $subsidy = (float)($cfg['subsidy'] ?? 0);
        // 引流订单工资 = 每单补助金额 × 订单数量（订单金额仅用于筛选/展示）
        $subsidyAmt = $count * $subsidy;
        $amt = $subsidyAmt;

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

        $formula = sprintf('%s%d单×¥%g(每单补助)=%.2f', $rangeLabel, $count, $subsidy, $subsidyAmt);
        if ($count === 0) {
            $formula = '0.00（无匹配订单）';
        }
        if ($formula === '') {
            $formula = '0.00';
        }
        return [
            'amount' => round($amt, 2),
            'formula' => $formula,
            'type' => 'referral_order',
        ];
    }

    // 辅助：按模块名筛选订单总额
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

    // ---- 考勤-全勤奖 ----
    private static function calcAttendanceFull($cfg, $c)
    {
        $fullAmount = (float)($cfg['full_amount'] ?? 200);
        $deductMode = $cfg['deduct_mode'] ?? 'step';      // step(默认阶梯) / none / prorate / fixed
        $workHours  = (float)($cfg['work_hours'] ?? $c['work_hours'] ?? 0); // 当月应出勤总小时数
        $absentHours = (float)($cfg['absent_hours'] ?? $c['absent_hours'] ?? 0); // 当月请假小时数
        $thresholdHours = isset($cfg['absent_threshold_hours']) && $cfg['absent_threshold_hours'] !== ''
            ? (float)$cfg['absent_threshold_hours'] : null; // 超过此值全勤奖归0

        // 无请假 或 扣除模式=none → 全额发放
        if ($absentHours <= 0 || $deductMode === 'none') {
            return [
                'amount' => round($fullAmount, 2),
                'formula' => sprintf('全勤奖 %g（满勤）', $fullAmount),
                'type' => 'attendance_full',
            ];
        }

        // 默认阶梯规则：请假≥8小时全扣，≥4小时扣一半，<4小时全发
        if ($deductMode === 'step') {
            if ($absentHours >= 8) {
                return [
                    'amount' => 0,
                    'formula' => sprintf('全勤奖 0（请假%.1f小时≥8h，全部扣除）', $absentHours),
                    'type' => 'attendance_full',
                ];
            }
            if ($absentHours >= 4) {
                $half = round($fullAmount / 2, 2);
                return [
                    'amount' => $half,
                    'formula' => sprintf('全勤奖 %g/2=%.2f（请假%.1f小时≥4h，扣除一半）', $fullAmount, $half, $absentHours),
                    'type' => 'attendance_full',
                ];
            }
            return [
                'amount' => round($fullAmount, 2),
                'formula' => sprintf('全勤奖 %g（请假%.1f小时<4h，不扣）', $fullAmount, $absentHours),
                'type' => 'attendance_full',
            ];
        }

        // 超过阈值 → 全勤奖归0
        if ($thresholdHours !== null && $absentHours >= $thresholdHours) {
            return [
                'amount' => 0,
                'formula' => sprintf('全勤奖 0（请假%.1f小时≥阈值%.1f小时，归零）', $absentHours, $thresholdHours),
                'type' => 'attendance_full',
            ];
        }

        // 按比例折算
        if ($deductMode === 'prorate') {
            if ($workHours <= 0) $workHours = 176; // 兜底默认22天×8小时
            $ratio = max(0, ($workHours - $absentHours) / $workHours);
            $amt = $fullAmount * $ratio;
            return [
                'amount' => round($amt, 2),
                'formula' => sprintf('全勤奖 %g×(%g-%g)/%g=%.2f', $fullAmount, $workHours, $absentHours, $workHours, $amt),
                'type' => 'attendance_full',
            ];
        }

        // 每小时扣固定金额（fixed）
        if ($deductMode === 'fixed') {
            if ($workHours <= 0) $workHours = 176;
            $perHour = $fullAmount / $workHours;
            $deduct = $absentHours * $perHour;
            $amt = max(0, $fullAmount - $deduct);
            return [
                'amount' => round($amt, 2),
                'formula' => sprintf('全勤奖 %g-%.1f×%.4f=%.2f', $fullAmount, $absentHours, $perHour, $amt),
                'type' => 'attendance_full',
            ];
        }

        // 未知模式 → 全额
        return [
            'amount' => round($fullAmount, 2),
            'formula' => sprintf('全勤奖 %g', $fullAmount),
            'type' => 'attendance_full',
        ];
    }

    // ---- 考勤-日薪制 ----
    private static function calcAttendanceDaily($cfg, $c)
    {
        // context 里没有出勤数据时用订单数估算（或可后续扩展传入实际出勤天数）
        $days = (int)($cfg['work_days'] ?? $c['order_count']);  // 默认用订单数替代
        $dailyRate = (float)($cfg['daily_rate'] ?? 100);
        $amt = $days * $dailyRate;
        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('%d天×%g=%.2f', $days, $dailyRate, $amt),
            'type' => 'attendance_daily',
        ];
    }

    // ---- 考勤-扣款制 ----
    private static function calcAttendanceDeduct($cfg, $c)
    {
        $absentDays = (int)($cfg['absent_days'] ?? 0);  // 缺勤天数（需后续扩展为实际录入）
        $deductPerDay = (float)($cfg['deduct_per_day'] ?? 100);
        $deduct = $absentDays * $deductPerDay;
        // 扣款制：金额为负数
        return [
            'amount' => -round($deduct, 2),  // 注意是负数！
            'formula' => sprintf('-%d天×%g=-%g', $absentDays, $deductPerDay, $deduct),
            'type' => 'attendance_deduct',
        ];
    }

    // ==================== 配置 CRUD ====================

    /**
     * 保存多模块配置（JSON格式）
     */
    public static function saveModulesConfig($employeeId, $modules)
    {
        $dir = self::dir();
        self::$lastError = '';
        // 确保目录可写
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            self::$lastError = "算法目录不可写或不存在：{$dir}";
            error_log("SalaryCalculator: algorithms directory not writable: " . $dir);
            return false;
        }
        $data = ['modules' => $modules, 'updated_at' => date('Y-m-d H:i:s')];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            self::$lastError = "配置编码失败（数据格式异常）";
            error_log("SalaryCalculator: json_encode failed for employee $employeeId");
            return false;
        }
        $file = self::getConfigFile($employeeId);
        // 先写临时文件，再 rename 覆盖目标文件。
        // 目录可写时，rename 的“删除旧文件”是目录级操作，
        // 不依赖旧文件自身权限，可绕过“旧文件所有者与 PHP 进程用户不同导致无法覆盖”的问题。
        $tmp = $file . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
        $result = file_put_contents($tmp, $json);
        if ($result === false) {
            self::$lastError = "写入临时文件失败：{$tmp}";
            error_log("SalaryCalculator: failed to write temp config to $tmp");
            return false;
        }
        // 跨平台 rename 覆盖
        if (!@rename($tmp, $file)) {
            // rename 失败（如跨设备），退回到复制+删除临时文件
            if (!@copy($tmp, $file)) {
                @unlink($tmp);
                self::$lastError = "写入配置文件失败：{$file}";
                error_log("SalaryCalculator: failed to write config to $file");
                return false;
            }
            @unlink($tmp);
        }
        // 尽量放宽权限，便于后续覆盖写入
        @chmod($file, 0664);
        return true;
    }

    /**
     * 读取多模块配置
     */
    public static function readModulesConfig($employeeId)
    {
        $file = self::getConfigFile($employeeId);
        if (!file_exists($file)) {
            return null; // 无配置 = 使用默认算法
        }
        $data = json_decode(file_get_contents($file), true);
        if (!$data || empty($data['modules'])) {
            return null;
        }
        return $data;
    }

    /**
     * 删除自定义配置
     */
    public static function deleteCustomConfig($employeeId)
    {
        $ok = true;
        $cf = self::getConfigFile($employeeId);
        if (file_exists($cf)) $ok = @unlink($cf) && $ok;
        $lf = self::getLegacyFile($employeeId);
        if (file_exists($lf)) $ok = @unlink($lf) && $ok;
        return $ok;
    }

    // ==================== 可用的模块类型定义（供前端渲染） ====================

    public static function getAvailableTypes()
    {
        return [
            'standard' => [
                'label' => '标准比例提成',
                'icon' => 'fa-percentage',
                'color' => 'primary',
                'desc' => '按订单总额的固定比例计算',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：续费、新单、线下渠道、淘宝店A','default'=>''],
                    ['key'=>'rate','label'=>'提成比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.018=1.8%, 0.05=5%','default'=>''],
                    ['key'=>'min_amount','label'=>'最小订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 50','default'=>''],
                    ['key'=>'max_amount','label'=>'最大订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 10000','default'=>''],
                    ['key'=>'shop_keyword','label'=>'店铺关键字','type'=>'text','placeholder'=>'留空=不限制，如：老客户','default'=>''],
                ],
            ],
            'tiered' => [
                'label' => '阶梯提成',
                'icon' => 'fa-stairs',
                'color' => 'warning',
                'desc' => '按订单总额分档，越高档提成越多',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：续费阶梯、新单阶梯','default'=>''],
                    ['key'=>'min_amount','label'=>'最小订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 50','default'=>''],
                    ['key'=>'max_amount','label'=>'最大订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制','default'=>''],
                    ['key'=>'shop_keyword','label'=>'店铺关键字','type'=>'text','placeholder'=>'留空=不限制，如：老客户','default'=>''],
                ],
            ],
            'per_order' => [
                'label' => '每笔订单奖励',
                'icon' => 'fa-receipt',
                'color' => 'info',
                'desc' => '按订单笔数计算固定提成+可选奖励',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：续费单奖、新单奖励','default'=>''],
                    ['key'=>'per_amount','label'=>'每笔提成','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 80 元','default'=>'80'],
                    ['key'=>'per_reward','label'=>'每笔额外奖励','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'可选，如 20','default'=>'0'],
                ],
            ],
            'attendance_full' => [
                'label' => '全勤奖金',
                'icon' => 'fa-calendar-check',
                'color' => 'success',
                'desc' => '出满勤给予固定全勤奖金，请假按小时折算或扣除',
                'fields' => [
                    ['key'=>'full_amount','label'=>'全勤奖金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 200','default'=>'200'],
                    ['key'=>'deduct_mode','label'=>'请假扣除方式','type'=>'select','options'=>['none'=>'不扣除（请假照发）','prorate'=>'按小时比例折算','fixed'=>'每小时扣固定金额'],'default'=>'none'],
                    ['key'=>'work_hours','label'=>'当月应出勤总小时数','type'=>'number','step'=>'0.1','min'=>'0','placeholder'=>'如 22天×8小时=176','default'=>'176'],
                    ['key'=>'absent_hours','label'=>'当月请假小时数','type'=>'number','step'=>'0.1','min'=>'0','placeholder'=>'每月结算时填写，如 4=半天','default'=>'0'],
                    ['key'=>'absent_threshold_hours','label'=>'请假阈值(小时)','type'=>'number','step'=>'0.1','min'=>'0','placeholder'=>'请假超过此值全勤奖归0，留空=不限制','default'=>''],
                ],
            ],
            'attendance_daily' => [
                'label' => '考勤日薪',
                'icon' => 'fa-calendar-day',
                'color' => 'teal',
                'desc' => '按实际出勤天数乘以日薪计算',
                'fields' => [
                    ['key'=>'work_days','label'=>'本月出勤天数','type'=>'number','step'=>'1','min'=>'0','max'=>'31','placeholder'=>'实际出勤天数','default'=>'22'],
                    ['key'=>'daily_rate','label'=>'日薪金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'每日工资','default'=>'150'],
                ],
            ],
            'attendance_deduct' => [
                'label' => '缺勤扣款',
                'icon' => 'fa-calendar-times',
                'color' => 'danger',
                'desc' => '按缺勤天数扣减工资',
                'fields' => [
                    ['key'=>'absent_days','label'=>'缺勤天数','type'=>'number','step'=>'1','min'=>'0','placeholder'=>'缺勤天数','default'=>'0'],
                    ['key'=>'deduct_per_day','label'=>'每天扣款','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'每缺勤一天扣多少','default'=>'100'],
                ],
            ],
            'base_salary' => [
                'label' => '底薪（自定义）',
                'icon' => 'fa-coins',
                'color' => 'secondary',
                'desc' => '自定义该员工底薪金额，会覆盖员工表中录入的底薪；不配置则用员工表底薪',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：底薪','default'=>''],
                    ['key'=>'base_amount','label'=>'底薪金额','type'=>'number','step'=>'any','placeholder'=>'如 3300','default'=>3300],
                ],
            ],
            'profit_commission' => [
                'label' => '成本比例提成',
                'icon' => 'fa-coins',
                'color' => 'info',
                'desc' => '基于订单金额和成本计算：((订单金额 - 成本) - 订单金额×服务费比例) × 提成比例',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：成本提成A','default'=>''],
                    ['key'=>'commission_rate','label'=>'提成比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.1=10%','default'=>''],
                    ['key'=>'service_fee_rate','label'=>'服务费比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.05=5%','default'=>''],
                    ['key'=>'min_amount','label'=>'最小订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 50','default'=>''],
                    ['key'=>'max_amount','label'=>'最大订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制，如 10000','default'=>''],
                    ['key'=>'shop_keyword','label'=>'店铺关键字','type'=>'text','placeholder'=>'留空=不限制，如：老客户','default'=>''],
                ],
            ],
            'base_salary_tiered' => [
                'label' => '底薪（阶梯）',
                'icon' => 'fa-layer-group',
                'color' => 'secondary',
                'desc' => '按订单总额分档的底薪',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：阶梯底薪','default'=>''],
                ],
            ],
            'referral_order' => [
                'label' => '引流订单',
                'icon' => 'fa-bullhorn',
                'color' => 'purple',
                'desc' => '设置每单补助金额，工资 = 订单金额×订单数量 + 每单补助×数量',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：引流、小红书引流','default'=>''],
                    ['key'=>'subsidy','label'=>'每个订单补助金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 5 元/单','default'=>'5'],
                    ['key'=>'min_amount','label'=>'最小订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制','default'=>''],
                    ['key'=>'max_amount','label'=>'最大订单金额','type'=>'number','step'=>'0.01','placeholder'=>'留空=不限制','default'=>''],
                    ['key'=>'shop_keyword','label'=>'店铺关键字','type'=>'text','placeholder'=>'留空=不限制，如：老客户','default'=>''],
                ],
            ],
        ];
    }

    // ==================== 向后兼容 ====================

    public static function createDefaultAlgorithm()
    {
        $template = <<<'PHP'
<?php
/**
 * 薪资计算 - 默认算法
 */
return function(array $context): array {
    $baseSalary     = (float)$context['employee']['base_salary'];
    $commissionRate = (float)$context['employee']['commission_rate'];
    $orderTotal     = (float)$context['order_total'];

    $commission = $orderTotal * $commissionRate;
    $netPay = $baseSalary + $commission;

    return [
        'commission' => round($commission, 2),
        'net_pay'    => round($netPay, 2),
        'formula_text' => sprintf('实发=底薪(%.2f)+(%.2f×%.2f%%)=%.2f', $baseSalary, $orderTotal, $commissionRate*100, $netPay),
        'algorithm_name' => '默认算法',
    ];
};
PHP;
        file_put_contents(self::dir() . '/default.php', $template);
    }

    public static function readAlgorithm($employeeId) { /* 兼容 */ return null; }
    public static function createEmployeeAlgorithm($id,$n){/*兼容*/return false;}
    public static function saveEmployeeAlgorithm($id,$c){/*兼容*/return false;}
    public static function deleteEmployeeAlgorithm($id){return @unlink(self::getLegacyFile($id));}
}
