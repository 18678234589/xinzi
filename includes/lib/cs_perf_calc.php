<?php

/**
 * 四指标档位达成 → 每指标明细（新算法核心）。$perf 为某店铺或汇总后的绩效行。
 * 启用判定：权重>0 且 该指标已配置档位区间（tiers_* 非空）；实际值<=0 的指标未命中任何档（不参与加权）。
 * @param array $params cs_perf_scheme_params() 输出的参数
 * @param array $perf   绩效行（含 net_sales/inquiry_conv/wangwang_reply/reply_speed）
 * @return array ['ok'=>bool,'composite'=>float(0~1),'w_sum'=>float,'rate_sum'=>float,
 *                'metrics'=>[['key','label','weight','value','unit','tier'=>['from','to','rate'],'tiers_all'=>[] ,'rate'=>float,'contribution'=>float]]]
 */
function cs_perf_metric_details($params, $perf)
{
    $defs = [
        ['key' => 'net_sales',      'label' => '净销售额',   'weightKey' => 'w_net_sales',      'tiersKey' => 'tiers_net_sales',      'valueKey' => 'net_sales',      'unit' =>
    '元'],
        ['key' => 'inquiry_conv',   'label' => '询单转化率', 'weightKey' => 'w_inquiry_conv',   'tiersKey' => 'tiers_inquiry_conv',   'valueKey' => 'inquiry_conv',   'unit' =>
    '%'],
        ['key' => 'wangwang_reply', 'label' => '旺旺回复率', 'weightKey' => 'w_wangwang_reply', 'tiersKey' => 'tiers_wangwang_reply', 'valueKey' => 'wangwang_reply', 'unit' =>
    '%'],
        ['key' => 'avg_response',   'label' => '平均响应',   'weightKey' => 'w_avg_response',   'tiersKey' => 'tiers_avg_response',   'valueKey' => 'reply_speed',    'unit' =>
    '秒'],
    ];
    $metrics = [];
    $wSum = 0.0; $rateSum = 0.0;
    foreach ($defs as $d) {
        $w = (float)($params[$d['weightKey']] ?? 0);
        $tiers = is_array($params[$d['tiersKey']] ?? null) ? $params[$d['tiersKey']] : [];
        if ($w <= 0 || !$tiers) continue; // 未启用：权重0 或 未配置档位
        $value = (float)($perf[$d['valueKey']] ?? 0);
        $tier = cs_perf_tier_lookup($tiers, $value);
        if ($tier === null) continue; // 实际值<=0，未命中档
        $rate = (float)$tier['rate'] / 100;
        $wSum += $w;
        $rateSum += $w * $rate;
        $metrics[] = [
            'key' => $d['key'], 'label' => $d['label'], 'unit' => $d['unit'],
            'weight' => $w, 'value' => $value, 'tier' => $tier, 'tiers_all' => $tiers,
            'rate' => $rate, 'contribution' => $w * $rate,
        ];
    }
    if (!$metrics) return ['ok' => false, 'composite' => 0.0, 'w_sum' => 0.0, 'rate_sum' => 0.0, 'metrics' => []];
    return ['ok' => true, 'composite' => $rateSum / $wSum, 'w_sum' => $wSum, 'rate_sum' => $rateSum, 'metrics' => $metrics];
}

/**
 * 四指标档位达成 → 综合达成率（新算法）。$perf 为某店铺或汇总后的绩效行。
 * @param array $params   cs_perf_scheme_params() 输出的参数
 * @param array $perf     绩效行（含 net_sales/inquiry_conv/wangwang_reply/reply_speed）
 * @return array ['ok'=>bool,'composite'=>float(0~1),'parts'=>数组]
 */
function cs_perf_composite_from($params, $perf)
{
    $d = cs_perf_metric_details($params, $perf);
    $parts = [];
    foreach ($d['metrics'] as $m) {
        $parts[] = sprintf('档[%s]%.1f%%×%.1f', cs_perf_fmt_range($m['tier']), $m['rate'] * 100, $m['weight']);
    }
    if (!$d['ok']) return ['ok' => false, 'composite' => 0.0, 'parts' => []];
    return ['ok' => true, 'composite' => $d['composite'], 'parts' => $parts];
}

/**
 * 计算某员工某月客服绩效金额（薄包装）。绩效页展示与薪资结算共用此函数，保证结果一致。
 * 完整算法过程（每指标命中档位/权重/加权贡献、逐店得分、排名、保底封顶）见 cs_perf_calc_detail()，
 * 两者共用同一套计算路径，口径绝对一致。
 *
 * @param int        $employeeId 员工ID
 * @param int        $year 年
 * @param int        $month 月(1-12)
 * @param array|null $legacyCfg 旧版「客服绩效底薪」模块参数（仅当部门未配置时回退使用）
 * @return array ['amount'=>float, 'formula'=>string, 'base'=>float]（设计客服另含 rank=>int, score=>float）
 */
function cs_perf_calc($employeeId, $year, $month, $legacyCfg = null)
{
    $d = cs_perf_calc_detail($employeeId, $year, $month, $legacyCfg);
    $out = ['amount' => $d['amount'], 'formula' => $d['formula'], 'base' => $d['base']];
    if (array_key_exists('rank', $d))  $out['rank']  = $d['rank'];
    if (array_key_exists('score', $d)) $out['score'] = $d['score'];
    return $out;
}

/**
 * 某员工某月客服绩效的「完整算法过程」明细：数据来源 → 每指标命中档位→达成率→加权 → 综合达成率 → 保底/封顶 → 金额。
 * cs_perf_calc() 即本函数的摘要（同一计算路径），结果一致性由实现保证。
 *
 * mode 取值：
 * - scheme     部门已配置 基数+方案：金额 = 基数 × 综合达成率（可保底/封顶）
 * - legacy     部门未配置时的旧版四因素回退（回复秒/接待/转化率）
 * - rank       设计客服：各店铺分别按方案算综合→平均得分为排名分→部门内前三名定底薪（850/800/750）
 * - no_scheme  部门未配置且无回退参数
 * - base_zero  绩效基数为0
 * - no_data    当月无绩效数据
 * - no_metrics 方案未配置有效指标
 *
 * @return array 固定含 ['mode','dept','amount','formula','base']；另按 mode 附：
 *   scheme/legacy：'dept_config','scheme','perf','perf_rows','deal_info','metrics','w_sum','composite','floor_pct','cap_pct','raw_amount','floor_amount','cap_amount'
 *   rank：'dept_config','scheme','stores','score','rank','rank_tiers','ranking'
 */
function cs_perf_calc_detail($employeeId, $year, $month, $legacyCfg = null)
{
    $employeeId = (int)$employeeId;
    $year  = (int)$year;
    $month = (int)$month;

    // 员工部门
    $dept = '';
    try {
        $st = db()->prepare("SELECT department FROM employees WHERE id=?");
        $st->execute([$employeeId]);
        $dept = (string)$st->fetchColumn();
    } catch (\Throwable $e) {}

    // 设计客服专用：部门内按「多店绩效平均→前三名」定底薪（850/800/750，不再乘达成率）
    if ($dept === CS_PERF_RANK_DEPT) {
        return cs_perf_rank_detail($employeeId, $year, $month);
    }

    // 优先部门基数+方案
    $deptCfg = ($dept !== '') ? get_cs_perf_dept_config($dept) : null;
    $scheme  = null;
    if ($deptCfg && (float)$deptCfg['base'] > 0 && (int)$deptCfg['scheme_id'] > 0) {
        $scheme = get_cs_perf_scheme((int)$deptCfg['scheme_id']);
    }
    $base   = 0.0;
    $legacy = false;
    $params = null;
    if ($scheme) {
        $base   = (float)$deptCfg['base'];
        $params = cs_perf_scheme_params($scheme);
    }

    // 回退：旧版「客服绩效底薪」模块参数（无绩效方案时沿用旧四因素算法，避免旧配置失效）
    if ($params === null && is_array($legacyCfg)) {
        $base = (float)($legacyCfg['base'] ?? 0);
        $params = [
            'weight_reply' => (float)($legacyCfg['weight_reply'] ?? 1), 'target_reply_sec' => (float)($legacyCfg['target_reply_sec'] ?? 0),
            'weight_incoming' => (float)($legacyCfg['weight_incoming'] ?? 1), 'target_incoming' => (int)($legacyCfg['target_incoming'] ?? 0),
            'weight_conv' => (float)($legacyCfg['weight_conversion'] ?? 1), 'target_conversion_pct' => (float)($legacyCfg['target_conversion_pct'] ?? 0),
            'weight_amount' => 0, 'amount_tiers' => [],
            'floor_pct' => (float)($legacyCfg['floor_pct'] ?? 0), 'cap_pct' => (float)($legacyCfg['cap_pct'] ?? 0),
        ];
        $legacy = true;
    }

    $detail = [
        'mode' => $legacy ? 'legacy' : ($scheme ? 'scheme' : 'no_scheme'),
        'dept' => $dept,
        'dept_config' => $deptCfg ?: null,
        'amount' => 0.0, 'formula' => '', 'base' => $legacy ? $base : ($scheme ? $base : 0.0),
    ];
    if ($scheme)    $detail['scheme'] = $scheme;
    if ($legacy)    $detail['legacy_cfg'] = $legacyCfg;

    if ($params === null) {
        $detail['mode']       = 'no_scheme';
        $detail['formula']    = '部门未配置绩效基数/方案';
        $detail['base']       = 0.0;
        return $detail;
    }
    if ($base <= 0) {
        $detail['mode']       = 'base_zero';
        $detail['formula']    = '绩效基数为0（部门未设置基数）';
        $detail['base']       = 0.0;
        return $detail;
    }

    $perf = get_cs_performance($employeeId, $year, $month);
    if (!$perf) {
        $detail['mode']       = 'no_data';
        $detail['formula']    = '当月无绩效数据';
        return $detail;
    }
    $detail['perf']     = $perf;
    $detail['perf_rows'] = get_cs_performance_stores($employeeId, $year, $month); // 供展示「数据是怎么聚合出来的」
    $replySpeed = (float)$perf['reply_speed'];
    $incoming   = (int)$perf['incoming_count'];
    $dealCount  = $perf['deal_count'];
    if ($dealCount === null || $dealCount === '') {
        $dealCount = get_employee_deal_count($employeeId, $year, $month);
        $detail['deal_info'] = ['source' => 'auto', 'value' => (int)$dealCount];
    } else {
        $detail['deal_info'] = ['source' => 'row', 'value' => (int)$dealCount];
    }
    $orderTotal = get_employee_order_total($employeeId, $year, $month);
    $detail['order_total'] = $orderTotal;

    $parts = [];

    // ============ 每指标：实际值 → 命中档位 → 达成率 → 加权贡献 ============
    if ($legacy) {
        // 旧版「客服绩效底薪」模块（回复/接待/转化率，无金额阶梯），仅作部门未配置时的回退
        $dMetrics = [];
        $wSum = 0.0; $rateSum = 0.0;
        // 1. 回复速度（越低越快越好）
        if ($params['weight_reply'] > 0 && $params['target_reply_sec'] > 0 && $replySpeed > 0) {
            $rate = $params['target_reply_sec'] / $replySpeed;
            $wSum += $params['weight_reply']; $rateSum += $params['weight_reply'] * $rate;
            $dMetrics[] = ['key'=>'reply','label'=>'平均响应时长','unit'=>'秒','weight'=>$params['weight_reply'],'value'=>$replySpeed,'target'=>$params['target_reply_sec'],'rate'
    =>$rate,'how'=>'目标秒数 ÷ 实际秒数（越快越好）'];
            $parts[] = sprintf('回复%.1f%%×%.1f', $rate * 100, $params['weight_reply']);
        }
        // 2. 接待人数
        if ($params['weight_incoming'] > 0 && $params['target_incoming'] > 0) {
            $rate = $incoming / $params['target_incoming'];
            $wSum += $params['weight_incoming']; $rateSum += $params['weight_incoming'] * $rate;
            $dMetrics[] = ['key'=>'incoming','label'=>'接待人数(进线)','unit'=>'人','weight'=>$params['weight_incoming'],'value'=>$incoming,'target'=>$params['target_incoming'
    ],'rate'=>$rate,'how'=>'进线数 ÷ 目标人数'];
            $parts[] = sprintf('接待%.1f%%×%.1f', $rate * 100, $params['weight_incoming']);
        }
        // 3. 转化率
        if ($params['weight_conv'] > 0 && $params['target_conversion_pct'] > 0 && $incoming > 0) {
            $convPct = $dealCount / $incoming * 100;
            $rate    = $convPct / $params['target_conversion_pct'];
            $wSum += $params['weight_conv']; $rateSum += $params['weight_conv'] * $rate;
            $dMetrics[] = ['key'=>'conv','label'=>'成交转化率','unit'=>'%','weight'=>$params['weight_conv'],'value'=>round($convPct,2),'target'=>$params['target_conversion_pct'
    ],'rate'=>$rate,'how'=>'成交数÷进线数，再÷目标转化率'];
            $parts[] = sprintf('转化%.1f%%×%.1f', $rate * 100, $params['weight_conv']);
        }
        $detail['metrics'] = $dMetrics;
        $detail['w_sum']   = $wSum;
        $detail['composite'] = $wSum > 0 ? $rateSum / $wSum : null;
        if ($wSum <= 0) {
            $detail['mode']       = 'no_metrics';
            $detail['formula']    = '方案未配置有效指标（需设置权重与目标）';
            return $detail;
        }
        $composite = $rateSum / $wSum;
    } else {
        $compo = cs_perf_metric_details($params, $perf);
        $detail['metrics']   = $compo['metrics'];
        $detail['w_sum']     = $compo['w_sum'];
        $detail['rate_sum']  = $compo['rate_sum'];
        $detail['composite'] = $compo['ok'] ? $compo['composite'] : null;
        foreach ($compo['metrics'] as $m) {
            $parts[] = sprintf('档[%s]%.1f%%×%.1f', cs_perf_fmt_range($m['tier']), $m['rate'] * 100, $m['weight']);
        }
        if (!$compo['ok']) {
            $detail['mode']       = 'no_metrics';
            $detail['formula']    = '方案未配置有效指标（需设置权重与档位区间）';
            return $detail;
        }
        $composite = $compo['composite'];
    }

    // ============ 金额：基数 × 综合（保底/封顶） ============
    $amount = $base * $composite;
    $detail['raw_amount'] = round($base * $composite, 2);
    $clamp = '';
    if ($params['floor_pct'] > 0) { $amount = max($amount, $base * $params['floor_pct'] / 100); $clamp .= ' 保底' . $params['floor_pct'] . '%'; }
    if ($params['cap_pct'] > 0)   { $amount = min($amount, $base * $params['cap_pct'] / 100);   $clamp .= ' 封顶' . $params['cap_pct'] . '%'; }
    $detail['floor_pct']    = $params['floor_pct'];
    $detail['cap_pct']      = $params['cap_pct'];
    $detail['floor_amount'] = $params['floor_pct'] > 0 ? round($base * $params['floor_pct'] / 100, 2) : null;
    $detail['cap_amount']   = $params['cap_pct']   > 0 ? round($base * $params['cap_pct'] / 100, 2)   : null;
    $detail['clamp_text']   = $clamp;

    $detail['formula'] = (implode(' + ', $parts) ?: '-')
        . sprintf(' → 综合%.1f%% × 基数%.2f = %.2f', $composite * 100, $base, $amount) . $clamp;
    $detail['amount'] = round($amount, 2);
    return $detail;
}
