<?php

/** 模板示例行：每列给一个正确写法；订单号以“示例”开头，上传时自动跳过，忘删也不会入账。 */
function ps_business_import_example_row($business, $headers)
{
    $columns = ps_business_import_columns($business);
    $kinds = ps_business_order_kinds($business);
    $samples = ['order_date' => '2026-09-01', 'shop' => '美呀美旗舰店', 'business' => '写具体做什么，如 小程序商城搭建', 'payment_nickname' => 'tb12345678', 'payment_reference' => '', 'order_no' => '示例-3316440471002001958（本行可删，上传时自动跳过）', 'contract_amount' => '350', 'status' => '已完成', 'contact_note' => '13800000000', 'customer_service' => '王宁', 'frontend' => '石凯新', 'backend' => '', 'order_kind' => $kinds[0] ?? '', 'program_name' => '森动中级版', 'domain_used' => '否', 'ssl_used' => '0', 'resource_note' => 'www.example.com', 'direct_cost' => '120', 'direct_cost2' => '0', 'shipping_cost' => '10', 'detail:domain_name' => '示例.com（本行可删，上传时自动跳过）', 'screenshot_marker' => '（截图链接或留空，仅凭证）', 'backend_type_marker' => '（仅记录）', 'split_amount_note' => '（仅记录）'];
    $row = [];
    foreach ($headers as $label) {
        $value = '';
        foreach ($columns as $key => $aliases) if (in_array($label, $aliases, true)) { $value = $samples[$key] ?? (strpos($key, 'detail:') === 0 ? '按实际填写' : ''); break; }
        if ($label === '客户手机号') $value = '13800138000';
        elseif ($label === '客户域名') $value = 'example.com';
        elseif ($label === '服务器到期日') $value = '2027-09-01';
        elseif ($label === '域名归属') $value = '我们代管';
        $row[] = $value;
    }
    return $row;
}

/** 模板示例行（任一单元格以“示例”开头）：导入时跳过。 */
function ps_import_row_is_example($row)
{
    foreach ((array)$row as $cell) if (mb_strpos(trim((string)$cell), '示例') === 0) return true;
    return false;
}

/** 首行像数据而不是表头（含订单号样式的长数字串）：用于识别没有表头、直接从第 1 行写订单的表格。 */
function ps_import_row_is_data($row)
{
    foreach ((array)$row as $cell) if (preg_match('/^[A-Za-z0-9-]{12,}$/', trim((string)$cell)) && preg_match_all('/\d/', (string)$cell) >= 10) return true;
    return false;
}

/**
 * 没有表头的表格：按各列内容识别列含义（订单号 / 日期 / 店铺 / 售价 / 手机 / 客服 / 技术 / 付款昵称 / 状态）。
 * 识别不出订单号列，或日期、售价都识别不出时返回 null。
 */
function ps_import_headerless_map($rows, $knownShops, $employeesByName)
{
    $rows = array_slice(array_values(array_filter($rows, 'is_array')), 0, 50);
    $width = $rows ? max(array_map('count', $rows)) : 0;
    $columns = [];
    for ($i = 0; $i < $width; $i++) $columns[$i] = array_values(array_filter(array_map(function ($r) use ($i) { return trim((string)($r[$i] ?? '')); }, $rows), 'strlen'));
    $share = function ($i, $test) use ($columns) { $values = $columns[$i]; if (!$values) return 0; return count(array_filter($values, $test)) / count($values); };
    $map = [];
    $free = function ($i) use (&$map) { return !in_array($i, $map, true); };
    $pick = function ($key, $test, $after = -1) use ($columns, $share, $free, &$map) {
        foreach ($columns as $i => $values) if ($i > $after && $values && $free($i) && $share($i, $test) >= 0.6) { $map[$key] = $i; return; }
    };
    $pick('order_no', function ($v) { return preg_match('/^[A-Za-z0-9-]{12,}$/', $v) && preg_match_all('/\d/', $v) >= 10; });
    if (!isset($map['order_no'])) return null;
    $pick('order_date', function ($v) { return (preg_match('/^\d{1,4}([.\/-]\d{1,2}){1,2}\.?$/', $v) || mb_strpos($v, '月') !== false || (is_numeric($v) && $v > 40000 && $v < 60000)) && ps_import_date($v); });
    $pick('contact_note', function ($v) { return (bool)preg_match('/^1[3-9]\d{9}$/', $v); });
    $pick('contract_amount', function ($v) { return is_numeric(str_replace([',', '¥', '￥'], '', $v)) && (float)str_replace([',', '¥', '￥'], '', $v) < 1000000; }, $map['order_no']);
    $pick('shop', function ($v) use ($knownShops) { foreach ($knownShops as $shop) if ($v === $shop || mb_strpos($shop, $v) !== false || mb_strpos($v, $shop) !== false) return true; return false; });
    // 网站客服旧表常无表头；产品列是确定标记，不靠 AI 猜整张表的业务。
    $pick('program_name', function ($v) { return (bool)preg_match('/^(php|jsp|森动|博山定制|华梦|大连定制|网站定制)/iu', $v); });
    // 人员列：姓名都在人员名单中；按账号角色区分客服列与技术列，角色不明时先客服后技术
    $isName = function ($v) use ($employeesByName) { foreach (preg_split('/[,，、\/]+/u', $v) as $n) if (!isset($employeesByName[trim($n)])) return false; return true; };
    $peopleColumns = [];
    foreach ($columns as $i => $values) if ($values && $free($i) && $share($i, $isName) >= 0.6) $peopleColumns[] = $i;
    foreach ($peopleColumns as $i) {
        $roles = [];
        foreach ($columns[$i] as $v) foreach (preg_split('/[,，、\/]+/u', $v) as $n) foreach ($employeesByName[trim($n)] ?? [] as $emp) if (!empty($emp['role'])) $roles[$emp['role']] = ($roles[$emp['role']] ?? 0) + 1;
        arsort($roles);
        $role = key($roles);
        $key = $role === 'technical' ? (isset($map['frontend']) ? 'backend' : 'frontend') : ($role === 'customer_service' && !isset($map['customer_service']) ? 'customer_service' : null);
        if ($key === null) foreach (['customer_service', 'frontend', 'backend'] as $candidate) if (!isset($map[$candidate])) { $key = $candidate; break; }
        if ($key !== null && !isset($map[$key])) $map[$key] = $i;
    }
    // 客服列后紧跟一列中文短名、但不在名单里（如负责传资料的同事）：当作技术列，导入时按“非合作人员”提示后忽略
    if (isset($map['customer_service']) && !isset($map['frontend'])) {
        $next = $map['customer_service'] + 1;
        if (!empty($columns[$next]) && $free($next) && $share($next, function ($v) { return (bool)preg_match('/^\p{Han}{2,4}$/u', $v); }) >= 0.6) $map['frontend'] = $next;
    }
    $pick('status', function ($v) { return $v !== '' && ps_import_delivery_status($v) !== null; });
    // 付款昵称：订单号前最近的一个未识别的文字列（淘宝表常见顺序：日期、店铺、付款账号、订单编号）
    for ($i = $map['order_no'] - 1; $i >= 0; $i--) if ($columns[$i] && $free($i)) { if ($share($i, function ($v) { return !is_numeric($v); }) >= 0.6) $map['payment_nickname'] = $i; break; }
    if (!isset($map['order_date']) && !isset($map['contract_amount'])) return null;
    return $map;
}

/** 导入用的姓名索引：附带部门、是否开通项目账号及账号可做的业务，供重名判断。 */
function ps_import_employee_index()
{
    $index = [];
    $rows = db()->query('SELECT e.id,e.name,e.department,u.id AS user_id,u.role FROM employees e LEFT JOIN project_users u ON u.employee_id=e.id AND u.is_active=1')->fetchAll();
    foreach ($rows as $emp) {
        $emp['has_account'] = $emp['user_id'] ? 1 : 0;
        $emp['businesses'] = $emp['user_id'] ? ps_actor_businesses(['id' => (int)$emp['user_id'], 'employee_id' => (int)$emp['id'], 'role' => $emp['role']]) : [];
        $index[$emp['name']][] = $emp;
    }
    return $index;
}
