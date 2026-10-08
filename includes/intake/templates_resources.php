<?php

function ps_intake_templates($category = null, $business = null)
{
    $sql = 'SELECT * FROM project_cost_templates WHERE is_active=1 AND requires_proof=0';
    $params = [];
    if ($category !== null) { $sql .= ' AND category=?'; $params[] = $category; }
    if ($business !== null) { $sql .= " AND (business_scope='' OR business_scope=?)"; $params[] = ps_business_normalize($business); }
    $sql .= ' ORDER BY category,name,specification,id DESC';
    $q = db()->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}

function ps_intake_template($id, $category)
{
    $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND category=? AND is_active=1 AND requires_proof=0');
    $q->execute([(int)$id, $category]);
    $template = $q->fetch();
    if (!$template) throw new RuntimeException('所选' . (['domain' => '域名', 'program' => '程序套餐'][$category] ?? '资源') . '成本模板不可用，请刷新后重选');
    return $template;
}

/** 按“程序名称”原文（如“5年JSP展示中级版”“PHP”）匹配程序套餐；无法唯一确定时返回 null 交给人工选择。 */
function ps_intake_program_suggestion($text, $templates)
{
    $matched = poi_template($text, $templates);
    if ($matched && $matched['category'] === 'program') return $matched;
    $text = preg_replace('/\s+/u', '', (string)$text);
    if ($text === '') return null;
    $years = preg_match('/^(\d+)年/u', $text, $m) ? (int)$m[1] : 1;
    $name = preg_replace('/^\d+年/u', '', $text);
    $matches = [];
    foreach ($templates as $template) {
        if ($template['category'] !== 'program' || preg_replace('/\s+/u', '', $template['name']) !== $name) continue;
        $specYears = preg_match('/^(\d+)年/u', $template['specification'], $sm) ? (int)$sm[1] : 0;
        if ($specYears && $specYears !== $years) continue;
        $matches[] = $template;
    }
    if (count($matches) === 1) return $matches[0];
    // 同名同年限常见“空间+域名 / 仅空间”两档：部门表默认含域名，技术可在预览中改选。
    $withDomain = array_values(array_filter($matches, function ($t) { return mb_strpos($t['specification'], '空间+域名') !== false; }));
    return count($withDomain) === 1 ? $withDomain[0] : null;
}

/**
 * 模板成本金额：固定价 × 数量；按售价百分比的模板（如华梦外包 80%）= 售价 × 百分比 × 数量。
 * 返回 [单价, 金额, 采购价金额]。
 */
function ps_template_cost_amount($template, $contract, $quantity = 1)
{
    if (($template['price_mode'] ?? 'fixed') === 'percent') {
        if ((float)$contract <= 0) throw new RuntimeException('“' . $template['name'] . '”按售价的 ' . rtrim(rtrim((string)$template['price'], '0'), '.') . '% 计算，请先填写售价');
        $unit = round((float)$contract * (float)$template['price'] / 100, 2);
        $supplier = $template['supplier_price'] !== null ? round((float)$contract * (float)$template['supplier_price'] / 100 * (float)$quantity, 2) : null;
    } else {
        $unit = round((float)$template['price'], 2);
        $supplier = $template['supplier_price'] !== null ? round((float)$template['supplier_price'] * (float)$quantity, 2) : null;
    }
    return [$unit, round($unit * (float)$quantity, 2), $supplier];
}

/** 标准价由成本中心确定：程序套餐与按售价比例的外包成本不受 ¥500 阈值限制；财务可把模板设为“不自动审”。 */
function ps_template_cost_status($template, $amount)
{
    if ((int)$template['auto_approve'] !== 1 || !empty($template['requires_proof'])) return 'pending';
    return ((float)$amount <= 500 || $template['category'] === 'program' || ($template['price_mode'] ?? 'fixed') === 'percent') ? 'approved' : 'pending';
}

function ps_intake_add_template_cost($orderId, $template, $actor, $origin, $quantity = 1, $forceStatus = null)
{
    $contract = 0.0;
    if (($template['price_mode'] ?? 'fixed') === 'percent') {
        $q = db()->prepare('SELECT contract_amount FROM project_orders WHERE id=?');
        $q->execute([(int)$orderId]);
        $contract = (float)$q->fetchColumn();
    }
    [$price, $amount, $supplier] = ps_template_cost_amount($template, $contract, $quantity);
    $status = $forceStatus ?? ps_template_cost_status($template, $amount);
    $q = db()->prepare('INSERT INTO project_costs (order_id,template_id,template_version,category,item_name,quantity,unit,unit_price,amount,supplier_amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)');
    $q->execute([(int)$orderId, (int)$template['id'], (int)$template['version'], $template['category'], $template['name'] . ($template['specification'] ? ' · ' . $template['specification'] : ''), (float)$quantity, $template['unit'], $price, $amount, $supplier, $template['cost_kind'], $origin, $status, $actor['employee_id'] ?? null]);
    $costId = (int)db()->lastInsertId();
    ps_audit('cost', $costId, 'create_from_intake', $actor, ['order_id' => (int)$orderId, 'template_id' => (int)$template['id'], 'amount' => $amount, 'status' => $status, 'origin' => $origin]);
    return $costId;
}

function ps_intake_save_resources($orderId, $sourceType, $sourceLine, $domainTemplate, $serverTemplate, $sslAmount, $domainMode = null, $programTemplate = null)
{
    $q = db()->prepare('INSERT INTO project_order_resources (order_id,source_type,source_line,domain_mode,domain_template_id,server_template_id,program_template_id,ssl_expected_amount) VALUES (?,?,?,?,?,?,?,?)');
    $q->execute([(int)$orderId, $sourceType, $sourceLine, $domainMode ?? ($domainTemplate ? 'template' : 'none'), $domainTemplate['id'] ?? null, $serverTemplate['id'] ?? null, $programTemplate['id'] ?? null, $sslAmount !== null && (float)$sslAmount > 0 ? round((float)$sslAmount, 2) : null]);
}

/**
 * 客服先建档后的技术确认：程序套餐、域名、服务器三类模板成本在同一事务中各入账一次。
 * 程序套餐已含空间与域名，只选套餐时按“无需另购域名”处理。
 */
function ps_intake_confirm_resources($orderId, $mode, $domainTemplateId, $serverTemplateId, $actor, $programTemplateId = 0)
{
    if (!(int)$programTemplateId) foreach (poi_items($orderId) as $item) if ($item['category']==='program' && $item['template_id']) { $programTemplateId=(int)$item['template_id']; break; }
    $program = (int)$programTemplateId > 0 ? ps_intake_template((int)$programTemplateId, 'program') : null;
    if ($program && $mode === '') $mode = 'none';
    if (!in_array($mode, ['none','template'], true)) throw new RuntimeException('请选择域名使用方式或程序套餐');
    $domain = $mode === 'template' ? ps_intake_template((int)$domainTemplateId, 'domain') : null;
    $server = (int)$serverTemplateId > 0 ? ps_intake_template((int)$serverTemplateId, 'server') : null;
    $pending = db()->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=? FOR UPDATE');
    $pending->execute([(int)$orderId]);
    $current = $pending->fetchColumn();
    if ($current === false) {
        ps_intake_save_resources($orderId, 'manual', null, null, null, null, 'pending');
        $current = 'pending';
    }
    if ($current !== 'pending') throw new RuntimeException('资源已确认，请勿重复添加成本');
    if ($program) poi_select_program($orderId, $program['id'], $actor);
    db()->prepare('UPDATE project_order_resources SET domain_mode=?,domain_template_id=?,server_template_id=?,program_template_id=? WHERE order_id=?')
        ->execute([$mode, $domain['id'] ?? null, $server['id'] ?? null, $program['id'] ?? null, (int)$orderId]);
    $hasProgramItem = false;
    if ($program) foreach (poi_items($orderId) as $item) if ((int)$item['template_id']===(int)$program['id']) { $hasProgramItem=true; break; }
    if ($program && !$hasProgramItem) ps_intake_add_template_cost($orderId, $program, $actor, '技术确认：程序套餐');
    if ($domain) ps_intake_add_template_cost($orderId, $domain, $actor, '技术确认：域名');
    if ($server) ps_intake_add_template_cost($orderId, $server, $actor, '技术确认：服务器');
    poi_sync_costs($orderId, $actor);
    return ['domain_template_id' => $domain['id'] ?? null, 'server_template_id' => $server['id'] ?? null, 'program_template_id' => $program['id'] ?? null];
}
