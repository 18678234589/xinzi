<?php

function ps_import_domain_mode($text)
{
    $text = mb_strtolower(trim((string)$text));
    if (in_array($text, ['否','无','无需','无需域名','no','n','客户提供','客户自有','客户自备'], true)) return 'none';
    if (in_array($text, ['是','有','yes','y','我们注册','我方注册','公司注册'], true)) return 'template';
    return '';
}

function ps_import_kind_preference($employeeId, $business, $layoutSignature)
{
    if (!$employeeId || !$layoutSignature) return '';
    try {
        $q = db()->prepare('SELECT order_kind FROM project_import_kind_preferences WHERE employee_id=? AND business_name=? AND layout_signature IN (?, ?) ORDER BY (source=?) DESC, (layout_signature=?) DESC LIMIT 1'
    );
        $q->execute([(int)$employeeId, $business, $layoutSignature, '*', 'finance', '*']);
        $kind = (string)$q->fetchColumn();
        return in_array($kind, ps_business_order_kinds($business), true) ? $kind : '';
    } catch (PDOException $e) { return ''; }
}

function ps_import_kind_preference_save($employeeId, $business, $layoutSignature, $kind)
{
    if (!$employeeId || !$layoutSignature || !in_array($kind, ps_business_order_kinds($business), true)) return;
    db()->prepare("INSERT INTO project_import_kind_preferences (employee_id,business_name,layout_signature,order_kind) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE order_kind=VALUES(order_kind),confirmed_count=confirmed_count+1,updated_at=NOW()"
    )
        ->execute([(int)$employeeId, $business, $layoutSignature, $kind]);
}

/** 表头加工作表名作为版式指纹，不把上传时所选业务写进指纹。 */
function ps_import_business_signature($head, $sheetName)
{
    return hash('sha256', json_encode([mb_strtolower(trim((string)$sheetName)), array_map(function ($value) { return mb_strtolower(trim((string)$value)); }, $head)], JSON_UNESCAPED_UNICODE
    ));
}

/** 在当前账号获准的业务中判定整份表的归属。只让明确证据自动覆盖默认业务。 */
function ps_import_business_detect($fileRow, $allowedBusinesses, $selectedBusiness, $employeeId = 0)
{
    if (count($allowedBusinesses) <= 1) return ['business' => $selectedBusiness, 'reason' => '账户唯一业务'];
    $explicit = []; $orderNos = []; $signatures = []; $scores = array_fill_keys($allowedBusinesses, 0);
    // 回购订单表（序号 + 旺旺号 + 订单编号，没有售价）：订单编号多半已是网站 / 小程序的店铺订单，不能按“已有关联订单号”把整张表归到那些业务，
    // 也不能被“本人同版式历史导入”带偏。账号有回购订单业务、或本次明确选了回购订单时，一律按回购订单（每行一单、按序号识别）。
    if (in_array('回购订单', $allowedBusinesses, true)) {
        if ($selectedBusiness === '回购订单') return ['business' => '回购订单', 'reason' => '你选择的业务'];
        foreach (array_slice(ps_import_file_sheets($fileRow), 0, 5, true) as $sheetRows) {
            $firstRow = is_array($sheetRows) ? reset($sheetRows) : null;
            if (!is_array($firstRow)) continue;
            $h = array_map(function ($value) { return trim((string)$value); }, $firstRow);
            if (in_array('序号', $h, true) && (in_array('旺旺号', $h, true) || in_array('返现', $h, true)) && !array_intersect(['售价', '金额', '订单金额'], $h)) return ['business' => '回购订单', 'reason' => '回购订单表（序号 + 旺旺号）'];
        }
    }
    foreach (array_slice(ps_import_file_sheets($fileRow), 0, 5, true) as $sheetName => $rows) {
        if (count($rows) < 2) continue;
        $head = array_map(function ($value) { return trim((string)$value); }, array_shift($rows));
        $signatures[] = ps_import_business_signature($head, $sheetName);
        foreach (['订单编号','订单号','订单','淘宝订单号','编码或者订单号'] as $alias) {
            $index = array_search($alias, $head, true);
            if ($index !== false) { foreach (array_slice($rows, 0, 20) as $row) { $no = trim((string)($row[$index] ?? '')); if ($no !== '') $orderNos[$no] = true; } break; }
        }
        foreach (['业务类型','项目类型','业务'] as $alias) {
            $index = array_search($alias, $head, true);
            if ($index !== false) { foreach (array_slice($rows, 0, 30) as $row) { $business = ps_business_normalize($row[$index] ?? ''); if (in_array($business, $allowedBusinesses,
    true)) $explicit[$business] = true; } break; }
        }
        foreach ($allowedBusinesses as $business) {
            try { $map = ps_business_import_map($business, $head, false); } catch (RuntimeException $e) { continue; }
            $scores[$business] += 1;
            if (mb_strpos(mb_strtolower((string)$sheetName), mb_strtolower($business)) !== false) $scores[$business] += 10;
            foreach ($map as $key => $index) {
                if (strpos($key, 'detail:') === 0 || in_array($key, ['program_name','domain_used','ssl_used','resource_note','ppt_marker'], true)) $scores[$business] += 3;
                elseif (in_array($key, ['direct_cost2','pay_mode'], true)) $scores[$business] += 2;
            }
            // 表头出现“设计师工资 / 设计师主”等列：成本口径为设计师佣金的业务（标书）加分，避免被当成同部门的小程序开发而要求指定技术
            $def = ps_business_catalog()[$business] ?? [];
            if (!empty($def['cost_label']) && mb_strpos((string)$def['cost_label'], '设计师') !== false) {
                foreach ($head as $cell) if (mb_strpos((string)$cell, '设计师') !== false) { $scores[$business] += 4; break; }
            }
        }
    }
    if (count($explicit) === 1) return ['business' => array_key_first($explicit), 'reason' => '表格业务列'];
    // 同一张微信代写原表常同时有自接与建群分表；不能用建群单的既有软文订单号把整本表改成软文代写。
    if (psw_is_member($employeeId) && in_array('微信代写', $allowedBusinesses, true)
        && in_array($selectedBusiness, ['微信代写', '软文代写'], true)) return ['business' => '微信代写', 'reason' => '微信代写账号，逐行区分自接与编辑对接'];
    if ($orderNos) {
        $numbers = array_slice(array_keys($orderNos), 0, 30);
        $q = db()->prepare('SELECT DISTINCT project_type FROM project_orders WHERE order_no IN (' . implode(',', array_fill(0, count($numbers), '?')) . ')');
        $q->execute($numbers);
        $known = array_values(array_unique(array_filter(array_map('ps_business_normalize', $q->fetchAll(PDO::FETCH_COLUMN)), function ($name) use ($allowedBusinesses) { return in_array
    ($name, $allowedBusinesses, true); })));
        if (count($known) === 1) return ['business' => $known[0], 'reason' => '已有关联订单号'];
    }
    if ($employeeId && $signatures) {
        try {
            $q = db()->prepare('SELECT DISTINCT business_name FROM project_import_business_preferences WHERE employee_id=? AND layout_signature IN (' . implode(',', array_fill(0, count
    ($signatures), '?')) . ')');
            $q->execute(array_merge([(int)$employeeId], $signatures));
            $saved = array_values(array_filter($q->fetchAll(PDO::FETCH_COLUMN), function ($name) use ($allowedBusinesses) { return in_array($name, $allowedBusinesses, true); }));
            if (count($saved) === 1) return ['business' => $saved[0], 'reason' => '本人同版式历史导入'];
        } catch (PDOException $e) { /* 升级数据库前仍可按表头判断。 */ }
    }
    arsort($scores);
    $ranked = array_keys($scores);
    if ($ranked && $scores[$ranked[0]] >= 4 && $scores[$ranked[0]] >= ($scores[$ranked[1]] ?? 0) + 3) return ['business' => $ranked[0], 'reason' => '表头与工作表特征'];
    return ['business' => $selectedBusiness, 'reason' => '未找到唯一业务特征，沿用账户默认业务'];
}

/** 成功导入后才记忆归属；预览/AI 猜测不学习，避免错误自我强化。 */
function ps_import_business_preference_save($employeeId, $signature, $business)
{
    if (!$employeeId || !$signature || !isset(ps_business_catalog()[$business])) return;
    try {
        db()->prepare('INSERT INTO project_import_business_preferences (employee_id,layout_signature,business_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE business_name=VALUES(business_name),confirmed_count=confirmed_count+1,updated_at=NOW()'
    )->execute([(int)$employeeId, $signature, $business]);
    } catch (PDOException $e) { /* 尚未执行迁移时不阻止订单导入。 */ }
}

/* ---------- 原始上传表格：保存、读取、权限 ---------- */
