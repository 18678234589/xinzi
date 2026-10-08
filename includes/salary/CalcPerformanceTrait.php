<?php
trait CalcPerformanceTrait
{
    private static function calcFixedSubsidy($cfg, $c, $moduleName = '')
    {
        $amount = (float)($cfg['amount'] ?? 0);
        return [
            'amount' => round($amount, 2),
            'formula' => sprintf('固定补助 ¥%.2f', $amount),
            'type' => 'fixed_subsidy',
        ];
    }

    // ---- 客服绩效固定服务费 ----
    // 绩效指标：回复速度（越低越好）、进线人数、成交转化率。
    // 达成率 = 各指标实际/目标 × 权重 → 综合达成率 → 绩效应发 = 基数 × 综合达成率。
    // 无绩效数据按 0 计（可直接不在算法里启用该模块）。
    private static function calcCsPerformance($cfg, $c, $moduleName = '')
    {
        $year = 0; $month = 0;
        $parts = explode('-', (string)($c['month'] ?? ''));
        if (count($parts) === 2) { $year = (int)$parts[0]; $month = (int)$parts[1]; }
        if ($year <= 0 || $month < 1 || $month > 12) {
            return ['amount' => 0, 'formula' => '月份无效', 'type' => 'cs_performance'];
        }

        ensureCsPerfSchema();
        // 绩效参与改按「部门配置」自动生成；此处模块仅作兜底。被排除的合作人员一律不计。
        if (is_cs_perf_excluded((int)$c['employee']['id'])) {
            return ['amount' => 0, 'formula' => '已被排除出绩效名单', 'type' => 'cs_performance'];
        }
        // 统一走共享算法（绩效页与项目结算共用）：优先「部门基数+绩效方案」，未配置时回退本模块旧参数
        $r = cs_perf_calc((int)$c['employee']['id'], $year, $month, $cfg);
        return ['amount' => $r['amount'], 'formula' => $r['formula'], 'type' => 'cs_performance'];
    }

    /**
     * 部门绩效自动计入：合作人员所在部门已配置绩效（基数>0 且 有效方案）且未被排除时，
     * 自动生成「客服绩效固定服务费」条目（无需在个人算法页手动添加该模块）。
     * 未满足条件返回 null。
     * @param array $c 结算上下文（含 employee / month）
     * @return array|null
     */
    private static function autoDeptPerf($c)
    {
        ensureCsPerfSchema();
        $empId = (int)($c['employee']['id'] ?? 0);
        $dept  = (string)($c['employee']['department'] ?? '');
        if ($empId <= 0 || $dept === '') return null;
        $deptCfg = get_cs_perf_dept_config($dept);
        $isRankDept = ($dept === CS_PERF_RANK_DEPT);
        // 排名部门（设计客服）：无需配置基数，按「多店绩效平均→前三名」定固定服务费；其余部门需 基数>0 且 有效方案
        if (!$isRankDept && (!$deptCfg || (float)$deptCfg['base'] <= 0 || (int)$deptCfg['scheme_id'] <= 0)) return null;
        if (is_cs_perf_excluded($empId)) return null; // 被排除者不自动计入
        $year = 0; $month = 0;
        $parts = explode('-', (string)($c['month'] ?? ''));
        if (count($parts) === 2) { $year = (int)$parts[0]; $month = (int)$parts[1]; }
        if ($year <= 0 || $month < 1 || $month > 12) return null;
        $r = cs_perf_calc($empId, $year, $month);
        return [
            'name'    => '客服绩效固定服务费',
            'amount'  => (float)($r['amount'] ?? 0.0),
            'formula' => (string)($r['formula'] ?? ''),
            'type'    => 'cs_performance',
            'base'    => (float)($r['base'] ?? 0.0),
        ];
    }

}
