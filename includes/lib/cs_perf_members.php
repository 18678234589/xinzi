<?php

/**
 * 绩效参与名单（按部门自动）：所在部门已配置绩效（基数>0 且 有效方案）且未被排除的员工。
 * 不需要手动逐个添加；被排除员工在绩效页手动维护。
 * @return array
 */
function get_cs_perf_participants()
{
    $c = cs_perf_cache_get('participants');
    if ($c['hit']) return $c['val'];
    try {
        ensureCsPerfSchema();
        $depts = [];
        foreach (get_cs_perf_dept_configs() as $dc) {
            $isRankDept = ((string)$dc['department'] === CS_PERF_RANK_DEPT);
            // 排名部门（设计客服）只要配置了方案即可参与（无需基数）；其余部门需基数>0 且 有效方案
            if ($isRankDept ? ((int)$dc['scheme_id'] > 0) : ((float)$dc['base'] > 0 && (int)$dc['scheme_id'] > 0)) {
                $depts[] = (string)$dc['department'];
            }
        }
        // 排名部门（设计客服）恒参与：即使尚未在部门配置里设置，也按「多店平均→前三名」排名
        if (!in_array(CS_PERF_RANK_DEPT, $depts, true)) $depts[] = CS_PERF_RANK_DEPT;
        if (!$depts) { cs_perf_cache_set('participants', []); return []; }
        $in = implode(',', array_map(function ($d) { return db()->quote($d); }, $depts));
        $rows = db()->query("SELECT e.* FROM employees e
            WHERE e.department IN ($in) AND e.id NOT IN (SELECT m.employee_id FROM cs_perf_members m WHERE m.is_excluded=1)
            ORDER BY e.department, e.id")->fetchAll();
        cs_perf_cache_set('participants', $rows);
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 被排除出客服绩效的员工（页面可手动排除/恢复）
 * @return array
 */
function get_cs_perf_excluded()
{
    try {
        ensureCsPerfSchema();
        return db()->query("SELECT e.* FROM employees e
            INNER JOIN cs_perf_members m ON m.employee_id=e.id AND m.is_excluded=1
            ORDER BY e.department, e.id")->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 是否已被排除（排除者不再参与部门绩效底薪）
 */
function is_cs_perf_excluded($employeeId)
{
    $key = 'is_excluded|' . (int)$employeeId;
    $c = cs_perf_cache_get($key);
    if ($c['hit']) return $c['val'];
    try {
        ensureCsPerfSchema();
        $st = db()->prepare("SELECT 1 FROM cs_perf_members WHERE employee_id=? AND is_excluded=1 LIMIT 1");
        $st->execute([(int)$employeeId]);
        $val = (bool)$st->fetchColumn();
        cs_perf_cache_set($key, $val);
        return $val;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 排除某员工参与客服绩效（去重）
 */
function exclude_cs_perf_member($employeeId)
{
    try {
        ensureCsPerfSchema();
        $ok = (bool)db()->prepare("INSERT INTO cs_perf_members (employee_id, is_excluded) VALUES (?,1)
            ON DUPLICATE KEY UPDATE is_excluded=1")->execute([(int)$employeeId]);
        if ($ok) cs_perf_cache_reset();
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 取消排除（恢复参与）
 */
function include_cs_perf_member($employeeId)
{
    try {
        ensureCsPerfSchema();
        $ok = (bool)db()->prepare("UPDATE cs_perf_members SET is_excluded=0 WHERE employee_id=?")->execute([(int)$employeeId]);
        if ($ok) cs_perf_cache_reset();
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * 读取某员工某月客服绩效（聚合全部店铺）。
 * - 存在 store='' 的行（历史上的单店上传 / 本页「编辑补录」写入的人工综合值）时，直接以该行作为结果；
 * - 仅上传多店（store 均非空）时合并：净销售额/进线求和，转化率/回复率按有值行平均，平均响应时长按进线加权平均。
 */
function get_cs_performance($employeeId, $year, $month)
{
    $rows = get_cs_performance_stores((int)$employeeId, (int)$year, (int)$month);
    if (!$rows) return null;
    // 人工综合行优先：编辑/补录或未分店上传都落在 store=''
    foreach ($rows as $r) {
        if ((string)$r['store'] === '') return $r;
    }
    if (count($rows) === 1) return $rows[0];

    $agg = $rows[0];
    $incomingSum = 0; $convSum = 0; $convN = 0; $wangSum = 0; $wangN = 0; $respWeighted = 0.0; $replyCount = 0;
    $salesSum = 0.0; $dealSum = 0; $dealN = 0; $orderSum = 0; $source = '';
    foreach ($rows as $r) {
        $inc = (int)$r['incoming_count'];
        $salesSum += (float)$r['net_sales'];
        $incomingSum += $inc;
        $orderSum += (int)$r['order_count'];
        $conv = (float)$r['inquiry_conv']; if ($conv > 0) { $convSum += $conv; $convN++; }
        $wang = (float)$r['wangwang_reply']; if ($wang > 0) { $wangSum += $wang; $wangN++; }
        $respWeighted += (float)$r['reply_speed'] * $inc; $replyCount++;
        if ($r['deal_count'] !== null && $r['deal_count'] !== '') { $dealSum += (int)$r['deal_count']; $dealN++; }
        if ($source === '' && trim((string)$r['source_file']) !== '') $source = (string)$r['source_file'];
    }
    $agg['store']        = '';
    $agg['net_sales']    = round($salesSum, 2);
    $agg['incoming_count'] = $incomingSum;
    $agg['order_count']  = $orderSum;
    $agg['inquiry_conv'] = $convN > 0 ? round($convSum / $convN, 2) : 0.0;
    $agg['wangwang_reply'] = $wangN > 0 ? round($wangSum / $wangN, 2) : 0.0;
    $agg['reply_speed']  = $incomingSum > 0 ? round($respWeighted / $incomingSum, 1) : round($respWeighted / $replyCount, 1);
    $agg['deal_count']   = $dealN > 0 ? $dealSum : null;
    $agg['source_file']  = $source;
    return $agg;
}

/**
 * 某员工某月按店铺分的绩效行（按店铺名排序）。用于设计客服「多店绩效分别计算后取平均」的排名。
 * @return array
 */
function get_cs_performance_stores($employeeId, $year, $month)
{
    $key = 'stores|' . (int)$employeeId . '|' . (int)$year . '-' . (int)$month;
    $c = cs_perf_cache_get($key);
    if ($c['hit']) return $c['val'];
    try {
        $stmt = db()->prepare("SELECT * FROM customer_service_performance WHERE employee_id=? AND year=? AND month=? ORDER BY store");
        $stmt->execute([(int)$employeeId, (int)$year, (int)$month]);
        $rows = $stmt->fetchAll();
        cs_perf_cache_set($key, $rows);
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 询单转化率的「计算过程」说明：转化率 = 下单人数 ÷ 询单人数。
 * 当文件/录入只有「询单人数 + 下单人数」时，系统据此算出转化率并保存，此处可还原推导式子
 * （如「转化率 = 下单5 ÷ 询单20 = 25%」，用于页面展示计算过程）。
 * @param float $inquiryConv 已存/已算出的转化率(%)（用它做最终展示值）
 * @param int   $orderCount  下单人数（分子）
 * @param int   $incoming    询单人数（分母）
 * @return string|null 推导式子；下单/询单人数缺失时返回 null（此时仅显示百分值本身）
 */
function cs_perf_conv_derivation($inquiryConv, $orderCount, $incoming)
{
    $orderCount = (int)$orderCount; $incoming = (int)$incoming;
    if ($orderCount <= 0 || $incoming <= 0) return null;
    // 展示值以已存的转化率为准，式子按原始两数还原
    $pct = rtrim(rtrim(number_format((float)$inquiryConv, 2, '.', ''), '0'), '.');
    return sprintf('转化率 = 下单%d ÷ 询单%d = %s%%', $orderCount, $incoming, $pct);
}

/**
 * [内部] 某客服员工当月成交/接单的聚合：deal_count（成交单数）+ order_total（接单金额）。
 * 口径：核验月、非异常、非删除、非退款、非部门拆分、非负金额（两份口径与旧实现逐行校验一致）。
 * 单一 SQL 一次返回 COUNT+SUM，避免两份相同 WHERE 的远程库聚合分别各跑一遍。
 * @return array ['deal_count'=>int, 'order_total'=>float]
 */
function get_employee_order_aggregate($employeeId, $year, $month)
{
    $key = 'agg|' . (int)$employeeId . '|' . (int)$year . '-' . (int)$month;
    $c = cs_perf_cache_get($key);
    if ($c['hit']) return $c['val'];
    try {
        $monthStr = sprintf('%04d-%02d', (int)$year, (int)$month);
        $credit = "(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = ?"
            . " OR ((raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) IS NULL"
            . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__verified_month__')) = '')"
            . "  AND (raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) IS NULL"
            . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) = ''"
            . "   OR JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.__order_status__')) <> '未核验')"
            . "  AND DATE_FORMAT(order_date, '%Y-%m') = ?))";
        // 退款/负金额/部门拆分均在 SQL 内过滤；聚合在库内完成，SELECT 只回传两个标量（不再搬运大段 raw_data，大幅降低远程库传输耗时）
        $stmt = db()->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(o.order_amount), 0) AS total FROM orders o"
            . " WHERE o.employee_id=? AND $credit AND COALESCE(o.is_abnormal,0)=0"
            . " AND COALESCE(o.is_deleted,0)=0"
            . " AND (o.raw_data IS NULL OR o.raw_data NOT LIKE '%\"__from_dept__\"%')"
            . " AND o.order_amount >= 0"
            . " AND (o.raw_data IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__is_refund__')) IS NULL"
            . "   OR JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__is_refund__')) <> '1')");
        $stmt->execute([(int)$employeeId, $monthStr, $monthStr]);
        $row = $stmt->fetch();
        $val = [
            'deal_count'  => (int)$row['cnt'],
            'order_total' => round((float)$row['total'], 2),
        ];
        cs_perf_cache_set($key, $val);
        return $val;
    } catch (\Throwable $e) {
        return ['deal_count' => 0, 'order_total' => 0.0];
    }
}

/**
 * 实时汇总某客服员工当月成交单数（口径同 get_employee_order_total：核验月、非异常、非退款、非部门拆分）。
 * @return int
 */
function get_employee_deal_count($employeeId, $year, $month)
{
    return get_employee_order_aggregate($employeeId, $year, $month)['deal_count'];
}

/**
 * 实时汇总某客服员工当月成交/接单金额（口径同 get_employee_deal_count：核验月、非异常、非退款、非部门拆分）。
 * @return float 元
 */
function get_employee_order_total($employeeId, $year, $month)
{
    return get_employee_order_aggregate($employeeId, $year, $month)['order_total'];
}

/* ---------------- 绩效方案（算法） ---------------- */
