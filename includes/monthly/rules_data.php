<?php

function ps_monthly_types()
{
    return [
        'tier_rate' => '阶梯比例（按月换档）',
        'threshold_bonus' => '超额奖金',
        'ranking' => '排名奖',
        'dept_share' => '部门主管提成',
        'fixed' => '固定补助',
        'per_unit' => '计件奖励',
        'base_fee' => '固定服务费（按考勤折算）',
        'attendance_bonus' => '全勤奖',
        'manual' => '手工调整（每月填写）',
        'legacy_sheet' => '原系统单量补贴（接单客服门控计列）',
        'legacy_module' => '原系统单模块（直接调用旧引擎）',
        'profit_pool' => '部门利润池分配',
        'perf_rank' => '绩效排名固定服务费（原系统客服绩效）',
        'order_count' => '部门单量提成（按订单数）',
        'sales_package' => '营业额阶梯薪酬（底薪 + 提成 + 单量）',
    ];
}

function ps_monthly_metrics()
{
    return ['profit' => '毛利', 'sales' => '售价', 'commission' => '已计项目分成', 'manual' => '财务填写名次'];
}

function ps_monthly_rules_for($month, $includeInactive = false)
{
    $sql = 'SELECT * FROM project_monthly_rules WHERE effective_from<=? AND (effective_to IS NULL OR effective_to>=?)' . ($includeInactive ? '' : ' AND is_active=1') . ' ORDER BY id'
    ;
    $q = db()->prepare($sql);
    $q->execute([$month, $month]);
    $rules = $q->fetchAll();
    foreach ($rules as $i => $rule) $rules[$i]['params'] = json_decode((string)$rule['params_json'], true) ?: [];
    return $rules;
}

function ps_monthly_scope_businesses($rule)
{
    if (trim((string)$rule['scope_business']) === '*' || trim((string)$rule['scope_business']) === '') return null;
    return array_map('ps_business_normalize', array_filter(array_map('trim', preg_split('/[,，、]+/u', $rule['scope_business']))));
}

function ps_monthly_snapshot_matches($rule, $snap)
{
    $businesses = ps_monthly_scope_businesses($rule);
    if ($businesses !== null && !in_array(ps_business_normalize($snap['project_type']), $businesses, true)) return false;
    if (!in_array($rule['scope_group'], ['*', ''], true) && $rule['scope_group'] !== $snap['commission_group']) return false;
    if (!in_array($rule['scope_role'], ['*', ''], true) && !in_array($rule['scope_role'], ps_role_keys($snap['role_name']), true)) return false;
    if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== (int)$snap['employee_id']) return false;
    return true;
}

function ps_monthly_snapshots($month)
{
    $q = db()->prepare('SELECT s.*,o.project_type,o.order_no,o.order_kind,o.contract_amount AS order_contract_amount FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE s.payroll_month=? ORDER BY s.id'
    );
    $q->execute([$month]);
    $rows = $q->fetchAll();
    foreach ($rows as &$snap) {
        if (!in_array($snap['project_type'], ['网站续费', '环境配置'], true)) continue;
        // 部门分成不是个人分单毛利：3%服务费应扣完整一次，不能只扣某人1/6的服务费。
        $fee = (float)$snap['order_contract_amount'] * ps_business_service_fee_rate($snap['project_type']);
        $cost = (float)$snap['direct_cost'];
        if ($snap['calc_mode'] === 'individual' && (float)$snap['group_weight'] > 0) $cost /= (float)$snap['group_weight'];
        $snap['department_profit'] = (float)$snap['income_amount'] - $cost - $fee;
        $snap['department_revenue'] = (float)$snap['income_amount'] - $fee;
    }
    unset($snap);
    return $rows;
}

/**
 * 单条快照对指标的贡献：[毛利, 售价, 分成, 逐单比例部分(不含补助), 本人计提部分]。
 * 与核算表一致：合接订单（主次）每人按整单售价、整单毛利计入月度指标；阶梯差额按本人实际计提部分（毛利 × 权重）计算。
 */
function ps_monthly_snapshot_values($snap)
{
    $individual = $snap['calc_mode'] === 'individual';
    $weight = (float)$snap['group_weight'];
    $profit = (float)$snap['contribution_profit'];
    $sales = (float)$snap['income_amount'];
    $commission = (float)$snap['commission_amount'];
    $portion = $individual ? $profit : $profit * $weight;
    return [$profit, $sales, $commission, $commission - (float)$snap['subsidy_amount'], $portion];
}

/** 当月考勤（原系统考勤表，小时制，8 小时 = 1 天）。 */
function ps_monthly_attendance($month)
{
    [$year, $mon] = array_map('intval', explode('-', $month));
    $out = [];
    try {
        $q = db()->prepare('SELECT employee_id,work_hours,absent_hours FROM attendances WHERE year=? AND month=?');
        $q->execute([$year, $mon]);
        foreach ($q->fetchAll() as $row) $out[(int)$row['employee_id']] = ['work' => (float)$row['work_hours'], 'absent' => (float)$row['absent_hours']];
    } catch (PDOException $e) {
        // 未启用考勤模块时按满勤处理
    }
    return $out;
}

/** 固定服务费按考勤折算：返回 [金额, 说明]。 */
function ps_monthly_prorate($amount, $attendance)
{
    if (!$attendance || $attendance['absent'] <= 0) return [round($amount, 2), $attendance ? '满勤' : '无考勤记录按满勤'];
    $leave = round($attendance['absent'] / 8, 2); // 与收入表一致：请假天数保留两位小数（如 1.19 天、0.23 天）
    if ($leave <= 4) return [round($amount - $amount / 30 * $leave, 2), sprintf('请假 %s 天：%s − %s/30 × %s', rtrim(rtrim(number_format($leave, 2, '.', ''), '0'), '.'), money_plain
    ($amount), money_plain($amount), rtrim(rtrim(number_format($leave, 2, '.', ''), '0'), '.'))];
    $actual = max(round($attendance['work'] / 8, 2) - $leave, 0);
    return [round($amount / 30 * $actual, 2), sprintf('请假超过 4 天，按实际出勤 %s 天：%s/30 × %s', rtrim(rtrim(number_format($actual, 2, '.', ''), '0'), '.'), money_plain
    ($amount), rtrim(rtrim(number_format($actual, 2, '.', ''), '0'), '.'))];
}

/**
 * 全勤奖审批建议（仅供财务参考，不自动发放）：请假 <4 小时全额、≥4 小时减半、≥8 小时不发；无考勤记录提示核对。
 * 返回 [建议金额, 说明]。
 */
function ps_attendance_suggestion($full, $attendance)
{
    if (!$attendance) return [0.0, '无考勤记录，请核对'];
    $hours = (float)$attendance['absent'];
    $text = $hours <= 0 ? '满勤' : '请假 ' . rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . ' 小时';
    if ($hours < 4) return [(float)$full, $text . ($hours > 0 ? '（<4 小时不扣）' : '')];
    if ($hours < 8) return [round((float)$full / 2, 2), $text . '（≥4 小时减半）'];
    return [0.0, $text . '（≥8 小时不发）'];
}

function ps_monthly_inputs($month)
{
    $q = db()->prepare('SELECT rule_id,employee_id,value,note FROM project_monthly_inputs WHERE payroll_month=?');
    $q->execute([$month]);
    $out = [];
    foreach ($q->fetchAll() as $row) $out[(int)$row['rule_id']][(int)$row['employee_id']] = $row;
    return $out;
}

function ps_monthly_pick_tier($tiers, $value)
{
    usort($tiers, function ($a, $b) { return (float)$a['from'] <=> (float)$b['from']; });
    $picked = null;
    foreach ($tiers as $tier) if ($value >= (float)$tier['from']) $picked = $tier;
    return $picked;
}

/**
 * 营业额阶梯薪酬（平面设计阎泸琪）：按当月营业额落档（≤ 上限取第一档，超过最高档按最高档），
 * 该档底薪 + 营业额 × 该档比例 + 单量补助（单笔 ≥ 门槛每单 big 元，< 门槛每单 small 元）
 * + 老客户找回订单收入 × returning_rate + 好评率低于 review_min% 扣 review_penalty 元（好评率由财务每月填写，未填不扣）。
 * $orders = [['income' => 金额, 'returning' => bool], ...]；$base 由调用方按考勤折算。纯函数，便于核对。
 * 返回 ['revenue','tier','base','items' => [[名称, 金额, 说明], ...]]（底薪单列在 base，不在 items）。
 */
function ps_sales_package_calc($params, $orders, $reviewRate = null)
{
    $tiers = $params['tiers'] ?? [];
    usort($tiers, function ($a, $b) { return (float)$a['upto'] <=> (float)$b['upto']; });
    $revenue = round(array_sum(array_map(function ($o) { return (float)$o['income']; }, $orders)), 2);
    $tier = null;
    foreach ($tiers as $candidate) if ($revenue <= (float)$candidate['upto']) { $tier = $candidate; break; }
    if (!$tier && $tiers) $tier = end($tiers);
    if (!$tier) return ['revenue' => $revenue, 'tier' => null, 'base' => 0.0, 'items' => []];
    $threshold = (float)($params['big_threshold'] ?? 50);
    $big = count(array_filter($orders, function ($o) use ($threshold) { return (float)$o['income'] >= $threshold; }));
    $small = count(array_filter($orders, function ($o) use ($threshold) { return (float)$o['income'] > 0 && (float)$o['income'] < $threshold; }));
    $tierText = '月营业额 ¥' . money_plain($revenue) . ' 落在“≤¥' . money_plain($tier['upto']) . '”档';
    $items = [];
    if ((float)$tier['rate'] > 0) $items[] = ['营业额提成', $revenue * (float)$tier['rate'], $tierText . '：¥' . money_plain($revenue) . ' × ' . round((float)$tier['rate']
    * 100, 4) . '%'];
    $perOrder = $big * (float)$tier['big'] + $small * (float)$tier['small'];
    if ($perOrder > 0) $items[] = ['单量补助', $perOrder, sprintf('≥¥%s 的 %d 单 × ¥%s + <¥%s 的 %d 单 × ¥%s', money_plain($threshold), $big, money_plain($tier['big'
    ]), money_plain($threshold), $small, money_plain($tier['small']))];
    $returning = round(array_sum(array_map(function ($o) { return !empty($o['returning']) ? (float)$o['income'] : 0.0; }, $orders)), 2);
    $returningRate = (float)($params['returning_rate'] ?? 0);
    if ($returning > 0 && $returningRate > 0) $items[] = ['老客户找回', $returning * $returningRate, '老客户找回订单收入 ¥' . money_plain($returning) . ' × ' . round
    ($returningRate * 100, 4) . '%'];
    $reviewMin = (float)($params['review_min'] ?? 0);
    if ($reviewRate !== null && $reviewMin > 0 && (float)$reviewRate < $reviewMin) $items[] = ['好评率罚款', -(float)($params['review_penalty'] ?? 0), '本月好评率 ' . rtrim
    (rtrim(number_format((float)$reviewRate, 2, '.', ''), '0'), '.') . '% 低于 ' . rtrim(rtrim(number_format($reviewMin, 2, '.', ''), '0'), '.') . '%'];
    return ['revenue' => $revenue, 'tier' => $tier, 'base' => (float)$tier['base'], 'tier_text' => $tierText, 'items' => $items];
}
