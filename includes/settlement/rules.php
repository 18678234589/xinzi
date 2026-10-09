<?php

/** 岗位名去掉括号说明后拆分，如“前端（技术）/后端” => ['前端','后端']。 */
function ps_role_keys($role)
{
    $keys = [];
    foreach (preg_split('/[\/、,，]+/u', (string)$role) as $part) {
        $part = trim(preg_replace('/[（(][^）)]*[）)]/u', '', $part));
        if ($part !== '') $keys[] = $part;
    }
    return $keys;
}

/**
 * 按“业务 > 岗位 > 订单类型 > 生效日期”匹配最具体的有效规则。
 * 历史“网站定制”与 AI 网站定制共用同一套版本化规则；同一请求内缓存候选规则。
 */
function ps_rule_for($group, $projectType, $orderDate, $role = '', $orderKind = '')
{
    static $cache = [];
    if ($projectType === '网站定制') $projectType = 'AI网站定制';
    $orderKind = ps_role_rule_order_kind($projectType, $group, $role, $orderKind);
    $key = $group . '|' . $projectType . '|' . $orderDate;
    if (!isset($cache[$key])) {
        $q = db()->prepare("SELECT * FROM project_commission_rules WHERE commission_group=? AND project_type IN (?, '*') AND effective_from<=? AND is_active=1 ORDER BY effective_from DESC, id DESC"
    );
        $q->execute([$group, $projectType, $orderDate]);
        $cache[$key] = $q->fetchAll();
    }
    $roles = ps_role_keys($role);
    $best = null;
    $bestScore = -1;
    foreach ($cache[$key] as $rule) {
        $ruleRole = (string)($rule['role_name'] ?? '*');
        $ruleKind = (string)($rule['order_kind'] ?? '*');
        $anyRole = in_array($ruleRole, ['*', ''], true);
        $anyKind = in_array($ruleKind, ['*', ''], true);
        if (!$anyRole && !in_array($ruleRole, $roles, true)) continue;
        if (!$anyKind && $ruleKind !== (string)$orderKind) continue;
        $score = ($rule['project_type'] === $projectType ? 4 : 0) + ($anyRole ? 0 : 2) + ($anyKind ? 0 : 1);
        if ($score > $bestScore) { $best = $rule; $bestScore = $score; } // 候选按生效日期倒序，同分取最新版本。
    }
    return $best;
}

/**
 * 为兼任多岗位的人员（如“前端/后端”、“外包前端/后端”）匹配全部适用的独立规则。
 * 若单岗位或未能匹配多规则，则返回单条规则组成的数组。
 */
function ps_rules_for_person($group, $projectType, $orderDate, $role = '', $orderKind = '')
{
    $roles = ps_role_keys($role);
    if (count($roles) <= 1) {
        $single = ps_rule_for($group, $projectType, $orderDate, $role, $orderKind);
        return $single ? [$single] : [];
    }
    $matched = [];
    $seenIds = [];
    foreach ($roles as $rKey) {
        $r = ps_rule_for($group, $projectType, $orderDate, $rKey, $orderKind);
        if ($r && !isset($seenIds[$r['id']])) {
            $seenIds[$r['id']] = true;
            $matched[] = $r;
        }
    }
    if ($matched) return $matched;
    $fallback = ps_rule_for($group, $projectType, $orderDate, $role, $orderKind);
    return $fallback ? [$fallback] : [];
}

/** 定制岗位由已分配的岗位确定算法，不能因 AI 猜成“技术服务/新订单”掉到通用 5% 档。 */
function ps_role_rule_order_kind($projectType, $group, $role, $orderKind)
{
    if ($projectType !== '小程序开发' || $orderKind === '续费') return $orderKind;
    if (in_array($orderKind, ['模板', '模板订单', '新建站'], true)) return '新订单';
    if (in_array($orderKind, ['开发定制', '定制开发'], true)) return '定制';
    $roles = ps_role_keys($role);
    if ($group === 'technical' && array_intersect($roles, ['定制技术15', '定制技术', '定制技术30'])) return '定制';
    if ($group === 'customer_service' && in_array('定制客服', $roles, true)) return '定制';
    return $orderKind;
}

function ps_rule($group, $projectType, $orderDate)
{
    return ps_rule_for($group, $projectType, $orderDate);
}

function money_plain($value)
{
    return number_format((float)$value, 2, '.', '');
}

/**
 * 单人分成计算（纯函数，与部门核算表口径一一对应）：
 * - pool 组池：max(收入 − 直接成本 − 服务费 × 组内权重, 0) × 比例 × 组内权重。
 *   单人时即 (售价 − 成本 − 3%) × 8%；两名客服合接（主次）时与核算表一致为 (售价 − 成本 − 1.5%) × 4%。
 * - individual 独立：max(收入 − 直接成本 × 分摊比例 − 服务费, 0) × 比例（定制前后端各按自己的比例算全单利润，域名/SSL 按权重分摊）。
 * 服务费 = 售价 × 规则服务费率（未设置时用业务默认）。规则设了“成本下限”时，直接成本取 max(实际成本, 售价 × 下限比例)，
 * 如客服核算博山定制单按售价 65% 计成本、华梦外包按实际 80%。售价低于规则最低售价时不计分成与补助；补助按人每单固定。
 */
function ps_calc_person($rule, $income, $directCost, $contract, $weight, $businessFeeRate, $costNote = '', $feeBase = null)
{
    $mode = ($rule['calc_mode'] ?? 'pool') === 'individual' ? 'individual' : 'pool';
    $feeRate = isset($rule['service_fee_rate']) && $rule['service_fee_rate'] !== null && $rule['service_fee_rate'] !== '' ? (float)$rule['service_fee_rate'] : (float)$businessFeeRate
    ;
    // 服务费基数：订单有退款时按“售价 − 退款”后的净额计（平台按实际成交额结算），其余仍按售价；起算售价等门槛仍看原售价。
    $feeBaseAmount = $feeBase === null ? (float)$contract : (float)$feeBase;
    $fee = round($feeBaseAmount * $feeRate, 2);
    $minCostRate = isset($rule['min_cost_rate']) && $rule['min_cost_rate'] !== null && $rule['min_cost_rate'] !== '' ? (float)$rule['min_cost_rate'] : 0.0;
    $floorApplied = $minCostRate > 0 && (float)$contract * $minCostRate > (float)$directCost;
    $cost = $floorApplied ? round((float)$contract * $minCostRate, 2) : (float)$directCost;
    $costBasis = $mode === 'individual' ? round($cost * (float)$weight, 2) : round($cost, 2);
    // 默认组池先扣整单服务费再分成。网站模板核算表另有“主次客服服务费 1.5%”约定，保留该业务明确口径。
    $feeWeight = $mode === 'pool' && ($rule['project_type'] ?? '') === '网站模板' && ($rule['commission_group'] ?? '') === 'customer_service' ? (float)$weight : 1.0;
    $feePart = round($fee * $feeWeight, 2);
    $base = round((float)$income - $costBasis - $feePart, 2);
    // 提成按未取整的服务费计算（核算表按月售价合计 × 费率），展示仍用到分的服务费；差额进“分成尾差”。
    $feeExact = $feeBaseAmount * $feeRate * $feeWeight;
    $baseExact = (float)$income - $costBasis - $feeExact;
    $rate = (float)$rule['rate'];
    $min = (float)($rule['min_contract_amount'] ?? 0);
    $blocked = $min > 0 && (float)$contract < $min;
    // allow_negative：退款冲减、亏损单按负数计入（代写/期刊按月合计口径）；否则单笔最低为 0。
    $allowNegative = !empty($rule['allow_negative']);
    $share = $blocked ? 0.0 : ($allowNegative ? $baseExact : max($baseExact, 0)) * $rate * ($mode === 'pool' ? (float)$weight : 1);
    $subsidy = $blocked ? 0.0 : round((float)($rule['per_order_subsidy'] ?? 0), 2);
    // 低利润单补助：整单利润（收入 − 成本，不扣服务费，与代写结算表一致）低于门槛时改按低档补助（如代写利润 5 元以下 1.5 元/单）。
    $lowThreshold = isset($rule['low_profit_threshold']) && $rule['low_profit_threshold'] !== null && $rule['low_profit_threshold'] !== '' ? (float)$rule['low_profit_threshold'] :
    null;
    $orderProfit = round((float)$income - (float)$cost, 2);
    $lowApplied = !$blocked && $lowThreshold !== null && $subsidy > 0 && $orderProfit < $lowThreshold;
    if ($lowApplied) $subsidy = round((float)($rule['low_profit_subsidy'] ?? 0), 2);
    $subsidyPool = $subsidy;
    $subsidy = round($subsidyPool * ($mode === 'pool' ? (float)$weight : 1), 2);
    $note = '(收入 ' . money_plain($income) . ' − 成本 ' . money_plain($costBasis) . ($costNote !== '' ? '〔' . $costNote . '〕' : '') . ($floorApplied ? '〔售价×' . round
    ($minCostRate * 100, 2) . '%〕' : '') . ($mode === 'individual' && (float)$weight < 1 ? '〔分摊 ' . round((float)$weight * 100, 2) . '%〕' : '') . ' − 服务费 ' . money_plain
    ($feePart) . ') × ' . round($rate * 100, 4) . '%' . ($mode === 'pool' && (float)$weight < 1 ? ' × 权重 ' . round((float)$weight * 100, 2) . '%' : '');
    if ($subsidy > 0) $note .= ' + 每单补助 ' . money_plain($subsidy) . ($mode === 'pool' && (float)$weight < 1 ? '（整单补助 ' . money_plain($subsidyPool) . ' × 权重 '
    . round((float)$weight * 100, 2) . '%）' : '') . ($lowApplied ? '（售价 − 成本 ' . money_plain($orderProfit) . ' 低于 ' . money_plain($lowThreshold) . '）' : '');
    if ($blocked) $note = '售价低于 ¥' . money_plain($min) . '，本单不计分成';
    return ['mode' => $mode, 'fee_rate' => $feeRate, 'fee' => $fee, 'fee_part' => $feePart, 'cost_basis' => $costBasis, 'base' => $base, 'rate' => $rate, 'weight' => (float)$weight
    , 'share' => $share, 'subsidy' => $subsidy, 'blocked' => $blocked, 'note' => $note,
        // 计算过程弹窗用：把每一步的输入原样带出
        'income' => round((float)$income, 2), 'contract' => round((float)$contract, 2), 'raw_cost' => round((float)$directCost, 2), 'min_cost_rate' => $minCostRate, 'floor_applied'
    => $floorApplied,
        'min_contract' => $min, 'allow_negative' => $allowNegative, 'low_applied' => $lowApplied, 'low_threshold' => $lowThreshold, 'order_profit' => $orderProfit, 'income_estimated'
    => false, 'subsidy_pool' => $subsidyPool];
}

/**
 * 商标资料专员 / 提交专员按件计：每单补助（规则里的每件单价）× 商标个数。
 * 原表“商标个数”留空的行（如 10 元小额单）不计件，与部门核算表合计口径一致。
 */
function ps_trademark_piece_calc($calc, $count)
{
    if (!$calc || $calc['blocked'] || $calc['subsidy'] <= 0) return $calc;
    // 每件单价取规则的整单补助（不乘组权重）：资料专员、提交专员各自按自己经手的商标件数全额计，不因岗位人数被均分（一件 = 2.2，而不是 1.1）
    $unit = (float)($calc['subsidy_pool'] ?? $calc['subsidy']);
    $pieces = $count === null ? 0.0 : (float)$count;
    $calc['subsidy'] = round($unit * $pieces, 2);
    $calc['subsidy_pool'] = $calc['subsidy'];
    if (isset($calc['mode'])) $calc['mode'] = 'individual'; // 补助不再按权重在组内分摊
    $calc['note'] .= $pieces > 0 ? '（每件 × 商标 ' . rtrim(rtrim(number_format($pieces, 2, '.', ''), '0'), '.') . ' 件 = ' . money_plain($calc['subsidy']) . '）' : '（未填商标个数，不计件）'
    ;
    return $calc;
}
