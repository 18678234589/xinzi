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
                foreach (array_filter($results, fn($r) => ($r['type'] ?? '') !== 'base_salary') as $r) {
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
        $debugLog = "=== calcRefundDeduction DEBUG START ===\n";

        // 读取员工算法配置，建立 模块名→rate 映射（standard 类型）
        $configFile = self::getConfigFile($c['employee']['id']);
        $moduleRates = [];   // 模块名 => rate
        $tieredModule = null; // 阶梯提成模块（回退用）
        $subsidy = 0;

        $debugLog .= "员工ID: {$c['employee']['id']}\n";
        $debugLog .= "配置文件: $configFile\n";

        if (file_exists($configFile)) {
            $raw = json_decode(file_get_contents($configFile), true);
            if ($raw && !empty($raw['modules'])) {
                $debugLog .= "配置文件已加载, 模块数量: " . count($raw['modules']) . "\n\n";
                foreach ($raw['modules'] as $mod) {
                    if (!($mod['enabled'] ?? true)) continue;
                    if ($mod['type'] === 'standard' && isset($mod['config']['rate'])) {
                        $moduleRates[$mod['name']] = (float)$mod['config']['rate'];
                    }
                    if ($mod['type'] === 'tiered' && !empty($mod['config']['tiers']) && $tieredModule === null) {
                        $tieredModule = $mod;
                    }
                }
            }
        } else {
            $debugLog .= "配置文件不存在!\n";
        }

        $debugLog .= "模块率映射: " . json_encode($moduleRates, JSON_UNESCAPED_UNICODE) . "\n";
        $debugLog .= "是否有阶梯模块: " . ($tieredModule ? '是' : '否') . "\n\n";

        // 按模块名分组收集退款订单，每组单独用对应模块的 rate 计算
        $refundByModule = []; // module名 => ['total'=>金额, 'count'=>笔数]
        $refundCount = 0;
        $refundTotal = 0;

        foreach (($c['orders'] ?? []) as $o) {
            $orderAmt = (float)($o['order_amount'] ?? 0);
            if ($orderAmt >= 0) continue; // 只处理负数金额

            $rawData = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
            $isRefund = isset($rawData['__is_refund__']) && $rawData['__is_refund__'] === '1';

            if ($isRefund) {
                $proj = trim($o['project'] ?? '');
                if ($proj === '') $proj = '(空)';
                if (!isset($refundByModule[$proj])) {
                    $refundByModule[$proj] = ['total' => 0, 'count' => 0];
                }
                $refundByModule[$proj]['total'] += $orderAmt;
                $refundByModule[$proj]['count']++;
                $refundTotal += $orderAmt;
                $refundCount++;
            }
        }

        $debugLog .= "退款订单统计: 共{$refundCount}笔, 总金额{$refundTotal}\n";
        $debugLog .= "按模块分组: " . json_encode($refundByModule, JSON_UNESCAPED_UNICODE) . "\n\n";

        if ($refundCount === 0) {
            return null; // 没有退款订单
        }

        // 阶梯提成：用总额匹配阶梯得到统一 rate + subsidy（回退方案）
        $tieredRate = null;
        if ($tieredModule) {
            $totalForTier = (float)$c['order_total'];
            $tiers = $tieredModule['config']['tiers'];
            usort($tiers, function($a, $b) {
                return ((float)($b['threshold'] ?? 0)) - ((float)($a['threshold'] ?? 0));
            });
            foreach ($tiers as $tier) {
                $threshold = (float)($tier['threshold'] ?? 0);
                if ($totalForTier >= $threshold) {
                    $tieredRate = (float)($tier['rate'] ?? 0.05);
                    $subsidy = (float)($tier['subsidy'] ?? 0);
                    break;
                }
            }
            $debugLog .= "阶梯匹配: rate=$tieredRate, subsidy=$subsidy\n\n";
        }

        // 逐模块计算退款扣除，按 project 匹配对应模块的 rate
        $totalDeduction = 0;
        $formulaParts = [];

        foreach ($refundByModule as $proj => $info) {
            // 优先用模块名精确匹配的 rate
            if (isset($moduleRates[$proj])) {
                $rate = $moduleRates[$proj];
            } elseif ($tieredRate !== null) {
                $rate = $tieredRate;
            } else {
                // 回退：取第一个 standard 模块的 rate
                $rate = count($moduleRates) > 0 ? reset($moduleRates) : 0.05;
            }

            $deduction = $info['total'] * $rate;
            $totalDeduction += $deduction;
            $debugLog .= "模块[{$proj}]: {$info['count']}笔, 金额={$info['total']}, rate={$rate}(" . ($rate*100) . "%), 扣除={$deduction}\n";
            $formulaParts[] = sprintf('%s:%d笔¥%.2f×%.2f%%=%.2f', $proj, $info['count'], $info['total'], $rate*100, $deduction);
        }

        // 补贴扣除（退款时补贴也要扣回）
        $subsidyDeduction = $refundCount * $subsidy;
        $totalDeduction -= $subsidyDeduction;

        $debugLog .= "\n补贴扣除 = {$refundCount}笔 × {$subsidy} = {$subsidyDeduction}\n";
        $debugLog .= "总扣除 = {$totalDeduction}\n";
        $debugLog .= "=== calcRefundDeduction DEBUG END ===\n";

        file_put_contents(__DIR__ . '/../debug_refund.txt', $debugLog);
        error_log($debugLog);

        // 公式展示：单模块简洁，多模块分项列出
        if (count($formulaParts) === 1) {
            $formula = sprintf('退款%d笔，¥%.2f×%.2f%%=%.2f', $refundCount, $refundTotal,
                (isset($moduleRates[array_key_first($refundByModule)]) ? $moduleRates[array_key_first($refundByModule)] : ($tieredRate ?? 0.05)) * 100,
                $totalDeduction + $subsidyDeduction);
        } else {
            $formula = '退款' . $refundCount . '笔：' . implode('；', $formulaParts);
        }
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
                    $cost = (float)preg_replace('/[^\d.\-]/', '', trim($v));
                }
            }

            $totalPrice  += $orderAmt;
            $totalCost   += $cost;
            $count++;
        }

        $amt = (($totalPrice - $totalCost) - $totalPrice * $serviceFeeRate) * $commissionRate;

        return [
            'amount' => round($amt, 2),
            'formula' => sprintf('((订单金额¥%.2f - 成本¥%.2f) - 订单金额¥%.2f×%.2f%%) ×%.2f%% = %.2f', $totalPrice, $totalCost, $totalPrice, $serviceFeeRate*100, $commissionRate*100, $amt),
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
        // 按指定列计数（如"域名"列去重计数）
        $countColumn = isset($cfg['count_column']) && trim($cfg['count_column']) !== '' ? trim($cfg['count_column']) : '';
        if ($countColumn !== '') {
            $distinct = ($cfg['count_distinct'] ?? '是') !== '否';
            $seen = [];       // 按 order_no 去重（避免多模块上传导致重复行）
            $values = [];
            foreach (($c['orders'] ?? []) as $o) {
                $ono = trim($o['order_no'] ?? '');
                if ($ono !== '' && isset($seen[$ono])) continue;
                if ($ono !== '') $seen[$ono] = true;
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // 排除退款订单（金额<0或标记为退款），退款不计算单量补贴
                // 注意：纯数量表金额=0是正常的，不应排除
                $isRefund = isset($rd['__is_refund__']) && $rd['__is_refund__'] === '1';
                if ($isRefund || (float)($o['order_amount'] ?? 0) < 0) continue;
                // 模糊匹配列名：优先精确匹配，找不到则用包含匹配
                $val = '';
                if (isset($rd[$countColumn])) {
                    $val = trim($rd[$countColumn]);
                } else {
                    foreach ($rd as $k => $v) {
                        if (mb_strpos($k, $countColumn) !== false) { $val = trim($v); break; }
                    }
                }
                if ($val !== '') $values[] = $val;
            }
            $cnt = $distinct ? count(array_unique($values)) : count($values);
            $amt1 = $cnt * (float)($cfg['per_amount'] ?? 50);
            $amt2 = $cnt * (float)($cfg['per_reward'] ?? 0);
            $colLabel = $distinct ? "{$countColumn}去重" : "{$countColumn}非空";
            return [
                'amount' => round($amt1 + $amt2, 2),
                'formula' => sprintf('%s%d个×¥%g+¥%g=%.2f', $colLabel, $cnt, $cfg['per_amount']??50, $cfg['per_reward']??0, $amt1+$amt2),
                'type' => 'per_order',
            ];
        }

        // 原有逻辑：按 project 名/金额范围/店铺关键字筛选计数
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
        // count_mode: 'keyword'(默认，按列+关键词计数) / 'staff_match'(接单客服匹配员工姓名+旺旺日期去重)
        $countMode = $cfg['count_mode'] ?? 'keyword';

        if ($countMode === 'staff_match') {
            // 接单客服出现员工姓名 → 该表订单归属此员工 → 计算单量（旺旺+日期去重）
            // 可选：配置 count_column + count_keyword 时，先按该列关键词筛选，再去重计数
            $employeeName = trim($c['employee']['name'] ?? '');
            $subsidy = (float)($cfg['subsidy'] ?? 0);

            // 拍建站列关键词筛选（可选）
            $filterColumn = isset($cfg['count_column']) && trim($cfg['count_column']) !== '' ? trim($cfg['count_column']) : '';
            $filterKeywords = $filterColumn !== '' ? array_filter(array_map('trim', explode('+', $cfg['count_keyword'] ?? ''))) : [];
            $filterMatch = $cfg['count_keyword_match'] ?? 'all'; // all=AND / any=OR

            // 辅助：模糊匹配列名取值
            $getCol = function($rd, $colName) {
                if (isset($rd[$colName])) return trim($rd[$colName]);
                foreach ($rd as $k => $v) {
                    if (mb_strpos($k, $colName) !== false) return trim($v);
                }
                return '';
            };

            // 第一步：扫描所有订单，判断接单客服列是否出现过员工姓名
            $ownsTable = false;
            foreach (($c['orders'] ?? []) as $o) {
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                $kefu = $getCol($rd, '接单客服');
                if ($kefu === '') continue;
                $names = array_map('trim', explode(',', $kefu));
                if (in_array($employeeName, $names, true)) { $ownsTable = true; break; }
            }

            if (!$ownsTable) {
                return [
                    'amount' => 0,
                    'formula' => sprintf('0.00（接单客服无匹配%s的订单）', $employeeName),
                    'type' => 'referral_order',
                ];
            }

            // 第二步：接单客服匹配到员工姓名，该表订单归属此员工，计算单量
            // - 配置了 count_column（如"拍建站链接"按单补助）：按 order_no 去重，每条匹配订单算1单
            //   （不要求付费旺旺/日期非空，这类数量表常不填旺旺日期，否则会少算）
            // - 未配置 count_column（如"单量补贴"，统计独立客户单量）：按 付费旺旺+日期 去重
            $cnt = 0;
            $seen = []; // key 视去重方式而定
            $dedupByOrderNo = ($filterColumn !== '');
            foreach (($c['orders'] ?? []) as $o) {
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // 排除退款订单
                $isRefund = isset($rd['__is_refund__']) && $rd['__is_refund__'] === '1';
                if ($isRefund || (float)($o['order_amount'] ?? 0) < 0) continue;
                // 按 order_no 去重（避免多模块上传导致重复行）
                if ($dedupByOrderNo) {
                    $ono = trim($o['order_no'] ?? '');
                    if ($ono !== '' && isset($seen[$ono])) continue;
                }
                // 可选：按拍建站列关键词筛选
                if ($filterColumn !== '') {
                    $val = $getCol($rd, $filterColumn);
                    if ($val === '') continue;
                    if (!empty($filterKeywords)) {
                        if ($filterMatch === 'any') {
                            $match = false;
                            foreach ($filterKeywords as $kw) {
                                if (mb_strpos($val, $kw) !== false) { $match = true; break; }
                            }
                        } else {
                            $match = true;
                            foreach ($filterKeywords as $kw) {
                                if (mb_strpos($val, $kw) === false) { $match = false; break; }
                            }
                        }
                        if (!$match) continue;
                    }
                }
                if ($dedupByOrderNo) {
                    // 按单补助：每条匹配订单算1单，按 order_no 去重
                    $ono = trim($o['order_no'] ?? '');
                    if ($ono !== '') $seen[$ono] = true;
                    $cnt++;
                } else {
                    // 单量补贴：读取"付费旺旺"和"日期"列，同旺旺同日期只算1单
                    $wangwang = $getCol($rd, '付费旺旺');
                    $dateVal  = $getCol($rd, '日期');
                    // 旺旺或日期为空的不计入单量
                    if ($wangwang === '' || $dateVal === '') continue;
                    $key = $wangwang . '|' . $dateVal;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $cnt++;
                }
            }
            $subsidyAmt = $cnt * $subsidy;
            // 公式描述
            if ($filterColumn !== '' && !empty($filterKeywords)) {
                $kwLabel = ($filterMatch === 'any' ? "含任一'" : "含全部'") . implode('+', $filterKeywords) . "'";
                $formula = sprintf('接单客服匹配%s %s%s %d单×¥%g(每单补助)=%.2f', $employeeName, $filterColumn, $kwLabel, $cnt, $subsidy, $subsidyAmt);
            } else {
                $formula = sprintf('接单客服匹配%s %d单×¥%g(每单补助)=%.2f', $employeeName, $cnt, $subsidy, $subsidyAmt);
            }
            if ($cnt === 0) {
                $formula = $filterColumn !== ''
                    ? sprintf('0.00（接单客服匹配%s但%s列无命中订单）', $employeeName, $filterColumn)
                    : sprintf('0.00（接单客服匹配%s但无有效订单）', $employeeName);
            }
            return [
                'amount' => round($subsidyAmt, 2),
                'formula' => $formula,
                'type' => 'referral_order',
            ];
        }

        // 按指定列+关键词计数（如"建站订单"列值同时包含"拍"+"链接"）
        $countColumn = isset($cfg['count_column']) && trim($cfg['count_column']) !== '' ? trim($cfg['count_column']) : '';
        if ($countColumn !== '') {
            $keywords = array_filter(array_map('trim', explode('+', $cfg['count_keyword'] ?? '')));
            $kwMatch  = $cfg['count_keyword_match'] ?? 'all'; // all=同时包含(AND) / any=任一包含(OR)
            $seen = [];   // 按 order_no 去重
            $cnt = 0;
            foreach (($c['orders'] ?? []) as $o) {
                $ono = trim($o['order_no'] ?? '');
                if ($ono !== '' && isset($seen[$ono])) continue;
                if ($ono !== '') $seen[$ono] = true;
                $rd = is_string($o['raw_data'] ?? '') ? json_decode($o['raw_data'], true) : ($o['raw_data'] ?? []);
                if (!is_array($rd)) $rd = [];
                // 排除退款订单（金额<0或标记为退款），退款不计算拍链接补贴
                // 注意：纯数量表金额=0是正常的，不应排除
                $isRefund = isset($rd['__is_refund__']) && $rd['__is_refund__'] === '1';
                if ($isRefund || (float)($o['order_amount'] ?? 0) < 0) continue;
                // 模糊匹配列名：优先精确匹配，找不到则用包含匹配
                $val = '';
                if (isset($rd[$countColumn])) {
                    $val = trim($rd[$countColumn]);
                } else {
                    foreach ($rd as $k => $v) {
                        if (mb_strpos($k, $countColumn) !== false) { $val = trim($v); break; }
                    }
                }
                if ($val === '') continue;
                // 关键词匹配：all=同时包含所有，any=包含任一即可
                if (empty($keywords)) {
                    $match = true; // 无关键词则只需列值非空
                } elseif ($kwMatch === 'any') {
                    $match = false;
                    foreach ($keywords as $kw) {
                        if (mb_strpos($val, $kw) !== false) { $match = true; break; }
                    }
                } else {
                    $match = true;
                    foreach ($keywords as $kw) {
                        if (mb_strpos($val, $kw) === false) { $match = false; break; }
                    }
                }
                if ($match) $cnt++;
            }
            $subsidy = (float)($cfg['subsidy'] ?? 0);
            $subsidyAmt = $cnt * $subsidy;
            if (!empty($keywords)) {
                $kwLabel = ($kwMatch === 'any' ? "含任一'" : "含全部'") . implode('+', $keywords) . "'";
            } else {
                $kwLabel = '非空';
            }
            $formula = sprintf('%s%s%d单×¥%g(每单补助)=%.2f', $countColumn, $kwLabel, $cnt, $subsidy, $subsidyAmt);
            if ($cnt === 0) {
                $formula = sprintf('0.00（%s列无匹配%s的订单）', $countColumn, $kwLabel);
            }
            return [
                'amount' => round($subsidyAmt, 2),
                'formula' => $formula,
                'type' => 'referral_order',
            ];
        }

        // 原有逻辑：按 project 名筛选订单（不再支持金额范围/店铺关键字过滤）
        $total = self::filterOrderTotal($c, $moduleName);
        $count = self::filterOrderCount($c, $moduleName);

        $subsidy = (float)($cfg['subsidy'] ?? 0);
        // 引流订单工资 = 每单补助金额 × 订单数量（订单金额仅用于筛选/展示）
        $subsidyAmt = $count * $subsidy;
        $amt = $subsidyAmt;

        $formula = sprintf('%d单×¥%g(每单补助)=%.2f', $count, $subsidy, $subsidyAmt);
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
    public static function saveModulesConfig($employeeId, $modules, $deptShare = 1)
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

        // 对比新旧配置，模块名变更时自动同步订单 project
        $oldConfig = self::readModulesConfig($employeeId);
        if ($oldConfig && !empty($oldConfig['modules'])) {
            $oldNames = [];
            foreach ($oldConfig['modules'] as $m) {
                $oldNames[$m['name']] = $m;
            }
            foreach ($modules as $newMod) {
                $newName = $newMod['name'] ?? '';
                if ($newName === '') continue;
                // 新模块名不在旧配置里，检查是否有旧模块名出现在订单 project 中但新名没有
                // 这种情况下无法自动判断对应关系，跳过
            }
            // 按模块位置顺序匹配旧→新（假设用户不会增删模块只改名）
            $oldMods = $oldConfig['modules'];
            $renameMap = []; // 旧名 => 新名
            $count = min(count($oldMods), count($modules));
            for ($i = 0; $i < $count; $i++) {
                $oldName = $oldMods[$i]['name'] ?? '';
                $newName = $modules[$i]['name'] ?? '';
                if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
                    $renameMap[$oldName] = $newName;
                }
            }
            // 同步数据库订单 project
            if (!empty($renameMap)) {
                try {
                    foreach ($renameMap as $oldName => $newName) {
                        $stmt = db()->prepare("UPDATE orders SET project = ? WHERE employee_id = ? AND project = ?");
                        $stmt->execute([$newName, $employeeId, $oldName]);
                    }
                    error_log("SalaryCalculator: synced project names for employee $employeeId: " . json_encode($renameMap, JSON_UNESCAPED_UNICODE));
                } catch (Exception $e) {
                    error_log("SalaryCalculator: failed to sync project names: " . $e->getMessage());
                }
            }
        }

        $data = ['modules' => $modules, 'dept_share' => $deptShare, 'updated_at' => date('Y-m-d H:i:s')];
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

        if ($deptShare) {
            // 参与部门订单提成：部门汇总行已在 orders 表中，结算时通过 __dept_modules__ 虚拟拆分，
            // 新员工配置后自动生效，无需物理同步拆分行
        } else {
            // 不参与部门订单提成：清理旧的物理拆分行（历史数据，新数据不再产生拆分行）
            try {
                $del = db()->prepare("UPDATE orders SET is_deleted=1 WHERE employee_id = ? AND raw_data LIKE '%__from_dept__%'");
                $del->execute([$employeeId]);
                $deleted = $del->rowCount();
                if ($deleted > 0) {
                    error_log("SalaryCalculator: removed {$deleted} legacy dept split orders for employee {$employeeId} (dept_share=0)");
                }
            } catch (Exception $e) {
                error_log("SalaryCalculator: failed to remove dept orders: " . $e->getMessage());
            }
        }

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
                    ['key'=>'count_column','label'=>'计数列名','type'=>'text','placeholder'=>'填列名如"域名"，按该列去重计数；留空则按订单笔数','default'=>''],
                    ['key'=>'count_distinct','label'=>'是否去重','type'=>'select','options'=>['是'=>'是（按列值去重计数）','否'=>'否（按列值非空计数）'],'default'=>'是'],
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
                'label' => '标书提成',
                'icon' => 'fa-coins',
                'color' => 'info',
                'desc' => '基于订单金额和成本计算：((订单金额 - 成本) - 订单金额×服务费比例) × 提成比例',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：成本提成A','default'=>''],
                    ['key'=>'commission_rate','label'=>'提成比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.1=10%','default'=>''],
                    ['key'=>'service_fee_rate','label'=>'服务费比例(小数)','type'=>'number','step'=>'any','placeholder'=>'0.05=5%','default'=>''],
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
                'desc' => '设置每单补助金额，按指定列内容筛选符合条件的订单数量',
                'fields' => [
                    ['key'=>'_name','label'=>'模块名称（必填）','type'=>'text','placeholder'=>'如：引流、小红书引流','default'=>''],
                    ['key'=>'subsidy','label'=>'每个订单补助金额','type'=>'number','step'=>'0.01','min'=>'0','placeholder'=>'如 5 元/单','default'=>'5'],
                    ['key'=>'count_column','label'=>'计数列名','type'=>'text','placeholder'=>'填列名如"建站订单"，按该列内容筛选计数','default'=>''],
                    ['key'=>'count_keyword','label'=>'关键词（+分隔）','type'=>'text','placeholder'=>'如"拍+链接"，按下方匹配方式筛选该列值','default'=>''],
                    ['key'=>'count_keyword_match','label'=>'关键词匹配方式','type'=>'select','options'=>['all'=>'同时包含所有词(且)','any'=>'包含任一词(或)'],'default'=>'all'],
                    ['key'=>'count_mode','label'=>'计数模式','type'=>'select','options'=>['keyword'=>'关键词匹配(按列+关键词计数)','staff_match'=>'接单客服匹配(按员工姓名匹配+旺旺日期去重)'],'default'=>'keyword'],
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
