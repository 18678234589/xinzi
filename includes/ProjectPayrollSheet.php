<?php
/**
 * 首页“项目报酬表”：版式对齐财务的月度合作商报酬表（合计行放在表头下方第一行），数据全部取自系统实时核算：
 * 逐单分成快照 + 售后调整 + 月度规则（固定服务费 / 全勤奖 / 补助 / 阶梯等）+ 保险与手工项 + 服务时长确认。
 * 合计口径与 project/payroll.php 的“应结算”一致（实结算 = 应结算合计 − 扣减项）。
 * 财务 / 管理账号看全部人员；其他合作人员只能看到自己那一行。
 */
require_once __DIR__ . '/ProjectSettlement.php';
require_once __DIR__ . '/ProjectMonthly.php';
require_once __DIR__ . '/ProjectPayrollExtras.php';
require_once __DIR__ . '/ProjectPartnerProfile.php';
require_once __DIR__ . '/ProjectPayrollFlags.php';

/** 固定类补助按名称归到经理 / 组长 / 保险补助，其余算其他补助。 */
function pps_allowance_slot($name)
{
    if (strpos($name, '经理') !== false) return 'mgr';
    if (strpos($name, '组长') !== false) return 'lead';
    if (strpos($name, '保险') !== false) return 'ins_sub';
    return 'other';
}

/** 某月报酬表：['rows' => [...], 'totals' => [...]]；$onlyEmployeeId > 0 时只算这一个人。 */
function pps_sheet($month, $onlyEmployeeId = 0, $withProfile = false)
{
    $onlyEmployeeId = (int)$onlyEmployeeId;
    $cols = ['base', 'share', 'bonus', 'mgr', 'lead', 'ins_sub', 'other', 'deduct', 'parking', 'reserve', 'unit_ins', 'personal_ins'];
    $blank = array_fill_keys($cols, 0.0);
    $people = [];
    foreach (db()->query('SELECT id,name,department FROM employees' . ($onlyEmployeeId ? ' WHERE id=' . $onlyEmployeeId : ''))->fetchAll() as $e) {
        $people[(int)$e['id']] = $blank + ['id' => (int)$e['id'], 'name' => $e['name'], 'department' => $e['department'], 'days' => null, 'ot_days' => 0.0, 'pending' => 0.0, 'pending_n' => 0, 'items' => [], 'notes' => []];
    }
    $scope = $onlyEmployeeId ? ' AND employee_id=' . $onlyEmployeeId : '';
    $q = db()->prepare('SELECT employee_id,SUM(commission_amount) FROM project_commission_snapshots WHERE payroll_month=?' . $scope . ' GROUP BY employee_id');
    $q->execute([$month]);
    foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $eid => $v) if (isset($people[(int)$eid])) $people[(int)$eid]['share'] += (float)$v;
    $q = db()->prepare('SELECT employee_id,SUM(amount) FROM project_commission_adjustments WHERE payroll_month=?' . $scope . ' GROUP BY employee_id');
    $q->execute([$month]);
    foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $eid => $v) if (isset($people[(int)$eid])) $people[(int)$eid]['share'] += (float)$v;

    // 月度规则（部门利润池 / 阶梯 / 超额奖 / 单量等）：未锁月份按“预期结算”口径算（含未审核订单），否则整月草稿单多的部门（如微信代写 420 单全是草稿）会算成 0；锁月后用冻结结果。
    $lockQ = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
    $lockQ->execute([$month]);
    if ($lockQ->fetchColumn() === 'locked') $monthlyRows = ps_monthly_results($month);
    else {
        require_once __DIR__ . '/ProjectExpectedSettlement.php';
        $monthlyRows = array_filter(ps_expected_month($month)['monthly'], function ($r) { return $r['rule_type'] !== 'adjustment'; }); // 跨月更正上面已按 payroll_month 单独计入
    }
    // 预期结算（ps_expected_month）已把“手工项”也汇进 monthly 结果；下面单独按手工项逐条计入，这里先去掉重复的那几条（按人 + 名称 + 金额配对）。
    $manualSeen = [];
    foreach (ps_payroll_extras($month) as $mEid => $mx) foreach ($mx['items'] as $mi) { $k = $mEid . '|' . $mi['label'] . '|' . number_format((float)$mi['amount'], 2, '.', ''); $manualSeen[$k] = ($manualSeen[$k] ?? 0) + 1; }
    foreach ($monthlyRows as $r) {
        if (in_array($r['rule_type'], ['manual', 'extra_share'], true)) {
            $mk = (int)$r['employee_id'] . '|' . $r['rule_name'] . '|' . number_format((float)$r['amount'], 2, '.', '');
            if (!empty($manualSeen[$mk])) { $manualSeen[$mk]--; continue; }
        }
        $eid = (int)$r['employee_id'];
        if (!isset($people[$eid]) || !empty($r['paid_separately'])) continue; // 另行支付（法人补助）不计入应结算
        $amount = (float)$r['amount'];
        // 固定服务费类：固定服务费、设计客服绩效固定服务费、营业额阶梯里的“底薪”
        if ($r['rule_type'] === 'base_fee' || $r['rule_type'] === 'perf_rank' || ($r['rule_type'] === 'sales_package' && strpos((string)$r['rule_name'], '底薪') !== false)) $people[$eid]['base'] += $amount;
        elseif ($r['rule_type'] === 'attendance_bonus') {
            $people[$eid]['bonus'] += $amount;
            if ($amount == 0 && strpos((string)$r['detail'], '待核对考勤') === 0) $people[$eid]['notes'][] = '全勤奖待核对服务时长确认';
        } elseif ($r['rule_type'] === 'fixed') $people[$eid][pps_allowance_slot((string)$r['rule_name'])] += $amount;
        elseif ($r['rule_type'] === 'overtime_pay' || $r['rule_type'] === 'manual') $people[$eid]['other'] += $amount; // 延时服务超时补贴、其他调整
        else $people[$eid]['share'] += $amount; // 阶梯 / 部门分成 / 单量 / 营业额 / 尾差等都是项目分成的一部分
    }

    // 还没审核的订单（客服 / 技术尚未点完成、财务尚未审核）：按系统预计分成计入项目分成，否则整月三分之一的成交单会被漏掉
    foreach (pps_pending($month, $onlyEmployeeId) as $eid => $v) if (isset($people[$eid])) { $people[$eid]['share'] += $v['amount']; $people[$eid]['pending'] = $v['amount']; $people[$eid]['pending_n'] = $v['n']; }

    $settings = ps_insurance_settings();
    foreach (ps_payroll_extras($month) as $eid => $x) {
        if (!isset($people[$eid])) continue;
        $p = &$people[$eid];
        foreach ($x['items'] as $item) {
            $amount = (float)$item['amount'];
            $label = (string)$item['label'];
            if ($amount < 0) {
                $slot = strpos($label, '车位') !== false ? 'parking' : (strpos($label, '预留') !== false ? 'reserve' : 'deduct');
                $p[$slot] += -$amount;
            } else $p[strpos($label, '补单') !== false ? 'base' : (strpos($label, '全勤') !== false ? 'bonus' : (preg_match('/分成|提成|游学|留学/u', $label) ? 'share' : pps_allowance_slot($label)))] += $amount; // 手工录入的“xx业务分成 / 提成”记在项目分成一栏 // 网站客服的“补单提成”在报酬表里记在固定服务费一栏
            if ($withProfile) $p['items'][] = ['id' => (int)$item['id'], 'label' => $label, 'amount' => $amount, 'note' => (string)$item['note']];
            $p['notes'][] = $label . ' ' . ($amount < 0 ? '-' : '+') . '¥' . number_format(abs($amount), 2, '.', '') . ($item['note'] !== '' ? '（' . $item['note'] . '）' : '');
        }
        // 保险只对有固定服务费 / 全勤奖的人扣（与结算单一致）
        if ($p['base'] > 0 || $p['bonus'] > 0) {
            $ded = max(0.0, -(float)$x['insurance']['amount']);
            $p['personal_ins'] = min($ded, (float)$settings['personal']);
            $p['unit_ins'] = round($ded - $p['personal_ins'], 2);
            if ($p['unit_ins'] > 0.004 || !empty($x['insurance']['manual'])) $p['notes'][] = $x['insurance']['detail'];
        }
        unset($p);
    }
    foreach (ps_monthly_attendance($month) as $eid => $a) {
        if (!isset($people[(int)$eid])) continue;
        $people[(int)$eid]['days'] = round((float)$a['work'] / 8, 2);
        $people[(int)$eid]['ot_days'] = round((float)$a['ot_days'] + (float)$a['ot_holiday_days'], 2);
    }

    $profiles = $withProfile ? ppp_all() : [];
    $flags = ppf_all($month);
    $rows = [];
    $totals = array_fill_keys(array_merge($cols, ['payable', 'net']), 0.0);
    foreach ($people as $p) {
        foreach ($cols as $c) $p[$c] = round($p[$c], 2);
        $p['payable'] = round($p['base'] + $p['share'] + $p['bonus'] + $p['mgr'] + $p['lead'] + $p['ins_sub'] + $p['other'], 2);
        $p['net'] = round($p['payable'] - $p['deduct'] - $p['parking'] - $p['reserve'] - $p['unit_ins'] - $p['personal_ins'], 2);
        if (abs($p['payable']) + abs($p['net']) + $p['deduct'] + $p['parking'] + $p['reserve'] + $p['unit_ins'] + $p['personal_ins'] < 0.005) continue;
        if ($withProfile) $p['profile'] = ($profiles[$p['id']] ?? []) + ppp_blank();
        $fl = $flags[$p['id']] ?? null;
        $p['flag'] = ['color' => $fl['color'] ?? null, 'reason' => (string)($fl['reason'] ?? '')];
        if ($withProfile) { $p['ref'] = $fl && $fl['ref_net'] !== null ? (float)$fl['ref_net'] : null; $p['diff'] = ppf_diff($p['net'], $p['ref']); } // 对照金额和差距只给财务 / 管理账号
        $p['note'] = implode('；', array_slice(array_unique($p['notes']), 0, 4));
        $rows[] = $p;
        foreach (array_keys($totals) as $c) $totals[$c] += $p[$c];
    }
    usort($rows, function ($a, $b) { return $b['net'] <=> $a['net'] ?: $a['id'] <=> $b['id']; });
    foreach ($totals as $k => $v) $totals[$k] = round($v, 2);
    return ['rows' => $rows, 'totals' => $totals];
}

/**
 * 某月未审核订单（草稿 / 待审）里每人的预计分成：[employee_id => ['amount', 'n']]。交易关闭、或买家已付款但店铺未发货且无实收的订单不算。
 * 用订单页同一套计算（ps_summary 的预计分成：没有实收时按售价预估，扣退款），所以和订单页数字一致。
 */
function pps_pending($month, $onlyEmployeeId = 0)
{
    $onlyEmployeeId = (int)$onlyEmployeeId;
    $from = $month . '-01';
    $until = date('Y-m-d', strtotime($from . ' +1 month'));
    $sql = "SELECT o.*,COALESCE(s.trade_status,'') AS trade_status FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id
        WHERE o.order_date>=? AND o.order_date<? AND o.settlement_status NOT IN ('approved','locked')"
        . ($onlyEmployeeId ? ' AND EXISTS (SELECT 1 FROM project_participants p WHERE p.order_id=o.id AND p.employee_id=' . $onlyEmployeeId . ')' : '');
    $q = db()->prepare($sql);
    $q->execute([$from, $until]);
    // 还没录成本的订单：预计分成会把成本当 0，偏高很多（9 月网站模板已核订单成本约占收入 38%）。
    // 按“同业务 + 同组别、近三个月已审核分成快照的成本率”估算成本，只用于预计、不写入任何成本记录。
    $ratio = [];
    $rq = db()->prepare('SELECT o.project_type t,c.commission_group g,SUM(c.direct_cost) cost,SUM(c.income_amount) inc FROM project_commission_snapshots c JOIN project_orders o ON o.id=c.order_id WHERE c.payroll_month>=? GROUP BY o.project_type,c.commission_group');
    $rq->execute([date('Y-m', strtotime($from . ' -2 month'))]);
    foreach ($rq->fetchAll() as $r) $ratio[$r['t'] . '|' . $r['g']] = (float)$r['inc'] > 0 ? max(0.0, min(0.9, (float)$r['cost'] / (float)$r['inc'])) : 0.0;
    $out = [];
    foreach ($q->fetchAll() as $order) {
        if (strpos((string)$order['trade_status'], '交易关闭') !== false && (float)$order['receipt_amount'] <= 0) continue;
        if (strpos((string)$order['trade_status'], '等待卖家发货') !== false && (float)$order['receipt_amount'] <= 0) continue; // 买家已付款、店铺还没发货：不算预计（9 月核对对表误差更小）
        $costs = ps_costs($order['id']);
        $sum = ps_summary($order, $costs, ps_participants($order['id']));
        foreach ($sum['groups'] as $groupName => $group) foreach ($group['people'] as $person) {
            $eid = (int)$person['employee_id'];
            if ($onlyEmployeeId && $eid !== $onlyEmployeeId) continue;
            $calc = $person['estimated_calc'] ?? null;
            if (!$calc) continue;
            $share = (float)$calc['share'];
            if (!$costs && ($calc['mode'] ?? '') === 'pool' && (float)$calc['base'] > 0) {
                $income = (float)($calc['income'] ?? $order['contract_amount']);
                $newBase = max(0.0, (float)$calc['base'] - ($ratio[$order['project_type'] . '|' . $groupName] ?? 0.0) * $income);
                $share *= $newBase / (float)$calc['base'];
            }
            $out[$eid]['amount'] = ($out[$eid]['amount'] ?? 0.0) + $share + (float)$calc['subsidy'];
            $out[$eid]['n'] = ($out[$eid]['n'] ?? 0) + 1;
        }
    }
    foreach ($out as $eid => $v) $out[$eid]['amount'] = round($v['amount'], 2);
    return $out;
}

/** 可选月份：上月起往前 6 个月，本月也可看（未结算的月份显示的是实时预计）。 */
function pps_months()
{
    $out = [date('Y-m')];
    for ($i = 1; $i <= 6; $i++) $out[] = date('Y-m', strtotime("first day of -$i month"));
    return $out;
}

function pps_cell($v, $class = '')
{
    return '<td class="pps-n' . ($class ? ' ' . $class : '') . '" data-v="' . (float)$v . '">' . (abs((float)$v) < 0.005 ? '<span class="pps-zero">—</span>' : number_format((float)$v, 2)) . '</td>';
}
