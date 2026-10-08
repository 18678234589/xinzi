<?php

/**
 * 计算某月全部月度规则结果。返回 [['employee_id','rule_id','rule_name','rule_type','amount','detail'], ...]（金额为 0 的不返回）。
 * 已锁定月份返回冻结结果。
 */
function ps_monthly_results($month, $forceLive = false, $context = null)
{
    // 预期结算复用同一规则引擎，但只传入内存数据，不落库、不替换已锁定结算。
    $forecast = !empty($context['forecast']);
    if (!$forceLive && $context === null) {
        $period = db()->prepare('SELECT status FROM project_payroll_periods WHERE period=?');
        $period->execute([$month]);
        if ($period->fetchColumn() === 'locked') {
            $q = db()->prepare('SELECT employee_id,rule_id,rule_name,rule_type,amount,paid_separately,detail FROM project_monthly_results WHERE payroll_month=? ORDER BY id');
            $q->execute([$month]);
            return $q->fetchAll();
        }
    }
    $rules = $context['rules'] ?? ps_monthly_rules_for($month);
    $snapshots = $context['snapshots'] ?? ps_monthly_snapshots($month);
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
    $inputs = $context['inputs'] ?? ps_monthly_inputs($month);
    $metricLabels = ps_monthly_metrics();
    $attendance = $context['attendance'] ?? ps_monthly_attendance($month);
    $results = [];
    $tierBases = [];
    $fullAttendance = [];
    foreach ($rules as $r) {
        if ($r['rule_type'] === 'attendance_bonus' && $r['employee_id'] !== null) $fullAttendance[(int)$r['employee_id']] = (float)($r['params']['amount'] ?? 0);
    }
    // 先确定阶梯固定额：规则中心调整展示顺序后，固定服务费仍须使用同一档位。
    foreach ($rules as $r) {
        if ($r['rule_type'] !== 'tier_rate') continue;
        $metrics = [];
        foreach ($snapshots as $snap) {
            if (!ps_monthly_snapshot_matches($r, $snap)) continue;
            [$profit, $sales] = ps_monthly_snapshot_values($snap);
            $eid = (int)$snap['employee_id'];
            $metrics[$eid] = ($metrics[$eid] ?? 0) + ($r['metric'] === 'sales' ? $sales : $profit);
        }
        foreach ($metrics as $eid => $value) {
            $tier = ps_monthly_pick_tier($r['params']['tiers'] ?? [], $value);
            if (!$tier || !isset($tier['base']) || $tier['base'] === '') continue;
            $includesAttendance = $r['params']['base_includes_attendance'] ?? ($r['scope_business'] === 'AI网站定制' && $r['scope_role'] === '前端');
            $tierBases[$eid] = ['amount' => max((float)$tier['base'] - ($includesAttendance ? ($fullAttendance[$eid] ?? 0) : 0), 0),
                'detail' => '按当月阶梯固定额 ¥' . money_plain($tier['base']) . ($includesAttendance ? '，扣除单列全勤奖 ¥' . money_plain($fullAttendance[$eid] ?? 0) : '')];
        }
    }
    $add = function ($employeeId, $rule, $amount, $detail, $keepZero = false) use (&$results, &$rounding) {
        $exact = (float)$amount;
        $amount = round($amount, 2);
        if (abs($amount) < 0.005 && !$keepZero) return; // 0 元行只保留需要提示状态的（如全勤奖待批准）
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
            /* split: includes/monthly/types/tier_rate.php */ include __DIR__ . '/monthly/types/tier_rate.php';

        } elseif ($type === 'threshold_bonus') {
            /* split: includes/monthly/types/threshold_bonus.php */ include __DIR__ . '/monthly/types/threshold_bonus.php';

        } elseif ($type === 'ranking' && $metric === 'manual') {
            /* split: includes/monthly/types/ranking_manual.php */ include __DIR__ . '/monthly/types/ranking_manual.php';

        } elseif ($type === 'ranking') {
            /* split: includes/monthly/types/ranking.php */ include __DIR__ . '/monthly/types/ranking.php';

        } elseif ($type === 'dept_share') {
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/dept_share.php */ include __DIR__ . '/monthly/types/dept_share.php';

        } elseif ($type === 'profit_pool') {
            /* split: includes/monthly/types/profit_pool.php */ include __DIR__ . '/monthly/types/profit_pool.php';

        } elseif ($type === 'perf_rank') {
            /* split: includes/monthly/types/perf_rank.php */ include __DIR__ . '/monthly/types/perf_rank.php';

        } elseif ($type === 'order_count') {
            // 主管单量提成（乔立宾 1.2 元/单）：范围内本月已审核订单中稿费（成本）> 0 的，每个订单号计 1 单（一单多写手仍计 1 单）；
            // 未在系统录单的团队（临沂、东营、合伙团队等）单量由财务每月填写。
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/order_count.php */ include __DIR__ . '/monthly/types/order_count.php';

        } elseif ($type === 'legacy_sheet') {
            // 原系统单量补贴（referral_order staff_match 精确还原，旧 SalaryCalculator::calcReferralOrder）：
            // 第一步门控：订单范围内门控列（接单客服）按逗号拆分出现本人姓名 → 整表归属本人；
            // 第二步计列（不看逐单人）：配置计数列的按该列关键词筛选 + order_no 去重；
            // 未配置计数列的按 付费旺旺+日期 去重（两列都非空才计），均排除退款（__is_refund__=1 或金额为负）。
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/legacy_sheet_1.php */ include __DIR__ . '/monthly/types/legacy_sheet_1.php';

            if ($employeeName === '') continue;
            /* split: includes/monthly/types/legacy_sheet_2.php */ include __DIR__ . '/monthly/types/legacy_sheet_2.php';

            if (!$ownsTable) {
                // 0 元行保留并写明原因（与旧系统一致），避免看起来像漏算
                $add($eid, $rule, 0, sprintf('0.00（%s无匹配%s的订单）', $gateColumn, $employeeName), true);
                continue;
            }
            /* split: includes/monthly/types/legacy_sheet_3.php */ include __DIR__ . '/monthly/types/legacy_sheet_3.php';

        } elseif ($type === 'legacy_module') {
            // 原系统单模块（直接调用旧引擎，任务十）：按员工算法配置里同名模块实时解析调用（配置改动自动跟随），
            // 配置里找不到时回退到规则参数保存的创建时快照；订单范围与 legacy_sheet 相同
            //（settle.php loadEmployeeOrdersWithDept 口径：部门共享由 config/dept_config.php 决定，与个人配置 dept_share 无关）。
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/legacy_module_1.php */ include __DIR__ . '/monthly/types/legacy_module_1.php';

            if (!$emp || trim((string)$emp['name']) === '') continue;
            /* split: includes/monthly/types/legacy_module_2.php */ include __DIR__ . '/monthly/types/legacy_module_2.php';

            if ($module === null || trim((string)($module['type'] ?? '')) === '') {
                $add($eid, $rule, 0, sprintf('0.00（算法中心未找到该员工配置的模块「%s」，请在算法中心核对模块名）', $want), true);
                continue;
            }
            /* split: includes/monthly/types/legacy_module_3.php */ include __DIR__ . '/monthly/types/legacy_module_3.php';

            if (!is_array($res) || !isset($res['amount'])) {
                $add($eid, $rule, 0, sprintf('0.00（旧引擎执行「%s」未返回结果）', trim((string)($module['name'] ?? $want))), true);
                continue;
            }
            /* split: includes/monthly/types/legacy_module_4.php */ include __DIR__ . '/monthly/types/legacy_module_4.php';

        } elseif ($type === 'fixed') {
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/fixed.php */ include __DIR__ . '/monthly/types/fixed.php';

        } elseif ($type === 'base_fee') {
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/base_fee_1.php */ include __DIR__ . '/monthly/types/base_fee_1.php';

            if ($amount <= 0) continue;
            /* split: includes/monthly/types/base_fee_2.php */ include __DIR__ . '/monthly/types/base_fee_2.php';

        } elseif ($type === 'attendance_bonus') {
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/attendance_bonus_1.php */ include __DIR__ . '/monthly/types/attendance_bonus_1.php';

            // 全勤奖默认不发：只有财务在规则中心“全勤奖审批”批准（写入本月金额）后才计入；考勤只作为审批建议。
            if ($override === null) {
                // 未批准：显示 0 元与考勤建议，让本人和财务都看得到“待财务批准”，而不是看起来漏算
                [$suggest, $suggestText] = ps_attendance_suggestion((float)($p['amount'] ?? 0), $attendance[$eid] ?? null);
                if ($forecast) {
                    if (!isset($attendance[$eid])) {
                        $suggest = (float)($p['amount'] ?? 0);
                        $suggestText = '暂无考勤记录，暂按满勤预期';
                    }
                    $add($eid, $rule, $suggest, '全勤奖预期：' . $suggestText . '；最终以财务批准为准', true);
                    continue;
                }
                $add($eid, $rule, 0, '待财务批准（本月' . $suggestText . '，建议 ¥' . money_plain($suggest) . '）', true);
                continue;
            }
            /* split: includes/monthly/types/attendance_bonus_2.php */ include __DIR__ . '/monthly/types/attendance_bonus_2.php';

        } elseif ($type === 'manual') {
            /* split: includes/monthly/types/manual.php */ include __DIR__ . '/monthly/types/manual.php';

        } elseif ($type === 'sales_package') {
            // 营业额阶梯薪酬：没有订单也照发第一档保底（按考勤折算）
            if ($rule['employee_id'] === null) continue;
            /* split: includes/monthly/types/sales_package_1.php */ include __DIR__ . '/monthly/types/sales_package_1.php';

            if (!$calc['tier']) continue;
            /* split: includes/monthly/types/sales_package_2.php */ include __DIR__ . '/monthly/types/sales_package_2.php';

        } elseif ($type === 'per_unit') {
            /* split: includes/monthly/types/per_unit.php */ include __DIR__ . '/monthly/types/per_unit.php';

        }
    }
    return array_merge($roundingItems(), $results);
}
