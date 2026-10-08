<?php
trait CalcBaseTrait
{
    private static function calcBaseSalary($cfg, $c, $moduleName = '')
    {
        $amount = (float)($cfg['base_amount'] ?? 0);
        $tableBase = (float)($c['base_salary'] ?? 0);
        $note = abs($amount - $tableBase) > 0.001 ? sprintf('（覆盖合作人员表固定服务费 %.2f）', $tableBase) : '';
        return [
            'amount' => round($amount, 2),
            'formula' => sprintf('固定服务费 %.2f%s', $amount, $note),
            'type' => 'base_salary',
        ];
    }

    // ---- 退款订单独立扣除 ----
    private static function calcRefundDeduction($c)
    {
        $debugLog = "=== calcRefundDeduction DEBUG START ===\n";

        // 读取合作人员算法配置，建立 模块名→rate 映射（standard 类型）
        $configFile = self::getConfigFile($c['employee']['id']);
        $moduleRates = [];   // 模块名 => rate
        $tieredModule = null; // 阶梯项目分成模块（回退用）
        $subsidy = 0;

        $debugLog .= "合作人员ID: {$c['employee']['id']}\n";
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

        // 阶梯项目分成：用总额匹配阶梯得到统一 rate + subsidy（回退方案）
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

        file_put_contents((dirname(__DIR__, 1)) . '/../debug_refund.txt', $debugLog);
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

    // ---- 固定服务费（阶梯）----
    private static function calcBaseSalaryTiered($cfg, $c, $moduleName = '')
    {
        $tiers = $cfg['tiers'] ?? [];
        
        // 计算所有订单的总额
        $totalForTier = 0;
        foreach (($c['orders'] ?? []) as $o) {
            $totalForTier += (float)($o['order_amount'] ?? 0);
        }
        
        // 按阶梯匹配固定服务费金额
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
            'formula' => sprintf('订单总额¥%.2f → 固定服务费¥%.2f', $totalForTier, $baseAmount),
            'type' => 'base_salary_tiered',
        ];
    }

}
