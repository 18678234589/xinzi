<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
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
        if ($action === 'preview') {
            if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || $_FILES['file']['size'] > 5 * 1024 * 1024) throw new RuntimeException('请选择不超过 5MB 的 XLSX 或 CSV 文件');
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx','csv'], true)) throw new RuntimeException('文件仅支持 XLSX 或 CSV');
            if ($ext === 'xlsx') $raw = SimpleXLSX::parse($_FILES['file']['tmp_name']);
            else {
                $raw = []; $handle = fopen($_FILES['file']['tmp_name'], 'rb');
                while (($line = fgetcsv($handle)) !== false && count($raw) <= 501) $raw[] = array_map(function ($v) { return mb_convert_encoding($v, 'UTF-8', 'UTF-8,GBK,GB2312'); }, $line);
                fclose($handle);
            }
            if (count($raw) < 2 || count($raw) > 502) throw new RuntimeException('文件须包含表头与数据，且一次最多 500 行');
            $head = array_map(function ($v) { return trim((string)$v); }, array_shift($raw));
            $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0] ?? '');
            // 表头按别名匹配：原 AI 定制模板、部门现有表（付款账号 / 接单日期 / 到账情况 / 程序名称…）都可直接上传。
            try { $columnMap = ps_business_import_map($selectedBusiness, $head); } catch (RuntimeException $e) { throw new RuntimeException('当前选中“' . $selectedBusiness . '”：' . $e->getMessage()); }
            $lookup = function ($row, $key) use ($columnMap) { return isset($columnMap[$key]) ? trim((string)($row[$columnMap[$key]] ?? '')) : ''; };
            $hasDomainColumn = isset($columnMap['domain_used']);
            $orderKinds = ps_business_order_kinds($selectedBusiness);
            $employeesByName = ps_import_employee_index();
            $knownShops = db()->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN);
            $seen = [];
            $preview = [];
            $exists = db()->prepare('SELECT o.id,o.project_type,o.settlement_status,o.shop,o.contract_amount,s.payment_nickname,s.price_source FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no=? LIMIT 1');
            $existingAccess = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
            $existingResource = db()->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=?');
            foreach ($raw as $index => $row) {
                if (!array_filter($row, function ($v) { return trim((string)$v) !== ''; })) continue;
                $record = ['line' => $index + 2, 'status' => '可导入', 'error' => '', 'warning' => '', 'base_valid' => true, 'people' => ['technical' => [], 'customer_service' => []], 'domain_mode' => '', 'domain_template_id' => 0];
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
                            if (!$existingAccess->fetchColumn()) throw new RuntimeException('该订单号已存在，但本人尚未被关联；请由参与人或财务关联后再上传');
                        }
                        $existingResource->execute([(int)$existing['id']]);
                        $existingMode = $existingResource->fetchColumn();
                        $record['resource_locked'] = $existingMode !== false && $existingMode !== 'pending';
                        $record['status'] = '补充已有订单';
                    }
                    $record['order_date'] = ps_import_date($lookup($row, 'order_date'));
                    $record['contract_amount'] = str_replace([',','¥','￥',' '], '', $lookup($row, 'contract_amount'));
                    if (preg_match('/^\d+\.\d{3,}$/', $record['contract_amount'])) $record['contract_amount'] = number_format((float)$record['contract_amount'], 2, '.', '');
                    $status = $lookup($row, 'status');
                    $record['delivery_status'] = ps_import_delivery_status($status);
                    if ($record['delivery_status'] === null) throw new RuntimeException('状态“' . $status . '”无法识别，请写已完成 / 未完成（或到账、已发货等）');
                    $record['trade_status'] = mb_strpos($status, '交易关闭') !== false ? '交易关闭' : '';
                    if ($record['trade_status'] !== '') $record['warning'] = '表格写交易关闭：请财务核对退款';
                    $sheetBusiness = $lookup($row, 'business');
                    if ($sheetBusiness !== '' && ps_business_normalize($sheetBusiness) !== $selectedBusiness) throw new RuntimeException('表格业务与当前选中业务不一致');
                    $record['project_type'] = $selectedBusiness;
                    $record['shop'] = $lookup($row, 'shop');
                    if ($record['shop'] !== '' && !in_array($record['shop'], $knownShops, true)) throw new RuntimeException('店铺不在店铺管理列表中，请先核对');
                    $record['payment_nickname'] = $lookup($row, 'payment_nickname');
                    $record['contact_note'] = $lookup($row, 'contact_note');
                    // 小程序结算表的“备注”常写 新订单 / 续费 / 定制：识别为订单类型。
                    $kindText = $lookup($row, 'order_kind');
                    if ($kindText === '' && in_array($record['contact_note'], $orderKinds, true)) { $kindText = $record['contact_note']; $record['contact_note'] = ''; }
                    if ($kindText !== '' && !in_array($kindText, $orderKinds, true)) throw new RuntimeException('订单类型“' . $kindText . '”无效，可选：' . implode('、', $orderKinds));
                    if ($kindText === '' && !empty($businessDefinition['kind_required'])) throw new RuntimeException('缺少订单类型（新订单 / 定制 / 续费），可在“订单类型”列或“备注”列填写');
                    $record['order_kind'] = $kindText;
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
                    if ($record['order_no'] === '' || strlen($record['order_no']) > 100 || !$record['order_date'] || ($record['contract_amount'] !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $record['contract_amount'])) || (float)$record['contract_amount'] > 999999999999.99) throw new RuntimeException(!$record['order_date'] ? '日期无法识别' : ($record['order_no'] === '' ? '缺少订单编号' : '订单号或售价无效'));
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
                    if (!$cs && !$front && !$back) throw new RuntimeException('至少需要匹配一名客服或技术参与人');
                    if ($actor['role'] !== 'finance') {
                        $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                        if (!isset($record['people'][$group][(int)$actor['employee_id']])) throw new RuntimeException('此行未写本人为' . ($group === 'technical' ? '技术' : '客服') . '，不可导入他人订单');
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
            $_SESSION['project_import_preview'] = $preview;
            $_SESSION['project_import_actor'] = $actorKey;
            $_SESSION['project_import_business'] = $selectedBusiness;
        } elseif ($action === 'commit') {
            if (!$preview || $previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness) throw new RuntimeException('预览已失效，请重新上传');
            $choices = $_POST['domain_choice'] ?? [];
            $serverChoices = $_POST['server_template_id'] ?? [];
            $programChoices = $_POST['program_choice'] ?? [];
            $ready = [];
            foreach ($preview as $row) {
                if (empty($row['base_valid'])) { $skipped++; continue; }
                $line = (int)$row['line'];
                if ($actor['role'] !== 'finance') {
                    $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
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
            if (!$ready) throw new RuntimeException('没有已核对可导入的订单；请先补齐域名选项');
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
                        if ($actor['role'] !== 'finance') {
                            $access = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
                            $access->execute([$orderId, (int)$actor['employee_id']]);
                            if (!$access->fetchColumn()) throw new RuntimeException('第 ' . $row['line'] . ' 行本人尚未关联此订单');
                        }
                        if (in_array($actor['role'], ['customer_service','finance'], true)) {
                            $changed = ps_save_customer_intake($orderId, ['customer_name' => $row['payment_nickname'], 'shop' => $row['shop'], 'contract_amount' => $row['contract_amount'], 'payment_nickname' => $row['payment_nickname']], $actor, true);
                            if ($changed) ps_audit('order', $orderId, 'import_customer_intake', $actor, ['line' => $row['line'], 'fields' => $changed]);
                        }
                        if ($actor['role'] !== 'customer_service' && $selectedBusiness === '网站模板') {
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
                    ps_audit('order', $orderId, 'import', $actor, ['line' => $row['line'], 'order_no' => $row['order_no'], 'domain_template_id' => $domainTemplate['id'] ?? null]);
                    $imported++;
                }
                if ($nested) $pdo->exec('RELEASE SAVEPOINT project_order_import');
                else $pdo->commit();
                unset($_SESSION['project_import_preview'], $_SESSION['project_import_actor'], $_SESSION['project_import_business']);
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
<div class="card project-form-card mb-3"><div class="card-body"><div class="project-section-title"><span class="project-step">01</span><div><h5>上传订单表</h5><p>支持 .xlsx / .csv，最多 500 行、5 MB。技术和客服只能导入写有本人参与的订单；网站客服新单须指定接单技术。</p></div></div>
<form method="get" class="form-inline mb-3"><label class="mr-2" for="importBusiness">业务模板</label><select id="importBusiness" name="business" class="form-control mr-2" onchange="this.form.submit()"><?php foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $selectedBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select><a class="btn btn-outline-success" href="?business=<?php echo rawurlencode($selectedBusiness); ?>&download=1">下载此业务 CSV 表头</a></form>
<form method="post" enctype="multipart/form-data" id="projectUploadForm"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><label for="projectImportFile" id="projectDropZone" class="project-drop-zone"><i class="fas fa-cloud-upload-alt"></i><strong>拖拽 Excel 到这里，或点击选择文件</strong><span id="projectFileName">尚未选择文件</span><input type="file" id="projectImportFile" name="file" accept=".xlsx,.csv" required></label><button class="btn btn-success btn-lg mt-3" type="submit">上传并核对每一行</button></form></div></div>
<?php endif; ?>
<?php if ($preview): ?>
<form method="post" class="card project-form-card mb-3"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="commit"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><div class="card-body pb-2"><div class="project-section-title"><span class="project-step">02</span><div><h5>核对预览</h5><p><?php echo $baseValidCount; ?> 行基础资料通过<?php echo $resourceSelection ? '；缺失域名规格的行请选标准模板，服务器成本可选填' : '；客服提交后由技术在同一订单确认资源与成本'; ?>。</p></div></div></div><div class="table-responsive"><table class="table project-preview-table mb-0"><thead><tr><th>行 / 订单</th><th>日期 / 售价</th><th>参与人</th><?php if ($businessDefinition['fields']): ?><th>业务信息</th><?php endif; ?><?php if ($resourceSelection && $usesProgram): ?><th>程序套餐</th><?php endif; ?><?php if ($resourceSelection): ?><th>域名选择与标准成本</th><th>服务器成本</th><?php endif; ?><th>核对结果</th></tr></thead><tbody>
<?php foreach ($preview as $row): ?><tr class="<?php echo empty($row['base_valid']) ? 'table-danger' : ($row['status'] === '可导入' ? '' : 'table-warning'); ?>">
<td><small>第 <?php echo e(implode('、', $row['lines'] ?? [$row['line']])); ?> 行</small><br><strong><?php echo e($row['order_no'] ?? '—'); ?></strong><br><small><?php echo e($row['project_type'] ?? ''); ?></small></td>
<td><?php echo e($row['order_date'] ?? '—'); ?><br><strong>¥<?php echo e(($row['contract_amount'] ?? '') === '' ? '待补' : $row['contract_amount']); ?></strong><?php if (($row['order_kind'] ?? '') !== ''): ?><br><small class="text-muted"><?php echo e($row['order_kind']); ?></small><?php endif; ?></td>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
