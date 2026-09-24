<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/../includes/ProjectAiFallback.php';
require_once __DIR__ . '/../classes/SimpleXLSX.php';
$actor = ps_require_actor();
$allowedBusinesses = ps_actor_businesses($actor);
$requestedBusiness = (string)($_POST['business'] ?? $_GET['business'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedBusiness !== '' && !in_array(ps_business_normalize($requestedBusiness), $allowedBusinesses, true)) { http_response_code(403); exit('当前账户未分配此业务'); }
$selectedBusiness = ps_business_choice($actor, $requestedBusiness);
if (!$selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'POST') { http_response_code(403); exit('当前账户未分配业务类型'); }
$businessDefinition = $selectedBusiness ? ps_business_catalog()[$selectedBusiness] : null;
if (isset($_GET['download']) && $selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="project-order-template.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ps_business_import_headers($selectedBusiness));
    fclose($output);
    exit;
}
$error = '';
$imported = 0;
$skipped = 0;
$preview = $_SESSION['project_import_preview'] ?? [];
$previewOwner = $_SESSION['project_import_actor'] ?? '';
$previewBusiness = $_SESSION['project_import_business'] ?? '';
$actorKey = $actor['type'] . ':' . $actor['id'];
if ($previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness) $preview = [];
$domainTemplates = ps_intake_templates('domain');
$serverTemplates = ps_intake_templates('server');
$programTemplates = $selectedBusiness ? ps_intake_templates('program', $selectedBusiness) : [];
$usesProgram = $businessDefinition && !empty($businessDefinition['program']);
$resourceSelection = $businessDefinition && $businessDefinition['resources'] && $actor['role'] !== 'customer_service';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ps_check_csrf();
    try {
        $businessDefinition = ps_require_business($actor, $selectedBusiness);
        $peopleLabels = ps_business_people_labels($selectedBusiness);
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'preview' || $action === 'repreview') {
            // 原始表格先保存（财务可在“原始表格”页查看 / 下载）；重新选择工作表时直接读已保存的文件，不必重新上传。
            if ($action === 'preview') {
                if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || $_FILES['file']['size'] > 5 * 1024 * 1024) throw new RuntimeException('请选择不超过 5MB 的 XLSX 或 CSV 文件');
                $fileId = ps_import_file_store($_FILES['file'], $selectedBusiness, $actor, $_FILES['parsed_file'] ?? null);
            } else $fileId = (int)($_POST['file_id'] ?? 0);
            $fileRow = ps_import_file_get($fileId, $actor);
            $chosenSheets = $action === 'repreview' ? array_map('strval', (array)($_POST['sheets'] ?? [])) : null;
            $sheetReport = [];
            $usedSheets = 0;
            $totalRows = 0;
            $orderKinds = ps_business_order_kinds($selectedBusiness);
            $employeesByName = ps_import_employee_index();
            $actorName = null;
            if ($actor['role'] !== 'finance') { $nameQuery = db()->prepare('SELECT name FROM employees WHERE id=?'); $nameQuery->execute([(int)$actor['employee_id']]); $actorName = $nameQuery->fetchColumn() ?: '本人'; }
            $knownShops = db()->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN);
            $seen = [];
            $preview = [];
            $exists = db()->prepare('SELECT o.id,o.project_type,o.settlement_status,o.shop,o.contract_amount,s.payment_nickname,s.price_source FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no=? LIMIT 1');
            $existingAccess = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
            $existingResource = db()->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=?');
            // 一个工作簿多张分表（如“图片 / PPT / 小额”、每位客服一张）：表头能对上当前业务的分表都读取，可在预览里取消勾选。
            $requirePeople = $actor['role'] === 'finance';
            $importColumns = ps_business_import_columns($selectedBusiness);
            $parsedSheets = [];
            foreach (ps_import_file_sheets($fileRow) as $sheetName => $raw) {
                $raw = array_values(array_filter($raw, function ($r) { return is_array($r); }));
                $head = $raw ? array_map(function ($v) { return trim((string)$v); }, array_shift($raw)) : [];
                if ($head) $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0] ?? '');
                $entry = ['raw' => $raw, 'head' => $head, 'map' => null, 'reason' => '', 'ai' => ''];
                if (!$raw) $entry['reason'] = '没有数据';
                else {
                    // 表头按别名匹配：原 AI 定制模板、部门现有表（付款账号 / 接单日期 / 到账情况 / 程序名称…）都可直接上传。
                    try { $entry['map'] = ps_business_import_map($selectedBusiness, $head, $requirePeople); } catch (RuntimeException $e) { $entry['reason'] = $e->getMessage(); }
                }
                $parsedSheets[(string)$sheetName] = $entry;
            }
            if (!array_filter($parsedSheets, function ($e) { return $e['map'] !== null; })) {
                // 没有一张分表能按别名识别：AI 托底（先查已存档方案），最多试数据最多的 3 张分表
                $candidates = array_filter($parsedSheets, function ($e) { return count($e['raw']) >= 1 && $e['head']; });
                uasort($candidates, function ($a, $b) { return count($b['raw']) <=> count($a['raw']); });
                foreach (array_slice(array_keys($candidates), 0, 3) as $name) {
                    $aiNote = '';
                    $aiMap = ps_ai_import_columns($selectedBusiness, $parsedSheets[$name]['head'], array_slice($parsedSheets[$name]['raw'], 0, 5), $importColumns, $actor, $aiNote);
                    if ($aiMap && (!$requirePeople || isset($aiMap['customer_service']) || isset($aiMap['frontend']) || isset($aiMap['backend']))) { $parsedSheets[$name]['map'] = $aiMap; $parsedSheets[$name]['ai'] = $aiNote; }
                    elseif (!$aiMap) $parsedSheets[$name]['reason'] .= function_exists('ps_ai_ready') && ps_ai_ready() ? '；AI 也未能识别' : '';
                }
            }
            foreach ($parsedSheets as $sheetName => $entry) {
                $sheetName = (string)$sheetName;
                $raw = $entry['raw'];
                $head = $entry['head'];
                if ($entry['map'] === null) { $sheetReport[$sheetName] = ['used' => false, 'matchable' => false, 'reason' => $entry['reason']]; continue; }
                $columnMap = $entry['map'];
                // 日期列没有表头（如第一列直接写 260901）：数据大多能识别为日期的空表头列当作日期列
                if (!isset($columnMap['order_date'])) foreach ($head as $i => $h) {
                    if ($h !== '' || in_array($i, $columnMap, true)) continue;
                    $values = array_filter(array_map(function ($r) use ($i) { return trim((string)($r[$i] ?? '')); }, $raw), 'strlen');
                    if (count($values) >= 1 && count(array_filter($values, 'ps_import_date')) >= 0.8 * count($values)) { $columnMap['order_date'] = $i; break; }
                }
                if ($chosenSheets !== null && !in_array($sheetName, $chosenSheets, true)) { $sheetReport[$sheetName] = ['used' => false, 'matchable' => true, 'reason' => '未勾选', 'rows' => count($raw)]; continue; }
                // 自动识别时，“未到账 / 交易关闭 / 汇总 / 合计 / 总表”类分表默认不读，需要时勾选后重新预览
                if ($chosenSheets === null && preg_match('/未到账|关闭|汇总|合计|总表|（总）|\(总\)/u', $sheetName)) { $sheetReport[$sheetName] = ['used' => false, 'matchable' => true, 'reason' => '默认不读取', 'rows' => count($raw)]; continue; }
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
                    if ($statusText !== '' && ps_import_delivery_status($statusText) === null) $unknownStatuses[] = $statusText;
                    if (!empty($businessDefinition['kind_required']) && $lookup($row, 'order_kind') === '' && !in_array($lookup($row, 'contact_note'), $orderKinds, true) && $lookup($row, 'order_no') !== '') {
                        $hint = $kindHint($row);
                        $guessed = false;
                        foreach (['续费', '定制', '技术服务', '维护'] as $word) if (mb_strpos($hint, $word) !== false) $guessed = true;
                        if ($hint !== '' && !$guessed) $unguessedHints[] = $hint;
                    }
                }
                $aiStatus = $unknownStatuses ? ps_ai_resolve_values('import_status', $selectedBusiness, $unknownStatuses, ['finished', 'unfinished'], '这些是订单表格“状态”列里的写法，请判断订单是否已完成交付/到账：finished = 已完成（可结算），unfinished = 未完成（进行中、未到账、退款中等）。', $actor) : [];
                $aiKindNew = [];
                $aiKind = $unguessedHints ? ps_ai_resolve_values('import_kind', $selectedBusiness, $unguessedHints, $orderKinds, '这些是“' . $selectedBusiness . '”订单的业务描述，请为每条选择最合适的订单类型（决定提成比例）。' . ($selectedBusiness === '小程序开发' ? '新订单 = 用现成模板新建小程序（如 v4 模板、外卖、点餐）；定制 = 按客户需求开发功能 / 系统 / 平台；续费 = 续年费；技术服务 = 小修改、维护、上架代办。' : ''), $actor, $aiKindNew) : [];
            foreach ($raw as $index => $row) {
                if (!array_filter($row, function ($v) { return trim((string)$v) !== ''; })) continue;
                $record = ['line' => $lineOffset + $index + 2, 'sheet' => $sheetName, 'status' => '可导入', 'error' => '', 'warning' => '', 'base_valid' => true, 'people' => ['technical' => [], 'customer_service' => []], 'domain_mode' => '', 'domain_template_id' => 0];
                try {
                    $record['order_no'] = $lookup($row, 'order_no');
                    $exists->execute([$record['order_no']]);
                    $existing = $exists->fetch();
                    $record['existing_order_id'] = $existing ? (int)$existing['id'] : 0;
                    $record['resource_locked'] = false;
                    if ($existing) {
                        if (ps_business_normalize($existing['project_type']) !== $selectedBusiness) throw new RuntimeException('该订单号已属于其他业务，请联系财务核对');
                        if (in_array($existing['settlement_status'], ['approved','locked'], true)) throw new RuntimeException('订单已审核，不能通过导入修改');
                        if ($actor['role'] !== 'finance') {
                            $existingAccess->execute([(int)$existing['id'], (int)$actor['employee_id']]);
                            // 代写类：编辑上传“编辑订单”表时可把本人挂到客服已建的订单（本组尚无人时），反之亦然。
                            if (!$existingAccess->fetchColumn()) {
                                if (empty($businessDefinition['import_cost'])) throw new RuntimeException('该订单号已存在，但本人尚未被关联；请由参与人或财务关联后再上传');
                                $record['attach_check'] = true; // 读出本行人员后再核对本人所在组是否空缺
                            }
                        }
                        $existingResource->execute([(int)$existing['id']]);
                        $existingMode = $existingResource->fetchColumn();
                        $record['resource_locked'] = $existingMode !== false && $existingMode !== 'pending';
                        $record['status'] = '补充已有订单';
                    }
                    $record['order_date'] = ps_import_date($lookup($row, 'order_date'));
                    // 部门原表偶有把时间写进日期列（如 18.05、“17. 00”）：按今天建单并提示核对
                    if (!$record['order_date'] && !empty($businessDefinition['free_shop']) && $lookup($row, 'order_date') !== '' && ($lookup($row, 'order_no') !== '' && $lookup($row, 'contract_amount') !== '')) { $record['order_date'] = date('Y-m-d'); $record['warning'] = '日期“' . $lookup($row, 'order_date') . '”无法识别，已按今天建单，请核对'; }
                    $record['contract_amount'] = str_replace([',','¥','￥',' '], '', $lookup($row, 'contract_amount'));
                    if (preg_match('/^\d+\.\d{3,}$/', $record['contract_amount'])) $record['contract_amount'] = number_format((float)$record['contract_amount'], 2, '.', '');
                    $status = $lookup($row, 'status');
                    $record['delivery_status'] = ps_import_delivery_status($status);
                    if ($record['delivery_status'] === null && isset($aiStatus[$status])) { $record['delivery_status'] = $aiStatus[$status]; $record['warning'] .= ($record['warning'] ? '；' : '') . '状态“' . $status . '”由 AI 识别为' . ($aiStatus[$status] === 'finished' ? '已完成' : '未完成'); }
                    if ($record['delivery_status'] === null) throw new RuntimeException('状态“' . $status . '”无法识别，请写已完成 / 未完成（或到账、已发货等）');
                    $record['trade_status'] = mb_strpos($status, '交易关闭') !== false ? '交易关闭' : '';
                    if ($record['trade_status'] !== '') $record['warning'] = '表格写交易关闭：请财务核对退款';
                    $sheetBusiness = $lookup($row, 'business');
                    $record['business_text'] = '';
                    if ($sheetBusiness !== '' && ps_business_normalize($sheetBusiness) !== $selectedBusiness) {
                        if (isset(ps_business_catalog()[ps_business_normalize($sheetBusiness)])) throw new RuntimeException('表格写的业务是“' . $sheetBusiness . '”，与当前选中的“' . $selectedBusiness . '”不一致');
                        $record['business_text'] = mb_substr($sheetBusiness, 0, 300); // 部门表“业务”列常写项目描述
                    }
                    $record['project_type'] = $selectedBusiness;
                    $record['shop'] = $lookup($row, 'shop');
                    if ($record['shop'] !== '' && empty($businessDefinition['free_shop']) && !in_array($record['shop'], $knownShops, true)) {
                        $shopText = $record['shop'];
                        $shopMatches = array_values(array_filter($knownShops, function ($known) use ($shopText) { return mb_strpos($known, $shopText) !== false || mb_strpos($shopText, $known) !== false; }));
                        if (count($shopMatches) === 1) $record['shop'] = $shopMatches[0];
                        else $record['warning'] .= ($record['warning'] ? '；' : '') . '店铺“' . $shopText . '”不在店铺列表，已按原文保存';
                    }
                    $record['payment_nickname'] = $lookup($row, 'payment_nickname');
                    $record['contact_note'] = $lookup($row, 'contact_note');
                    // 小程序结算表的“备注”常写 新订单 / 续费 / 定制：识别为订单类型。
                    $kindText = $lookup($row, 'order_kind');
                    if ($kindText === '' && in_array($record['contact_note'], $orderKinds, true)) { $kindText = $record['contact_note']; $record['contact_note'] = ''; }
                    if ($kindText !== '' && !in_array($kindText, $orderKinds, true)) throw new RuntimeException('订单类型“' . $kindText . '”无效，可选：' . implode('、', $orderKinds));
                    // 代写 / 期刊 / 微信代写按原表内容识别类型：负数行 = 退款冲减；“提成”列为 0 = 合并单；微信付款；期刊“杂志社版面费” = 代付版面费。
                    if ($kindText === '' && !empty($businessDefinition['import_cost'])) {
                        $amountText = str_replace([',','¥','￥',' '], '', $lookup($row, 'contract_amount'));
                        $unitText = $lookup($row, 'unit_marker');
                        $shopText = $lookup($row, 'shop');
                        if (in_array('退款冲减', $orderKinds, true) && is_numeric($amountText) && (float)$amountText < 0) $kindText = '退款冲减';
                        elseif (in_array('合并单', $orderKinds, true) && $unitText !== '' && is_numeric($unitText) && (float)$unitText == 0) $kindText = '合并单';
                        elseif (in_array('新订单', $orderKinds, true)) $kindText = '新订单';
                        elseif (in_array('代付版面费', $orderKinds, true) && mb_strpos($lookup($row, 'pay_mode'), '版面费') !== false) $kindText = '代付版面费';
                        elseif (mb_strpos($shopText, '微信') !== false && in_array('微信付款', $orderKinds, true)) $kindText = '微信付款';
                        elseif (in_array('店铺付款', $orderKinds, true)) $kindText = '店铺付款';
                        elseif (in_array('店铺订单', $orderKinds, true)) $kindText = '店铺订单';
                    }
                    // 设计：有“设计师佣金”列的是 PPT 总表，否则是图片总表；同一客服同一客户当月第二单起记“图片同客户”（不计单量）。
                    if ($kindText === '' && $selectedBusiness === '设计') {
                        $kindText = isset($columnMap['ppt_marker']) ? 'PPT' : '图片';
                        $designDate = ps_import_date($lookup($row, 'order_date'));
                        $customerKey = preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_strtolower($lookup($row, 'payment_nickname')));
                        if ($kindText === '图片' && $customerKey !== '' && $designDate) {
                            $monthKey = substr($designDate, 0, 7) . '|' . $lookup($row, 'customer_service') . '|' . $customerKey;
                            $designRepeat = $designRepeat ?? db()->prepare("SELECT 1 FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.commission_group='customer_service' JOIN employees e ON e.id=p.employee_id WHERE o.project_type='设计' AND o.order_kind='图片' AND DATE_FORMAT(o.order_date,'%Y-%m')=? AND e.name=? AND REPLACE(LOWER(o.customer_name),' ','')=? LIMIT 1");
                            $designRepeat->execute([substr($designDate, 0, 7), $lookup($row, 'customer_service'), $customerKey]);
                            if (isset($designSeen[$monthKey]) || $designRepeat->fetchColumn()) $kindText = '图片同客户';
                            $designSeen[$monthKey] = true;
                        }
                    }
                    // 商标业务识别订单类型：小额分表或包含小额 -> 小额返款；备注或类型含“新客” -> 新客户；其余 -> 普通订单。
                    if ($selectedBusiness === '商标') {
                        if (mb_strpos($sheetName, '小额') !== false || mb_strpos($lookup($row, 'order_kind'), '小额') !== false || mb_strpos($lookup($row, 'contact_note'), '小额') !== false) {
                            $kindText = '小额返款';
                        } elseif (mb_strpos($lookup($row, 'order_kind'), '新客') !== false || mb_strpos($lookup($row, 'contact_note'), '新客') !== false || mb_strpos($lookup($row, 'detail:service_type'), '新客') !== false) {
                            $kindText = '新客户';
                        } elseif ($kindText === '') {
                            $kindText = '普通订单';
                        }
                    }
                    $record['kind_missing'] = false;
                    if ($kindText === '' && !empty($businessDefinition['kind_required'])) {
                        // 从业务描述 / 备注猜类型（续费、定制、技术服务），猜不到的在预览里选择
                        $hint = ($record['business_text'] ?? '') . ' ' . $record['contact_note'] . ' ' . $lookup($row, 'detail:make_requirement');
                        foreach (['续费' => '续费', '定制' => '定制', '技术服务' => '技术服务', '维护' => '技术服务'] as $word => $guess) if (in_array($guess, $orderKinds, true) && mb_strpos($hint, $word) !== false) { $record['kind_guess'] = $guess; break; }
                        $aiHint = $kindHint($row);
                        if (empty($record['kind_guess']) && isset($aiKind[$aiHint])) { $record['kind_guess'] = $aiKind[$aiHint]; $record['kind_from_ai'] = true; }
                        $record['kind_missing'] = true;
                        $record['status'] = '需选择订单类型';
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '表格没写订单类型，请在本行选择（决定分成比例和每单补助）';
                    }
                    $record['order_kind'] = $kindText;
                    // 成本（稿费 / 杂志社费用 + 写手费用）：只对代写类业务读取；退款冲减行为负数。
                    $record['direct_cost'] = '';
                    if (!empty($businessDefinition['import_cost'])) {
                        $costTotal = 0.0; $hasCost = false;
                        foreach (['direct_cost', 'direct_cost2'] as $costKey) {
                            $costText = str_replace([',','¥','￥',' '], '', $lookup($row, $costKey));
                            if ($costText === '' || !is_numeric($costText)) continue;
                            $costTotal += (float)$costText; $hasCost = true;
                        }
                        if ($hasCost) $record['direct_cost'] = number_format($costTotal, 2, '.', '');
                    }
                    $record['domain_used'] = $businessDefinition['resources'] ? $lookup($row, 'domain_used') : '否';
                    $record['ssl_used'] = $businessDefinition['resources'] ? $lookup($row, 'ssl_used') : '';
                    $record['resource_note'] = $businessDefinition['resources'] ? $lookup($row, 'resource_note') : '';
                    $record['program_name'] = $usesProgram ? $lookup($row, 'program_name') : '';
                    $record['program_template_id'] = 0;
                    $record['lines'] = [$record['line']];
                    $rawDetails = [];
                    foreach ($businessDefinition['fields'] as $key => $label) $rawDetails[$key] = $lookup($row, 'detail:' . $key);
                    $record['details'] = ps_business_details($selectedBusiness, $rawDetails);
                    if ($record['ssl_used'] !== '' && !in_array($record['ssl_used'], ['无','否'], true) && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $record['ssl_used']) || (float)$record['ssl_used'] > 999999999999.99)) throw new RuntimeException('SSL 真实成本无效，请填写金额、0 或无');
                    if ($record['order_no'] === '' || strlen($record['order_no']) > 100 || !$record['order_date'] || ($record['contract_amount'] !== '' && !preg_match(($record['order_kind'] === '退款冲减' ? '/^-?' : '/^') . '\d+(?:\.\d{1,2})?$/', $record['contract_amount'])) || (float)$record['contract_amount'] > 999999999999.99) throw new RuntimeException(!$record['order_date'] ? '日期无法识别' : ($record['order_no'] === '' ? '缺少订单编号' : '订单号或售价无效'));
                    if ($existing) {
                        $conflicts = ps_customer_intake_conflicts($existing, $record);
                        if ($conflicts) throw new RuntimeException('原单与上传表的' . implode('、', $conflicts) . '不一致，请由财务核对');
                    }
                    $cs = ps_import_names($lookup($row, 'customer_service'), $employeesByName, $selectedBusiness);
                    $front = ps_import_names($lookup($row, 'frontend'), $employeesByName, $selectedBusiness);
                    $back = ps_import_names($lookup($row, 'backend'), $employeesByName, $selectedBusiness);
                    foreach ($cs as $id => $name) $record['people']['customer_service'][$id] = ['id' => $id, 'role' => '客服', 'name' => $name];
                    foreach ($front as $id => $name) $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['frontend'], 'name' => $name];
                    foreach ($back as $id => $name) {
                        if (isset($record['people']['technical'][$id])) $record['people']['technical'][$id]['role'] .= '/' . $peopleLabels['backend'];
                        else $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['backend'], 'name' => $name];
                    }
                    if ($actor['role'] !== 'finance') {
                        $selfId = (int)$actor['employee_id'];
                        if (!isset($record['people']['technical'][$selfId]) && !isset($record['people']['customer_service'][$selfId])) {
                            $selfGroup = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                            // 没有填写本组人员时可由上传人接单；若表格明确写了别人，不能擅自把订单据为己有。
                            $groupHasNamedPerson = $selfGroup === 'technical' ? (bool)($front || $back) : (bool)$cs;
                            if (!$groupHasNamedPerson) {
                                $record['people'][$selfGroup][$selfId] = ['id' => $selfId, 'role' => $selfGroup === 'technical' ? $peopleLabels['frontend'] : '客服', 'name' => $actorName ?? '本人'];
                                if ($selfGroup === 'technical') $front[$selfId] = true; else $cs[$selfId] = true;
                            }
                        }
                    }
                    if (!$cs && !$front && !$back) throw new RuntimeException('至少需要匹配一名客服或技术参与人');
                    if ($actor['role'] !== 'finance') {
                        $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                        // 代写类：编辑员（客服账号）在代写订单上是“对接编辑”，本人在任一组即可
                        if (!empty($businessDefinition['import_cost']) && !isset($record['people'][$group][(int)$actor['employee_id']]) && isset($record['people']['technical'][(int)$actor['employee_id']])) $group = 'technical';
                        if (!isset($record['people'][$group][(int)$actor['employee_id']])) throw new RuntimeException('此行未写本人为' . ($group === 'technical' ? '技术' : '客服') . '，不可导入他人订单');
                        if (!empty($record['attach_check']) && ps_import_group_taken((int)$record['existing_order_id'], $group)) throw new RuntimeException('该订单号已存在且已有' . ($group === 'technical' ? '对接编辑 / 技术' : '客服') . '，请由财务核对');
                    }
                    if (!$existing && $actor['role'] === 'customer_service' && ps_business_requires_technical($selectedBusiness)) {
                        if (!$record['people']['technical']) throw new RuntimeException('客服导入新订单须指定接单技术');
                        foreach ($record['people']['technical'] as $person) if (!ps_active_employee_for_business($person['id'], 'technical', $selectedBusiness)) throw new RuntimeException('接单技术未开通此业务的有效账号');
                    }
                    if ($resourceSelection && !$record['resource_locked'] && $usesProgram && $record['program_name'] !== '') {
                        $programSuggestion = ps_intake_program_suggestion($record['program_name'], $programTemplates);
                        if ($programSuggestion) $record['program_template_id'] = (int)$programSuggestion['id'];
                        else $record['warning'] .= ($record['warning'] ? '；' : '') . '程序名称“' . $record['program_name'] . '”未唯一匹配套餐，请在下方选择';
                    }
                    $record['domain_mode'] = !$businessDefinition['resources'] ? 'none' : ($resourceSelection && !$record['resource_locked'] ? ($hasDomainColumn ? ps_import_domain_mode($record['domain_used']) : 'pending') : 'pending');
                    // 程序套餐已含空间和域名；表格未写域名时不再要求另选域名。
                    if (in_array($record['domain_mode'], ['', 'pending'], true) && $record['program_template_id'] && $resourceSelection && !$record['resource_locked']) $record['domain_mode'] = 'none';
                    if ($record['domain_mode'] === 'pending' && $resourceSelection && !$record['resource_locked']) $record['warning'] .= ($record['warning'] ? '；' : '') . '表格没有域名列，资源留待技术在结算单确认';
                    if ($resourceSelection && !$record['resource_locked'] && $record['domain_mode'] === 'template') {
                        $suggestion = ps_intake_domain_suggestion($record['resource_note'], $domainTemplates);
                        if ($suggestion) $record['domain_template_id'] = (int)$suggestion['id'];
                        else { $record['status'] = '需选择域名模板'; $record['warning'] = '表格只写“是”，未能唯一确认域名规格与周期'; }
                    } elseif ($resourceSelection && !$record['resource_locked'] && $record['domain_mode'] === '') {
                        $record['status'] = '需确认域名';
                        $record['warning'] = '域名使用未明确写是/否，请在下方选择';
                    }
                    if ($record['existing_order_id']) $record['warning'] .= ($record['warning'] ? '；' : '') . '将补充到同一订单号，不会新建订单';
                    if (is_numeric($record['ssl_used']) && (float)$record['ssl_used'] > 0) $record['warning'] .= ($record['warning'] ? '；' : '') . 'SSL 实际成本需创建后补凭证';
                } catch (RuntimeException $e) { $record['base_valid'] = false; $record['status'] = '需处理'; $record['error'] = $e->getMessage(); }
                // 同一订单号的多行（加购、补差价、SSL 追加）合并为一张订单：售价与 SSL 相加，参与人取并集。
                $orderKey = $record['order_no'] ?? '';
                if ($orderKey !== '' && isset($seen[$orderKey])) {
                    $target = &$preview[$seen[$orderKey]];
                    $target['lines'][] = $record['line'];
                    if (empty($record['base_valid']) || empty($target['base_valid'])) {
                        $target['base_valid'] = false;
                        $target['status'] = '需处理';
                        $target['error'] = trim(($target['error'] ?? '') . '；同号第 ' . $record['line'] . ' 行：' . ($record['error'] ?: '所在订单有错误'), '；');
                    } else {
                        if ($record['contract_amount'] !== '') $target['contract_amount'] = number_format((float)$target['contract_amount'] + (float)$record['contract_amount'], 2, '.', '');
                        if (($record['direct_cost'] ?? '') !== '') $target['direct_cost'] = number_format((float)($target['direct_cost'] ?? 0) + (float)$record['direct_cost'], 2, '.', '');
                        if (is_numeric($record['ssl_used']) && (float)$record['ssl_used'] > 0) $target['ssl_used'] = number_format((float)(is_numeric($target['ssl_used']) ? $target['ssl_used'] : 0) + (float)$record['ssl_used'], 2, '.', '');
                        foreach (['technical', 'customer_service'] as $groupKey) foreach ($record['people'][$groupKey] as $personId => $person) $target['people'][$groupKey][$personId] = $target['people'][$groupKey][$personId] ?? $person;
                        foreach ($record['details'] as $key => $value) if ($value !== '' && ($target['details'][$key] ?? '') === '') $target['details'][$key] = $value;
                        foreach (['payment_nickname', 'contact_note', 'resource_note', 'order_kind', 'program_name', 'shop'] as $field) if (($target[$field] ?? '') === '' && ($record[$field] ?? '') !== '') $target[$field] = $record[$field];
                        if (!$target['program_template_id'] && $record['program_template_id']) $target['program_template_id'] = $record['program_template_id'];
                        if ($record['domain_mode'] === 'template' && $target['domain_mode'] !== 'template') { $target['domain_mode'] = 'template'; $target['domain_template_id'] = $record['domain_template_id']; $target['status'] = $record['status']; }
                        if ($record['delivery_status'] === 'unfinished') $target['delivery_status'] = 'unfinished';
                        $target['warning'] = trim(($target['warning'] ?? '') . '；已合并同号第 ' . $record['line'] . ' 行（加购 / 补差价），售价合计 ¥' . $target['contract_amount'], '；');
                    }
                    unset($target);
                    continue;
                }
                if ($orderKey !== '') $seen[$orderKey] = count($preview);
                $preview[] = $record;
            }
            }
            if (!$usedSheets) {
                $reasons = [];
                foreach ($sheetReport as $name => $info) $reasons[] = '“' . $name . '”' . $info['reason'];
                throw new RuntimeException('没有与“' . $selectedBusiness . '”表头对应的工作表（' . implode('；', $reasons) . '）。请确认业务类型，或下载该业务模板对照表头');
            }
            ps_import_file_mark($fileId, 'preview', ['sheets_used' => mb_substr(implode('、', array_keys(array_filter($sheetReport, function ($i) { return $i['used']; }))), 0, 500), 'rows_total' => count($preview)]);
            $_SESSION['project_import_preview'] = $preview;
            $_SESSION['project_import_actor'] = $actorKey;
            $_SESSION['project_import_business'] = $selectedBusiness;
            $_SESSION['project_import_file'] = $fileId;
            $_SESSION['project_import_sheets'] = $sheetReport;
        } elseif ($action === 'commit') {
            if (!$preview || $previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness) throw new RuntimeException('预览已失效，请重新上传');
            $choices = $_POST['domain_choice'] ?? [];
            $serverChoices = $_POST['server_template_id'] ?? [];
            $programChoices = $_POST['program_choice'] ?? [];
            $kindChoices = $_POST['kind_choice'] ?? [];
            $commitKinds = ps_business_order_kinds($selectedBusiness);
            $kindMissingLines = [];
            $ready = [];
            foreach ($preview as $row) {
                if (empty($row['base_valid'])) { $skipped++; continue; }
                $line = (int)$row['line'];
                $pickedKind = trim((string)($kindChoices[$line] ?? ''));
                if ($pickedKind !== '' && in_array($pickedKind, $commitKinds, true)) $row['order_kind'] = $pickedKind;
                if (($row['order_kind'] ?? '') === '' && !empty($businessDefinition['kind_required'])) { $skipped++; $kindMissingLines[] = $line % 10000; continue; }
                if ($actor['role'] !== 'finance') {
                    $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                    if (!empty($businessDefinition['import_cost']) && !isset($row['people'][$group][(int)$actor['employee_id']]) && isset($row['people']['technical'][(int)$actor['employee_id']])) $group = 'technical';
                    if (!isset($row['people'][$group][(int)$actor['employee_id']])) throw new RuntimeException('第 ' . $line . ' 行不属于当前登录人员，请重新上传核对');
                }
                $needsResources = $resourceSelection && empty($row['resource_locked']);
                $programId = $needsResources && $usesProgram ? (int)($programChoices[$line] ?? $row['program_template_id']) : 0;
                $programTemplate = $programId > 0 ? ps_intake_template($programId, 'program') : null;
                $choice = $needsResources ? (string)($choices[$line] ?? ($row['domain_mode'] === 'none' || ($programTemplate && in_array($row['domain_mode'], ['', 'pending'], true)) ? 'none' : ($row['domain_mode'] === 'pending' ? 'pending' : ($row['domain_template_id'] ?: '')))) : 'none';
                if ($choice === 'pending' && $programTemplate) $choice = 'none';
                if ($choice === 'pending' && $row['domain_mode'] !== 'pending') $choice = '';
                if ($choice === 'pending') { $ready[] = [$row, null, null, $programTemplate, 'pending']; continue; }
                if ($choice !== 'none' && !ctype_digit($choice)) { $skipped++; continue; }
                $domainTemplate = $choice === 'none' ? null : ps_intake_template((int)$choice, 'domain');
                if ($needsResources && $row['domain_mode'] === 'template' && $choice === 'none') throw new RuntimeException('第 ' . $line . ' 行写了使用域名，不能选无需域名；请修正表格或选择模板');
                if ($needsResources && $row['domain_mode'] === 'none' && $choice !== 'none') throw new RuntimeException('第 ' . $line . ' 行写了无需域名，不能选择域名模板；请修正表格');
                $serverId = $needsResources ? (int)($serverChoices[$line] ?? 0) : 0;
                $serverTemplate = $serverId > 0 ? ps_intake_template($serverId, 'server') : null;
                $ready[] = [$row, $domainTemplate, $serverTemplate, $programTemplate, null];
            }
            if (!$ready) throw new RuntimeException($kindMissingLines ? '请先为每行选择订单类型（可用“全部设为”一次选好）' : '没有已核对可导入的订单；请先补齐域名选项');
            $pdo = db();
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT project_order_import');
            else $pdo->beginTransaction();
            try {
                $insertOrder = $pdo->prepare('INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,note,created_by_admin) VALUES (?,?,?,?,?,?,0,?,?,?,?)');
                foreach ($ready as [$row, $domainTemplate, $serverTemplate, $programTemplate, $forcedMode]) {
                    $existingQuery = $pdo->prepare('SELECT o.id,o.project_type,o.settlement_status,o.shop,o.contract_amount,s.payment_nickname,s.price_source FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no=? FOR UPDATE');
                    $existingQuery->execute([$row['order_no']]);
                    $existing = $existingQuery->fetch();
                    if ($existing) {
                        if (ps_business_normalize($existing['project_type']) !== $selectedBusiness || in_array($existing['settlement_status'], ['approved','locked'], true)) throw new RuntimeException('第 ' . $row['line'] . ' 行订单状态已变化，请重新预览');
                        if (ps_customer_intake_conflicts($existing, $row)) throw new RuntimeException('第 ' . $row['line'] . ' 行买家资料与原单不一致，请重新核对');
                        $orderId = (int)$existing['id'];
                        if (!empty($businessDefinition['import_cost'])) {
                            // 代写类：补充本组尚无人的参与人（客服 / 对接编辑），已有人的组不改动
                            $missing = [];
                            foreach (['technical', 'customer_service'] as $groupKey) if ($row['people'][$groupKey] && !ps_import_group_taken($orderId, $groupKey)) $missing[$groupKey] = array_values($row['people'][$groupKey]);
                            if ($missing) { ps_intake_participants($orderId, $missing, $selectedBusiness); ps_audit('order', $orderId, 'import_add_participants', $actor, ['line' => $row['line'], 'groups' => array_keys($missing)]); }
                        }
                        if ($actor['role'] !== 'finance') {
                            $access = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
                            $access->execute([$orderId, (int)$actor['employee_id']]);
                            if (!$access->fetchColumn()) throw new RuntimeException('第 ' . $row['line'] . ' 行本人尚未关联此订单');
                        }
                        if (in_array($actor['role'], ['customer_service','finance'], true)) {
                            $changed = ps_save_customer_intake($orderId, ['customer_name' => $row['payment_nickname'], 'shop' => $row['shop'], 'contract_amount' => $row['contract_amount'], 'payment_nickname' => $row['payment_nickname']], $actor, true);
                            if ($changed) ps_audit('order', $orderId, 'import_customer_intake', $actor, ['line' => $row['line'], 'fields' => $changed]);
                        }
                        if ($actor['role'] !== 'customer_service' && in_array($selectedBusiness, ['网站模板', '商标'], true)) {
                            $q = $pdo->prepare('SELECT details_json FROM project_order_details WHERE order_id=? FOR UPDATE');
                            $q->execute([$orderId]);
                            $details = json_decode((string)($q->fetchColumn() ?: '{}'), true) ?: [];
                            foreach ($row['details'] as $key => $value) if ($value !== '') $details[$key] = $value;
                            $pdo->prepare('INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)')
                                ->execute([$orderId, $selectedBusiness, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                        }
                        if ($resourceSelection) {
                            $resource = $pdo->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=? FOR UPDATE');
                            $resource->execute([$orderId]);
                            $mode = $resource->fetchColumn();
                            if ($forcedMode === 'pending') {
                                if ($mode === false) ps_intake_save_resources($orderId, 'excel', (int)$row['line'], null, null, is_numeric($row['ssl_used']) ? $row['ssl_used'] : null, 'pending');
                            } elseif ($mode === 'pending') {
                                ps_intake_confirm_resources($orderId, $domainTemplate ? 'template' : 'none', $domainTemplate['id'] ?? 0, $serverTemplate['id'] ?? 0, $actor, $programTemplate['id'] ?? 0);
                            } elseif ($mode === false) {
                                ps_intake_save_resources($orderId, 'excel', (int)$row['line'], $domainTemplate, $serverTemplate, is_numeric($row['ssl_used']) ? $row['ssl_used'] : null, null, $programTemplate);
                                if ($programTemplate) ps_intake_add_template_cost($orderId, $programTemplate, $actor, 'Excel 补充第' . $row['line'] . '行：程序套餐');
                                if ($domainTemplate) ps_intake_add_template_cost($orderId, $domainTemplate, $actor, 'Excel 补充第' . $row['line'] . '行：域名');
                                if ($serverTemplate) ps_intake_add_template_cost($orderId, $serverTemplate, $actor, 'Excel 补充第' . $row['line'] . '行：服务器');
                            } elseif (!$row['resource_locked']) throw new RuntimeException('第 ' . $row['line'] . ' 行资源已被他人确认，请重新预览');
                        }
                        if (($row['order_kind'] ?? '') !== '') $pdo->prepare("UPDATE project_orders SET order_kind=? WHERE id=? AND order_kind=''")->execute([$row['order_kind'], $orderId]);
                        ps_audit('order', $orderId, 'import_supplement', $actor, ['line' => $row['line'], 'order_no' => $row['order_no']]);
                        $imported++;
                        continue;
                    }
                    if (!empty($row['existing_order_id'])) throw new RuntimeException('第 ' . $row['line'] . ' 行原订单已变化，请重新预览');
                    $noteParts = [];
                    if ($row['payment_nickname'] !== '') $noteParts[] = '付款昵称：' . $row['payment_nickname'];
                    if (($row['business_text'] ?? '') !== '') $noteParts[] = '业务说明：' . $row['business_text'];
                    if ($row['contact_note'] !== '') $noteParts[] = '客户联系方式：' . $row['contact_note'];
                    if ($programTemplate) $noteParts[] = '程序套餐：' . $programTemplate['name'] . ' ' . $programTemplate['specification'];
                    elseif ($businessDefinition['resources'] && $forcedMode !== 'pending' && $actor['role'] !== 'customer_service') $noteParts[] = $domainTemplate ? '域名：' . $domainTemplate['name'] . ' ' . $domainTemplate['specification'] : '域名：无需域名';
                    if (count($row['lines'] ?? []) > 1) $noteParts[] = '合并表格第 ' . implode('、', $row['lines']) . ' 行';
                    if ($row['resource_note'] !== '') $noteParts[] = '域名/空间说明：' . $row['resource_note'];
                    if (is_numeric($row['ssl_used']) && (float)$row['ssl_used'] > 0) $noteParts[] = 'SSL 实际成本报备：¥' . $row['ssl_used'] . '（待技术补充成本凭证）';
                    $insertOrder->execute([$row['order_no'], $row['payment_nickname'], $row['project_type'], $row['order_kind'] ?? '', $row['shop'], $row['contract_amount'] === '' ? 0 : $row['contract_amount'], $row['order_date'], $row['delivery_status'], implode('；', $noteParts), $actor['role'] === 'finance' ? $actor['id'] : null]);
                    $orderId = (int)$pdo->lastInsertId();
                    ps_source_record($orderId, $row['contract_amount'] === '' ? 'missing' : 'manual', $row['payment_nickname'], $row['trade_status'] ?? '');
                    ps_save_business_details($orderId, $selectedBusiness, $actor['role'] === 'customer_service' && $selectedBusiness === '网站模板' ? ps_business_details($selectedBusiness, []) : $row['details']);
                    ps_intake_participants($orderId, $row['people'], $selectedBusiness);
                    if ($businessDefinition['resources']) ps_intake_save_resources($orderId, 'excel', (int)$row['line'], $domainTemplate, $serverTemplate, is_numeric($row['ssl_used']) ? $row['ssl_used'] : null, $actor['role'] === 'customer_service' || $forcedMode === 'pending' ? 'pending' : null, $programTemplate);
                    if ($programTemplate) ps_intake_add_template_cost($orderId, $programTemplate, $actor, 'Excel 第' . $row['line'] . '行：程序套餐');
                    if ($domainTemplate) ps_intake_add_template_cost($orderId, $domainTemplate, $actor, 'Excel 第' . $row['line'] . '行：域名');
                    if ($serverTemplate) ps_intake_add_template_cost($orderId, $serverTemplate, $actor, 'Excel 第' . $row['line'] . '行：服务器');
                    if (($row['direct_cost'] ?? '') !== '' && (float)$row['direct_cost'] != 0) {
                        // 部门结算表的稿费 / 杂志社费用：¥500 以内自动通过，超过的由财务审核（与成本中心模板阈值一致）。
                        $costAmount = round((float)$row['direct_cost'], 2);
                        $costStatus = abs($costAmount) <= 500 ? 'approved' : 'pending';
                        $costItemName = $businessDefinition['cost_label'] ?? ($selectedBusiness === '期刊' ? '杂志社 / 写手费用' : '写手稿费');
                        $pdo->prepare("INSERT INTO project_costs (order_id,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,'outsourcing',?,1,'项',?,?,'one_time',1,?,?,?)")
                            ->execute([$orderId, $costItemName, $costAmount, $costAmount, 'Excel 第' . $row['line'] . '行导入', $costStatus, $actor['employee_id'] ?? null]);
                    }
                    ps_audit('order', $orderId, 'import', $actor, ['line' => $row['line'], 'order_no' => $row['order_no'], 'domain_template_id' => $domainTemplate['id'] ?? null]);
                    $imported++;
                }
                if ($nested) $pdo->exec('RELEASE SAVEPOINT project_order_import');
                else $pdo->commit();
                if (!empty($_SESSION['project_import_file'])) ps_import_file_mark((int)$_SESSION['project_import_file'], 'imported', ['imported_count' => $imported, 'skipped_count' => $skipped]);
                unset($_SESSION['project_import_preview'], $_SESSION['project_import_actor'], $_SESSION['project_import_business'], $_SESSION['project_import_file'], $_SESSION['project_import_sheets']);
                $preview = [];
            } catch (Throwable $e) { if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_order_import'); else $pdo->rollBack(); throw $e; }
        } else throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '导入发生订单号冲突或保存失败，请重新上传预览' : $e->getMessage(); }
}
$baseValidCount = count(array_filter($preview, function ($row) { return !empty($row['base_valid']); }));
$page_title = '导入项目订单';
include __DIR__ . '/../includes/header.php';
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · 批量录入</div><h2>导入<?php echo e($selectedBusiness ?: '项目'); ?>订单</h2><p>先选业务模板，再拖入 Excel 逐行核对。已关联人员上传相同订单号时补充原单，不会重复建单；售价不直接作为实收。</p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/index.php?business=<?php echo rawurlencode($selectedBusiness ?: ''); ?>">返回订单录入</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($imported): ?><div class="alert alert-success">已导入 <?php echo $imported; ?> 个订单<?php echo $skipped ? '；另有 ' . $skipped . ' 行未通过核对，未写入' : ''; ?>。<?php echo $businessDefinition['resources'] ? '已选择的标准域名/服务器成本按模板价生成；' : ''; ?>实收仍须财务确认。</div><?php endif; ?>
<?php if (!$selectedBusiness): ?><div class="alert alert-warning">当前账户尚未分配业务，请联系财务配置。</div><?php else: ?>
<div class="card project-form-card mb-3"><div class="card-body"><div class="project-section-title"><span class="project-step">01</span><div><h5>上传订单表</h5><p>支持 .xlsx / .csv，最多 1500 行、5 MB。技术和客服只能导入写有本人参与的订单；网站客服新单须指定接单技术。</p></div></div>
<form method="get" class="form-inline mb-3"><label class="mr-2" for="importBusiness">业务模板</label><select id="importBusiness" name="business" class="form-control mr-2" onchange="this.form.submit()"><?php foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $selectedBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select><a class="btn btn-outline-success" href="?business=<?php echo rawurlencode($selectedBusiness); ?>&download=1">下载此业务 CSV 表头</a></form>
<form method="post" enctype="multipart/form-data" id="projectUploadForm" data-legacy-xls-upload><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><input type="file" name="parsed_file" hidden><label for="projectImportFile" id="projectDropZone" class="project-drop-zone"><i class="fas fa-cloud-upload-alt"></i><strong>拖拽 Excel 到这里，或点击选择文件</strong><span id="projectFileName">尚未选择文件</span><input type="file" id="projectImportFile" name="file" accept=".xlsx,.xls,.csv" required></label><button class="btn btn-success btn-lg mt-3" type="submit">上传并核对每一行</button><small class="d-block text-muted mt-2" data-xls-status>旧版 XLS 可直接上传，原件会保留。</small></form></div></div>
<?php endif; ?>
<?php
$previewSheets = $preview ? array_filter($_SESSION['project_import_sheets'] ?? [], function ($i) { return !empty($i['used']); }) : [];
$sheetReport = $preview ? ($_SESSION['project_import_sheets'] ?? []) : [];
$previewFileId = $preview ? (int)($_SESSION['project_import_file'] ?? 0) : 0;
?>
<?php if ($preview && count($sheetReport) > 1): ?>
<form method="post" class="card project-form-card mb-3"><div class="card-body"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="repreview"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><input type="hidden" name="file_id" value="<?php echo $previewFileId; ?>">
<div class="project-mini-title mb-2">这个表格有 <?php echo count($sheetReport); ?> 张工作表 <small>表头能对上“<?php echo e($selectedBusiness); ?>”的已自动勾选；取消不需要的分表后点“重新预览”，不用重新上传。</small></div>
<div class="d-flex flex-wrap" style="gap:8px 16px"><?php foreach ($sheetReport as $name => $info): ?><label class="mb-0 <?php echo empty($info['matchable']) ? 'text-muted' : ''; ?>"><input type="checkbox" name="sheets[]" value="<?php echo e($name); ?>" <?php echo !empty($info['used']) ? 'checked' : ''; ?> <?php echo empty($info['matchable']) ? 'disabled' : ''; ?>> <?php echo e($name); ?><?php echo isset($info['rows']) ? ' · ' . (int)$info['rows'] . ' 行' : ''; ?><?php echo empty($info['matchable']) ? '（' . e(mb_strimwidth($info['reason'], 0, 40, '…')) . '）' : ''; ?></label><?php endforeach; ?></div>
<button class="btn btn-outline-primary btn-sm mt-2">重新预览所选工作表</button> <a class="btn btn-link btn-sm mt-2" href="<?php echo BASE_URL; ?>/project/files.php?view=<?php echo $previewFileId; ?>" target="_blank" rel="noopener">查看原始表格</a>
</div></form>
<?php endif; ?>
<?php $previewKinds = $preview ? ps_business_order_kinds($selectedBusiness) : []; ?>
<?php foreach ($previewSheets as $sheetName => $info): if (empty($info['ai'])) continue; ?><div class="alert alert-info"><i class="fas fa-robot mr-1"></i><?php echo count($previewSheets) > 1 ? '【' . e($sheetName) . '】' : ''; ?><?php echo e($info['ai']); ?> 请核对下方识别结果。</div><?php endforeach; ?>
<?php if ($preview): ?>
<form method="post" class="card project-form-card mb-3"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="commit"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><div class="card-body pb-2"><?php if ($previewKinds): ?><div class="project-kind-bulk d-flex flex-wrap align-items-center mb-2" style="gap:8px"><span class="small text-muted">订单类型：</span><select class="form-control form-control-sm" id="kindBulk" style="width:auto" aria-label="批量设置订单类型"><option value="">未选的行全部设为…</option><?php foreach ($previewKinds as $k): ?><option value="<?php echo e($k); ?>"><?php echo e($k); ?></option><?php endforeach; ?></select><label class="small mb-0"><input type="checkbox" id="kindBulkAll"> 已选的行也改</label></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var bulk = document.getElementById('kindBulk'); if (!bulk) return;
  bulk.addEventListener('change', function () {
    var all = document.getElementById('kindBulkAll').checked;
    document.querySelectorAll('.js-kind-choice').forEach(function (sel) { if (bulk.value && (all || !sel.value)) { sel.value = bulk.value; sel.classList.remove('is-invalid'); } });
  });
  document.querySelectorAll('.js-kind-choice').forEach(function (sel) { sel.addEventListener('change', function () { sel.classList.toggle('is-invalid', !sel.value); }); });
});
</script><?php endif; ?><div class="project-section-title"><span class="project-step">02</span><div><h5>核对预览</h5><p><?php echo $baseValidCount; ?> 行基础资料通过<?php echo $resourceSelection ? '；缺失域名规格的行请选标准模板，服务器成本可选填' : '；客服提交后由技术在同一订单确认资源与成本'; ?>。</p></div></div></div><div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>行 / 订单</th><th>日期 / 售价</th><th>参与人</th><?php if ($businessDefinition['fields']): ?><th>业务信息</th><?php endif; ?><?php if ($resourceSelection && $usesProgram): ?><th>程序套餐</th><?php endif; ?><?php if ($resourceSelection): ?><th>域名选择与标准成本</th><th>服务器成本</th><?php endif; ?><th>核对结果</th></tr></thead><tbody>
<?php foreach ($preview as $row): ?><tr class="<?php echo empty($row['base_valid']) ? 'table-danger' : ($row['status'] === '可导入' ? '' : 'table-warning'); ?>">
<td><small><?php echo count($previewSheets) > 1 && !empty($row['sheet']) ? '【' . e($row['sheet']) . '】' : ''; ?>第 <?php echo e(implode('、', array_map(function ($l) { return (int)$l % 10000; }, $row['lines'] ?? [$row['line']]))); ?> 行</small><br><strong><?php echo e($row['order_no'] ?? '—'); ?></strong><br><small><?php echo e($row['project_type'] ?? ''); ?></small></td>
<td><?php echo e($row['order_date'] ?? '—'); ?><br><strong>¥<?php echo e(($row['contract_amount'] ?? '') === '' ? '待补' : $row['contract_amount']); ?></strong><?php if ($previewKinds && !empty($row['base_valid'])): $currentKind = ($row['order_kind'] ?? '') !== '' ? $row['order_kind'] : ($row['kind_guess'] ?? ''); ?><br><select class="form-control form-control-sm mt-1 js-kind-choice<?php echo ($row['order_kind'] ?? '') === '' ? ' is-invalid' : ''; ?>" name="kind_choice[<?php echo (int)$row['line']; ?>]" aria-label="订单类型"><option value="">选择订单类型</option><?php foreach ($previewKinds as $k): ?><option value="<?php echo e($k); ?>" <?php echo $currentKind === $k ? 'selected' : ''; ?>><?php echo e($k); ?></option><?php endforeach; ?></select><?php if (($row['order_kind'] ?? '') === '' && !empty($row['kind_guess'])): ?><small class="text-muted"><?php echo !empty($row['kind_from_ai']) ? 'AI 建议' : '按描述猜测'; ?>，请确认</small><?php endif; ?><?php elseif (($row['order_kind'] ?? '') !== ''): ?><br><small class="text-muted"><?php echo e($row['order_kind']); ?></small><?php endif; ?></td>
<td><small>客服：<?php echo e(implode('、', array_column($row['people']['customer_service'], 'name')) ?: '—'); ?><br>技术：<?php echo e(implode('、', array_column($row['people']['technical'], 'name')) ?: '—'); ?></small></td>
<?php if ($businessDefinition['fields']): ?><td><small><?php foreach ($businessDefinition['fields'] as $key => $label): ?><?php echo e($label . '：' . ps_contact_for($actor, ($row['details'][$key] ?? '') ?: '—', $key === 'customer_wechat')); ?><br><?php endforeach; ?></small></td><?php endif; ?>
<?php if ($resourceSelection && $usesProgram): ?><td><?php if (!empty($row['resource_locked'])): ?><span class="text-muted">原单已确认</span><?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="program_choice[<?php echo (int)$row['line']; ?>]" aria-label="第<?php echo (int)$row['line']; ?>行程序套餐"><option value="0">不使用程序套餐</option><?php foreach ($programTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo (int)($row['program_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name'] . ' · ' . $t['specification'] . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><small class="text-muted">原表：<?php echo e(($row['program_name'] ?? '') ?: '未写程序名称'); ?></small><?php else: ?>—<?php endif; ?></td><?php endif; ?>
<?php if ($resourceSelection): ?>
<td><?php if (!empty($row['resource_locked'])): ?><span class="text-muted">原单已确认</span><?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="domain_choice[<?php echo (int)$row['line']; ?>]" aria-label="第<?php echo (int)$row['line']; ?>行域名"><option value="">请选择域名方式</option><?php if (($row['domain_mode'] ?? '') === 'pending'): ?><option value="pending" selected>待技术确认</option><?php endif; ?><option value="none" <?php echo $row['domain_mode'] === 'none' ? 'selected' : ($row['domain_mode'] === 'template' ? 'disabled' : ''); ?>>无需域名</option><?php foreach ($domainTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$row['domain_template_id'] === (int)$t['id'] ? 'selected' : ($row['domain_mode'] === 'none' ? 'disabled' : ''); ?>><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><small class="text-muted">原表：<?php echo e(($row['domain_used'] ?: '空白') . ' · ' . ($row['resource_note'] ?: '未写域名/空间')); ?></small><?php else: ?>—<?php endif; ?></td>
<td><?php if (!empty($row['resource_locked'])): ?>—<?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="server_template_id[<?php echo (int)$row['line']; ?>]"><option value="0">不自动计服务器</option><?php foreach ($serverTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><?php else: ?>—<?php endif; ?></td>
<?php endif; ?>
<td><span class="badge badge-<?php echo empty($row['base_valid']) ? 'danger' : ($row['status'] === '可导入' ? 'success' : 'warning'); ?>"><?php echo e($row['status']); ?></span><?php if ($row['error']): ?><div class="small text-danger mt-1"><?php echo e($row['error']); ?></div><?php endif; ?><?php if ($row['warning']): ?><div class="small text-warning mt-1"><?php echo e($row['warning']); ?></div><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="card-body border-top d-flex flex-wrap justify-content-between align-items-center"><small class="text-muted">只导入已核对的行；需要修正原表的行重新上传。售价不会直接变成实收<?php echo $businessDefinition['resources'] ? '，SSL 报备价不会直接入成本' : ''; ?>。</small><button class="btn btn-success btn-lg mt-2" type="submit">确认导入已核对订单</button></div></form>
<?php endif; ?>
</div>
<script>
(function () {
  var zone = document.getElementById('projectDropZone');
  var input = document.getElementById('projectImportFile');
  var label = document.getElementById('projectFileName');
  if (!zone || !input) return;
  input.addEventListener('change', function () { label.textContent = input.files.length ? input.files[0].name : '尚未选择文件'; });
  ['dragenter','dragover'].forEach(function (eventName) { zone.addEventListener(eventName, function (event) { event.preventDefault(); zone.classList.add('is-dragging'); }); });
  ['dragleave','drop'].forEach(function (eventName) { zone.addEventListener(eventName, function (event) { event.preventDefault(); zone.classList.remove('is-dragging'); }); });
  zone.addEventListener('drop', function (event) { if (!event.dataTransfer.files.length) return; input.files = event.dataTransfer.files; label.textContent = input.files[0].name; });
})();
</script>
<script src="<?php echo BASE_URL; ?>/assets/lib/xlsx.full.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/project-xls-upload.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
