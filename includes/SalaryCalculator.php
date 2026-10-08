<?php
require_once __DIR__ . '/salary/CalcBaseTrait.php';
require_once __DIR__ . '/salary/CalcStandardTrait.php';
require_once __DIR__ . '/salary/CalcProfitTrait.php';
require_once __DIR__ . '/salary/CalcTrademarkTrait.php';
require_once __DIR__ . '/salary/CalcTieredTrait.php';
require_once __DIR__ . '/salary/CalcPerOrderTrait.php';
require_once __DIR__ . '/salary/CalcReferralTrait.php';
require_once __DIR__ . '/salary/CalcOrderFilterTrait.php';
require_once __DIR__ . '/salary/CalcAttendanceRewardTrait.php';
require_once __DIR__ . '/salary/CalcOrderCustomerTrait.php';
require_once __DIR__ . '/salary/CalcMiniProgramTrait.php';
require_once __DIR__ . '/salary/CalcPerformanceTrait.php';
require_once __DIR__ . '/salary/ModulesConfigTrait.php';
require_once __DIR__ . '/salary/ModulesTypesTrait.php';

/**
 * 多模块组合式项目报酬算法加载器
 *
 * 设计理念：
 * - 固定服务费固定（从合作人员表读取），不参与算法配置
 * - 项目报酬 = 固定服务费 + Σ(各独立模块的计算结果)
 * - 每个合作人员可配置多个模块，每个模块有独立的算法类型和参数
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

    // ==================== 核心：计算项目报酬 ====================
    
    /**
     * 计算项目报酬（多模块组合）
     * 
     * @return array
     *   base_salary   -> 固定服务费(固定)
     *   modules       -> [['name','amount','formula','type'], ...]  各模块结果
     *   module_total  -> 所有模块合计
     *   net_pay       -> 固定服务费 + 模块合计
     *   formula_text  -> 完整公式说明
     *   is_custom     -> 是否自定义了算法
     */
    public static function calculate($employee, $orders, $orderTotal, $month)
    {
        // 固定服务费只来自固定服务费模块：有固定服务费模块抓取固定服务费；没有固定服务费模块视为无固定服务费（外包合作人员）。
        // 不再回退到 employees.base_salary 字段。
        $baseSalary   = 0.0;
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
                
                // 已禁用自动退款扣除
                // $refundDeduction = self::calcRefundDeduction($context);
                // if ($refundDeduction !== null && $refundDeduction['amount'] != 0) {
                //     $results[] = $refundDeduction;
                // }
                
                // 再计算各个项目分成模块（排除退款订单）
                // 先找出 base_salary 模块（自定义固定服务费，覆盖合作人员表固定服务费）
                $customBase = null;
                $customBaseIdx = -1;
                foreach ($raw['modules'] as $mi => $mod) {
                    if (!($mod['enabled'] ?? true)) continue;
                    if ($mod['type'] === 'base_salary') {
                        $r = self::runModule($mod['type'], $mod['config'], $context, $mod['name']);
                        if ($r !== null) {
                            $customBase = $r;
                            $customBaseIdx = $mi;
                            $baseSalary = (float)$r['amount']; // 自定义固定服务费覆盖合作人员表固定服务费
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
                // 部门绩效自动计入：部门已配置且未排除 → 无需在个人算法里重复加「客服绩效固定服务费」模块
                $autoPerf = self::autoDeptPerf($context);
                if ($autoPerf !== null) {
                    $hasPerfMod = false;
                    foreach ($raw['modules'] as $mod) {
                        if (($mod['type'] ?? '') === 'cs_performance' && ($mod['enabled'] ?? true)) { $hasPerfMod = true; break; }
                    }
                    if (!$hasPerfMod) $results[] = $autoPerf; // 有个人绩效模块时该模块已按部门算法计算，避免重复
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
                    'algorithm_name'=> count($results) > 0 ? '多模块组合' : '仅固定服务费',
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
        $modules = [[
            'name' => '默认项目分成',
            'amount' => round($commission, 2),
            'formula' => sprintf('%.2f × %.2f%%', $orderTotal, (float)$employee['commission_rate']*100),
            'type' => 'standard',
        ]];
        $moduleTotal = round($commission, 2);
        $formulaText = sprintf('%.2f(固定服务费)+%.2f(默认项目分成)=%.2f', $baseSalary, $commission, $netPay);
        // 部门绩效自动计入（无自定义模块的合作人员同样生效）
        $autoPerf = self::autoDeptPerf($context);
        if ($autoPerf !== null) {
            $modules[] = $autoPerf;
            $moduleTotal = round($commission + $autoPerf['amount'], 2);
            $netPay = round($baseSalary + $moduleTotal, 2);
            $formulaText = sprintf('%.2f(固定服务费)+%.2f(默认项目分成)+%.2f(%s)=%.2f', $baseSalary, $commission, $autoPerf['amount'], $autoPerf['name'], $netPay);
        }
        return [
            'base_salary'   => $baseSalary,
            'modules'       => $modules,
            'module_total'  => $moduleTotal,
            'net_pay'       => $netPay,
            'formula_text'  => $formulaText,
            'algorithm_name'=> '默认算法' . ($autoPerf !== null ? '+(部门绩效)' : ''),
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
            case 'trademark_commission': return self::calcTrademarkCommission($config, $ctx, $moduleName);
            case 'trademark_cashback': return self::calcTrademarkCashback($config, $ctx, $moduleName);
            case 'referral_order':    return self::calcReferralOrder($config, $ctx, $moduleName);
            case 'miniprogram_commission': return self::calcMiniProgramCommission($config, $ctx, $moduleName);
            case 'attendance_full':   return self::calcAttendanceFull($config, $ctx);
            case 'attendance_daily':  return self::calcAttendanceDaily($config, $ctx);
            case 'attendance_deduct': return self::calcAttendanceDeduct($config, $ctx);
            case 'customer_reward':   return self::calcCustomerReward($config, $ctx, $moduleName);
            case 'fixed_subsidy':     return self::calcFixedSubsidy($config, $ctx, $moduleName);
            case 'cs_performance':    return self::calcCsPerformance($config, $ctx, $moduleName);
            default: return null;
        }
    }

    // ---- 固定服务费（自定义，覆盖合作人员表固定服务费）----
/* split: includes/salary/CalcBaseTrait.php */ use CalcBaseTrait;

    // ---- 标准比例 ----
/* split: includes/salary/CalcStandardTrait.php */ use CalcStandardTrait;


    // ---- 成本比例项目分成 ----
/* split: includes/salary/CalcProfitTrait.php */ use CalcProfitTrait;

    // ---- 商标部项目分成 ----
/* split: includes/salary/CalcTrademarkTrait.php */ use CalcTrademarkTrait;

    // ---- 阶梯项目分成 ----
/* split: includes/salary/CalcTieredTrait.php */ use CalcTieredTrait;

    // ---- 每笔固定 ----
/* split: includes/salary/CalcPerOrderTrait.php */ use CalcPerOrderTrait;

    // ---- 引流订单 ----
/* split: includes/salary/CalcReferralTrait.php */ use CalcReferralTrait;

    // 辅助：按模块名筛选订单总额
/* split: includes/salary/CalcOrderFilterTrait.php */ use CalcOrderFilterTrait;

    // ---- 考勤-全勤奖 ----
/* split: includes/salary/CalcAttendanceRewardTrait.php */ use CalcAttendanceRewardTrait;
    
/* split: includes/salary/CalcOrderCustomerTrait.php */ use CalcOrderCustomerTrait;

    // ---- 小程序项目分成（利润项目分成 + 新老客户补助）----
/* split: includes/salary/CalcMiniProgramTrait.php */ use CalcMiniProgramTrait;

    // ---- 固定补助 ----
/* split: includes/salary/CalcPerformanceTrait.php */ use CalcPerformanceTrait;

    // ==================== 配置 CRUD ====================

    /**
     * 保存多模块配置（JSON格式）
     */
/* split: includes/salary/ModulesConfigTrait.php */ use ModulesConfigTrait;

    // ==================== 可用的模块类型定义（供前端渲染） ====================

/* split: includes/salary/ModulesTypesTrait.php */ use ModulesTypesTrait;

    // ==================== 向后兼容 ====================

    public static function createDefaultAlgorithm()
    {
        $template = <<<'PHP'
<?php
/**
 * 项目报酬计算 - 默认算法
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
        'formula_text' => sprintf('应结算金额=固定服务费(%.2f)+(%.2f×%.2f%%)=%.2f', $baseSalary, $orderTotal, $commissionRate*100, $netPay),
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
