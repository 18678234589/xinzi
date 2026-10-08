<?php

/**
 * [内部] 设计客服（排名部门）的完整算法明细，供 cs_perf_calc_detail 调用。
 * 最终金额/名次与 cs_perf_rank_result 完全一致（直接复用其结果与提示文案）。
 */
function cs_perf_rank_detail($employeeId, $year, $month)
{
    $rr = cs_perf_rank_result($employeeId, $year, $month);
    $detail = [
        'mode'        => 'rank',
        'dept'        => CS_PERF_RANK_DEPT,
        'dept_config' => get_cs_perf_dept_config(CS_PERF_RANK_DEPT) ?: null,
        'rank_tiers'  => CS_PERF_RANK_TIERS,
        'rank'        => $rr['rank']  ?? null,
        'score'       => $rr['score'] ?? 0.0,
        'amount'      => $rr['amount'],
        'formula'     => $rr['formula'],
        'base'        => $rr['base'],
        'stores'      => [],
        'ranking'     => [],
    ];

    // 方案：与 cs_perf_rank_list 相同的选取逻辑（部门配置方案 → 默认方案）
    $scheme = null;
    $deptCfg = $detail['dept_config'];
    if ($deptCfg && (int)$deptCfg['scheme_id'] > 0) $scheme = get_cs_perf_scheme((int)$deptCfg['scheme_id']);
    if (!$scheme) {
        foreach (get_cs_perf_schemes() as $s) {
            if ((int)$s['is_default'] === 1) { $scheme = $s; break; }
        }
    }
    if ($scheme) {
        $detail['scheme'] = $scheme;
        $params = cs_perf_scheme_params($scheme);

        // 该员工逐店铺：实际值 → 每指标命中档位 → 综合达成率（得分=各店综合的平均，只取命中档位的店铺）
        foreach (get_cs_performance_stores($employeeId, $year, $month) as $row) {
            $compo = cs_perf_metric_details($params, $row);
            $stores[] = [
                'store'     => (string)$row['store'],
                'row'       => $row,
                'ok'        => $compo['ok'],
                'metrics'   => $compo['metrics'],
                'composite' => $compo['ok'] ? $compo['composite'] : null,
            ];
        }
        $detail['stores'] = $stores ?? [];
    }

    // 部门内完整排名（名次 → 底薪档位），供展示「三人同一份名单如何排出名次」
    $tiers = CS_PERF_RANK_TIERS;
    foreach (cs_perf_rank_list($year, $month) as $i => $item) {
        $detail['ranking'][] = [
            'rank'   => $i + 1,
            'id'     => (int)$item['id'],
            'name'   => (string)$item['name'],
            'score'  => (float)$item['score'],
            'amount' => ($i < count($tiers)) ? (float)$tiers[$i] : 0.0,
        ];
    }
    return $detail;
}

/**
 * 设计客服「按排名定底薪」：部门内员工按「多店绩效平均得分」排序，只取前三名发底薪
 * （第1名850 / 第2名800 / 第3名750，其余0）。此机制仅设计客服固定启用。
 *
 * 得分 = 该员工当月各店铺分别用绩效方案算出的综合达成率，相加后除以店铺数；
 * 只上传一店则用该店；没上传数据得0分，排最后。同分按员工ID升序（结果稳定可复现）。
 * 方案：优先取「设计客服」部门配置的 scheme，未配置则用默认方案。
 *
 * @return array ['amount'=>float,'formula'=>string,'base'=>float,'rank'=>int|null,'score'=>float]
 */
function cs_perf_rank_result($employeeId, $year, $month)
{
    $employeeId = (int)$employeeId;
    if (is_cs_perf_excluded($employeeId)) {
        return ['amount' => 0.0, 'formula' => '已被排除出绩效名单', 'base' => 0.0, 'rank' => null, 'score' => 0.0];
    }
    // 员工部门必须为排名部门
    $emp = null;
    try {
        $st = db()->prepare("SELECT id, name, department FROM employees WHERE id=?");
        $st->execute([$employeeId]);
        $emp = $st->fetch();
    } catch (\Throwable $e) {}
    $dept = $emp ? (string)$emp['department'] : '';
    if ($dept !== CS_PERF_RANK_DEPT) {
        return ['amount' => 0.0, 'formula' => '非排名部门', 'base' => 0.0, 'rank' => null, 'score' => 0.0];
    }

    // 排名结果（整条排序名单）按 年月 缓存：同一页对多名设计客服重复计算的是同一份名单
    $list = cs_perf_rank_list((int)$year, (int)$month);

    $tiers = CS_PERF_RANK_TIERS;
    $rank = null; $score = 0.0; $rates = [];
    foreach ($list as $i => $item) {
        if ($item['id'] === $employeeId) { $rank = $i + 1; $score = $item['score']; $rates = $item['rates']; break; }
    }
    if ($rank === null) {
        return ['amount' => 0.0, 'formula' => '未在参与名单内', 'base' => 0.0, 'rank' => null, 'score' => 0.0];
    }

    $amount = ($rank <= count($tiers)) ? (float)$tiers[$rank - 1] : 0.0;
    $storeDesc = [];
    foreach ($rates as $store => $rate) {
        $name = $store !== '' ? $store : '默认店铺';
        $storeDesc[] = sprintf('%s %.1f%%', $name, $rate * 100);
    }
    $scoreTxt = sprintf('多店绩效 %s → 平均得分 %.2f%%', $storeDesc ? implode('  |  ', $storeDesc) : '无绩效数据', $score * 100);
    if ($amount > 0) {
         $formula = sprintf('%s → 第%d名 → 绩效固定服务费 %.2f元', $scoreTxt, $rank, $amount);
    } else {
         $formula = sprintf('%s → 第%d名（仅前三名结算固定服务费850/800/750）→ 0.00元', $scoreTxt, $rank);
    }
    return ['amount' => round($amount, 2), 'formula' => $formula, 'base' => round($amount, 2), 'rank' => $rank, 'score' => round($score, 4)];
}

/**
 * [内部] 设计客服月度排名名单（按得分降序，同分按员工ID升序）。（供 cs_perf_rank_result 共享，按年月缓存）
 * @return array 每项 ['id','name','score','rates']
 */
function cs_perf_rank_list($year, $month)
{
    $key = 'rank_list|' . (int)$year . '-' . (int)$month;
    $c = cs_perf_cache_get($key);
    if ($c['hit']) return $c['val'];

    ensureCsPerfSchema();

    // 方案：优先「设计客服」部门配置，其次默认方案
    $scheme = null;
    try {
        $deptCfg = get_cs_perf_dept_config(CS_PERF_RANK_DEPT);
        if ($deptCfg && (int)$deptCfg['scheme_id'] > 0) $scheme = get_cs_perf_scheme((int)$deptCfg['scheme_id']);
        if (!$scheme) {
            foreach (get_cs_perf_schemes() as $s) {
                if ((int)$s['is_default'] === 1) { $scheme = $s; break; }
            }
        }
    } catch (\Throwable $e) {}
    if (!$scheme) { cs_perf_cache_set($key, []); return []; }

    $params = cs_perf_scheme_params($scheme);

    // 同部门参与员工（排除者不参与）
    $members = [];
    try {
        $st = db()->prepare("SELECT e.id, e.name FROM employees e
            WHERE e.department=? AND e.id NOT IN (SELECT m.employee_id FROM cs_perf_members m WHERE m.is_excluded=1)");
        $st->execute([CS_PERF_RANK_DEPT]);
        $members = $st->fetchAll();
    } catch (\Throwable $e) {}
    if (!$members) { cs_perf_cache_set($key, []); return []; }

    // 每人：多店分别算综合达成率，取平均作为排名得分
    // 人工综合行（store=''，编辑/补录产生）优先：存在时只用该行得分，其余店铺上传行不再叠加，与 get_cs_performance 口径一致
    $list = [];
    foreach ($members as $m) {
        $id = (int)$m['id'];
        $rates = [];
        $storeRows = get_cs_performance_stores($id, (int)$year, (int)$month);
        $manualRow = null;
        foreach ($storeRows as $row) {
            if ((string)$row['store'] === '') { $manualRow = $row; break; }
        }
        if ($manualRow !== null) {
            $compo = cs_perf_composite_from($params, $manualRow);
            if ($compo['ok']) $rates[''] = $compo['composite'];
        } else {
            foreach ($storeRows as $row) {
                $compo = cs_perf_composite_from($params, $row);
                if ($compo['ok']) $rates[(string)$row['store']] = $compo['composite'];
            }
        }
        $score = $rates ? array_sum($rates) / count($rates) : 0.0;
        $list[] = ['id' => $id, 'name' => (string)$m['name'], 'score' => $score, 'rates' => $rates];
    }
    // 得分降序，同分按员工ID升序（usort 二次键确定，结果稳定）
    usort($list, function ($a, $b) {
        if ($a['score'] != $b['score']) return ($a['score'] < $b['score']) ? 1 : -1;
        return $a['id'] <=> $b['id'];
    });
    cs_perf_cache_set($key, $list);
    return $list;
}

/**
 * 目标基准值「自动抓取」：取指定月份所有绩效员工的实际平均值，作为方案指标的目标基线。
 * 默认抓取上个月；该月无数据时回退到最近一个有数据的月份。
 * @param int $year  0 = 默认上个月
 * @param int $month 0 = 默认上个月
 * @return array|null ['year','month','emp_count','avg_sales','avg_conv','avg_reply','avg_resp']；无数据返回 null
 */
function get_cs_perf_target_suggestions($year = 0, $month = 0)
{
    $year  = (int)$year;
    $month = (int)$month;
    if (!($year >= 2000 && $month >= 1 && $month <= 12)) {
        $year  = (int)date('Y', strtotime('-1 month'));
        $month = (int)date('n', strtotime('-1 month'));
    }
    ensureCsPerfSchema();
    $pdo = db();
    $rows = [];
    try {
        $rows = $pdo->query("SELECT net_sales, inquiry_conv, wangwang_reply, reply_speed
            FROM customer_service_performance
            WHERE year={$year} AND month={$month} AND (net_sales>0 OR inquiry_conv>0 OR wangwang_reply>0 OR reply_speed>0)")->fetchAll();
    } catch (\Throwable $e) {}
    // 该月无数据 → 回退最近一个有数据的月份
    if (!$rows) {
        try {
            $recent = $pdo->query("SELECT year, month FROM customer_service_performance
                WHERE net_sales>0 OR inquiry_conv>0 OR wangwang_reply>0 OR reply_speed>0
                ORDER BY year DESC, month DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($recent && (int)$recent['year'] > 0) {
                $year  = (int)$recent['year'];
                $month = (int)$recent['month'];
                $rows = $pdo->query("SELECT net_sales, inquiry_conv, wangwang_reply, reply_speed
                    FROM customer_service_performance
                    WHERE year={$year} AND month={$month} AND (net_sales>0 OR inquiry_conv>0 OR wangwang_reply>0 OR reply_speed>0)")->fetchAll();
            }
        } catch (\Throwable $e) {}
    }
    if (!$rows) return null;

    $nSales = $sumSales = $nConv = $sumConv = $nReply = $sumReply = $nResp = $sumResp = 0;
    foreach ($rows as $r) {
        $s = (float)$r['net_sales'];     if ($s > 0)    { $nSales++; $sumSales += $s; }
        $c = (float)$r['inquiry_conv'];  if ($c > 0)    { $nConv++;  $sumConv  += $c; }
        $w = (float)$r['wangwang_reply'];if ($w > 0)    { $nReply++; $sumReply += $w; }
        $rs = (float)$r['reply_speed'];  if ($rs > 0)   { $nResp++;  $sumResp  += $rs; }
    }
    return [
        'year'      => $year,
        'month'     => $month,
        'emp_count' => count($rows),
        'avg_sales' => $nSales > 0 ? round($sumSales / $nSales, 2) : 0.0,
        'avg_conv'  => $nConv  > 0 ? round($sumConv  / $nConv,  2) : 0.0,
        'avg_reply' => $nReply > 0 ? round($sumReply / $nReply, 2) : 0.0,
        'avg_resp'  => $nResp  > 0 ? round($sumResp  / $nResp,  1) : 0.0,
    ];
}

/**
 * 从 CSV 文本识别列位置（模糊匹配）
 * @param array $header 表头行（已转UTF-8）
 * @return array 标准列名 => 列索引（找不到则为 null）
 */
function detect_cs_perf_columns($header)
{
    $map = ['name'=>null,'wangwang'=>null,'date'=>null,'year'=>null,'month'=>null,
            'incoming'=>null,'total_sec'=>null,'reply_speed'=>null,'reply_count'=>null,
            'net_sales'=>null,'inquiry_conv'=>null,'wangwang_reply'=>null,'order_count'=>null];
    foreach ($header as $i => $cell) {
        $c = mb_strtolower(trim((string)$cell));
        $c = str_replace([' ', "\xEF\xBB\xBF"], '', $c);
        // 旺旺账号列：须含「旺旺」但不含 率/转化/销售（排除「旺旺回复率」这类指标列）
        $isRateLike = (strpos($c, '率') !== false || strpos($c, '转化') !== false || strpos($c, '销售') !== false);
        $hasWW  = ($c === 'wangwang' || $c === 'wangwangno' || (strpos($c, '旺旺') !== false && !$isRateLike));
        $hasKefu = (strpos($c, '客服') !== false || strpos($c, '姓名') !== false || strpos($c, '员工') !== false || $c === 'name' || $c === 'employee');
        if ($map['wangwang'] === null && $hasWW) $map['wangwang'] = $i;
        if ($map['name'] === null && $hasKefu && !$hasWW) $map['name'] = $i;
        if ($map['date'] === null && (strpos($c, '日期') !== false || strpos($c, 'date') !== false)) $map['date'] = $i;
        if ($map['year'] === null && (strpos($c, '年份') !== false || $c === 'year')) $map['year'] = $i;
        if ($map['month'] === null && (strpos($c, '月份') !== false || $c === 'month')) $map['month'] = $i;
        // 下单人数（转化率分子）：下单人数/下单买家数/成交人数/下单客户数/下单量 等
        if ($map['order_count'] === null && (strpos($c, '下单人数') !== false || strpos($c, '下单买家数') !== false || strpos($c, '成交人数') !== false || strpos($c, '下单客户数'
    ) !== false || strpos($c, '下单数') !== false || strpos($c, '下单量') !== false || $c === 'ordercount' || $c === 'order_count')) $map['order_count'] = $i;
        // 询单最终下单转化率：须含「率」（且带 转化/询单 语境），避免把「询单人数」这类整数误认为转化率
        $isConvRate = (strpos($c, '转化') !== false || strpos($c, '询单') !== false) && strpos($c, '率') !== false;
        if ($map['inquiry_conv'] === null && $isConvRate) $map['inquiry_conv'] = $i;
        // 接待/进线/询单/咨询人数（不含 下单/成交 人数）
        $isUnder = (strpos($c, '下单') !== false || strpos($c, '成交') !== false);
        if ($map['incoming'] === null && !$isUnder && (strpos($c, '接待') !== false || strpos($c, '进线') !== false || strpos($c, '会话') !== false || strpos($c, '咨询') !==
    false || strpos($c, '询单') !== false || strpos($c, '人数') !== false || strpos($c, '买家数') !== false)) $map['incoming'] = $i;
        // 总回复时长（秒）
        if ($map['total_sec'] === null && (strpos($c, '总秒') !== false || strpos($c, '总回复') !== false || strpos($c, '回复秒数') !== false || strpos($c, '回复总时长'
    ) !== false)) $map['total_sec'] = $i;
        if ($map['reply_count'] === null && (strpos($c, '回复次数') !== false || strpos($c, '回复条数') !== false || $c === 'replycount' || $c === 'replycounts')) $map['reply_count'
    ] = $i;
        // 平均回复/响应时长（千牛官方导出常用「平均响应时长」「平均首次响应时长」）
        if ($map['reply_speed'] === null && (strpos($c, '响应时长') !== false || strpos($c, '响应时间') !== false || strpos($c, '回复时长') !== false || strpos($c, '平均回复'
    ) !== false || strpos($c, '回复速度') !== false || strpos($c, '首次响应') !== false)) $map['reply_speed'] = $i;
        // 净销售额
        if ($map['net_sales'] === null && (strpos($c, '净销售额') !== false || strpos($c, '销售额') !== false || strpos($c, '销售金额') !== false || $c === 'netsales' ||
    $c === 'sales' || $c === 'orderamount')) $map['net_sales'] = $i;
        // 旺旺回复率
        if ($map['wangwang_reply'] === null && (strpos($c, '回复率') !== false || strpos($c, '旺旺回复') !== false)) $map['wangwang_reply'] = $i;
    }
    return $map;
}
