<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $businessDefinition = ps_require_business($actor, $selectedBusiness);
        $peopleLabels = ps_business_people_labels($selectedBusiness);
        $action = (string)($_POST['action'] ?? '');
        $followup = null;
        $followupCommit = false;
        if (in_array($action, ['preview', 'repreview', 'repair_preview', 'followup'], true)) {
/* split: project/import/02_read_sheets.php */ include (dirname(__DIR__, 1)) . '/import/02_read_sheets.php';
            foreach ($parsedSheets as $sheetName => $entry) {
                $sheetName = (string)$sheetName;
                $raw = $entry['raw'];
                $head = $entry['head'];
                if ($entry['map'] === null) { $sheetReport[$sheetName] = ['used' => false, 'matchable' => false, 'reason' => $entry['reason']]; continue; }
                $columnMap = $entry['map'];
                $layoutSignature = ps_ai_signature(array_merge([$selectedBusiness], $head));
                $preferredKind = $actor['role'] === 'finance' ? '' : ps_import_kind_preference((int)$actor['employee_id'], $selectedBusiness, $layoutSignature);
                if (!$preferredKind && $actor['role'] !== 'finance') $preferredKind = ps_order_kind_from_role($selectedBusiness, ps_employee_default_role((int)$actor['employee_id']
    , $selectedBusiness, $actor['role']) ?? '');
                // 日期列没有表头（如第一列直接写 260901）：数据大多能识别为日期的空表头列当作日期列
                if (!isset($columnMap['order_date'])) foreach ($head as $i => $h) {
                    if ($h !== '' || in_array($i, $columnMap, true)) continue;
                    $values = array_filter(array_map(function ($r) use ($i) { return trim((string)($r[$i] ?? '')); }, $raw), 'strlen');
                    if (count($values) >= 1 && count(array_filter($values, 'ps_import_date')) >= 0.8 * count($values)) { $columnMap['order_date'] = $i; break; }
                }
                // 平面设计原表的到账状态列没有表头：数据大多是“到账 / 发货”等状态的空表头列当作状态列
                if ($selectedBusiness === '平面设计' && !isset($columnMap['status'])) foreach ($head as $i => $h) {
                    if ($h !== '' || in_array($i, $columnMap, true)) continue;
                    $values = array_filter(array_map(function ($r) use ($i) { return trim((string)($r[$i] ?? '')); }, $raw), 'strlen');
                    if (count($values) >= 1 && count(array_filter($values, function ($v) { return ps_import_delivery_status($v) !== null; })) >= 0.8 * count($values)) { $columnMap[
    'status'] = $i; break; }
                }
                if ($selectedBusiness === '标书') {
                    // 标书原表：同一订单多位设计师的续行（无订单号、无售价，佣金已计入主行成本）与底部汇总行不当订单；保留原下标对应 Excel 行号
                    $raw = array_filter($raw, function ($r) use ($columnMap) { $get = function ($k) use ($r, $columnMap) { return isset($columnMap[$k]) ? trim((string)($r[$columnMap
    [$k]] ?? '')) : ''; }; return preg_match('/\d{5,}/', $get('order_no')) || ($get('contract_amount') !== '' && ps_import_date($get('order_date'))); });
                }
                if ($selectedBusiness === '商标') {
                    // 商标原表的错位行归位、汇总行（合计 / 底薪 / 提成）跳过；保留原下标以对应 Excel 行号
                    $fixedRows = [];
                    foreach ($raw as $rawIndex => $rawRow) { $fixedRow = ps_trademark_fix_row($rawRow, $columnMap); if ($fixedRow !== null) $fixedRows[$rawIndex] = $fixedRow; }
                    $raw = $fixedRows;
                }
                if ($chosenSheets !== null && !in_array($sheetName, $chosenSheets, true)) { $sheetReport[$sheetName] = ['used' => false, 'matchable' => true, 'reason' => '未勾选'
    , 'rows' => count($raw)]; continue; }
                // 自动识别时，“未到账 / 交易关闭 / 汇总 / 合计 / 总表”类分表默认不读，需要时勾选后重新预览
                if ($chosenSheets === null && preg_match('/未到账|关闭|汇总|合计|总表|（总）|\(总\)/u', $sheetName)) { $sheetReport[$sheetName] = ['used' => false, 'matchable'
    => true, 'reason' => '默认不读取', 'rows' => count($raw)]; continue; }
                $totalRows += count($raw);
                if ($totalRows > 1500) throw new RuntimeException('所选工作表合计超过 1500 行，请取消部分分表后再预览');
                $sheetReport[$sheetName] = ['used' => true, 'matchable' => true, 'rows' => count($raw), 'ai' => $entry['ai']];
                $lineOffset = $usedSheets * 10000;
                $usedSheets++;
                $lookup = function ($row, $key) use ($columnMap) { return isset($columnMap[$key]) ? trim((string)($row[$columnMap[$key]] ?? '')) : ''; };
                $hasDomainColumn = isset($columnMap['domain_used']);
                // 订单类型提示文字（业务描述 + 备注 + 制作要求），用于关键字猜测与 AI 建议
                $kindHint = function ($row) use ($lookup, $selectedBusiness) {
                    $businessText = $lookup($row, 'business');
                    if ($businessText !== '' && ps_business_normalize($businessText) === $selectedBusiness) $businessText = '';
                    return trim($businessText . ' ' . $lookup($row, 'contact_note') . ' ' . $lookup($row, 'detail:make_requirement'));
                };
                $unknownStatuses = [];
                $unguessedHints = [];
                foreach ($raw as $row) {
                    $statusText = $lookup($row, 'status');
                    if ($statusText !== '' && !is_numeric(str_replace([',', '¥', '￥'], '', $statusText)) && ps_import_delivery_status($statusText, $selectedBusiness) === null) $unknownStatuses
    [] = $statusText;
                    if (!$preferredKind && !empty($businessDefinition['kind_required']) && $lookup($row, 'order_kind') === '' && !in_array($lookup($row, 'contact_note'), $orderKinds
    , true) && $lookup($row, 'order_no') !== '') {
                        $hint = $kindHint($row);
                        $guessed = false;
                        foreach (['续费', '定制', '技术服务', '维护'] as $word) if (mb_strpos($hint, $word) !== false) $guessed = true;
                        if ($hint !== '' && !$guessed) $unguessedHints[] = $hint;
                    }
                }
                $aiStatus = $unknownStatuses ? ps_ai_resolve_values('import_status', $selectedBusiness, $unknownStatuses, ['finished', 'unfinished'], '这些是订单表格“状态”列里的写法，请判断订单是否已完成交付/到账：finished = 已完成（可结算），unfinished = 未完成（进行中、未到账、退款中等）。'
    , $actor) : [];
                $aiKindNew = [];
                $aiKind = $unguessedHints ? ps_ai_resolve_values('import_kind', $selectedBusiness, $unguessedHints, $orderKinds, '这些是“' . $selectedBusiness . '”订单的业务描述，请为每条选择最合适的订单类型（决定提成比例）。'
    . ($selectedBusiness === '小程序开发' ? '新订单 = 用现成模板新建小程序（如 v4 模板、外卖、点餐）；定制 = 按客户需求开发功能 / 系统 / 平台；续费 = 续年费；技术服务 = 小修改、维护、上架代办。'
    : ''), $actor, $aiKindNew) : [];
            $prevFullRow = null; $prevFullLine = 0;
            foreach ($raw as $index => $row) {
                if (!array_filter($row, function ($v) { return trim((string)$v) !== ''; })) continue;
                if (ps_import_row_is_example($row)) continue; // 模板自带的示例行
                $rowLine = $lineOffset + $index + $entry['line_base'];
                // 没有订单号、没有流水号、也没有金额：预先填好姓名 / 写手号的空白行或纯备注行，不是订单，直接略过
                $amountCell = str_replace([',', '¥', '￥', ' '], '', $lookup($row, 'contract_amount'));
                if (ps_import_numeric_summary($row, $lookup($row, 'order_no'), $lookup($row, 'payment_reference'), $lookup($row, 'order_date'))) { $blankRows[$sheetName] = ($blankRows
    [$sheetName] ?? 0) + 1; continue; }
                if ($lookup($row, 'order_no') === '' && $lookup($row, 'payment_reference') === '' && trim((string)($fixOrderNos[$rowLine] ?? '')) === '' && trim((string)($fixPaymentReferences
    [$rowLine] ?? '')) === '' && ($amountCell === '' || (is_numeric($amountCell) && (float)$amountCell == 0)) && !($selectedBusiness === '森动备案' && $lookup($row, 'detail:domain_name') !== '')) { $blankRows[$sheetName] = ($blankRows[$sheetName] ?? 0) + 1; continue
    ; }
                // 只写了订单号的续行（同一客户的第二个订单号 / 加购单）：日期、店铺、付款昵称、客服、技术等沿用上一行
                $continuationOf = 0;
                $emptyCell = function ($key) use ($row, $columnMap) { return !isset($columnMap[$key]) || trim((string)($row[$columnMap[$key]] ?? '')) === ''; };
                if ($lookup($row, 'order_no') !== '' && $emptyCell('order_date') && $emptyCell('shop') && $emptyCell('payment_nickname') && $emptyCell('customer_service') && $emptyCell
    ('frontend')) {
                    if ($prevFullRow !== null) {
                        foreach (['order_date', 'shop', 'payment_nickname', 'status', 'customer_service', 'frontend', 'backend', 'business', 'program_name', 'contact_note'] as $inheritKey
    ) if (isset($columnMap[$inheritKey]) && $emptyCell($inheritKey)) $row[$columnMap[$inheritKey]] = $prevFullRow[$columnMap[$inheritKey]] ?? '';
                        $continuationOf = $prevFullLine;
                        if ($amountCell === '' && isset($columnMap['contract_amount'])) {
                            $shopMatches = array_values(array_filter(ps_shop_order_lookup($lookup($row, 'order_no')), function ($m) { return $m['price'] !== null; }));
                            if (count($shopMatches) === 1) $row[$columnMap['contract_amount']] = (string)$shopMatches[0]['price'];
                        }
                    }
                } else { $prevFullRow = $row; $prevFullLine = $rowLine; }
                $record = ['line' => $rowLine, 'sheet' => $sheetName, 'layout_signature' => $layoutSignature, 'business_signature' => ps_import_business_signature($head, $sheetName
    ), 'status' => '可导入', 'error' => '', 'warning' => '', 'base_valid' => true, 'people' => ['technical' => [], 'customer_service' => []], 'domain_mode' => '', 'domain_template_id'
    => 0];
                require_once (dirname(__DIR__, 1)) . '/../includes/ProjectRenewalImport.php';
                $record['renewal_fields'] = pr_import_fields($head, $row);
                $record['amount_from_shop'] = $continuationOf && $amountCell === '' && $lookup($row, 'contract_amount') !== '';
                if ($continuationOf) $record['warning'] = '此行只写了订单号：日期、店铺、客服、技术等沿用第 ' . ($continuationOf % 10000) . ' 行' . ($amountCell
    === '' ? ($lookup($row, 'contract_amount') !== '' ? '，售价按店铺流水带入' : '，售价待补（财务核对）') : '');
                try {
/* split: project/import/03a_order_no_and_split.php */ include (dirname(__DIR__, 1)) . '/import/03a_order_no_and_split.php';
/* split: project/import/03b_date_amount_status.php */ include (dirname(__DIR__, 1)) . '/import/03b_date_amount_status.php';
/* split: project/import/03c_people_resources.php */ include (dirname(__DIR__, 1)) . '/import/03c_people_resources.php';
                } catch (RuntimeException $e) { $record['base_valid'] = false; $record['status'] = $record['skip_status'] ?? '需处理'; $record['error'] = $e->getMessage(); }
                $itemTemplates = $itemTemplates ?? ps_intake_templates(null, $selectedBusiness);
                $record['items'] = !empty($businessDefinition['program']) ? poi_from_row($record['program_name'], empty($record['amount_from_shop']) ? $record['contract_amount'] :
    null, $head, $row, $itemTemplates, $record['line'], $record['resource_note'] ?? '') : [];
                if (!$record['resource_locked']) foreach ($record['items'] as $item) if ($item['category'] === 'program' && $item['template_id']) { $record['program_template_id'] =
    (int)$item['template_id']; break; }
                // 同一订单号保留全部商品明细；收款只落在主单，不给证书另建一笔收入。
                $orderKey = $record['order_no'] ?? '';
                if ($orderKey !== '' && isset($seen[$orderKey])) {
/* split: project/import/04_merge_items.php */ include (dirname(__DIR__, 1)) . '/import/04_merge_items.php';
                    continue;
                }
                if ($orderKey !== '') $seen[$orderKey] = count($preview);
                $preview[] = $record;
            }
            if (!empty($blankRows[$sheetName])) $sheetReport[$sheetName]['blank'] = $blankRows[$sheetName];
            }
/* split: project/import/04_merge_rows.php */ include (dirname(__DIR__, 1)) . '/import/04_merge_rows.php';
        }
        if ($action === 'commit' || $followupCommit) {
/* split: project/import/05_commit.php */ include (dirname(__DIR__, 1)) . '/import/05_commit.php';
        } elseif (!in_array($action, ['preview', 'repreview', 'repair_preview', 'followup'], true)) throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '导入发生订单号冲突或保存失败，请重新上传预览' : $e->getMessage(); }
}
