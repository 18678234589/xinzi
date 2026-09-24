<?php
/**
 * 规则中心 · 月度规则。在已审核的逐单分成快照之上按月汇总计算，未锁定月份实时生效，锁月时冻结结果。
 *
 * 指标（按人、按规则范围内的快照汇总；组池订单按组内权重折算，个人独立订单取本人计提基数）：
 *   profit     毛利 = Σ 计提基数（收入 − 直接成本 − 服务费）
 *   sales      售价 = Σ 可结算收入
 *   commission 已计项目分成 = Σ 逐单分成（含每单补助）
 * 规则类型：
 *   tier_rate        阶梯比例：按月指标落档，全部业绩统一按该档比例，补发 / 扣回与逐单比例的差额（刘帅、外包前端）
 *   threshold_bonus  超额奖金：(指标 − 门槛) × 比例（模板技术 1 万 × 1.5%、网站客服 2 万 × 0.8%）
 *   ranking          排名奖：范围内按指标排名，前几名依次奖励（网站客服 500 / 300 / 200）
 *   dept_share       部门主管提成：所选业务当月总毛利（每单计一次）− 当月其他费用 × 比例 × 分配比例（于洋 5% × 50%）
 *   fixed            固定补助：每月固定金额（经理补助、技术主管补助）
 *   per_unit         计件奖励：当月件数 × 单价，件数由财务在规则中心填写（优站模板每个 15 元）
 *   base_fee         固定服务费（原“基本工资”）：按考勤折算——请假 ≤4 天：金额 − 金额/30 × 请假天数；>4 天：金额/30 × 实际出勤天数；
 *                    当月可填写金额覆盖默认值（如网站客服每月不同的“补单提成”）
 *   attendance_bonus 全勤奖：请假 <4 小时全额、≥4 小时减半、≥8 小时不发；无考勤记录不发；当月可填写金额覆盖（如申请在家上班 0 元）
 *   manual           手工调整：当月逐人填写（上月漏记、未接入系统的业务提成等）
 * 固定补助可标记“另行支付”（如法人补助），单列展示、不计入应结算金额。
 */
require_once __DIR__ . '/ProjectSettlement.php';

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
    ];
}

function ps_monthly_metrics()
{
    return ['profit' => '毛利', 'sales' => '售价', 'commission' => '已计项目分成', 'manual' => '财务填写名次'];
}

function ps_monthly_rules_for($month, $includeInactive = false)
{
    $sql = 'SELECT * FROM project_monthly_rules WHERE effective_from<=? AND (effective_to IS NULL OR effective_to>=?)' . ($includeInactive ? '' : ' AND is_active=1') . ' ORDER BY id';
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
    $q = db()->prepare('SELECT s.*,o.project_type,o.order_no FROM project_commission_snapshots s JOIN project_orders o ON o.id=s.order_id WHERE s.payroll_month=? ORDER BY s.id');
    $q->execute([$month]);
    return $q->fetchAll();
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
    if ($leave <= 4) return [round($amount - $amount / 30 * $leave, 2), sprintf('请假 %s 天：%s − %s/30 × %s', rtrim(rtrim(number_format($leave, 2, '.', ''), '0'), '.'), money_plain($amount), money_plain($amount), rtrim(rtrim(number_format($leave, 2, '.', ''), '0'), '.'))];
    $actual = max(round($attendance['work'] / 8, 2) - $leave, 0);
    return [round($amount / 30 * $actual, 2), sprintf('请假超过 4 天，按实际出勤 %s 天：%s/30 × %s', rtrim(rtrim(number_format($actual, 2, '.', ''), '0'), '.'), money_plain($amount), rtrim(rtrim(number_format($actual, 2, '.', ''), '0'), '.'))];
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
 * 计算某月全部月度规则结果。返回 [['employee_id','rule_id','rule_name','rule_type','amount','detail'], ...]（金额为 0 的不返回）。
 * 已锁定月份返回冻结结果。
 */
function ps_monthly_results($month, $forceLive = false)
{
    if (!$forceLive) {
        $period = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
        $period->execute([$month]);
        if ($period->fetchColumn() === 'locked') {
            $q = db()->prepare('SELECT employee_id,rule_id,rule_name,rule_type,amount,paid_separately,detail FROM project_monthly_results WHERE payroll_month=? ORDER BY id');
            $q->execute([$month]);
            return $q->fetchAll();
        }
    }
    $rules = ps_monthly_rules_for($month);
    $snapshots = ps_monthly_snapshots($month);
    // 分成尾差：逐单四舍五入与“按月合计后四舍五入”的差额（核算表口径），通常为几分钱。
    $rounding = [];
    foreach ($snapshots as $snap) {
        if ($snap['commission_exact'] === null) continue;
        $eid = (int)$snap['employee_id'];
        $rounding[$eid] = $rounding[$eid] ?? ['exact' => 0.0, 'cents' => 0];
        $rounding[$eid]['exact'] += (float)$snap['commission_exact'];
        $rounding[$eid]['cents'] += (int)round((float)$snap['commission_amount'] * 100);
    }
    $roundingItems = function () use (&$rounding) {
        $items = [];
        foreach ($rounding as $eid => $sum) {
            $diffCents = (int)round($sum['exact'] * 100) - $sum['cents'];
            if ($diffCents !== 0) $items[] = ['employee_id' => $eid, 'rule_id' => 0, 'rule_name' => '分成尾差', 'rule_type' => 'rounding', 'amount' => $diffCents / 100, 'paid_separately' => 0, 'detail' => sprintf('提成逐项合计 ¥%s，按月合计后四舍五入 ¥%s', money_plain($sum['cents'] / 100), money_plain(round($sum['exact'], 2)))];
        }
        return $items;
    };
    if (!$rules) return $roundingItems();
    $inputs = ps_monthly_inputs($month);
    $metricLabels = ps_monthly_metrics();
    $attendance = ps_monthly_attendance($month);
    $results = [];
    $add = function ($employeeId, $rule, $amount, $detail) use (&$results, &$rounding) {
        $exact = (float)$amount;
        $amount = round($amount, 2);
        if (abs($amount) < 0.005) return;
        // 超额奖金、阶梯差额与逐单分成合计后统一四舍五入（核算表“总提成”口径）
        if (in_array($rule['rule_type'], ['threshold_bonus', 'tier_rate'], true)) {
            $eid = (int)$employeeId;
            $rounding[$eid] = $rounding[$eid] ?? ['exact' => 0.0, 'cents' => 0];
            $rounding[$eid]['exact'] += $exact;
            $rounding[$eid]['cents'] += (int)round($amount * 100);
        }
        $results[] = ['employee_id' => (int)$employeeId, 'rule_id' => (int)$rule['id'], 'rule_name' => $rule['name'], 'rule_type' => $rule['rule_type'], 'amount' => $amount, 'paid_separately' => !empty($rule['params']['separate']) ? 1 : 0, 'detail' => mb_substr($detail, 0, 500)];
    };
    foreach ($rules as $rule) {
        $p = $rule['params'];
        $type = $rule['rule_type'];
        // 按人汇总范围内快照
        $people = [];
        foreach ($snapshots as $snap) {
            if (!ps_monthly_snapshot_matches($rule, $snap)) continue;
            [$profit, $sales, $commission, $share, $portion] = ps_monthly_snapshot_values($snap);
            $eid = (int)$snap['employee_id'];
            $people[$eid] = $people[$eid] ?? ['profit' => 0.0, 'sales' => 0.0, 'commission' => 0.0, 'share' => 0.0, 'portion' => 0.0, 'orders' => 0];
            $people[$eid]['portion'] += $portion;
            $people[$eid]['profit'] += $profit;
            $people[$eid]['sales'] += $sales;
            $people[$eid]['commission'] += $commission;
            $people[$eid]['share'] += $share;
            $people[$eid]['orders']++;
        }
        $metric = $rule['metric'];
        $metricLabel = $metricLabels[$metric] ?? $metric;
        if ($type === 'tier_rate') {
            foreach ($people as $eid => $m) {
                $tier = ps_monthly_pick_tier($p['tiers'] ?? [], $m[$metric]);
                if (!$tier) continue;
                $target = $m['portion'] * (float)$tier['rate'];
                $add($eid, $rule, $target - $m['share'], sprintf('月%s ¥%s 落在 ≥¥%s 档 %s%%：毛利 ¥%s × %s%% = ¥%s，逐单已计 ¥%s', $metricLabel, money_plain($m[$metric]), money_plain($tier['from']), round((float)$tier['rate'] * 100, 2), money_plain($m['portion']), round((float)$tier['rate'] * 100, 2), money_plain($target), money_plain($m['share'])) . (isset($tier['base']) && $tier['base'] !== '' ? '；对应底薪 ¥' . money_plain($tier['base']) . '（底薪在原系统结算）' : ''));
            }
        } elseif ($type === 'threshold_bonus') {
            $threshold = (float)($p['threshold'] ?? 0);
            $rate = (float)($p['rate'] ?? 0);
            foreach ($people as $eid => $m) {
                if ($m[$metric] <= $threshold) continue;
                $add($eid, $rule, ($m[$metric] - $threshold) * $rate, sprintf('月%s ¥%s − 门槛 ¥%s = ¥%s，× %s%%', $metricLabel, money_plain($m[$metric]), money_plain($threshold), money_plain($m[$metric] - $threshold), round($rate * 100, 4)));
            }
        } elseif ($type === 'ranking' && $metric === 'manual') {
            // 名次由财务每月填写（如客服绩效考核排名）。
            $awards = array_values(array_map('floatval', $p['awards'] ?? []));
            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                $position = (int)$input['value'];
                if ($eid === 0 || $position < 1 || !isset($awards[$position - 1])) continue;
                $add($eid, $rule, $awards[$position - 1], '第 ' . $position . ' 名（财务填写）' . ($input['note'] !== '' ? '：' . $input['note'] : ''));
            }
        } elseif ($type === 'ranking') {
            $awards = array_values(array_filter(array_map('floatval', $p['awards'] ?? []), function ($v) { return $v > 0; }));
            $ranked = array_filter($people, function ($m) use ($metric) { return $m[$metric] > 0; });
            uasort($ranked, function ($a, $b) use ($metric) { return $b[$metric] <=> $a[$metric]; });
            $position = 0;
            foreach ($ranked as $eid => $m) {
                if (!isset($awards[$position])) break;
                $add($eid, $rule, $awards[$position], sprintf('第 %d 名（月%s ¥%s，共 %d 人参与排名）', $position + 1, $metricLabel, money_plain($m[$metric]), count($ranked)));
                $position++;
            }
        } elseif ($type === 'dept_share') {
            if ($rule['employee_id'] === null) continue;
            $orders = [];
            $commissions = 0.0;
            $businesses = ps_monthly_scope_businesses($rule);
            foreach ($snapshots as $snap) {
                if ($businesses !== null && !in_array(ps_business_normalize($snap['project_type']), $businesses, true)) continue;
                $oid = (int)$snap['order_id'];
                $orders[$oid] = $orders[$oid] ?? ['pool' => null, 'max' => null, 'revenue' => 0.0];
                // 收入口径：售价收入 − 服务费（每单计一次）
                $orders[$oid]['revenue'] = max($orders[$oid]['revenue'], (float)$snap['income_amount'] - (float)$snap['service_fee']);
                if ($snap['calc_mode'] === 'pool' && (float)$snap['group_weight'] >= 1) $orders[$oid]['pool'] = (float)$snap['contribution_profit'];
                else $orders[$oid]['max'] = max((float)$snap['contribution_profit'], $orders[$oid]['max'] ?? -INF);
                $commissions += (float)$snap['commission_amount'];
            }
            $byRevenue = ($p['base'] ?? 'profit') === 'revenue';
            // 毛利口径每单只计一次：组池快照的计提基数即整单毛利；否则取其中最大者。
            $total = $byRevenue ? array_sum(array_column($orders, 'revenue')) : array_sum(array_map(function ($o) { return $o['pool'] ?? $o['max'] ?? 0; }, $orders));
            $deduct = !empty($p['deduct_commissions']) ? $commissions : 0.0;
            $extra = (float)($inputs[(int)$rule['id']][0]['value'] ?? 0);
            $rate = (float)($p['rate'] ?? 0);
            $share = (float)($p['share'] ?? 1);
            $base = max($total - $deduct - $extra, 0);
            $add($rule['employee_id'], $rule, $base * $rate * $share, sprintf('部门 %d 单%s ¥%s%s − 其他费用 ¥%s = ¥%s，× %s%% × 分配 %s%%', count($orders), $byRevenue ? '收入（扣服务费）' : '毛利', money_plain($total), $deduct > 0 ? ' − 部门提成 ¥' . money_plain($deduct) : '', money_plain($extra), money_plain($base), round($rate * 100, 4), round($share * 100, 2)));
        } elseif ($type === 'fixed') {
            if ($rule['employee_id'] === null) continue;
            $override = $inputs[(int)$rule['id']][(int)$rule['employee_id']] ?? null;
            $amount = $override !== null ? (float)$override['value'] : (float)($p['amount'] ?? 0);
            $add($rule['employee_id'], $rule, $amount, ($override !== null ? '本月填写 ¥' . money_plain($amount) . ($override['note'] !== '' ? '（' . $override['note'] . '）' : '') : '每月固定 ¥' . money_plain($amount)) . (!empty($p['separate']) ? '，另行支付，不计入应结算' : ''));
        } elseif ($type === 'base_fee') {
            if ($rule['employee_id'] === null) continue;
            $eid = (int)$rule['employee_id'];
            $override = $inputs[(int)$rule['id']][$eid] ?? null;
            $amount = $override !== null ? (float)$override['value'] : (float)($p['amount'] ?? 0);
            if ($amount <= 0) continue;
            [$value, $how] = ps_monthly_prorate($amount, $attendance[$eid] ?? null);
            $add($eid, $rule, $value, ($override !== null ? '本月金额 ¥' . money_plain($amount) . ($override['note'] !== '' ? '（' . $override['note'] . '）' : '') . '；' : '') . $how);
        } elseif ($type === 'attendance_bonus') {
            if ($rule['employee_id'] === null) continue;
            $eid = (int)$rule['employee_id'];
            $override = $inputs[(int)$rule['id']][$eid] ?? null;
            $full = (float)($p['amount'] ?? 0);
            if ($override !== null) { $add($eid, $rule, (float)$override['value'], '本月填写 ¥' . money_plain($override['value']) . ($override['note'] !== '' ? '（' . $override['note'] . '）' : '')); continue; }
            $att = $attendance[$eid] ?? null;
            if (!$att) continue; // 无考勤记录不发全勤奖（与原系统一致）
            $hours = $att['absent'];
            $value = $hours < 4 ? $full : ($hours < 8 ? $full / 2 : 0);
            $add($eid, $rule, $value, $hours <= 0 ? '满勤' : sprintf('请假 %s 小时：%s', rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.'), $hours < 4 ? '不扣' : ($hours < 8 ? '减半' : '不发')));
        } elseif ($type === 'manual') {
            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                if ($eid === 0) continue;
                if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== $eid) continue;
                $add($eid, $rule, (float)$input['value'], $input['note'] !== '' ? $input['note'] : '财务填写');
            }
        } elseif ($type === 'per_unit') {
            $unit = (float)($p['amount'] ?? 0);
            foreach ($inputs[(int)$rule['id']] ?? [] as $eid => $input) {
                if ($eid === 0 || (float)$input['value'] == 0) continue;
                if ($rule['employee_id'] !== null && (int)$rule['employee_id'] !== $eid) continue;
                $add($eid, $rule, (float)$input['value'] * $unit, sprintf('%s 个 × ¥%s%s', rtrim(rtrim(money_plain($input['value']), '0'), '.'), money_plain($unit), $input['note'] !== '' ? '（' . $input['note'] . '）' : ''));
            }
        }
    }
    return array_merge($roundingItems(), $results);
}

function ps_monthly_freeze($month)
{
    $results = ps_monthly_results($month, true);
    db()->prepare('DELETE FROM project_monthly_results WHERE payroll_month=?')->execute([$month]);
    $insert = db()->prepare('INSERT INTO project_monthly_results (payroll_month,rule_id,rule_name,rule_type,employee_id,amount,paid_separately,detail) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($results as $row) $insert->execute([$month, $row['rule_id'], $row['rule_name'], $row['rule_type'], $row['employee_id'], $row['amount'], $row['paid_separately'], $row['detail']]);
    return $results;
}

/** 校验并整理规则表单参数。 */
function ps_monthly_params_from_input($type, $input)
{
    $num = function ($value, $label, $allowNegative = false) {
        $value = trim((string)$value);
        if ($value === '' || !is_numeric($value) || (!$allowNegative && (float)$value < 0)) throw new RuntimeException('请填写有效的' . $label);
        return round((float)$value, 6);
    };
    if ($type === 'tier_rate') {
        $tiers = [];
        foreach ((array)($input['tier_from'] ?? []) as $i => $from) {
            if (trim((string)$from) === '' && trim((string)($input['tier_rate'][$i] ?? '')) === '') continue;
            $tiers[] = ['from' => $num($from, '档位起点'), 'rate' => $num($input['tier_rate'][$i] ?? '', '档位比例') / 100, 'base' => trim((string)($input['tier_base'][$i] ?? ''))];
        }
        if (!$tiers) throw new RuntimeException('请至少填写一档');
        usort($tiers, function ($a, $b) { return $a['from'] <=> $b['from']; });
        return ['tiers' => $tiers];
    }
    if ($type === 'threshold_bonus') return ['threshold' => $num($input['threshold'] ?? '', '门槛'), 'rate' => $num($input['rate'] ?? '', '比例') / 100];
    if ($type === 'ranking') {
        $awards = array_values(array_filter(array_map('trim', preg_split('/[,，\s\/]+/u', (string)($input['awards'] ?? ''))), 'strlen'));
        foreach ($awards as $award) if (!is_numeric($award) || (float)$award < 0) throw new RuntimeException('排名奖金额请用逗号分隔，如 500,300,200');
        if (!$awards) throw new RuntimeException('请填写排名奖金额');
        return ['awards' => array_map('floatval', $awards)];
    }
    if ($type === 'dept_share') return ['rate' => $num($input['rate'] ?? '', '比例') / 100, 'share' => $num($input['share'] ?? '100', '分配比例') / 100, 'base' => ($input['dept_base'] ?? '') === 'revenue' ? 'revenue' : 'profit', 'deduct_commissions' => !empty($input['deduct_commissions'])];
    if ($type === 'fixed') return ['amount' => $num($input['amount'] ?? '', '金额'), 'separate' => !empty($input['separate'])];
    if ($type === 'per_unit' || $type === 'base_fee' || $type === 'attendance_bonus') return ['amount' => $num($input['amount'] ?? '0', '金额')];
    if ($type === 'manual') return [];
    throw new RuntimeException('规则类型无效');
}

/**
 * 月度口径预置：部门核算表（奖金、排名、阶梯、主管提成、计件）+《2026.7/8 月合作商收入表》（固定服务费、全勤奖、各项补助）。
 * 人员按姓名匹配，找不到或重名的跳过并提示。
 */
function ps_monthly_presets()
{
    $all = '*';
    $rows = [
        ['name' => '模板技术超额奖金', 'rule_type' => 'threshold_bonus', 'scope_business' => '网站模板', 'scope_group' => 'technical', 'scope_role' => '模板技术', 'employee' => null, 'metric' => 'profit', 'params' => ['threshold' => 10000, 'rate' => 0.015], 'note' => '光君 / 孙妍 / 张强：毛利超过 1 万的部分 × 1.5%'],
        ['name' => '网站客服超额奖金', 'rule_type' => 'threshold_bonus', 'scope_business' => '网站模板,AI网站定制', 'scope_group' => 'customer_service', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => ['threshold' => 20000, 'rate' => 0.008], 'note' => '毛利超过 2 万的部分 × 0.8%（合接订单按整单毛利计入）'],
        ['name' => '网站客服排名奖', 'rule_type' => 'ranking', 'scope_business' => '网站模板,AI网站定制', 'scope_group' => 'customer_service', 'scope_role' => '*', 'employee' => null, 'metric' => 'manual', 'params' => ['awards' => [500, 300, 200]], 'note' => '第一 500、第二 300、第三 200；名次按客服考核每月在规则中心填写'],
        ['name' => '定制内部前端阶梯', 'rule_type' => 'tier_rate', 'scope_business' => 'AI网站定制', 'scope_group' => 'technical', 'scope_role' => '前端', 'employee' => null, 'metric' => 'profit', 'params' => ['tiers' => [['from' => 0, 'rate' => 0.05, 'base' => '2000'], ['from' => 10000, 'rate' => 0.07, 'base' => '2000'], ['from' => 15000, 'rate' => 0.09, 'base' => '2500'], ['from' => 20000, 'rate' => 0.12, 'base' => '2500'], ['from' => 25000, 'rate' => 0.13, 'base' => '2500'], ['from' => 30000, 'rate' => 0.14, 'base' => '2500'], ['from' => 35000, 'rate' => 0.15, 'base' => '2500']]], 'note' => '刘帅：按月利润落档，全部业绩统一按该档比例'],
        ['name' => '外包前端阶梯', 'rule_type' => 'tier_rate', 'scope_business' => 'AI网站定制', 'scope_group' => 'technical', 'scope_role' => '外包前端', 'employee' => null, 'metric' => 'sales', 'params' => ['tiers' => [['from' => 0, 'rate' => 0.15, 'base' => ''], ['from' => 10000, 'rate' => 0.20, 'base' => ''], ['from' => 30000, 'rate' => 0.25, 'base' => '']]], 'note' => '李仁超 / 孙磊：按月售价 1 万以下 15%、1–3 万 20%、3 万以上 25%'],
        ['name' => '环境配置主管提成', 'rule_type' => 'dept_share', 'scope_business' => '环境配置', 'scope_group' => '*', 'scope_role' => '*', 'employee' => '于洋', 'metric' => 'profit', 'params' => ['rate' => 0.05, 'share' => 0.5, 'base' => 'revenue', 'deduct_commissions' => true], 'note' => '于洋（网站售后部主管）：(环境配置收入 − 3% 服务费 − 部门客服/技术提成 − 员工底薪等其他费用) × 5% × 50%'],
        ['name' => '优站模板奖励', 'rule_type' => 'per_unit', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => ['amount' => 15], 'note' => '每做一个优站模板奖励 15 元（8 月李仁超 75、李子晖 195、崔鑫栋 30）'],
        ['name' => '其他业务提成（未接入系统）', 'rule_type' => 'manual', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => [], 'note' => '标书、续费、代写等尚未在项目系统录单的业务提成，由财务每月填写'],
        ['name' => '其他调整', 'rule_type' => 'manual', 'scope_business' => '*', 'scope_group' => '*', 'scope_role' => '*', 'employee' => null, 'metric' => 'profit', 'params' => [], 'note' => '上月漏记、临时奖扣等，每月填写并写明原因'],
    ];
    // 固定服务费（原基本工资，按考勤折算）；网站客服为每月不同的“补单提成”，默认 0，每月在规则中心填写。
    foreach (['光君' => 800, '张强' => 800, '孙妍' => 800, '刘帅' => 2300, '于海波' => 2300, '崔鑫栋' => 2300, '李子晖' => 2800, '纪鹏程' => 1300, '石凯新' => 2000, '刘丹丹' => 2000, '曹双双' => 800, '王宁' => 800, '王亚' => 3000, '吴宁' => 1800, '刘媛媛' => 800, '于洋' => 3800, '翟建跃' => 4800, '朱俊英' => 2300, '田悦琦' => 3300, '谢文婷' => 2800, '高晶晶' => 2300, '姚琳' => 3900, '孙曼' => 3800, '刘群' => 3500, '魏慧子' => 3800, '王芳' => 3400, '宋文娜' => 2700, '董旭' => 0, '宋倩倩' => 0, '苏婷' => 0, '孙湉湉' => 0] as $name => $amount) {
        $rows[] = ['name' => $name . ' 固定服务费', 'rule_type' => 'base_fee', 'scope_business' => $all, 'scope_group' => $all, 'scope_role' => $all, 'employee' => $name, 'metric' => 'profit', 'params' => ['amount' => $amount], 'note' => $amount > 0 ? '原基本工资，按考勤折算' : '网站客服补单提成，每月金额不同，请在本月试算里填写（按考勤折算）'];
    }
    foreach (['光君' => 200, '张强' => 200, '孙妍' => 200, '刘帅' => 200, '于海波' => 200, '崔鑫栋' => 200, '李子晖' => 200, '纪鹏程' => 200, '石凯新' => 200, '刘丹丹' => 200, '曹双双' => 200, '王宁' => 200, '吴宁' => 200, '刘媛媛' => 200, '于洋' => 200, '翟建跃' => 200, '朱俊英' => 200, '田悦琦' => 200, '谢文婷' => 200, '高晶晶' => 200, '姚琳' => 200, '孙曼' => 200, '刘群' => 200, '魏慧子' => 200, '王芳' => 200, '宋文娜' => 200, '董旭' => 100, '宋倩倩' => 100, '苏婷' => 100, '孙湉湉' => 100] as $name => $amount) {
        $rows[] = ['name' => $name . ' 全勤奖', 'rule_type' => 'attendance_bonus', 'scope_business' => $all, 'scope_group' => $all, 'scope_role' => $all, 'employee' => $name, 'metric' => 'profit', 'params' => ['amount' => $amount], 'note' => '请假 <4 小时全额、≥4 小时减半、≥8 小时不发'];
    }
    foreach ([['孙妍', '经理补助', 100, false], ['崔鑫栋', '部门经理补助', 200, false], ['石凯新', '技术主管补助', 500, false], ['于洋', '其他补助', 2000, false], ['翟建跃', '其他补助', 900, false], ['王亚', '其他补助', 300, false], ['曹双双', '其他补助', 200, false], ['王宁', '其他补助', 200, false], ['姚琳', '其他补助', 300, false], ['孙曼', '其他补助', 400, false], ['刘群', '经理补助', 300, false], ['魏慧子', '其他补助', 200, false], ['宋文娜', '其他补助', 500, false], ['于洋', '法人补助', 500, true], ['翟建跃', '法人补助', 200, true]] as [$name, $label, $amount, $separate]) {
        $rows[] = ['name' => $name . ' ' . $label, 'rule_type' => 'fixed', 'scope_business' => $all, 'scope_group' => $all, 'scope_role' => $all, 'employee' => $name, 'metric' => 'profit', 'params' => ['amount' => $amount, 'separate' => $separate], 'note' => $separate ? '由关联公司另行支付，单列展示，不计入应结算' : '收入表“经理补助 / 其他补助”'];
    }
    return $rows;
}

function ps_monthly_apply_presets($actor, $month)
{
    $exists = db()->prepare('SELECT 1 FROM project_monthly_rules WHERE name=? AND is_active=1 LIMIT 1');
    $findEmployee = db()->prepare('SELECT id FROM employees WHERE name=?');
    $insert = db()->prepare('INSERT INTO project_monthly_rules (name,rule_type,scope_business,scope_group,scope_role,employee_id,metric,params_json,effective_from,note,updated_by_admin) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $added = 0;
    $skipped = [];
    $legacyNames = ['孙妍 经理补助' => '孙妍经理补助', '崔鑫栋 部门经理补助' => '崔鑫栋部门经理补助', '石凯新 技术主管补助' => '小程序技术主管补助'];
    foreach (ps_monthly_presets() as $row) {
        if (isset($legacyNames[$row['name']])) { $exists->execute([$legacyNames[$row['name']]]); if ($exists->fetchColumn()) continue; }
        $exists->execute([$row['name']]);
        if ($exists->fetchColumn()) continue;
        $employeeId = null;
        if ($row['employee'] !== null) {
            $findEmployee->execute([$row['employee']]);
            $ids = $findEmployee->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) > 1) {
                // 重名时取已开通项目账号的那位（如两条“孙湉湉”，网站客服那条有账号）
                $withAccount = db()->prepare('SELECT e.id FROM employees e JOIN project_users u ON u.employee_id=e.id AND u.is_active=1 WHERE e.name=?');
                $withAccount->execute([$row['employee']]);
                $accountIds = $withAccount->fetchAll(PDO::FETCH_COLUMN);
                if (count($accountIds) === 1) $ids = $accountIds;
            }
            if (count($ids) !== 1) { $skipped[] = $row['name'] . '（人员“' . $row['employee'] . '”' . (count($ids) ? '重名' : '不存在') . '）'; continue; }
            $employeeId = (int)$ids[0];
        }
        $insert->execute([$row['name'], $row['rule_type'], $row['scope_business'], $row['scope_group'], $row['scope_role'], $employeeId, $row['metric'], json_encode($row['params'], JSON_UNESCAPED_UNICODE), $month, $row['note'], $actor['id']]);
        $added++;
    }
    ps_audit('monthly_rule', 0, 'import_preset', $actor, ['added' => $added, 'skipped' => $skipped]);
    return [$added, $skipped];
}
