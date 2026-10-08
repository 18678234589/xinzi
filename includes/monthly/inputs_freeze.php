<?php

function ps_monthly_freeze($month)
{
    $results = ps_monthly_results($month, true);
    db()->prepare('DELETE FROM project_monthly_results WHERE payroll_month=?')->execute([$month]);
    $insert = db()->prepare('INSERT INTO project_monthly_results (payroll_month,rule_id,rule_name,rule_type,employee_id,amount,paid_separately,detail) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($results as $row) $insert->execute([$month, $row['rule_id'], $row['rule_name'], $row['rule_type'], $row['employee_id'], $row['amount'], $row['paid_separately'], $row['detail'
    ]]);
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
            $tiers[] = ['from' => $num($from, '档位起点'), 'rate' => $num($input['tier_rate'][$i] ?? '', '档位比例') / 100, 'base' => trim((string)($input['tier_base'][$i]
    ?? ''))];
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
    if ($type === 'dept_share') return ['rate' => $num($input['rate'] ?? '', '比例') / 100, 'share' => $num($input['share'] ?? '100', '分配比例') / 100, 'base' => ($input['dept_base'
    ] ?? '') === 'revenue' ? 'revenue' : 'profit', 'deduct_commissions' => !empty($input['deduct_commissions'])];
    if ($type === 'fixed') return ['amount' => $num($input['amount'] ?? '', '金额'), 'separate' => !empty($input['separate'])];
    if ($type === 'base_fee') return ['amount' => $num($input['amount'] ?? '0', '金额'), 'no_prorate' => !empty($input['no_prorate'])];
    if ($type === 'per_unit' || $type === 'attendance_bonus' || $type === 'order_count') return ['amount' => $num($input['amount'] ?? '0', '金额')];
    if ($type === 'sales_package') {
        // 每行“营业额上限,底薪,比例%,≥门槛每单,<门槛每单”，如 6000,2100,5,2,0.5
        $tiers = [];
        foreach (array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($input['package_tiers'] ?? ''))), 'strlen') as $line) {
            $cells = array_map('trim', preg_split('/[,，\s]+/u', $line));
            if (count($cells) !== 5) throw new RuntimeException('阶梯每行填 5 个数：营业额上限,底薪,比例%,≥门槛每单,<门槛每单');
            $tiers[] = ['upto' => $num($cells[0], '营业额上限'), 'base' => $num($cells[1], '底薪'), 'rate' => $num($cells[2], '比例') / 100, 'big' => $num($cells[3], '大单每单金额'
    ), 'small' => $num($cells[4], '小单每单金额')];
        }
        if (!$tiers) throw new RuntimeException('请至少填写一档');
        usort($tiers, function ($a, $b) { return $a['upto'] <=> $b['upto']; });
        return ['tiers' => $tiers, 'big_threshold' => $num($input['big_threshold'] ?? '50', '大单门槛'), 'returning_rate' => $num($input['returning_rate'] ?? '0', '老客户找回比例'
    ) / 100, 'review_min' => $num($input['review_min'] ?? '0', '好评率门槛'), 'review_penalty' => $num($input['review_penalty'] ?? '0', '好评率罚款')];
    }
    if ($type === 'legacy_sheet') {
        // 计列每行 5 项：名称,计数列(空=按 付费旺旺+日期 去重),关键词(+分隔),匹配(any=任一/all=全部),每单金额
        $counters = [];
        foreach (array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($input['legacy_counters'] ?? ''))), 'strlen') as $line) {
            $cells = array_map('trim', preg_split('/[,，]/u', $line));
            if (count($cells) < 5) throw new RuntimeException('计列每行填 5 项：名称,计数列,关键词,匹配(any|all),每单金额，如 拍建站链接,拍建站,网站链接+小程序链接,any,0.5'
    );
            $counters[] = ['name' => $cells[0], 'column' => $cells[1], 'keywords' => $cells[2], 'match' => $cells[3] === 'any' ? 'any' : 'all', 'unit' => $num($cells[4], '每单金额'
    )];
        }
        if (!$counters) throw new RuntimeException('请至少填写一条计列');
        return ['dept' => trim((string)($input['legacy_dept'] ?? '')), 'gate_column' => trim((string)($input['legacy_gate'] ?? '')) ?: '接单客服', 'counters' => $counters];
    }
    if ($type === 'legacy_module') {
        // 原系统单模块：旧配置模块名必填（运行时按该员工 algorithms/config_<id>.json 的同名模块实时计算）；
        // 可选粘贴模块 JSON 快照（{"type":...,"name":...,"config":{...}}）作为配置缺失时的兜底与列表展示。
        $moduleName = trim((string)($input['legacy_module_name'] ?? ''));
        if ($moduleName === '') throw new RuntimeException('请填写旧配置模块名（与算法中心里该员工配置的模块名称一致）');
        $module = null;
        $raw = trim((string)($input['legacy_module'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || trim((string)($decoded['type'] ?? '')) === '') {
                throw new RuntimeException('模块配置快照必须是包含 type 的模块 JSON，如 {"type":"per_order","name":"每笔订单奖励5元","config":{...}}');
            }
            $module = [
                'name'   => trim((string)($decoded['name'] ?? '')) ?: $moduleName,
                'type'   => trim((string)$decoded['type']),
                'config' => is_array($decoded['config'] ?? null) ? $decoded['config'] : [],
            ];
        }
        return ['module_name' => $moduleName, 'module' => $module];
    }
    if ($type === 'manual' || $type === 'perf_rank') return [];
    if ($type === 'profit_pool') {
        // 固定分成人员：每行“姓名=13%”，按姓名匹配合作人员（重名时取有项目账号者）
        $fixed = [];
        foreach (array_filter(array_map('trim', preg_split('/[\r\n,，;；]+/u', (string)($input['pool_fixed'] ?? ''))), 'strlen') as $line) {
            if (!preg_match('/^(.+?)\s*[=＝:：]\s*([\d.]+)\s*%?$/u', $line, $m)) throw new RuntimeException('固定分成人员请按“姓名=13%”填写，每行一个');
            $ids = db()->prepare('SELECT e.id FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id AND u.is_active=1 WHERE e.name=? ORDER BY u.id IS NULL, e.id');
            $ids->execute([trim($m[1])]);
            $id = $ids->fetchColumn();
            if (!$id) throw new RuntimeException('找不到合作人员“' . trim($m[1]) . '”');
            $fixed[] = ['employee_id' => (int)$id, 'name' => trim($m[1]), 'share' => round((float)$m[2] / 100, 6)];
        }
        $params = ['deduction' => $num($input['deduction'] ?? '0', '扣除额'), 'rate' => $num($input['rate'] ?? '', '比例') / 100, 'members_share' => $num($input['members_share'
    ] ?? '', '成员分配比例') / 100, 'fixed' => $fixed];
        // 利润奖励（可选）：部门总利润超过起点后每增加一档，每人奖固定金额，封顶
        if (trim((string)($input['milestone_from'] ?? '')) !== '') $params['milestone'] = ['from' => $num($input['milestone_from'], '利润奖励起点'), 'step' => $num($input['milestone_step'
    ] ?? '', '每档利润'), 'amount' => $num($input['milestone_amount'] ?? '', '每档每人奖励'), 'cap' => $num($input['milestone_cap'] ?? '', '每人封顶')];
        return $params;
    }
    throw new RuntimeException('规则类型无效');
}
