<?php
/** 商标办理事项识别与报价。网报方式、商标名称、付款流水不是办理事项；不能默认按注册收费。 */
function ptc_service_names($text)
{
    $text = preg_replace('/[\s\x{3000}（）()：:]+/u', '', (string)$text);
    // 注册号 / 注册日期是商标资料，不是一次注册业务。
    $text = preg_replace('/注册(?:号|日期|时间|地址|信息)|已注册(?:商标)?/u', '', $text);
    $text = preg_replace('/(?:未|非|不|无|没有)(?:超期|逾期|过期|宽展|超时)/u', '', $text);
    $lateRenewal = mb_strpos($text, '续展') !== false && preg_match('/超期|逾期|过期|宽展|宽限期|超时|延期|过(?:了)?有效期/u', $text);
    $patterns = [
        '超期续展' => '/(?:超期|逾期|过期|宽展|宽限期|宽展期)(?:商标)?续展|续展(?:超期|逾期|过期|宽展|宽限期)|宽展期/u',
        '补证' => '/补证|补发(?:商标)?注册证|补办(?:商标)?注册证/u',
        '许可备案' => '/(?:使用)?许可(?:使用|合同)?(?:备案)?|商标授权备案/u',
        '成品商标成本' => '/成品商标|购买商标|商标购买/u',
        '国际商标成本' => '/国际商标|马德里|海外商标|国外商标/u',
        '法务外包成本' => '/法务|诉讼|异议|无效宣告|撤三|驳回复审/u',
    ];
    $found = []; $directLate = false;
    foreach ($patterns as $name => $pattern) if (preg_match($pattern, $text)) {
        $found[] = $name;
        if ($name === '超期续展') $directLate = true;
        $text = preg_replace($pattern, '', $text);
    }
    foreach (['注册', '转让', '续展', '变更', '注销', '撤回', '更正'] as $name) {
        if (mb_strpos($text, $name) !== false) $found[] = $name;
    }
    if ($lateRenewal && !$directLate) { $found = array_values(array_diff($found, ['续展'])); $found[] = '超期续展'; }
    if (in_array('国际商标成本', $found, true)) $found = array_values(array_diff($found, ['注册']));
    return array_values(array_unique($found));
}

function ptc_find_service(array $templates, $name)
{
    $matches = array_values(array_filter($templates, function ($t) use ($name) {
        return in_array(ptc_kind($t), ['service', 'variable'], true) && ptc_keyword($t) === $name;
    }));
    // 同名的启用模板不应靠数据库顺序挑一个价格。
    return count($matches) === 1 ? $matches[0] : null;
}

/** 混合订单只有逐项件数明确且合计一致才自动计算；不能取最长项目套到全部件数。 */
function ptc_mixed_plan(array $templates, array $details, $text, array $names, $excelCost)
{
    $fail = function ($message) { return ['status'=>'unresolved','lines'=>[],'message'=>$message]; };
    $text = preg_replace('/\s+/u', '', (string)$text);
    $text = str_replace(['逾期续展','过期续展','宽展期续展','宽展续展','补发注册证','许可合同备案','许可使用备案'], ['超期续展','超期续展','超期续展','超期续展','补证','许可备案','许可备案'], $text);
    $pattern = '(超期续展|许可备案|补证|注册|转让|续展|变更|注销|撤回|更正)';
    preg_match_all('/'.$pattern.'(\d+)(?![\d.])(?:件|个|类)?/u', $text, $matches, PREG_SET_ORDER);
    preg_match_all('/(?<![\d.])(\d+)(?![\d.])(?:件|个|类)'.$pattern.'/u', $text, $before, PREG_SET_ORDER);
    foreach ($before as $m) $matches[] = [$m[0],$m[2],$m[1]];
    $counts = [];
    foreach ($matches as $m) $counts[$m[1]][(int)$m[2]] = true;
    foreach ($names as $name) if (empty($counts[$name]) || count($counts[$name]) !== 1) return $fail('混合商标事项须逐项写清整数件数（如注册2、超期续展3），无法明确时请拆行或由财务逐项录入');
    $sum = 0; $lines = []; $labels = [];
    foreach ($names as $name) {
        $quantity = (int)array_keys($counts[$name])[0];
        if ($quantity < 1 || $quantity > 1000) return $fail('混合商标事项的每项件数须为1–1000的整数');
        $sub = ptc_cost_plan($templates, ['trademark_service'=>$name,'trademark_count'=>(string)$quantity,'trademark_extra_count'=>$name==='注册'?($details['trademark_extra_count']??''):'']);
        if ($sub['status'] !== 'ready') return $sub;
        $sum += $quantity; $lines = array_merge($lines,$sub['lines']); $labels[]=$name.$quantity;
    }
    $count = trim((string)($details['trademark_count']??''));
    if ($sum > 1000) return $fail('混合商标事项总件数不能超过1000');
    if ($count !== '' && (!is_numeric($count) || (float)$count !== (float)$sum)) return $fail('混合事项逐项件数合计'.$sum.'与商标总件数不一致，请核对');
    if (preg_match('/多选|附加项目/u',$text) && empty($details['trademark_extra_count'])) return $fail('混合订单含注册多选项目，请填写整单多选项目总数');
    $total = round(array_sum(array_column($lines,'amount')),2);
    if ($excelCost !== null && $excelCost !== '' && (!is_numeric($excelCost)||(float)$excelCost<0)) return $fail('商标实际成本金额无效');
    $excel = round((float)$excelCost,2);
    if ($excel > $total) $lines[]=['template'=>null,'quantity'=>1,'amount'=>round($excel-$total,2),'status'=>'pending'];
    return ['status'=>'ready','service'=>implode('+',$labels),'count'=>$sum,'lines'=>$lines,'total'=>max($total,$excel),'message'=>implode(' + ',$labels).'，逐项标准成本 ¥'.money_plain($total).($excel>$total?'，实际成本差额待财务审核':'')];
}

/** 返回明确的报价或需核对原因；额外项目数是整单总数，不再乘商标件数。 */
function ptc_cost_plan(array $templates, array $details, $context = '', $excelCost = null)
{
    $explicit = trim((string)($details['trademark_service'] ?? ''));
    $primary = $explicit !== '' ? $explicit : (ptc_service_names($details['service_type']??'') ? (string)$details['service_type'] : '');
    $contextNames = ptc_service_names(($details['service_type'] ?? '') . ' ' . $context);
    $names = ptc_service_names($primary !== '' ? $primary : implode(' ', [
        $details['service_type'] ?? '', $context, $details['trademark_name'] ?? ''
    ]));
    $fail = function ($message) { return ['status' => 'unresolved', 'lines' => [], 'message' => $message]; };
    // 泛称“续展”的备注涵盖超期续展；只有明确另外列出普通续展数量时才算另一项。
    if (in_array('超期续展',$names,true) && !in_array('续展',$names,true) && !preg_match('/(?<!超期)(?<!逾期)(?<!过期)(?<!宽展)续展\s*\d/u',($details['service_type']??'').' '.$context)) $contextNames=array_values(array_diff($contextNames,['续展']));
    if ($primary !== '' && $contextNames && array_diff($contextNames, $names)) {
        if (count($names) === 1 && count($contextNames) === 1 && !array_diff(array_merge($names, $contextNames), ['续展', '超期续展'])) $names = ['超期续展'];
        else return $fail('办理事项与网报类型/备注中的事项不一致，请核对后选择真实事项，不能套用较低成本');
    }
    if (count($names) > 1) {
        $breakdown = $primary !== '' ? $primary : $context;
        return ptc_mixed_plan($templates,$details,$breakdown,$names,$excelCost);
    }
    if (!$names) return $fail('无法识别商标办理事项，请选择注册、转让、续展、超期续展等事项；网报方式不能用于确定官费');
    $name = $names[0];
    $template = ptc_find_service($templates, $name);
    if (!$template) return $fail('商标办理事项“' . $name . '”没有唯一的启用成本模板，请财务核对成本中心');
    $excel = $excelCost === null || $excelCost === '' ? null : (is_numeric($excelCost) && (float)$excelCost >= 0 ? round((float)$excelCost, 2) : false);
    if ($excel === false) return $fail('商标实际成本金额无效');
    if (ptc_kind($template) === 'variable') {
        if ((float)$template['price'] !== 1.0) return $fail('实际金额成本模板单价必须为1元，请财务先核对成本中心');
        if ($excel === null || $excel <= 0) return $fail($name . '须填写询价后的实际成本，不能按注册或其它标准官费计算');
        return ['status' => 'ready', 'service' => $name, 'lines' => [['template' => $template, 'quantity' => $excel, 'amount' => $excel, 'status' => 'pending']], 'total' => $excel, 'message' => $name . '：实际成本 ¥' . money_plain($excel) . '，待财务审核'];
    }
    $count = trim((string)($details['trademark_count'] ?? ''));
    if ($count === '') return ['status' => 'incomplete', 'lines' => [], 'service' => $name, 'message' => '请补填商标件数，成本尚未核实'];
    if (!is_numeric($count) || (float)$count < 1 || (float)$count > 1000 || floor((float)$count) != (float)$count) return $fail('商标件数须为 1–1000 的整数，不能按小数件计算');
    $extra = trim((string)($details['trademark_extra_count'] ?? ''));
    $hasExtraText = preg_match('/多选|超(?:出|过)(?:十|10)项|附加项目/u', $context . ' ' . ($details['service_type'] ?? '') . ' ' . $explicit);
    if (preg_match_all('/(\d+)\s*(?:个)?(?:项目|项商品|项)/u', $context . ' ' . $explicit, $itemCounts) && max(array_map('intval', $itemCounts[1])) > 10) $hasExtraText = true;
    if ($name === '注册' && $hasExtraText && ($extra === '' || (int)$extra === 0)) return $fail('商标注册有多选项目，请填写整单多选项目总数，每个附加项目按成本中心计费');
    if ($extra !== '' && (!ctype_digit($extra) || (int)$extra > 100000)) return $fail('多选项目总数须为非负整数');
    if ((int)$extra > 0 && $name !== '注册') return $fail('多选项目附加费只适用于商标注册，请核对办理事项');
    [, $amount] = ps_template_cost_amount($template, 0, (float)$count);
    $lines = [['template' => $template, 'quantity' => (float)$count, 'amount' => $amount, 'status' => (int)$template['auto_approve'] === 1 ? 'approved' : 'pending']];
    if ((int)$extra > 0) {
        $matches = array_values(array_filter($templates, function ($t) { return ptc_kind($t) === 'extra' && ptc_keyword($t) === '注册多选项目加收'; }));
        if (count($matches) !== 1) return $fail('商标注册多选项目加收没有唯一的启用成本模板');
        [, $extraAmount] = ps_template_cost_amount($matches[0], 0, (int)$extra);
        $lines[] = ['template' => $matches[0], 'quantity' => (int)$extra, 'amount' => $extraAmount, 'status' => (int)$matches[0]['auto_approve'] === 1 ? 'approved' : 'pending'];
    }
    $total = round(array_sum(array_column($lines, 'amount')), 2);
    // 标准官费是下限；表格明确给出的整单实际成本更高时，保留差额并交财务核对。
    if ($excel !== null && $excel > $total) $lines[] = ['template' => null, 'quantity' => 1, 'amount' => round($excel - $total, 2), 'status' => 'pending'];
    return ['status' => 'ready', 'service' => $name, 'lines' => $lines, 'total' => max($total, $excel ?? 0), 'message' => $name . ' × ' . ptc_count_label($count) . ' 件，标准成本 ¥' . money_plain($total) . ($excel !== null && $excel > $total ? '，实际成本差额待财务审核' : '')];
}

/** 所有入口都读取同一份已保存的办理信息，包括老表“付款流水号”里填写的事项。 */
function ptc_order_pricing_data($orderId)
{
    $q = db()->prepare('SELECT o.note,d.details_json,s.payment_reference FROM project_orders o LEFT JOIN project_order_details d ON d.order_id=o.id LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.id=?');
    $q->execute([(int)$orderId]);
    $row = $q->fetch() ?: [];
    return [json_decode((string)($row['details_json'] ?? ''), true) ?: [], trim(($row['payment_reference'] ?? '') . ' ' . ($row['note'] ?? ''))];
}

function ptc_import_check(&$record, $existing, $choice = '', $actor = [])
{
    $details = $record['details'];
    $context = implode(' ', [$record['payment_reference'] ?? '', $record['contact_note'] ?? '', $record['business_text'] ?? '', $record['resource_note'] ?? '']);
    $incomingNames = ptc_service_names(($details['service_type'] ?? '') . ' ' . $context);
    if (trim((string)($details['trademark_service'] ?? '')) === '' && count($incomingNames) === 1) $details['trademark_service'] = $incomingNames[0];
    if (trim((string)($details['trademark_service'] ?? '')) === '' && count($incomingNames) > 1) {
        $incomingPlan = ptc_cost_plan(ptc_templates(),$details,$context,$record['direct_cost']??null);
        if ($incomingPlan['status']==='ready') { $details['trademark_service']=$incomingPlan['service']; if (trim((string)($details['trademark_count']??''))==='') $details['trademark_count']=(string)$incomingPlan['count']; }
    }
    if ($existing) {
        [$stored, $storedText] = ptc_order_pricing_data((int)$existing['id']);
        if (!empty($stored['trademark_count']) && !empty($details['trademark_count']) && (float)$stored['trademark_count'] != (float)$details['trademark_count'] && ($actor['role'] ?? '') !== 'technical' && ($actor['role'] ?? '') !== 'finance' && !ps_is_management($actor)) throw new RuntimeException('商标件数与原单不一致，请资料/提交专员或财务核对更正，避免错误计价');
        foreach ($stored as $key => $value) if (trim((string)($details[$key] ?? '')) === '') $details[$key] = $value;
        $context .= ' ' . $storedText;
    }
    if ($choice !== '') $details['trademark_service'] = $choice;
    $plan = ptc_cost_plan(ptc_templates(), $details, $context, $record['direct_cost'] ?? null);
    $record['warning'] = trim(($record['warning'] ?? '') . '；' . $plan['message'], '；');
    if ($plan['status'] === 'unresolved') {
        $record['need_trademark_service'] = true;
        throw new RuntimeException($plan['message']);
    }
    $record['details']['trademark_service'] = $plan['service'];
    if (trim((string)($record['details']['trademark_count']??''))==='' && isset($plan['count'])) $record['details']['trademark_count']=(string)$plan['count'];
    if ($plan['status'] === 'ready' && isset($record['contract_amount']) && is_numeric($record['contract_amount']) && (float)$plan['total'] > (float)$record['contract_amount'] + 0.004) $record['warning'] .= '；成本高于销售金额，请核对整单售价、计价件数及补款归属';
}

function ptc_merge_import_details($orderId, &$details, $incoming, $actor)
{
    foreach (['trademark_service', 'trademark_extra_count', 'trademark_number', 'trademark_classes', 'trademark_breakdown', 'trademark_cost_note', 'trademark_price_note'] as $key) if (trim((string)($incoming[$key] ?? '')) !== '' && (string)($details[$key] ?? '') !== (string)$incoming[$key]) {
        ps_audit('order', (int)$orderId, 'import_trademark_pricing_field', $actor, ['field' => $key, 'from' => $details[$key] ?? '', 'to' => $incoming[$key]]);
        $details[$key] = $incoming[$key];
    }
    if ((($actor['role'] ?? '') === 'finance' || ps_is_management($actor)) && trim((string)($incoming['trademark_count'] ?? '')) !== '') $details['trademark_count'] = $incoming['trademark_count'];
}

/** 财务生成分成前必须有已核对成本；旧的错误默认注册成本也不能直接过关。 */
function ptc_approval_guard($orderId, $costs)
{
    [$details, $context] = ptc_order_pricing_data($orderId);
    $templates = ptc_templates();
    $actual = round(array_sum(array_map(function ($c) { return $c['review_status'] === 'approved' ? (float)$c['amount'] : 0; }, $costs)), 2);
    $plan = ptc_cost_plan($templates, $details, $context, $actual);
    $manualNames = [];
    foreach ($costs as $cost) if ($cost['review_status'] === 'approved' && strpos((string)$cost['reason'], '订单页快捷录入：') === 0) {
        foreach ($templates as $t) if ((int)$t['id'] === (int)$cost['template_id'] && in_array(ptc_kind($t), ['service', 'variable'], true)) $manualNames[] = ptc_keyword($t);
    }
    if ($plan['status'] !== 'ready' && $manualNames) {
        $names = ptc_service_names(($details['trademark_service'] ?? '') . ' ' . ($details['service_type'] ?? '') . ' ' . $context);
        if (!$names || !array_diff($names, $manualNames)) return; // 已逐项明确录入并审核的混合业务。
    }
    if ($plan['status'] !== 'ready') throw new RuntimeException('商标成本未核实：' . $plan['message'] . '，不能生成项目分成');
    if (!$costs || $actual + 0.004 < $plan['total']) throw new RuntimeException('商标成本低于成本中心标准价，请先重新核对成本，不能生成项目分成');
    foreach ($plan['lines'] as $line) if ($line['template']) {
        $found = false;
        foreach ($costs as $cost) if ($cost['review_status'] === 'approved' && (int)$cost['template_id'] === (int)$line['template']['id'] && (float)$cost['amount'] + 0.004 >= $line['amount']) $found = true;
        if (!$found) throw new RuntimeException('商标成本项目与办理事项不一致，请先重新核对成本，不能生成项目分成');
    }
}
