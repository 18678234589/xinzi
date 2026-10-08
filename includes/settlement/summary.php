<?php

function ps_summary($order, $costs, $participants)
{
    $order = ps_order_asof($order);
    $income = round((float)$order['receipt_amount'] - (float)$order['refund_amount'], 2);
    $approvedCost = 0.0;
    $pendingCost = 0.0;
    foreach ($costs as $cost) {
        if ($cost['review_status'] === 'approved') $approvedCost += (float)$cost['amount'];
        if ($cost['review_status'] === 'pending') $pendingCost += (float)$cost['amount'];
    }
    $approvedCost = round($approvedCost, 2);
    $pendingCost = round($pendingCost, 2);
    // PHPweb 程序：个人提成的成本按《PHPweb程序成本区间表》随售价与岗位调整（订单毛利仍按实际成本）。
    $phpApproved = 0.0; $phpAll = 0.0;
    foreach ($costs as $cost) {
        if (!ps_is_php_cost($cost)) continue;
        if ($cost['review_status'] === 'approved') $phpApproved += (float)$cost['amount'];
        if (in_array($cost['review_status'], ['approved', 'pending'], true)) $phpAll += (float)$cost['amount'];
    }
    $personCost = function ($group, $role, $withPending) use ($phpApproved, $phpAll, $approvedCost, $pendingCost, $order) {
        $base = $withPending ? $approvedCost + $pendingCost : $approvedCost;
        $php = $withPending ? $phpAll : $phpApproved;
        if ($php <= 0) return [$base, ''];
        [$adjusted, $note] = ps_php_cost_for((float)($order['contract_amount'] ?? 0), $group, $role, $php);
        return [round($base - $php + $adjusted, 2), $note];
    };
    $contract = (float)($order['contract_amount'] ?? 0);
    // 预计分成：尚未录入任何收款时，收入按售价（扣退款）预估，便于财务提前看到大概金额；已核算的分成仍按实收。
    $estimatedByContract = (float)$order['receipt_amount'] <= 0 && $contract > 0;
    $estIncome = $estimatedByContract ? max(round($contract - (float)$order['refund_amount'], 2), 0.0) : $income;
    $estMark = function ($calc) use ($estimatedByContract) {
        if ($calc && $estimatedByContract) { $calc['note'] .= '〔收入按售价预估，尚未录入收款〕'; $calc['income_estimated'] = true; }
        return $calc;
    };
    $orderKind = (string)($order['order_kind'] ?? '');
    // 业务默认店铺服务费按售价计（网站模板/环境配置/小程序 3%），AI 定制默认不扣；分成规则可按组或岗位覆盖。
    $businessFeeRate = ps_business_service_fee_rate($order['project_type']);
    $feeBase = (float)$order['refund_amount'] > 0 ? max(round($contract - (float)$order['refund_amount'], 2), 0.0) : $contract; // 有退款时服务费按退款后的净额计
    $serviceFee = round($feeBase * $businessFeeRate, 2);
    $trademarkCount = null;
    if ($order['project_type'] === '商标') {
        static $orderDetailStmt = null;
        if ($orderDetailStmt === null) {
            $orderDetailStmt = db()->prepare('SELECT details_json FROM project_order_details WHERE order_id=?');
        }
        $orderDetailStmt->execute([(int)$order['id']]);
        $detailsRaw = $orderDetailStmt->fetchColumn();
        if ($detailsRaw) {
            $detailsJson = json_decode($detailsRaw, true);
            if (isset($detailsJson['trademark_count']) && is_numeric($detailsJson['trademark_count']) && (float)$detailsJson['trademark_count'] >= 0) {
                $trademarkCount = (float)$detailsJson['trademark_count'];
            }
        }
    }
    $groups = [];
    foreach (['technical', 'customer_service'] as $group) {
        $people = array_values(array_filter($participants, function ($p) use ($group) { return $p['commission_group'] === $group; }));
        $defaultRule = ps_rule_for($group, $order['project_type'], $order['order_date'], '', $orderKind);
        $weight = array_sum(array_map(function ($p) { return (float)$p['group_weight']; }, $people));
        $pool = 0.0; $estimatedPool = 0.0; $subsidy = 0.0; $missing = false; $subsidyRule = false;
        foreach ($people as $i => $person) {
            $rules = ps_rules_for_person($group, $order['project_type'], $order['order_date'], $person['role_name'] ?? '', $orderKind);
            $rule = $rules ? $rules[0] : null;
            $people[$i]['rule'] = $rule;
            $people[$i]['rules'] = $rules;
            if ($rule && (float)$rule['per_order_subsidy'] > 0) $subsidyRule = true; // 规则本身配置了每单补助（与限定名单是否实际发放无关）

            if (count($rules) > 1) {
                // 兼任多岗位（如前端/后端、外包前端/后端、模板技术/资料员）：按子岗位分别计算并汇总
                $subWeight = (float)$person['group_weight'] / count($rules);
                $subCalcs = [];
                $subCalcsEst = [];
                $subShareSum = 0.0;
                $subSubsidySum = 0.0;
                $subEstShareSum = 0.0;
                $subEstSubsidySum = 0.0;
                $feeSum = 0.0;
                $feePartSum = 0.0;
                $costBasisSum = 0.0;
                $baseSum = 0.0;
                $rateSum = 0.0;
                $subNotes = [];
                $subEstNotes = [];

                foreach ($rules as $mRule) {
                    $mRole = $mRule['role_name'] ?: '协作';
                    [$cNow, $nNow] = $personCost($group, $mRole, false);
                    [$cEst, $nEst] = $personCost($group, $mRole, true);
                    $sc = ps_calc_person($mRule, $income, $cNow, $contract, $subWeight, $businessFeeRate, $nNow, $feeBase);
                    $scEst = $estMark(ps_calc_person($mRule, $estIncome, $cEst, $contract, $subWeight, $businessFeeRate, $nEst, $feeBase));

                    $mSubsidyOnly = array_filter(array_map('intval', preg_split('/[^\d]+/', (string)($mRule['subsidy_employee_ids'] ?? ''))));
                    if ($mSubsidyOnly && !in_array((int)($person['employee_id'] ?? 0), $mSubsidyOnly, true)) {
                        $sc['subsidy'] = 0.0; $sc['subsidy_pool'] = 0.0; $sc['note'] = preg_replace('/\s*\+ 每单补助.*$/u', '', (string)$sc['note']);
                        $scEst['subsidy'] = 0.0; $scEst['subsidy_pool'] = 0.0; $scEst['note'] = preg_replace('/\s*\+ 每单补助.*$/u', '', (string)$scEst['note']);
                    }

                    $subCalcs[] = ['role' => $mRole, 'rule' => $mRule, 'calc' => $sc];
                    $subCalcsEst[] = ['role' => $mRole, 'rule' => $mRule, 'calc' => $scEst];

                    $subShareSum += $sc['share'];
                    $subSubsidySum += $sc['subsidy'];
                    $subEstShareSum += $scEst['share'];
                    $subEstSubsidySum += $scEst['subsidy'];

                    $feeSum += $sc['fee'];
                    $feePartSum += $sc['fee_part'];
                    $costBasisSum += $sc['cost_basis'];
                    $baseSum += $sc['base'];
                    $rateSum += (float)$mRule['rate'];

                    $subNotes[] = $mRole . '提成 ¥' . money_plain($sc['share']) . ' (' . round((float)$mRule['rate'] * 100, 2) . '%)';
                    $subEstNotes[] = $mRole . '预估 ¥' . money_plain($scEst['share']) . ' (' . round((float)$mRule['rate'] * 100, 2) . '%)';
                }

                $people[$i]['calc'] = array_merge($subCalcs[0]['calc'], [
                    'mode' => 'individual',
                    'fee' => round($feeSum, 2),
                    'fee_part' => round($feePartSum, 2),
                    'cost_basis' => round($costBasisSum, 2),
                    'base' => round($baseSum, 2),
                    'rate' => $rateSum,
                    'share' => round($subShareSum, 2),
                    'subsidy' => round($subSubsidySum, 2),
                    'blocked' => false,
                    'note' => implode(' + ', $subNotes) . '：合计提成 ¥' . money_plain($subShareSum) . ($subSubsidySum > 0 ? '（含补助 ¥' . money_plain($subSubsidySum) . '）' : ''),
                    'sub_calcs' => $subCalcs
                ]);

                $people[$i]['estimated_calc'] = array_merge($subCalcsEst[0]['calc'], [
                    'mode' => 'individual',
                    'fee' => round($feeSum, 2),
                    'fee_part' => round($feePartSum, 2),
                    'cost_basis' => round($costBasisSum, 2),
                    'base' => round($baseSum, 2),
                    'rate' => $rateSum,
                    'share' => round($subEstShareSum, 2),
                    'subsidy' => round($subEstSubsidySum, 2),
                    'blocked' => false,
                    'note' => implode(' + ', $subEstNotes) . '：合计预估 ¥' . money_plain($subEstShareSum) . ($subEstSubsidySum > 0 ? '（含补助 ¥' . money_plain($subEstSubsidySum) . '）' : ''),
                    'sub_calcs' => $subCalcsEst
                ]);
            } else {
                [$costNow, $noteNow] = $personCost($group, $person['role_name'] ?? '', false);
                [$costEst, $noteEst] = $personCost($group, $person['role_name'] ?? '', true);
                $people[$i]['calc'] = $rule ? ps_calc_person($rule, $income, $costNow, $contract, $person['group_weight'], $businessFeeRate, $noteNow, $feeBase) : null;
                $people[$i]['estimated_calc'] = $rule ? $estMark(ps_calc_person($rule, $estIncome, $costEst, $contract, $person['group_weight'], $businessFeeRate, $noteEst, $feeBase)) : null;

                // 规则限定“每单补助只发给指定员工”（subsidy_employee_ids，逗号分隔，留空 = 所有参与人）：不在名单内则取消补助。
                $subsidyOnly = array_filter(array_map('intval', preg_split('/[^\d]+/', (string)($rule['subsidy_employee_ids'] ?? ''))));
                if ($rule && $subsidyOnly && !in_array((int)($person['employee_id'] ?? 0), $subsidyOnly, true)) {
                    foreach (['calc', 'estimated_calc'] as $ck) {
                        if (!$people[$i][$ck]) continue;
                        $people[$i][$ck]['subsidy'] = 0.0;
                        $people[$i][$ck]['subsidy_pool'] = 0.0;
                        $people[$i][$ck]['note'] = preg_replace('/\s*\+ 每单补助.*$/u', '', (string)$people[$i][$ck]['note']);
                    }
                }
            }
            if ($order['project_type'] === '商标' && $group === 'technical') {
                $people[$i]['calc'] = ps_trademark_piece_calc($people[$i]['calc'], $trademarkCount);
                $people[$i]['estimated_calc'] = ps_trademark_piece_calc($people[$i]['estimated_calc'], $trademarkCount);
            }
            if (!$rule) { $missing = true; continue; }
            $pool += $people[$i]['calc']['share'];
            $estimatedPool += $people[$i]['estimated_calc']['share'];
            $subsidy += $people[$i]['calc']['subsidy'];
        }
        // 补助也是组池的一部分；用分为单位分摊尾差，人数增加不能重复发整单补助。
        foreach (['calc', 'estimated_calc'] as $ck) {
            $subsidyCents = ps_group_subsidy_cents($people, $ck);
            $forAllocation = array_map(function ($p) use ($ck) { $p['calc'] = $p[$ck]; return $p; }, $people);
            $shareCents = ps_group_share_cents($forAllocation);
            foreach ($people as $i => $person) if ($people[$i][$ck]) {
                $people[$i][$ck]['share_exact'] = $people[$i][$ck]['share'];
                $people[$i][$ck]['share'] = $shareCents[$i] / 100;
                $people[$i][$ck]['subsidy'] = $subsidyCents[$i] / 100;
            }
        }
        $pool = array_sum(array_map(function ($p) { return (float)($p['calc']['share'] ?? 0); }, $people));
        $estimatedPool = array_sum(array_map(function ($p) { return (float)($p['estimated_calc']['share'] ?? 0); }, $people));
        $subsidy = array_sum(array_map(function ($p) { return (float)($p['calc']['subsidy'] ?? 0); }, $people));
        if (!$people) {
            // 无参与人时仍显示该组按默认规则可形成的分成池，供财务预估。
            [$costNow, $noteNow] = $personCost($group, '', false);
            [$costEst, $noteEst] = $personCost($group, '', true);
            $calc = $defaultRule ? ps_calc_person($defaultRule, $income, $costNow, $contract, 1, $businessFeeRate, $noteNow, $feeBase) : null;
            $estimated = $defaultRule ? $estMark(ps_calc_person($defaultRule, $estIncome, $costEst, $contract, 1, $businessFeeRate, $noteEst, $feeBase)) : null;
            if ($order['project_type'] === '商标' && $group === 'technical') {
                $calc = ps_trademark_piece_calc($calc, $trademarkCount);
                $estimated = ps_trademark_piece_calc($estimated, $trademarkCount);
            }
            $pool = $calc ? $calc['share'] : null;
            $estimatedPool = $estimated ? $estimated['share'] : null;
        }
        $rule = $missing ? null : ($defaultRule ?: ($people[0]['rule'] ?? null));
        if ($defaultRule && (float)$defaultRule['per_order_subsidy'] > 0) $subsidyRule = true;
        $groups[$group] = ['people' => $people, 'rule' => $rule, 'weight' => $weight, 'rate' => $rule ? (float)$rule['rate'] : null, 'missing_rule' => $missing, 'subsidy' => round($subsidy, 2), 'subsidy_rule' => $subsidyRule ? 1 : 0,
            'pool' => $missing || $pool === null ? null : round($pool, 2), 'estimated_pool' => $missing || $estimatedPool === null ? null : round($estimatedPool, 2)];
    }
    return ['income' => $income, 'direct_cost' => $approvedCost, 'approved_cost' => round($approvedCost + $serviceFee, 2), 'service_fee' => $serviceFee, 'service_fee_rate' => $businessFeeRate, 'pending_cost' => $pendingCost,
        'profit' => round($income - $approvedCost - $serviceFee, 2), 'estimated_profit' => round($income - $approvedCost - $serviceFee - $pendingCost, 2), 'groups' => $groups];
}

/**
 * 一组参与人的分成（不含补助）换算为分：组池模式同一规则的子组整体四舍五入后按权重分摊，尾差归最高权重者；
 * 独立模式逐人四舍五入。返回与 $people 下标一致的分值。
 */
function ps_group_share_cents($people)
{
    $cents = [];
    $poolGroups = [];
    foreach ($people as $i => $person) {
        $cents[$i] = 0;
        if (empty($person['calc'])) continue;
        // 计提基数和比例相同的组池整体分摊；岗位别名命中不同但等价的规则时也只计一个池。
        if ($person['calc']['mode'] === 'pool' && !$person['calc']['blocked']) $poolGroups[$person['calc']['rate'] . '|' . (int)!empty($person['rule']['allow_negative']) . '|' . money_plain($person['calc']['base'])][$i] = $person;
        else $cents[$i] = (int)round($person['calc']['share'] * 100);
    }
    foreach ($poolGroups as $members) {
        $first = reset($members);
        $weightSum = array_sum(array_map(function ($p) { return (float)$p['group_weight']; }, $members));
        $subPoolCents = (int)round((!empty($first['rule']['allow_negative']) ? $first['calc']['base'] : max($first['calc']['base'], 0)) * $first['calc']['rate'] * $weightSum * 100);
        $normalized = array_map(function ($p) use ($weightSum) { return ['group_weight' => $weightSum > 0 ? (float)$p['group_weight'] / $weightSum : 0]; }, array_values($members));
        $shares = ps_allocate_pool_cents($subPoolCents, $normalized);
        foreach (array_keys($members) as $n => $i) $cents[$i] = $shares[$n];
    }
    return $cents;
}

function ps_allocate_pool_cents($poolCents, $people)
{
    if (!$people) return [];
    $shares = [];
    $highestIndex = 0;
    foreach ($people as $i => $person) {
        $shares[$i] = (int)round($poolCents * (float)$person['group_weight']);
        if ((float)$person['group_weight'] > (float)$people[$highestIndex]['group_weight']) $highestIndex = $i;
    }
    $shares[$highestIndex] += $poolCents - array_sum($shares);
    return $shares;
}

/** 同一组池规则的按单补助仅发一池，独立岗位规则保持原口径。 */
function ps_group_subsidy_cents($people, $calcKey = 'calc')
{
    $cents = []; $pools = [];
    foreach ($people as $i => $p) {
        $c = $p[$calcKey] ?? null;
        $cents[$i] = (int)round((float)($c['subsidy'] ?? 0) * 100);
        if (!$c || $c['mode'] !== 'pool' || $c['blocked'] || empty($c['subsidy_pool'])) continue;
        $pools[money_plain($c['subsidy_pool'])][$i] = $p;
    }
    foreach ($pools as $members) {
        $first = reset($members); $weight = array_sum(array_column($members, 'group_weight'));
        $total = (int)round($first[$calcKey]['subsidy_pool'] * $weight * 100);
        $normalized = array_map(function ($p) use ($weight) { return ['group_weight' => $weight > 0 ? $p['group_weight'] / $weight : 0]; }, array_values($members));
        $shares = ps_allocate_pool_cents($total, $normalized);
        foreach (array_keys($members) as $n => $i) $cents[$i] = $shares[$n];
    }
    return $cents;
}

function ps_settlement_preview($legacyNetAmount, $projectCommission, $legacyTechnicalDeduction = 0, $technicalReconciled = true)
{
    if ($legacyNetAmount === null || !$technicalReconciled) return null;
    $legacyCents = (int)round((float)$legacyNetAmount * 100);
    $projectCents = (int)round((float)$projectCommission * 100);
    $deductionCents = (int)round((float)$legacyTechnicalDeduction * 100);
    return ($legacyCents - $deductionCents + $projectCents) / 100;
}

function ps_technical_reconciliation_summary($rows)
{
    $pending = 0;
    $deductionCents = 0;
    foreach ($rows as $row) {
        if (($row['commission_group'] ?? '') !== 'technical') continue;
        if (!isset($row['legacy_amount'])) { $pending++; continue; }
        $deductionCents += (int)round((float)$row['legacy_amount'] * 100);
    }
    return ['pending' => $pending, 'deduction' => $deductionCents / 100];
}

/*
 * 私有文件（付款凭证、原始上传表格）：存放在站点目录内 storage/private/<类别>/。
 * 线上 PHP 的 open_basedir 只允许站点目录和 /tmp，不能写到站点目录外；为防止被直接下载，
 * 每个文件都存成 .php、以“返回 404 并退出”的 PHP 代码开头——即使路径被猜到，Web 服务器也只会执行它返回 404。
 */
const PS_PRIVATE_GUARD = "<?php http_response_code(404); exit; ?>\n"; // 用转义写，不受源码换行符（LF / CRLF）影响
