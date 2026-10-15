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
        'overtime_pay' => '超时补贴（固定服务费 ÷ 30 × 天数 × 倍率）',
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
    $sql = 'SELECT r.*,e.department AS employee_department FROM project_monthly_rules r LEFT JOIN employees e ON e.id=r.employee_id WHERE r.effective_from<=? AND (r.effective_to IS NULL OR r.effective_to>=?)' . ($includeInactive ? '' : ' AND r.is_active=1') . ' ORDER BY r.id'
    ;
    $q = db()->prepare($sql);
    $q->execute([$month, $month]);
    $rules = $q->fetchAll();
    foreach ($rules as $i => $rule) {
        $rules[$i]['params'] = json_decode((string)$rule['params_json'], true) ?: [];
        if ($rule['rule_type'] === 'attendance_bonus') $rules[$i]['params']['amount'] = ps_attendance_bonus_amount($rule['employee_department'] ?? '');
    }
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
        $q = db()->prepare('SELECT * FROM attendances WHERE year=? AND month=?');
        $q->execute([$year, $mon]);
        foreach ($q->fetchAll() as $row) $out[(int)$row['employee_id']] = ['work' => (float)$row['work_hours'], 'absent' => (float)$row['absent_hours'],
            'ot_days' => (float)($row['overtime_days'] ?? 0), 'ot_holiday_days' => (float)($row['holiday_overtime_days'] ?? 0), 'remark' => (string)($row['remark'] ?? ''),
            'work_from_home' => ps_attendance_work_from_home($row['remark'] ?? '')];
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
 * 全勤奖按部门标准核算；无有效考勤或申请居家办公时不自动发放。
 * 返回 [建议金额, 说明]。
 */
function ps_attendance_bonus_amount($department)
{
    return in_array(trim((string)$department), ['网站客服', '网站客服部'], true) ? 100.0 : 200.0;
}

function ps_attendance_work_from_home($remark)
{
    $home = '(?:在家(?:上班|办公)?|居家(?:办公)?|远程办公|WFH)';
    $remark = preg_replace('/(?:没有|未|无|非|不)(?:申请)?' . $home . '|' . $home . '\s*[:：]?\s*0(?:\.0+)?\s*天/iu', '', (string)$remark);
    return preg_match('/' . $home . '/iu', $remark) === 1;
}

function ps_attendance_row_work_from_home($headers, $row, $nameColumn)
{
    foreach ($row as $index => $cell) {
        if ($index === $nameColumn) continue;
        $homeColumn = ps_attendance_work_from_home($headers[$index] ?? '');
        if (ps_attendance_work_from_home($cell) || ($homeColumn && preg_match('/^\d+(?:\.\d+)?\s*(?:天|小时|h|d)?$/iu', trim((string)$cell)) && (float)$cell > 0)
            || ($homeColumn && in_array(strtolower(trim((string)$cell)), ['是', '有', '已申请', 'yes', 'true', 'y', '√', '✓'], true))) return true;
    }
    return false;
}

/** 财务导入后补齐有考勤但未配置全勤奖的人员；在调用方事务中一起提交。 */
function ps_monthly_ensure_attendance_rules($month, $adminId)
{
    if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $month)) throw new InvalidArgumentException('月份格式无效');
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $period = $pdo->prepare('SELECT status FROM project_payroll_periods WHERE period=? FOR UPDATE');
        $period->execute([$month]);
        $added = 0;
        if ($period->fetchColumn() !== 'locked') {
            [$year, $mon] = array_map('intval', explode('-', $month));
            // 锁住人员后再查规则，重复/并发导入不会重复新增全勤奖。
            $people = $pdo->prepare('SELECT e.id,e.name,e.department FROM employees e JOIN attendances a ON a.employee_id=e.id WHERE a.year=? AND a.month=? AND a.work_hours>0 ORDER BY e.id FOR UPDATE');
            $people->execute([$year, $mon]);
            $exists = $pdo->prepare("SELECT id FROM project_monthly_rules WHERE employee_id=? AND rule_type='attendance_bonus' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) LIMIT 1 FOR UPDATE");
            $insert = $pdo->prepare('INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note,updated_by_admin) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($people->fetchAll() as $employee) {
                $exists->execute([(int)$employee['id'], $month, $month]);
                if ($exists->fetchColumn()) continue;
                $insert->execute([$employee['name'] . ' 全勤奖（' . $month . '起）', 'attendance_bonus', '*', '*', '*', (int)$employee['id'], 'profit', json_encode(['amount' => ps_attendance_bonus_amount($employee['department'])]), $month, '考勤导入自动配置；网站客服100元，其他部门200元；本月居家办公不发', (int)$adminId > 0 ? (int)$adminId : null]);
                $added++;
            }
        }
        if ($ownTransaction) $pdo->commit();
        return $added;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ps_attendance_suggestion($full, $attendance)
{
    if (!$attendance || (float)($attendance['work'] ?? 0) <= 0) return [0.0, '无有效考勤记录，请核对'];
    if (!empty($attendance['work_from_home'])) return [0.0, '本月申请居家办公，不发全勤奖'];
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
 * 某人某月参与的、还没审核的订单（月度规则只认已审核订单的收入）：返回 [单数, 售价合计]。
 * 用来在结算明细里提醒“还有多少钱的订单没审核，没计进营业额”，避免月营业额按 0 落档、却看不出原因。
 */
function ps_unreviewed_orders($employeeId, $month, $businesses = null)
{
    $sql = "SELECT COUNT(DISTINCT o.id), COALESCE(SUM(o.contract_amount),0) FROM project_orders o JOIN project_participants p ON p.order_id=o.id
        WHERE p.employee_id=? AND o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked')";
    $params = [(int)$employeeId, $month . '-01', date('Y-m-d', strtotime($month . '-01 +1 month'))];
    if ($businesses) { $sql .= ' AND o.project_type IN (' . implode(',', array_fill(0, count($businesses), '?')) . ')'; $params = array_merge($params, $businesses); }
    $q = db()->prepare($sql); $q->execute($params);
    $r = $q->fetch(PDO::FETCH_NUM);
    return [(int)$r[0], round((float)$r[1], 2)];
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
