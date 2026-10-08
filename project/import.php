<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
poi_ensure();
require_once __DIR__ . '/../includes/ProjectOrderSplit.php';
require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';
require_once __DIR__ . '/../includes/ProjectBusiness.php';
require_once __DIR__ . '/../includes/ProjectOrderSource.php';
require_once __DIR__ . '/../includes/ProjectAiFallback.php';
require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';
require_once __DIR__ . '/../includes/ProjectImportResult.php';
require_once __DIR__ . '/../includes/ProjectImportClassification.php';
require_once __DIR__ . '/../classes/SimpleXLSX.php';
pos_ensure();
$actor = ps_require_actor();
$operator = $actor;
$resumeFileId = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'preview') ? 0 : (int)($_GET['resume_file'] ?? ($_POST['resume_file'] ?? 0));
if (!$resumeFileId && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') !== 'preview' && ($_SESSION['project_import_delegate_operator'] ?? '') === $operator['type'] . ':' . $operator['id']) $resumeFileId = (int)($_SESSION['project_import_delegate_file'] ?? 0);
$resumeFile = null;
if ($resumeFileId > 0) {
    try {
        $resumeFile = ps_import_file_get($resumeFileId, $operator);
        $actor = ps_import_upload_actor($resumeFile, $operator);
        $_SESSION['project_import_delegate_file'] = $resumeFileId;
        $_SESSION['project_import_delegate_operator'] = $operator['type'] . ':' . $operator['id'];
        if (empty($_POST['business'])) $_GET['business'] = $resumeFile['business_name'];
    } catch (RuntimeException $e) { http_response_code(403); exit(e($e->getMessage())); }
} else unset($_SESSION['project_import_delegate_file'], $_SESSION['project_import_delegate_operator']);
$allowedBusinesses = ps_actor_businesses($actor);
$scope = (string)($_POST['scope'] ?? $_GET['scope'] ?? ($resumeFile && ps_department_import_allowed($actor, $resumeFile['business_name']) && $actor['role'] !== 'finance' ? 'department' : (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_SESSION['project_import_scope'] ?? 'personal') : 'personal')));
$scope = $scope === 'department' ? 'department' : 'personal';
$departmentMode = $scope === 'department';
$departmentBusinesses = array_values(array_filter($allowedBusinesses, function ($name) use ($actor) { return ps_department_import_allowed($actor, $name); }));
$requestedBusiness = (string)($_POST['business'] ?? $_GET['business'] ?? '');
if ($departmentMode && $requestedBusiness === '') {
    foreach (['网站续费', '网站修改'] as $candidate) if (in_array($candidate, $allowedBusinesses, true)) { $requestedBusiness = $candidate; break; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedBusiness !== '' && !in_array(ps_business_normalize($requestedBusiness), $allowedBusinesses, true)) { http_response_code(403); exit('当前账户未分配此业务'); }
$selectedBusiness = ps_business_choice($actor, $requestedBusiness);
if (!$selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'POST') { http_response_code(403); exit('当前账户未分配业务类型'); }
if ($departmentMode && !ps_department_import_allowed($actor, $selectedBusiness)) { http_response_code(403); exit('当前账户没有此部门业务的代录权限'); }
$departmentChoices = $departmentMode ? db()->query("SELECT id,name FROM employees WHERE department='网站售后部' ORDER BY name,id")->fetchAll() : [];
$ruleMonth = (string)($_POST['rule_month'] ?? $_GET['rule_month'] ?? $_SESSION['project_import_rule_month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ruleMonth)) $ruleMonth = date('Y-m');
$renewalRates = $departmentMode ? ps_department_renewal_rates($ruleMonth) : [];
$businessDefinition = $selectedBusiness ? ps_business_catalog()[$selectedBusiness] : null;
if (isset($_GET['download']) && $selectedBusiness && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="project-order-template.csv"; filename*=UTF-8' . chr(39) . chr(39) . rawurlencode($selectedBusiness . '-订单模板.csv'));
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    $templateHeaders = ps_business_import_headers($selectedBusiness, $actor);
    fputcsv($output, $templateHeaders);
    // 附一行示例（订单号以“示例”开头，上传时自动跳过），照着填即可
    fputcsv($output, ps_business_import_example_row($selectedBusiness, $templateHeaders));
    fclose($output);
    exit;
}
$error = '';
$businessDetectionNote = '';
$retryFileId = 0;
$imported = 0;
$skipped = 0;
$importReport = [];
$resultFileId = 0;
$preview = $_SESSION['project_import_preview'] ?? [];
$previewOwner = $_SESSION['project_import_actor'] ?? '';
$previewBusiness = $_SESSION['project_import_business'] ?? '';
$previewScope = $_SESSION['project_import_scope'] ?? 'personal';
$departmentDefaults = $departmentMode ? ($_SESSION['project_import_people'] ?? []) : [];
$actorKey = $actor['type'] . ':' . $actor['id'];
if ($previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness || $previewScope !== $scope) $preview = [];
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
        $followup = null;
        $followupCommit = false;
        if (in_array($action, ['preview', 'repreview', 'repair_preview', 'followup'], true)) {
            // 原始表格先保存（财务可在“原始表格”页查看 / 下载）；重新选择工作表时直接读已保存的文件，不必重新上传。
            if ($action === 'preview') {
                if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || $_FILES['file']['size'] > 20 * 1024 * 1024) throw new RuntimeException('请选择不超过 20 MB 的 XLSX、XLS 或 CSV 文件');
                $fileId = ps_import_file_store($_FILES['file'], $selectedBusiness, $actor, $_FILES['parsed_file'] ?? null);
                $retryFileId = $fileId;
                unset($_SESSION['project_import_pending_lines']);
            } elseif ($action === 'repair_preview') {
                if (!$preview || $previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness) throw new RuntimeException('预览已失效，请重新上传');
                $fileId = (int)($_SESSION['project_import_file'] ?? 0);
            } elseif ($action === 'followup') {
                // 弹窗补填：只处理待补全的行，补好后直接导入
                $followup = ps_import_followup_get((int)($_POST['followup_id'] ?? 0), $actor);
                if (!$followup) throw new RuntimeException('这条待补全记录已处理或不存在，请刷新页面');
                $fileId = (int)$followup['file_id'];
            } else $fileId = (int)($_POST['file_id'] ?? 0);
            $fileRow = ps_import_file_get($fileId, $actor);
            if ($action === 'preview' && count($allowedBusinesses) > 1) {
                $detected = ps_import_business_detect($fileRow, $allowedBusinesses, $selectedBusiness, (int)($actor['employee_id'] ?? 0));
                $selectedBusiness = $detected['business'];
                if ($departmentMode && !ps_department_import_allowed($actor, $selectedBusiness)) throw new RuntimeException('表格识别到非网站售后业务，请选择正确业务模板后重新上传');
                $businessDetectionNote = '已按' . $detected['reason'] . '归入“' . $selectedBusiness . '”。若不对，下方可切换业务后重新核对，无需重传表格。';
            }
            if ($fileRow['business_name'] !== $selectedBusiness) {
                db()->prepare('UPDATE project_import_files SET business_name=? WHERE id=?')->execute([$selectedBusiness, $fileId]);
                $fileRow['business_name'] = $selectedBusiness;
            }
            $businessDefinition = ps_require_business($actor, $selectedBusiness);
            $peopleLabels = ps_business_people_labels($selectedBusiness);
            $programTemplates = ps_intake_templates('program', $selectedBusiness);
            $usesProgram = !empty($businessDefinition['program']);
            $resourceSelection = !empty($businessDefinition['resources']) && $actor['role'] !== 'customer_service';
            $chosenSheets = $action === 'repreview' ? (!empty($_POST['all_sheets']) ? null : array_map('strval', (array)($_POST['sheets'] ?? []))) : ($action === 'repair_preview' ? array_keys(array_filter($_SESSION['project_import_sheets'] ?? [], function ($entry) { return !empty($entry['used']); })) : null);
            if ($action === 'followup') $chosenSheets = $followup['sheets'] !== '' ? explode('、', $followup['sheets']) : null;
            $fixOrderNos = in_array($action, ['repair_preview', 'followup'], true) ? (array)($_POST['fix_order_no'] ?? []) : [];
            $fixDates = in_array($action, ['repair_preview', 'followup'], true) ? (array)($_POST['fix_date'] ?? []) : [];
            $fixPaymentReferences = in_array($action, ['repair_preview', 'followup'], true) ? (array)($_POST['fix_payment_reference'] ?? []) : [];
            $sheetReport = [];
            $usedSheets = 0;
            $totalRows = 0;
            $orderKinds = ps_business_order_kinds($selectedBusiness);
            $departmentDefaults = $departmentMode
                ? ps_department_import_people($actor, $selectedBusiness, $action === 'preview' ? (array)($_POST['dept_people'] ?? []) : array_keys((array)($_SESSION['project_import_people'] ?? [])))
                : [];
            $employeesByName = ps_import_employee_index();
            $actorName = null;
            if ($actor['role'] !== 'finance') { $nameQuery = db()->prepare('SELECT name FROM employees WHERE id=?'); $nameQuery->execute([(int)$actor['employee_id']]); $actorName = $nameQuery->fetchColumn() ?: '本人'; }
            $knownShops = db()->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN);
            $seen = [];
            $wxSeq = []; // 无订单号行的同键序号：同一张表里完全相同的行也各得一个稳定的内部号
            $siteSeq = []; // 网站表同一订单号的多行（一个客户做多个网站）：第 2、3… 个网站各记一张订单 订单号#2、#3
            $blankRows = [];
            $preview = [];
            $exists = db()->prepare('SELECT o.id,o.project_type,o.settlement_status,o.shop,o.contract_amount,o.order_date,s.payment_nickname,s.payment_reference,s.price_source FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no=? LIMIT 1');
            $existingAccess = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
            $existingResource = db()->prepare('SELECT domain_mode FROM project_order_resources WHERE order_id=?');
            // 一个工作簿多张分表（如“图片 / PPT / 小额”、每位客服一张）：表头能对上当前业务的分表都读取，可在预览里取消勾选。
            $requirePeople = $actor['role'] === 'finance' && !$departmentMode;
            $importColumns = ps_business_import_columns($selectedBusiness);
            $parsedSheets = [];
            foreach (ps_import_file_sheets($fileRow) as $sheetName => $raw) {
                $raw = array_values(array_filter($raw, function ($r) { return is_array($r); }));
                $head = $raw ? array_map(function ($v) { return trim((string)$v); }, array_shift($raw)) : [];
                if ($head) $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0] ?? '');
                $entry = ['raw' => $raw, 'head' => $head, 'map' => null, 'reason' => '', 'ai' => '', 'line_base' => 2];
                // 森动备案“二次备案”分表 / 备案-单量表没有订单编号列：按“域名 + 联系方式”生成稳定的内部订单号
                // （二次备案 EB-…、备案-单量 BA-…，业务名参与散列避免跨业务撞号），同一行重复上传不会重复建单。
                if (in_array($selectedBusiness, ['森动备案', '备案-单量'], true) && $head && in_array('域名', $head, true) && !array_intersect(['订单编号', '订单号', '订单', '淘宝订单号', '微信交易流水号', '微信支付订单号', '支付订单号'], $head)) {
                    $ebDomain = array_search('域名', $head, true); $ebContact = array_search('联系方式', $head, true);
                    $ebWidth = count($head); $head[] = '订单编号';
                    $ebPrefix = $selectedBusiness === '森动备案' ? 'EB' : 'BA';
                    $ebSalt = $selectedBusiness === '森动备案' ? '' : mb_strtolower($selectedBusiness) . '|';
                    $raw = array_map(function ($r) use ($ebDomain, $ebContact, $ebWidth, $ebPrefix, $ebSalt) {
                        if (!is_array($r)) return $r;
                        $r = array_pad(array_values($r), $ebWidth, '');
                        $dom = trim((string)($r[$ebDomain] ?? '')); $con = $ebContact !== false ? trim((string)($r[$ebContact] ?? '')) : '';
                        $r[] = $dom !== '' ? $ebPrefix . '-' . strtoupper(substr(md5($ebSalt . mb_strtolower($dom) . '|' . $con), 0, 12)) : '';
                        return $r;
                    }, $raw);
                    $entry['raw'] = $raw; $entry['head'] = $head;
                }
                // 森动备案：①“二次备案”分表按“2026年N月份”标题分段，每行取该月最后一天为日期（订单号带月份，同域名不同月份不合并）；
                //   已核算月份及以前的段落不导入。②“备案状态=未备案”且无订单号 / 日期的占位行直接忽略（备案完成后再传）。行号保持不变，忽略的行置空。
                if ($selectedBusiness === '森动备案' && $head && $raw) {
                    $sbDate = array_search('日期', $head, true); $sbStatus = array_search('备案状态', $head, true);
                    $sbSecond = $sbDate === false && in_array('域名', $head, true);
                    if ($sbSecond || $sbStatus !== false) {
                        $sbNoIdx = array_search('订单编号', $head, true); $sbDom = array_search('域名', $head, true); $sbCon = array_search('联系方式', $head, true);
                        $sbSettled = (string)ps_setting_get('refund_settled_through', ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $sbSettled)) $sbSettled = '';
                        if ($sbSecond) { $head[] = '日期'; $sbDate = count($head) - 1; }
                        $sbWidth = count($head); $sbMonth = ''; $sbSettledRows = 0; $sbPlaceholder = 0; $sbMonths = [];
                        $blank = array_fill(0, $sbWidth, '');
                        $nonEmpty = function ($v) { return trim((string)$v) !== ''; };
                        foreach ($raw as $k => $r) {
                            if (!is_array($r)) continue;
                            $r = array_pad(array_values($r), $sbWidth, '');
                            if (!array_filter($r, $nonEmpty)) continue;
                            if ($sbSecond) {
                                $first = trim((string)$r[0]);
                                if ($first !== '' && count(array_filter(array_slice($r, 1, $sbWidth - 2), $nonEmpty)) === 0 && preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月/u', $first, $mm)) { $sbMonth = sprintf('%04d-%02d', $mm[1], $mm[2]); $raw[$k] = $blank; continue; }
                                if ($sbMonth !== '') {
                                    if ($sbSettled !== '' && $sbMonth <= $sbSettled) { $sbSettledRows++; $raw[$k] = $blank; continue; }
                                    $r[$sbDate] = date('Y-m-t', strtotime($sbMonth . '-01'));
                                    if ($sbNoIdx !== false && $sbDom !== false && trim((string)$r[$sbDom]) !== '') $r[$sbNoIdx] = 'EB-' . strtoupper(substr(md5(mb_strtolower(trim((string)$r[$sbDom])) . '|' . ($sbCon !== false ? trim((string)$r[$sbCon]) : '') . '|' . $sbMonth), 0, 12));
                                    $sbMonths[$sbMonth] = ($sbMonths[$sbMonth] ?? 0) + 1;
                                }
                                $raw[$k] = $r;
                            } elseif ($sbStatus !== false && mb_strpos(trim((string)$r[$sbStatus]), '未备案') !== false && ($sbNoIdx === false || trim((string)$r[$sbNoIdx]) === '') && ($sbDate === false || trim((string)$r[$sbDate]) === '')) {
                                $sbPlaceholder++; $raw[$k] = $blank;
                            }
                        }
                        $sbNotes = [];
                        if ($sbSecond && $sbMonths) { ksort($sbMonths); $sbNotes[] = '已按月份标题取日期：' . implode('、', array_map(function ($m, $c) { return $m . ' ' . $c . ' 行'; }, array_keys($sbMonths), $sbMonths)); }
                        if ($sbSettledRows) $sbNotes[] = $sbSettledRows . ' 行属于已核算月份（' . $sbSettled . ' 及以前），不再导入';
                        if ($sbPlaceholder) $sbNotes[] = $sbPlaceholder . ' 行“未备案”占位行已忽略，备案完成后再上传';
                        if ($sbNotes) $entry['ai'] = implode('；', $sbNotes) . '。';
                        $entry['raw'] = $raw; $entry['head'] = $head;
                    }
                }
                // 没有表头、第 1 行就是订单（首行有订单号样式的长数字串）：按各列内容识别，首行也作为订单读取
                $headerlessMap = $head && ps_import_row_is_data($head) ? ps_import_headerless_map(array_merge([$head], $raw), $knownShops, $employeesByName) : null;
                if ($headerlessMap) $entry = ['raw' => array_merge([$head], $raw), 'head' => array_fill(0, count($head), ''), 'map' => $headerlessMap, 'reason' => '', 'ai' => '表格没有表头，已按各列内容识别订单号、日期、售价、客服、技术等。', 'line_base' => 1];
                elseif (!$raw) $entry['reason'] = '没有数据';
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
                $layoutSignature = ps_ai_signature(array_merge([$selectedBusiness], $head));
                $preferredKind = $actor['role'] === 'finance' ? '' : ps_import_kind_preference((int)$actor['employee_id'], $selectedBusiness, $layoutSignature);
                if (!$preferredKind && $actor['role'] !== 'finance') $preferredKind = ps_order_kind_from_role($selectedBusiness, ps_employee_default_role((int)$actor['employee_id'], $selectedBusiness, $actor['role']) ?? '');
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
                    if (count($values) >= 1 && count(array_filter($values, function ($v) { return ps_import_delivery_status($v) !== null; })) >= 0.8 * count($values)) { $columnMap['status'] = $i; break; }
                }
                if ($selectedBusiness === '标书') {
                    // 标书原表：同一订单多位设计师的续行（无订单号、无售价，佣金已计入主行成本）与底部汇总行不当订单；保留原下标对应 Excel 行号
                    $raw = array_filter($raw, function ($r) use ($columnMap) { $get = function ($k) use ($r, $columnMap) { return isset($columnMap[$k]) ? trim((string)($r[$columnMap[$k]] ?? '')) : ''; }; return preg_match('/\d{5,}/', $get('order_no')) || ($get('contract_amount') !== '' && ps_import_date($get('order_date'))); });
                }
                if ($selectedBusiness === '商标') {
                    // 商标原表的错位行归位、汇总行（合计 / 底薪 / 提成）跳过；保留原下标以对应 Excel 行号
                    $fixedRows = [];
                    foreach ($raw as $rawIndex => $rawRow) { $fixedRow = ps_trademark_fix_row($rawRow, $columnMap); if ($fixedRow !== null) $fixedRows[$rawIndex] = $fixedRow; }
                    $raw = $fixedRows;
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
                    if ($statusText !== '' && !is_numeric(str_replace([',', '¥', '￥'], '', $statusText)) && ps_import_delivery_status($statusText, $selectedBusiness) === null) $unknownStatuses[] = $statusText;
                    if (!$preferredKind && !empty($businessDefinition['kind_required']) && $lookup($row, 'order_kind') === '' && !in_array($lookup($row, 'contact_note'), $orderKinds, true) && $lookup($row, 'order_no') !== '') {
                        $hint = $kindHint($row);
                        $guessed = false;
                        foreach (['续费', '定制', '技术服务', '维护'] as $word) if (mb_strpos($hint, $word) !== false) $guessed = true;
                        if ($hint !== '' && !$guessed) $unguessedHints[] = $hint;
                    }
                }
                $aiStatus = $unknownStatuses ? ps_ai_resolve_values('import_status', $selectedBusiness, $unknownStatuses, ['finished', 'unfinished'], '这些是订单表格“状态”列里的写法，请判断订单是否已完成交付/到账：finished = 已完成（可结算），unfinished = 未完成（进行中、未到账、退款中等）。', $actor) : [];
                $aiKindNew = [];
                $aiKind = $unguessedHints ? ps_ai_resolve_values('import_kind', $selectedBusiness, $unguessedHints, $orderKinds, '这些是“' . $selectedBusiness . '”订单的业务描述，请为每条选择最合适的订单类型（决定提成比例）。' . ($selectedBusiness === '小程序开发' ? '新订单 = 用现成模板新建小程序（如 v4 模板、外卖、点餐）；定制 = 按客户需求开发功能 / 系统 / 平台；续费 = 续年费；技术服务 = 小修改、维护、上架代办。' : ''), $actor, $aiKindNew) : [];
            $prevFullRow = null; $prevFullLine = 0;
            foreach ($raw as $index => $row) {
                if (!array_filter($row, function ($v) { return trim((string)$v) !== ''; })) continue;
                if (ps_import_row_is_example($row)) continue; // 模板自带的示例行
                $rowLine = $lineOffset + $index + $entry['line_base'];
                // 没有订单号、没有流水号、也没有金额：预先填好姓名 / 写手号的空白行或纯备注行，不是订单，直接略过
                $amountCell = str_replace([',', '¥', '￥', ' '], '', $lookup($row, 'contract_amount'));
                if (ps_import_numeric_summary($row, $lookup($row, 'order_no'), $lookup($row, 'payment_reference'), $lookup($row, 'order_date'))) { $blankRows[$sheetName] = ($blankRows[$sheetName] ?? 0) + 1; continue; }
                if ($lookup($row, 'order_no') === '' && $lookup($row, 'payment_reference') === '' && trim((string)($fixOrderNos[$rowLine] ?? '')) === '' && trim((string)($fixPaymentReferences[$rowLine] ?? '')) === '' && ($amountCell === '' || (is_numeric($amountCell) && (float)$amountCell == 0))) { $blankRows[$sheetName] = ($blankRows[$sheetName] ?? 0) + 1; continue; }
                // 只写了订单号的续行（同一客户的第二个订单号 / 加购单）：日期、店铺、付款昵称、客服、技术等沿用上一行
                $continuationOf = 0;
                $emptyCell = function ($key) use ($row, $columnMap) { return !isset($columnMap[$key]) || trim((string)($row[$columnMap[$key]] ?? '')) === ''; };
                if ($lookup($row, 'order_no') !== '' && $emptyCell('order_date') && $emptyCell('shop') && $emptyCell('payment_nickname') && $emptyCell('customer_service') && $emptyCell('frontend')) {
                    if ($prevFullRow !== null) {
                        foreach (['order_date', 'shop', 'payment_nickname', 'status', 'customer_service', 'frontend', 'backend', 'business', 'program_name', 'contact_note'] as $inheritKey) if (isset($columnMap[$inheritKey]) && $emptyCell($inheritKey)) $row[$columnMap[$inheritKey]] = $prevFullRow[$columnMap[$inheritKey]] ?? '';
                        $continuationOf = $prevFullLine;
                        if ($amountCell === '' && isset($columnMap['contract_amount'])) {
                            $shopMatches = array_values(array_filter(ps_shop_order_lookup($lookup($row, 'order_no')), function ($m) { return $m['price'] !== null; }));
                            if (count($shopMatches) === 1) $row[$columnMap['contract_amount']] = (string)$shopMatches[0]['price'];
                        }
                    }
                } else { $prevFullRow = $row; $prevFullLine = $rowLine; }
                $record = ['line' => $rowLine, 'sheet' => $sheetName, 'layout_signature' => $layoutSignature, 'business_signature' => ps_import_business_signature($head, $sheetName), 'status' => '可导入', 'error' => '', 'warning' => '', 'base_valid' => true, 'people' => ['technical' => [], 'customer_service' => []], 'domain_mode' => '', 'domain_template_id' => 0];
                require_once __DIR__ . '/../includes/ProjectRenewalImport.php';
                $record['renewal_fields'] = pr_import_fields($head, $row);
                $record['amount_from_shop'] = $continuationOf && $amountCell === '' && $lookup($row, 'contract_amount') !== '';
                if ($continuationOf) $record['warning'] = '此行只写了订单号：日期、店铺、客服、技术等沿用第 ' . ($continuationOf % 10000) . ' 行' . ($amountCell === '' ? ($lookup($row, 'contract_amount') !== '' ? '，售价按店铺流水带入' : '，售价待补（财务核对）') : '');
                try {
                    $record['project_type'] = ps_import_website_business($selectedBusiness, $lookup($row, 'program_name'), $lookup($row, 'frontend') . '/' . $lookup($row, 'backend'), $allowedBusinesses);
                    if ($record['project_type'] !== $selectedBusiness) $record['warning'] .= ($record['warning'] ? '；' : '') . '按产品/技术岗位自动归入“' . $record['project_type'] . '”分成方案';
                    $record['order_no'] = ps_order_no_resolve($lookup($row, 'order_no'));
                    if ($record['order_no'] !== $lookup($row, 'order_no') && $lookup($row, 'order_no') !== '') $record['warning'] .= ($record['warning'] ? '；' : '') . '订单号“' . mb_substr($lookup($row, 'order_no'), 0, 40) . '”按“' . $record['order_no'] . '”识别（已去掉标签 / 备注，同号不会重复建单）';
                    if ($record['order_no'] !== '' && in_array($selectedBusiness, ['网站模板', 'AI网站定制'], true) && $lookup($row, 'program_name') !== '') {
                        $siteBase = $record['order_no'];
                        $siteSeq[$siteBase] = ($siteSeq[$siteBase] ?? 0) + 1;
                        if ($siteSeq[$siteBase] > 1) {
                            $record['order_no'] = $siteBase . '#' . $siteSeq[$siteBase];
                            $record['multi_site_parent_no'] = $siteBase;
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '同一订单号的第 ' . $siteSeq[$siteBase] . ' 个网站，另记一张订单（订单号 ' . $record['order_no'] . '），售价、域名、成本各记各的';
                        }
                    }
                    $record['payment_reference'] = trim((string)($fixPaymentReferences[$record['line']] ?? $lookup($row, 'payment_reference')));
                    if (mb_strlen($record['payment_reference']) > 200) throw new RuntimeException('微信交易流水号或支付订单号过长');
                    if ($record['order_no'] === '' && trim((string)($fixOrderNos[$record['line']] ?? '')) !== '') {
                        $record['order_no'] = trim((string)$fixOrderNos[$record['line']]);
                        $record['warning'] = '订单号由上传人在预览中补填，请核对原始交易记录';
                    }
                    if ($record['order_no'] === '' && $record['payment_reference'] !== '') {
                        $record['order_no'] = ps_payment_reference_order_no($selectedBusiness, $record['payment_reference']);
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '无店铺订单号，已按微信交易流水号生成内部关联号';
                    }
                    // 微信付款等没有订单号的订单：只要有日期、金额和一个识别信息（付款昵称 / 联系方式 / 客服），就按这些内容生成稳定的内部订单号（WX-…），
                    // 同一张表重复上传得到同一个号，不会重复建单；拿到真实订单号后可在订单页补录。
                    if ($record['order_no'] === '' && $record['payment_reference'] === '') {
                        $wxAmount = trim((string)$lookup($row, 'contract_amount')); $wxDate = trim((string)$lookup($row, 'order_date'));
                        $wxNick = trim((string)$lookup($row, 'payment_nickname')); $wxContact = trim((string)$lookup($row, 'contact_note')); $wxCs = trim((string)$lookup($row, 'customer_service'));
                        if ($wxAmount !== '' && $wxDate !== '' && ($wxNick !== '' || $wxContact !== '' || $wxCs !== '')) {
                            $wxKey = mb_strtolower($selectedBusiness . '|' . $wxDate . '|' . trim((string)$lookup($row, 'shop')) . '|' . $wxNick . '|' . $wxAmount . '|' . $wxCs);
                            $wxSeq[$wxKey] = ($wxSeq[$wxKey] ?? 0) + 1;
                            $record['order_no'] = 'WX-' . strtoupper(substr(hash('sha256', 'noorder|' . $wxKey . '|' . $wxSeq[$wxKey]), 0, 24));
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '没有订单号（微信付款等）：已按“日期＋店铺＋付款昵称＋金额”生成内部订单号，拿到真实订单号后可在订单页补录';
                        }
                    }
                    $exists->execute([$record['order_no']]);
                    $existing = $exists->fetch();
                    $record['existing_order_id'] = $existing ? (int)$existing['id'] : 0;
                    $record['resource_locked'] = false;
                    // 同一订单号客户一次付款、由多位商标客服分别录入：后录入的客服另记自己那份（同业务分单）
                    $coCustomerService = $existing && $selectedBusiness === '商标' && $actor['role'] === 'customer_service' && !$departmentMode
                        && ps_business_normalize($existing['project_type']) === $record['project_type']
                        && !ps_import_order_visible((int)$existing['id'], $actor) && ps_import_group_taken((int)$existing['id'], 'customer_service');
                    if ($existing && (ps_business_normalize($existing['project_type']) !== $record['project_type'] || $coCustomerService)) {
                        if (!$coCustomerService && ps_import_order_visible((int)$existing['id'], $actor)) { $record['skip_status'] = '已导入过'; throw new RuntimeException('此单已在项目订单中，保留原业务“' . $existing['project_type'] . '”和分成规则，不重复建单；改类目请由财务核对'); }
                        if (pos_parent_of((int)$existing['id']) || strpos($record['order_no'], 'WX-') === 0) throw new RuntimeException('该订单号已属于其他业务，请联系财务核对');
                        // 他人用另一业务录过的同号订单：本人这份另建分单子单（各记各的金额与业务规则），不再被拦
                        $record['split_parent_id'] = (int)$existing['id'];
                        $record['split_parent_no'] = $record['order_no'];
                        $record['split_parent'] = ['id' => (int)$existing['id'], 'order_no' => $existing['order_no'] ?? $record['order_no'], 'project_type' => $existing['project_type'], 'contract_amount' => $existing['contract_amount'], 'order_date' => $existing['order_date'] ?? null];
                        $record['order_no'] = pos_child_order_no($record['split_parent_no'], $record['project_type'], $coCustomerService ? (int)$actor['employee_id'] : 0);
                        $exists->execute([$record['order_no']]);
                        $existing = $exists->fetch();
                        $record['existing_order_id'] = $existing ? (int)$existing['id'] : 0;
                    }
                    if ($existing) {
                        if (in_array($existing['settlement_status'], ['approved','locked'], true)) { $record['skip_status'] = ps_import_order_visible((int)$existing['id'], $actor) ? '已导入过' : '他人订单'; throw new RuntimeException('此单已导入并经财务审核，本次自动跳过；如需更改请联系财务'); }
                        if ($departmentMode && !ps_department_import_is_order((int)$existing['id']) && $actor['role'] !== 'finance') {
                            // 本人之前用个人上传导入过的同一单：视为已导入，自动跳过，不当成错误
                            $existingAccess->execute([(int)$existing['id'], (int)$actor['employee_id']]);
                            if ($existingAccess->fetchColumn()) { $record['skip_status'] = '已导入过'; throw new RuntimeException('此单本人已导入过（个人订单），本次自动跳过'); }
                            throw new RuntimeException('同号订单不是网站售后部门订单，请由财务核对');
                        }
                        if ($actor['role'] !== 'finance' && !$departmentMode) {
                            $existingAccess->execute([(int)$existing['id'], (int)$actor['employee_id']]);
                            if (!$existingAccess->fetchColumn()) {
                                $record['attach_check'] = true; // 仅本人所在分成组还无人时，允许与同号订单关联。
                            }
                        }
                        $existingResource->execute([(int)$existing['id']]);
                        $existingMode = $existingResource->fetchColumn();
                        $record['resource_locked'] = $existingMode !== false && $existingMode !== 'pending';
                        $record['status'] = '补充已有订单';
                    }
                    $record['order_date'] = ps_import_date($lookup($row, 'order_date'));
                    if (!$record['order_date'] && trim((string)($fixDates[$record['line']] ?? '')) !== '') {
                        $record['order_date'] = ps_import_date($fixDates[$record['line']]);
                        if ($record['order_date']) $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由上传人在预览中确认补填';
                    }
                    if (!$record['order_date'] && $existing && !empty($existing['order_date'])) {
                        $record['order_date'] = ps_import_date($existing['order_date']);
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由同号结算单带入';
                    }
                    if (!$record['order_date'] && !empty($record['split_parent']['order_date'])) {
                        $record['order_date'] = ps_import_date($record['split_parent']['order_date']);
                        if ($record['order_date']) $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由同号原订单带入';
                    }
                    if (!$record['order_date'] && $record['order_no'] !== '' && substr($record['order_no'], 0, 3) !== 'WX-') {
                        $matches = array_values(array_filter(ps_shop_order_lookup($record['split_parent_no'] ?? $record['order_no']), function ($match) use ($row, $lookup) { return $match['price'] !== null && ($lookup($row, 'shop') === '' || $lookup($row, 'shop') === $match['shop']); }));
                        if (count($matches) === 1 && !empty($matches[0]['date'])) {
                            $record['order_date'] = ps_import_date($matches[0]['date']);
                            if ($record['order_date']) $record['warning'] .= ($record['warning'] ? '；' : '') . '日期由同号店铺流水带入';
                        }
                    }
                    // 部门原表偶有把时间写进日期列（如 18.05、“17. 00”）：按今天建单并提示核对
                    if (!$record['order_date'] && !empty($businessDefinition['free_shop']) && $lookup($row, 'order_date') !== '' && ($lookup($row, 'order_no') !== '' && $lookup($row, 'contract_amount') !== '')) { $record['order_date'] = date('Y-m-d'); $record['warning'] = '日期“' . $lookup($row, 'order_date') . '”无法识别，已按今天建单，请核对'; }
                    // 备案-单量表没有日期列：按导入当天记单（月度归属由上传时间决定，财务可在结算单调整）。
                    if (!$record['order_date'] && $selectedBusiness === '备案-单量') {
                        $record['order_date'] = date('Y-m-d');
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '表无日期列，已按导入当天记单';
                    }
                    $record['contract_amount'] = str_replace([',','¥','￥',' '], '', $lookup($row, 'contract_amount'));
                    if (preg_match('/^\d+\.\d{3,}$/', $record['contract_amount'])) $record['contract_amount'] = number_format((float)$record['contract_amount'], 2, '.', '');
                    if (!empty($record['split_parent'])) {
                        $parentAmount = (float)$record['split_parent']['contract_amount'];
                        if (!is_numeric($record['contract_amount']) || (float)$record['contract_amount'] <= 0) {
                            // 设计师等“同单另一岗位”的业务：表里不写售价，沿用设计客服已录入的原单售价，用于本人的月营业额
                            if (!empty($businessDefinition['price_from_order']) && $parentAmount > 0) {
                                $record['contract_amount'] = number_format($parentAmount, 2, '.', '');
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '同号订单已由“' . $record['split_parent']['project_type'] . '”业务录入：本行未写售价，沿用原单售价 ¥' . $record['contract_amount'] . '，另记“' . $record['project_type'] . '”用于本人月营业额，请财务核对';
                            } else {
                                // 技术表常不带售价：照常建分单，售价留空待补（客服或财务补填，分单合计须与客户实付一致）
                                $record['contract_amount'] = '';
                                $record['warning'] .= ($record['warning'] ? '；' : '') . '同号订单已由其他业务 / 客服录入，本行作为分单加入；表里没写售价，分单售价待补（原单 ¥' . number_format($parentAmount, 2, '.', '') . '，合计须与客户实付一致）';
                            }
                        } else $record['warning'] .= ($record['warning'] ? '；' : '') . pos_summary_text($record['split_parent'], $record['project_type'], $record['contract_amount'], $record['order_no']);
                    }
                    $status = $lookup($row, 'status');
                    $record['delivery_status'] = ps_import_delivery_status($status, $selectedBusiness);
                    if ($record['delivery_status'] === null && isset($aiStatus[$status])) { $record['delivery_status'] = $aiStatus[$status]; $record['warning'] .= ($record['warning'] ? '；' : '') . '状态“' . $status . '”由 AI 识别为' . ($aiStatus[$status] === 'finished' ? '已完成' : '未完成'); }
                    // “到账情况”列写的是到账金额（如 740）：按已到账处理
                    if ($record['delivery_status'] === null && is_numeric(str_replace([',', '¥', '￥'], '', $status))) { $record['delivery_status'] = 'finished'; $record['warning'] .= ($record['warning'] ? '；' : '') . '状态列写的是金额“' . $status . '”，按已到账处理'; }
                    // 状态列里写的是别的文字（如接入商名称、备注）：不拦整行，按“未完成”导入并提示，交付后在订单里再标记完成
                    if ($record['delivery_status'] === null) { $record['delivery_status'] = 'unfinished'; $record['warning'] .= ($record['warning'] ? '；' : '') . '状态“' . mb_substr($status, 0, 20) . '”无法识别，已按未完成导入，交付后请在订单里标记完成'; }
                    $record['trade_status'] = mb_strpos($status, '交易关闭') !== false ? '交易关闭' : '';
                    if ($record['trade_status'] !== '') $record['warning'] = '表格写交易关闭：请财务核对退款';
                    $sheetBusiness = $lookup($row, 'business');
                    $record['business_text'] = '';
                    if ($sheetBusiness !== '' && ps_business_normalize($sheetBusiness) !== $selectedBusiness) {
                        if (isset(ps_business_catalog()[ps_business_normalize($sheetBusiness)])) throw new RuntimeException('表格写的业务是“' . $sheetBusiness . '”，与当前选中的“' . $selectedBusiness . '”不一致');
                        $record['business_text'] = mb_substr($sheetBusiness, 0, 300); // 部门表“业务”列常写项目描述
                    }
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
                    if ($kindText !== '' && !in_array($kindText, $orderKinds, true)) {
                        // 网站续费表“拍建站”列常写拍下的具体内容（网站链接/小程序链接/域名等）：有值一律记为“拍链接”。
                        if ($selectedBusiness === '网站续费') {
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '拍建站“' . $kindText . '”已按拍链接处理';
                        $record['kind_mapped'] = true;
                            $kindText = '拍链接';
                        } else {
                            // 类型列常被写成付款方式（微信 / 对公）等：不拦整行，忽略后按描述 / 默认预选，本行可改选
                            $record['warning'] .= ($record['warning'] ? '；' : '') . '订单类型列写的“' . $kindText . '”不是可选类型（' . implode('、', $orderKinds) . '），已忽略';
                            $kindText = '';
                        }
                    }
                    // 备案-提成表“建站订单”列有值：一律记为拍链接类型（与网站续费“拍建站”列同逻辑），可另配每单奖励。
                    if ($selectedBusiness === '备案-提成' && $kindText === '' && $lookup($row, 'build_order_marker') !== '') {
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '建站订单“' . $lookup($row, 'build_order_marker') . '”已按拍链接处理';
                        $record['kind_mapped'] = true;
                        $kindText = '拍链接';
                    }
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
                    // 平面设计：原表“老客户”列有内容（不是否 / 无）即老客户找回，其余为新订单
                    if ($selectedBusiness === '平面设计') {
                        $marker = $lookup($row, 'returning_marker');
                        $kindText = $marker !== '' && !in_array($marker, ['否', '无', '0', '不是'], true) ? '老客户找回' : '新订单';
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
                        // 单量补助按当月去重客户：同一客服同一客户（旺旺）当月第二单起记“同客户”（照常提成、不计单量）。
                        // 资料 / 提交专员表的旺旺写群名，不参与判断，由客服上传时更正。
                        $tmDate = ps_import_date($lookup($row, 'order_date'));
                        $tmCustomer = preg_replace('/[\s\x{3000}\x{00A0}]+/u', '', mb_strtolower($lookup($row, 'payment_nickname')));
                        $tmService = $lookup($row, 'customer_service') !== '' ? $lookup($row, 'customer_service') : (string)$actorName;
                        if (in_array($kindText, ['普通订单', '新客户'], true) && $actor['role'] !== 'technical' && $tmDate && $tmCustomer !== '' && $tmService !== '') {
                            $tmKey = substr($tmDate, 0, 7) . '|' . $tmService . '|' . $tmCustomer;
                            $tmRepeat = $tmRepeat ?? db()->prepare("SELECT 1 FROM project_orders o JOIN project_participants p ON p.order_id=o.id AND p.commission_group='customer_service' JOIN employees e ON e.id=p.employee_id WHERE o.project_type='商标' AND o.order_kind IN ('普通订单','新客户') AND DATE_FORMAT(o.order_date,'%Y-%m')=? AND e.name=? AND REPLACE(LOWER(o.customer_name),' ','')=? AND o.order_no<>? LIMIT 1");
                            $tmRepeat->execute([substr($tmDate, 0, 7), $tmService, $tmCustomer, $lookup($row, 'order_no')]);
                            if (isset($trademarkSeen[$tmKey]) || $tmRepeat->fetchColumn()) $kindText = '同客户';
                            $trademarkSeen[$tmKey] = true;
                        }
                        // “小额”分表没有状态列：返款已完成，按已完成计
                        if ($kindText === '小额返款' && !isset($columnMap['status'])) $record['delivery_status'] = 'finished';
                    }
                    $record['kind_missing'] = false;
                    // 森动备案：表格没写订单类型时按工作表 / 店铺判断——“二次备案”分表 → 二次备案；淘宝店铺 → 备案-淘宝；其余 → 备案
                    if ($kindText === '' && $selectedBusiness === '森动备案') {
                        $kindText = mb_strpos((string)$sheetName, '二次') !== false ? '二次备案' : (mb_strpos($lookup($row, 'shop'), '淘宝') !== false ? '备案-淘宝' : '备案');
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '订单类型按表格自动判断为“' . $kindText . '”（二次备案分表 → 二次备案；淘宝店铺 → 备案-淘宝），可在本行改选';
                    }
                    if ($kindText === '' && !empty($businessDefinition['kind_required'])) {
                        // 显式类型优先；之后按描述、本人确认过的同布局默认、AI 建议、业务默认依次托底。
                        $hint = ($record['business_text'] ?? '') . ' ' . $record['contact_note'] . ' ' . $lookup($row, 'detail:make_requirement');
                        foreach (['续费' => '续费', '定制' => '定制', '技术服务' => '技术服务', '维护' => '技术服务'] as $word => $guess) if (in_array($guess, $orderKinds, true) && mb_strpos($hint, $word) !== false) { $record['kind_guess'] = $guess; break; }
                        $aiHint = $kindHint($row);
                        if (empty($record['kind_guess']) && $preferredKind) { $record['kind_guess'] = $preferredKind; $record['kind_from_preference'] = true; }
                        if (empty($record['kind_guess']) && isset($aiKind[$aiHint])) { $record['kind_guess'] = $aiKind[$aiHint]; $record['kind_from_ai'] = true; }
                        if (empty($record['kind_guess'])) { $record['kind_guess'] = in_array($businessDefinition['default_kind'] ?? '', $orderKinds, true) ? $businessDefinition['default_kind'] : ($orderKinds[0] ?? ''); $record['kind_default_review'] = true; }
                        $kindText = $record['kind_guess'];
                        $kindSource = !empty($record['kind_from_ai']) ? 'AI 建议' : (!empty($record['kind_from_preference']) ? '本人历史确认分类' : (!empty($record['kind_default_review']) ? '业务默认' : '业务描述'));
                        $record['warning'] .= ($record['warning'] ? '；' : '') . '表格未写订单类型，已按' . $kindSource . '预选“' . $kindText . '”；可在本行改选，财务也可纠正';
                    }
                    $record['order_kind'] = $kindText;
                    // 部门代录补充：同号原单建单时默认记了类型，表格明确给出拍建站时提示按表格更正。
                    if ($departmentMode && !empty($record['kind_mapped']) && !empty($existing['order_kind']) && $existing['order_kind'] !== $kindText) $record['warning'] .= ($record['warning'] ? '；' : '') . '原单类型“' . $existing['order_kind'] . '”将按表格更正为“' . $kindText . '”';
                    // 成本（稿费 / 杂志社费用 + 写手费用 + 备案-提成快递费）：随表导入的业务读成本列；退款冲减行为负数。
                    $record['direct_cost'] = '';
                    if (!empty($businessDefinition['import_cost'])) {
                        $costTotal = 0.0; $hasCost = false;
                        foreach (['direct_cost', 'direct_cost2', 'shipping_cost'] as $costKey) {
                            $costText = str_replace([',','¥','￥',' '], '', $lookup($row, $costKey));
                            if ($costText === '' || !is_numeric($costText)) continue;
                            $costTotal += (float)$costText; $hasCost = true;
                        }
                        if ($hasCost) $record['direct_cost'] = number_format($costTotal, 2, '.', '');
                    }
                    $record['domain_used'] = $businessDefinition['resources'] ? $lookup($row, 'domain_used') : '否';
                    $record['ssl_used'] = $businessDefinition['resources'] ? $lookup($row, 'ssl_used') : '';
                    // 常见写法“30元”“¥30”：去掉单位按金额识别
                    if (preg_match('/^[¥￥]?\s*(\d+(?:\.\d{1,2})?)\s*元?$/u', $record['ssl_used'], $sslMatch)) $record['ssl_used'] = $sslMatch[1];
                    $record['resource_note'] = $businessDefinition['resources'] ? $lookup($row, 'resource_note') : '';
                    // 网站续费新表：空间/域名/域名真实成本不再另算订单成本（只取总成本），连同备注2 一起拼进订单备注备查。
                    if ($selectedBusiness === '网站续费') {
                        $renewalExtras = [];
                        foreach (['space_cost' => '空间成本', 'domain_cost' => '域名成本', 'domain_real_cost' => '域名真实成本'] as $extraKey => $extraLabel) {
                            $extraText = str_replace([',', '¥', '￥', ' '], '', $lookup($row, $extraKey));
                            if ($extraText !== '' && is_numeric($extraText) && (float)$extraText != 0) $renewalExtras[] = $extraLabel . '：¥' . number_format((float)$extraText, 2, '.', '');
                        }
                        $remark2Text = trim($lookup($row, 'remark2'));
                        if ($remark2Text !== '') $renewalExtras[] = '备注2：' . $remark2Text;
                        $record['renewal_extras'] = $renewalExtras;
                    }
                    // 网站修改新表（2026-10）：后台类型 / 分单备注金额仅记录，拼进订单备注备查；付款截图不参与计算。
                    if ($selectedBusiness === '网站修改') {
                        $modifyExtras = [];
                        $backendTypeText = trim($lookup($row, 'backend_type_marker'));
                        if ($backendTypeText !== '') $modifyExtras[] = '后台类型：' . $backendTypeText;
                        $splitAmountText = trim($lookup($row, 'split_amount_note'));
                        if ($splitAmountText !== '') $modifyExtras[] = '分单备注金额：' . $splitAmountText;
                        $record['renewal_extras'] = array_merge($record['renewal_extras'] ?? [], $modifyExtras);
                    }
                    $record['program_name'] = $usesProgram ? $lookup($row, 'program_name') : '';
                    $record['program_template_id'] = 0;
                    $record['lines'] = [$record['line']];
                    $rawDetails = [];
                    foreach ($businessDefinition['fields'] as $key => $label) $rawDetails[$key] = $lookup($row, 'detail:' . $key);
                    $record['details'] = ps_business_details($selectedBusiness, $rawDetails);
                    if ($record['ssl_used'] !== '' && !in_array($record['ssl_used'], ['无','否'], true) && (!preg_match('/^\d+(?:\.\d{1,2})?$/', $record['ssl_used']) || (float)$record['ssl_used'] > 999999999999.99)) throw new RuntimeException('SSL 真实成本无效，请填写金额、0 或无');
                    if ($record['order_no'] === '' || strlen($record['order_no']) > 100 || !$record['order_date'] || ($record['contract_amount'] !== '' && !preg_match(($record['order_kind'] === '退款冲减' ? '/^-?' : '/^') . '\d+(?:\.\d{1,2})?$/', $record['contract_amount'])) || (float)$record['contract_amount'] > 999999999999.99) throw new RuntimeException(!$record['order_date'] ? '日期无法识别' : ($record['order_no'] === '' ? '缺少店铺订单号或支付流水号' : '订单号或售价无效'));
                    // 日期年份明显写错（如把 2026 写成 2029）：不让订单悄悄落到错误的月份，要求核对
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$record['order_date']) && ($record['order_date'] > date('Y-m-d', strtotime('+35 days')) || $record['order_date'] < date('Y-m-d', strtotime('-3 years')))) throw new RuntimeException('日期“' . $record['order_date'] . '”年份异常（应在今天前后合理范围内），请核对年份后重新上传');
                    if ($existing) $record['existing_snapshot'] = $existing;
                    $cs = ps_import_names($lookup($row, 'customer_service'), $employeesByName, $selectedBusiness);
                    // 技术列写的不是合作人员（常见是把项目名称填进了“制作技术”）：不拦整行，提示后忽略；客服列仍严格校验
                    $unknownTech = [];
                    $front = ps_import_names_lenient($lookup($row, 'frontend'), $employeesByName, $selectedBusiness, $unknownTech);
                    $back = ps_import_names_lenient($lookup($row, 'backend'), $employeesByName, $selectedBusiness, $unknownTech);
                    if ($unknownTech) $record['warning'] .= ($record['warning'] ? '；' : '') . '技术列写的“' . implode('、', $unknownTech) . '”不是合作人员，已忽略' . ($actor['role'] === 'technical' ? '，由本人作为技术' : '') . '；如需指定技术请填姓名';
                    // 前面已有的信息自动补全业务说明（小程序名称 / 制作要求 / 客户微信等），表格可不再重复填写
                    $record['details'] = ps_import_autofill_details($record['details'], $record['business_text'] ?? '', $record['contact_note'], $record['payment_nickname'], $unknownTech ? implode('、', $unknownTech) : '');
                    foreach ($cs as $id => $name) $record['people']['customer_service'][$id] = ['id' => $id, 'role' => '客服', 'name' => $name];
                    foreach ($front as $id => $name) $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['frontend'], 'name' => $name];
                    foreach ($back as $id => $name) {
                        if (isset($record['people']['technical'][$id])) $record['people']['technical'][$id]['role'] .= '/' . $peopleLabels['backend'];
                        else $record['people']['technical'][$id] = ['id' => $id, 'role' => $peopleLabels['backend'], 'name' => $name];
                    }
                    $record['people'] = ps_import_website_people_roles($record['people'], $record['project_type']);
                    if ($departmentMode) {
                        $namedIds = array_values(array_unique(array_merge(array_keys($record['people']['customer_service']), array_keys($record['people']['technical']))));
                        if ($namedIds) {
                            $namedPeople = ps_department_import_people($actor, $selectedBusiness, $namedIds);
                            $record['people'] = ['technical' => [], 'customer_service' => $namedPeople];
                        } elseif ($departmentDefaults) $record['people'] = ['technical' => [], 'customer_service' => $departmentDefaults];
                    }
                    if ($actor['role'] !== 'finance' && !$departmentMode) {
                        $selfId = (int)$actor['employee_id'];
                        if (!isset($record['people']['technical'][$selfId]) && !isset($record['people']['customer_service'][$selfId])) {
                            $selfGroup = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                            // 没有填写本组人员时可由上传人接单；若表格明确写了别人，不能擅自把订单据为己有。
                            $groupHasNamedPerson = $selfGroup === 'technical' ? (bool)($front || $back) : (bool)$cs;
                            if (!$groupHasNamedPerson) {
                                $record['people'][$selfGroup][$selfId] = ['id' => $selfId, 'role' => ps_employee_default_role($selfId, $selectedBusiness, $selfGroup) ?? ($selfGroup === 'technical' ? $peopleLabels['frontend'] : '客服'), 'name' => $actorName ?? '本人'];
                                if ($selfGroup === 'technical') $front[$selfId] = true; else $cs[$selfId] = true;
                            }
                        }
                    }
                    if (!$record['people']['customer_service'] && !$record['people']['technical']) throw new RuntimeException($departmentMode ? '此行没有售后参与人，请在上传前选择默认参与人，或在表格填写姓名' : '至少需要匹配一名客服或技术参与人');
                    if ($actor['role'] !== 'finance' && !$departmentMode) {
                        $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                        // 代写类：编辑员（客服账号）在代写订单上是“对接编辑”，本人在任一组即可
                        if (!empty($businessDefinition['import_cost']) && !isset($record['people'][$group][(int)$actor['employee_id']]) && isset($record['people']['technical'][(int)$actor['employee_id']])) $group = 'technical';
                        // 上传人只要在这行订单里（客服或技术任一栏）就算本人订单；另一栏写别人是正常业务（如环境配置同事写客服、技术是前后端同事）。
                        $otherGroup = $group === 'technical' ? 'customer_service' : 'technical';
                        if (!isset($record['people'][$group][(int)$actor['employee_id']]) && isset($record['people'][$otherGroup][(int)$actor['employee_id']])) $group = $otherGroup;
                        if (!isset($record['people'][$group][(int)$actor['employee_id']])) {
                            $listed = implode('、', array_column($record['people'][$group], 'name'));
                            if ($listed !== '') $record['skip_status'] = '他人订单';
                            throw new RuntimeException($listed !== '' ? '此行' . ($group === 'technical' ? '技术' : '客服') . '是“' . $listed . '”，不是本人：请由本人上传或交财务导入，本人的订单不受影响' : '此行未写本人为' . ($group === 'technical' ? '技术' : '客服') . '，不可导入他人订单');
                        }
                        // 商标：资料专员、提交专员各自上传同一单，技术组按岗位区分，同岗位无人即可加入
                        $trademarkRoleOpen = function () use ($selectedBusiness, $group, $record, $actor) { return $selectedBusiness === '商标' && $group === 'technical' && ps_trademark_technical_role_open((int)$record['existing_order_id'], (int)$actor['employee_id'], $record['people']['technical'][(int)$actor['employee_id']]['role']) !== null; };
                        if (!empty($record['attach_check']) && ps_import_group_taken((int)$record['existing_order_id'], $group) && !$trademarkRoleOpen()) throw new RuntimeException('该订单号已存在且已有' . ($group === 'technical' ? '对接编辑 / 技术' : '客服') . '，本人尚未被关联；请由财务核对');
                    }
                    if (!$existing && $actor['role'] === 'customer_service' && ps_business_requires_technical($selectedBusiness)) {
                        if (!$record['people']['technical'] && !$unknownTech) throw new RuntimeException('客服导入新订单须指定接单技术');
                        // 技术列写的是资料员等非合作人员（如负责传资料的同事）：照常导入，订单暂不记技术
                        if (!$record['people']['technical']) $record['warning'] .= ($record['warning'] ? '；' : '') . '订单暂无接单技术，如需记技术提成请财务在结算单补录';
                        // 技术有有效账号但未开通本业务：照常导入，提示财务开通业务，避免客服整批订单被拦；完全没有账号才拦
                        $techAccount = $techAccount ?? db()->prepare('SELECT 1 FROM project_users WHERE employee_id=? AND is_active=1 LIMIT 1');
                        $techNotOpen = [];
                        foreach ($record['people']['technical'] as $person) {
                            if (ps_active_employee_for_business($person['id'], 'technical', $selectedBusiness)) continue;
                            $techAccount->execute([(int)$person['id']]);
                            if (!$techAccount->fetchColumn()) throw new RuntimeException('接单技术“' . $person['name'] . '”还没有开通项目账号，请联系财务开通');
                            $techNotOpen[] = $person['name'];
                        }
                        if ($techNotOpen) $record['warning'] .= ($record['warning'] ? '；' : '') . '技术' . implode('、', $techNotOpen) . '的账号未开通“' . $selectedBusiness . '”技术业务，已照常记录，请财务在账号管理中开通以免漏算提成';
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
                } catch (RuntimeException $e) { $record['base_valid'] = false; $record['status'] = $record['skip_status'] ?? '需处理'; $record['error'] = $e->getMessage(); }
                $itemTemplates = $itemTemplates ?? ps_intake_templates(null, $selectedBusiness);
                $record['items'] = !empty($businessDefinition['program']) ? poi_from_row($record['program_name'], empty($record['amount_from_shop']) ? $record['contract_amount'] : null, $head, $row, $itemTemplates, $record['line'], $record['resource_note'] ?? '') : [];
                if (!$record['resource_locked']) foreach ($record['items'] as $item) if ($item['category'] === 'program' && $item['template_id']) { $record['program_template_id'] = (int)$item['template_id']; break; }
                // 同一订单号保留全部商品明细；收款只落在主单，不给证书另建一笔收入。
                $orderKey = $record['order_no'] ?? '';
                if ($orderKey !== '' && isset($seen[$orderKey])) {
                    $target = &$preview[$seen[$orderKey]];
                    $target['lines'][] = $record['line'];
                    $target['items'] = array_merge($target['items'] ?? [], $record['items']);
                    if (empty($record['base_valid']) || empty($target['base_valid'])) {
                        $target['base_valid'] = false;
                        $target['status'] = '需处理';
                        $target['error'] = trim(($target['error'] ?? '') . '；同号第 ' . $record['line'] . ' 行：' . ($record['error'] ?: '所在订单有错误'), '；');
                    } else {
                        // 按店铺流水带入的售价是整单价：同号重复出现时不再累加
                        if ($record['contract_amount'] !== '' && empty($record['amount_from_shop'])) $target['contract_amount'] = number_format((float)$target['contract_amount'] + (float)$record['contract_amount'], 2, '.', '');
                        if (($record['direct_cost'] ?? '') !== '') $target['direct_cost'] = number_format((float)($target['direct_cost'] ?? 0) + (float)$record['direct_cost'], 2, '.', '');
                        if (is_numeric($record['ssl_used']) && (float)$record['ssl_used'] > 0) $target['ssl_used'] = number_format((float)(is_numeric($target['ssl_used']) ? $target['ssl_used'] : 0) + (float)$record['ssl_used'], 2, '.', '');
                        foreach (['technical', 'customer_service'] as $groupKey) foreach ($record['people'][$groupKey] as $personId => $person) $target['people'][$groupKey][$personId] = $target['people'][$groupKey][$personId] ?? $person;
                        foreach ($record['details'] as $key => $value) if ($value !== '' && ($target['details'][$key] ?? '') === '') $target['details'][$key] = $value;
                        $target['renewal_extras'] = array_values(array_unique(array_merge($target['renewal_extras'] ?? [], $record['renewal_extras'] ?? [])));
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
            if (!empty($blankRows[$sheetName])) $sheetReport[$sheetName]['blank'] = $blankRows[$sheetName];
            }
            if (!$usedSheets) {
                $reasons = [];
                foreach ($sheetReport as $name => $info) $reasons[] = '“' . $name . '”' . $info['reason'];
                throw new RuntimeException('没有与“' . $selectedBusiness . '”表头对应的工作表（' . implode('；', $reasons) . '）。请确认业务类型，或下载该业务模板对照表头');
            }
            // 同号加购先合并，再与原单比较；重复上传整张表不能拿每个分项价格和整单总价比。
            $previewForHint = $preview;
            $prevShopNick = null;
            foreach ($preview as &$mergedRow) {
                $thisShopNick = trim((string)($mergedRow['shop'] ?? '')) . '|' . trim((string)($mergedRow['payment_nickname'] ?? ''));
                $sameAsPrev = $prevShopNick !== null && $prevShopNick === $thisShopNick && $thisShopNick !== '|';
                $prevShopNick = $thisShopNick;
                if (empty($mergedRow['base_valid']) || empty($mergedRow['existing_snapshot'])) continue;
                $conflicts = ps_customer_intake_conflicts($mergedRow['existing_snapshot'], $mergedRow);
                if ($selectedBusiness === '商标' && $actor['role'] === 'technical') $conflicts = array_values(array_intersect($conflicts, ['售价']));
                if ($conflicts) { $mergedRow['base_valid'] = false; $mergedRow['status'] = '需处理'; $mergedRow['error'] = '原单与上传表的' . implode('、', $conflicts) . '不一致，请由财务核对'; $mergedRow['conflict_detail'] = ps_customer_intake_conflict_detail($mergedRow['existing_snapshot'], $mergedRow, $conflicts) . ($sameAsPrev && (in_array('店铺', $conflicts, true) || in_array('付款昵称', $conflicts, true)) ? '。这一行的店铺、付款昵称与上一行完全相同，可能是整列下拉填充时带下来的，请对照备注列核对' : '');
                    require_once __DIR__ . '/../includes/ProjectOrderFix.php';
                    $mergedRow['fix_panel'] = ['order_id' => (int)($mergedRow['existing_snapshot']['id'] ?? 0), 'order_no' => $mergedRow['order_no'], 'fields' => pof_fields($mergedRow['existing_snapshot'], $mergedRow, $conflicts), 'hint' => pof_split_hint($mergedRow['existing_snapshot'], $mergedRow, $previewForHint), 'same_prev' => $sameAsPrev];
                }
            }
            unset($mergedRow);
            $totalPreviewRows = count($preview);
            $lastDateBySheet = [];
            foreach ($preview as &$previewItem) {
                $sheetKey = (string)($previewItem['sheet'] ?? '');
                if (!empty($previewItem['order_date'])) $lastDateBySheet[$sheetKey] = $previewItem['order_date'];
                elseif (isset($lastDateBySheet[$sheetKey])) $previewItem['suggested_date'] = $lastDateBySheet[$sheetKey];
            }
            unset($previewItem);
            if ($action === 'repair_preview' && !empty($_SESSION['project_import_pending_lines'])) {
                $pendingLines = array_map('intval', (array)$_SESSION['project_import_pending_lines']);
                $preview = array_values(array_filter($preview, function ($item) use ($pendingLines) { return in_array((int)$item['line'], $pendingLines, true); }));
            }
            if ($action === 'followup') {
                // 只留待补全的行；勾选“不是订单”的行不再追踪
                $ignoredLines = array_map('intval', array_keys(array_filter((array)($_POST['ignore'] ?? []))));
                $followLines = array_values(array_diff(array_map(function ($r) { return (int)$r['line']; }, $followup['rows']), $ignoredLines));
                $preview = array_values(array_filter($preview, function ($item) use ($followLines) { return (bool)array_intersect(array_map('intval', $item['lines'] ?? [$item['line']]), $followLines); }));
                $previewOwner = $actorKey; $previewBusiness = $selectedBusiness; $previewScope = $scope;
                $followupCommit = (bool)array_filter($preview, function ($item) { return !empty($item['base_valid']); });
                if (!$followupCommit) ps_import_followup_save($fileId, $actor, $selectedBusiness, $scope, explode('、', (string)$followup['sheets']), ps_import_followup_rows($preview, true));
            }
            ps_import_file_mark($fileId, $fileRow['status'] === 'imported' ? 'imported' : 'preview', ['sheets_used' => mb_substr(implode('、', array_keys(array_filter($sheetReport, function ($i) { return $i['used']; }))), 0, 500), 'rows_total' => $totalPreviewRows]);
            $_SESSION['project_import_preview'] = $preview;
            $_SESSION['project_import_actor'] = $actorKey;
            $_SESSION['project_import_business'] = $selectedBusiness;
            $_SESSION['project_import_scope'] = $scope;
            $_SESSION['project_import_people'] = $departmentDefaults;
            $_SESSION['project_import_rule_month'] = $ruleMonth;
            $_SESSION['project_import_file'] = $fileId;
            $resultFileId = $fileId;
            $_SESSION['project_import_sheets'] = $sheetReport;
            $_SESSION['project_import_ai_touched'] = ps_ai_touched();
            if (!empty($_POST['auto_import'])) {
                $previewOwner = $actorKey; $previewBusiness = $selectedBusiness; $previewScope = $scope;
                if (array_filter($preview, function ($row) { return !empty($row['base_valid']); })) $followupCommit = true;
                elseif ($preview) {
                    // 全是已在库 / 他人订单也算核对完成，不能永远显示成“仅预览”。
                    $importReport = ps_import_result_save($fileId, $preview, [], $actor, $operator);
                    if (!$importReport['pending']) ps_import_file_mark($fileId, 'imported', ['skipped_count' => count($preview)]);
                    ps_import_followup_save($fileId, $actor, $selectedBusiness, $scope, array_keys(array_filter($sheetReport, function ($s) { return !empty($s['used']); })), ps_import_followup_rows($preview, true));
                }
            }
        }
        if ($action === 'commit' || $followupCommit) {
            if (!$preview || $previewOwner !== $actorKey || $previewBusiness !== $selectedBusiness || $previewScope !== $scope) throw new RuntimeException('预览已失效，请重新上传');
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
                if ($actor['role'] !== 'finance' && !$departmentMode) {
                    $group = $actor['role'] === 'technical' ? 'technical' : 'customer_service';
                    if (!empty($businessDefinition['import_cost']) && !isset($row['people'][$group][(int)$actor['employee_id']]) && isset($row['people']['technical'][(int)$actor['employee_id']])) $group = 'technical';
                    // 与预览一致：上传人在客服或技术任一栏即可，另一栏可以是别人
                    $otherGroup = $group === 'technical' ? 'customer_service' : 'technical';
                    if (!isset($row['people'][$group][(int)$actor['employee_id']]) && isset($row['people'][$otherGroup][(int)$actor['employee_id']])) $group = $otherGroup;
                    if (!isset($row['people'][$group][(int)$actor['employee_id']])) throw new RuntimeException('第 ' . $line . ' 行不属于当前登录人员，请重新上传核对');
                }
                $needsResources = $resourceSelection && empty($row['resource_locked']);
                // 自动导入时资源未填写也先建单，留“待技术确认”，绝不擅自选免费资源或估算成本。
                if ($needsResources && !empty($_POST['auto_import']) && ($row['domain_mode'] ?? '') === '' && !isset($choices[$line])) $row['domain_mode'] = 'pending';
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
            // 可选的重复单提醒会懒建表；MySQL DDL 会隐式提交，必须在导入事务之前初始化。
            if (is_file(__DIR__ . '/../includes/dup_feedback.php')) { require_once __DIR__ . '/../includes/dup_feedback.php'; if (function_exists('pd_ensure')) pd_ensure(); }
            $nested = $pdo->inTransaction();
            if ($nested) $pdo->exec('SAVEPOINT project_order_import');
            else $pdo->beginTransaction();
            try {
                $insertOrder = $pdo->prepare('INSERT INTO project_orders (order_no,customer_name,project_type,order_kind,shop,contract_amount,receipt_amount,order_date,delivery_status,note,created_by_admin) VALUES (?,?,?,?,?,?,0,?,?,?,?)');
                foreach ($ready as [$row, $domainTemplate, $serverTemplate, $programTemplate, $forcedMode]) {
                    if ($programTemplate && !empty($row['items'])) foreach ($row['items'] as &$item) if ($item['category'] === 'program') { $item['template_id'] = (int)$programTemplate['id']; break; }
                    unset($item);
                    $existingQuery = $pdo->prepare('SELECT o.id,o.project_type,o.settlement_status,o.shop,o.contract_amount,s.payment_nickname,s.payment_reference,s.price_source FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no=? FOR UPDATE');
                    $existingQuery->execute([$row['order_no']]);
                    $existing = $existingQuery->fetch();
                    if ($existing) {
                        if (ps_business_normalize($existing['project_type']) !== ($row['project_type'] ?? $selectedBusiness) || in_array($existing['settlement_status'], ['approved','locked'], true)) throw new RuntimeException('第 ' . $row['line'] . ' 行订单状态已变化，请重新预览');
                        if ($departmentMode && !ps_department_import_is_order((int)$existing['id']) && $actor['role'] !== 'finance') throw new RuntimeException('第 ' . $row['line'] . ' 行同号订单不是网站售后部门订单');
                        if (array_intersect(ps_customer_intake_conflicts($existing, $row), $selectedBusiness === '商标' && $actor['role'] === 'technical' ? ['售价'] : ['店铺', '售价', '付款昵称', '支付流水号'])) throw new RuntimeException('第 ' . $row['line'] . ' 行买家资料与原单不一致，请重新核对');
                        $orderId = (int)$existing['id'];
                        // 同号二次上传只补缺失的分成组；已有技术或客服不改人、不改权重。
                        $missing = [];
                        foreach (['technical', 'customer_service'] as $groupKey) if ($row['people'][$groupKey] && !ps_import_group_taken($orderId, $groupKey)) $missing[$groupKey] = array_values($row['people'][$groupKey]);
                        if ($missing) { ps_intake_participants($orderId, $missing, $row['project_type'] ?? $selectedBusiness); ps_audit('order', $orderId, 'import_add_participants', $actor, ['line' => $row['line'], 'groups' => array_keys($missing)]); }
                        // 商标：技术组已有资料专员时，提交专员（或反之）上传同一单按岗位加入
                        if ($selectedBusiness === '商标' && !isset($missing['technical']) && $actor['role'] === 'technical' && isset($row['people']['technical'][(int)$actor['employee_id']])) {
                            $openRole = ps_trademark_technical_role_open($orderId, (int)$actor['employee_id'], $row['people']['technical'][(int)$actor['employee_id']]['role']);
                            if ($openRole !== null) { ps_trademark_add_technical($orderId, (int)$actor['employee_id'], $openRole); ps_audit('order', $orderId, 'import_add_participants', $actor, ['line' => $row['line'], 'groups' => ['technical'], 'role' => $openRole]); }
                        }
                        // 商标：客服表标注的新客户 / 同客户 / 小额返款以客服为准，覆盖资料专员先建单时的“普通订单”
                        if ($selectedBusiness === '商标' && in_array($actor['role'], ['customer_service', 'finance'], true) && in_array($row['order_kind'] ?? '', ['新客户', '同客户', '小额返款'], true)) {
                            $kindUpdate = $pdo->prepare("UPDATE project_orders SET order_kind=? WHERE id=? AND order_kind='普通订单'");
                            $kindUpdate->execute([$row['order_kind'], $orderId]);
                            if ($kindUpdate->rowCount()) ps_audit('order', $orderId, 'import_order_kind', $actor, ['line' => $row['line'], 'from' => '普通订单', 'to' => $row['order_kind']]);
                        }
                        if ($actor['role'] !== 'finance' && !$departmentMode) {
                            $access = $pdo->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?');
                            $access->execute([$orderId, (int)$actor['employee_id']]);
                            if (!$access->fetchColumn()) throw new RuntimeException('第 ' . $row['line'] . ' 行本人尚未关联此订单');
                        }
                        if (in_array($actor['role'], ['customer_service','finance'], true)) {
                            $changed = ps_save_customer_intake($orderId, ['customer_name' => $row['payment_nickname'], 'shop' => $row['shop'], 'contract_amount' => $row['contract_amount'], 'payment_nickname' => $row['payment_nickname'], 'payment_reference' => $row['payment_reference'] ?? ''], $actor, true, true);
                            if ($changed) ps_audit('order', $orderId, 'import_customer_intake', $actor, ['line' => $row['line'], 'fields' => $changed]);
                        }
                        if ($row['details']) {
                            $q = $pdo->prepare('SELECT details_json FROM project_order_details WHERE order_id=? FOR UPDATE');
                            $q->execute([$orderId]);
                            $details = json_decode((string)($q->fetchColumn() ?: '{}'), true) ?: [];
                            $detailsChanged = false;
                            foreach ($row['details'] as $key => $value) if ($value !== '' && trim((string)($details[$key] ?? '')) === '') { $details[$key] = $value; $detailsChanged = true; }
                            // 商标资料 / 提交专员按件计：以专员表的“商标个数”为准（客服表“数量”可能不同）
                            $tmCount = (string)($row['details']['trademark_count'] ?? '');
                            if ($selectedBusiness === '商标' && $actor['role'] === 'technical' && is_numeric($tmCount) && (string)($details['trademark_count'] ?? '') !== $tmCount) {
                                ps_audit('order', $orderId, 'import_trademark_count', $actor, ['line' => $row['line'], 'from' => $details['trademark_count'] ?? '', 'to' => $tmCount]);
                                $details['trademark_count'] = $tmCount; $detailsChanged = true;
                            }
                            if ($detailsChanged) {
                                $pdo->prepare('INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)')
                                    ->execute([$orderId, $row['project_type'] ?? $selectedBusiness, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                            }
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
                        if (($row['payment_reference'] ?? '') !== '') $pdo->prepare("UPDATE project_order_sources SET payment_reference=? WHERE order_id=? AND payment_reference=''")->execute([$row['payment_reference'], $orderId]);
                        if (($row['order_kind'] ?? '') !== '') {
                            // 部门代录：表格拍建站列明确给出类型的行（或上传人在预览中改选的），补充时按表格更正原单类型；仅补空值的行不变。
                            if ($departmentMode && (!empty($row['kind_mapped']) || $pickedKind !== '')) $pdo->prepare('UPDATE project_orders SET order_kind=? WHERE id=? AND order_kind<>?')->execute([$row['order_kind'], $orderId, $row['order_kind']]);
                            else $pdo->prepare("UPDATE project_orders SET order_kind=? WHERE id=? AND order_kind=''")->execute([$row['order_kind'], $orderId]);
                        }
                        if (!empty($row['renewal_extras'])) $pdo->prepare("UPDATE project_orders SET note=CONCAT_WS('；', NULLIF(note,''), ?) WHERE id=?")->execute([implode('；', $row['renewal_extras']), $orderId]);
                        poi_save($orderId, $row['items'] ?? [], (int)$_SESSION['project_import_file'], $actor);
                        ps_audit('order', $orderId, 'import_supplement', $actor, ['line' => $row['line'], 'order_no' => $row['order_no']]);
                        if ($departmentMode) ps_department_import_record($orderId, $actor);
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
                    foreach ($row['renewal_extras'] ?? [] as $renewalExtra) $noteParts[] = $renewalExtra;
                    if (is_numeric($row['ssl_used']) && (float)$row['ssl_used'] > 0) $noteParts[] = 'SSL 实际成本报备：¥' . $row['ssl_used'] . '（待技术补充成本凭证）';
                    $insertOrder->execute([$row['order_no'], $row['payment_nickname'], $row['project_type'], $row['order_kind'] ?? '', $row['shop'], $row['contract_amount'] === '' ? 0 : $row['contract_amount'], $row['order_date'], $row['delivery_status'], implode('；', $noteParts), $actor['role'] === 'finance' ? $actor['id'] : null]);
                    $orderId = (int)$pdo->lastInsertId();
                    if (!empty($row['multi_site_parent_no'])) {
                        $siteParent = $pdo->prepare('SELECT id FROM project_orders WHERE order_no=?');
                        $siteParent->execute([(string)$row['multi_site_parent_no']]);
                        if (($siteParentId = (int)$siteParent->fetchColumn()) && $siteParentId !== $orderId) pos_link($siteParentId, $orderId, $actor);
                    }
                    if (!empty($row['split_parent_id'])) {
                        $parentCheck = $pdo->prepare('SELECT id FROM project_orders WHERE id=? AND order_no=?');
                        $parentCheck->execute([(int)$row['split_parent_id'], (string)$row['split_parent_no']]);
                        if (!$parentCheck->fetchColumn()) throw new RuntimeException('第 ' . $row['line'] . ' 行同号原订单已变化，请重新预览');
                        pos_link((int)$row['split_parent_id'], $orderId, $actor);
                        ps_audit('order', (int)$row['split_parent_id'], 'split_child_added', $actor, ['child_order_id' => $orderId, 'business' => $row['project_type'], 'amount' => $row['contract_amount']]);
                    }
                    if (count($row['lines'] ?? []) > 1 && !empty($actor['employee_id'])) { try { require_once __DIR__ . '/../includes/dup_feedback.php'; pd_ask($orderId, $row['order_no'], (int)$actor['employee_id'], $row['lines'], $row['contract_amount']); } catch (Throwable $e) { /* 通知失败不影响导入 */ } }
                    ps_source_record($orderId, $row['contract_amount'] === '' ? 'missing' : 'manual', $row['payment_nickname'], $row['trade_status'] ?? '', $row['payment_reference'] ?? '');
                    $writeBusiness = $row['project_type'] ?? $selectedBusiness;
                    ps_save_business_details($orderId, $writeBusiness, $actor['role'] === 'customer_service' && $writeBusiness === '网站模板' ? ps_business_details($writeBusiness, []) : $row['details']);
                    ps_intake_participants($orderId, $row['people'], $writeBusiness);
                    if ($departmentMode) ps_department_import_record($orderId, $actor);
                    if ($businessDefinition['resources']) ps_intake_save_resources($orderId, 'excel', (int)$row['line'], $domainTemplate, $serverTemplate, is_numeric($row['ssl_used']) ? $row['ssl_used'] : null, $actor['role'] === 'customer_service' || $forcedMode === 'pending' ? 'pending' : null, $programTemplate);
                    if ($programTemplate) ps_intake_add_template_cost($orderId, $programTemplate, $actor, 'Excel 第' . $row['line'] . '行：程序套餐');
                    if ($domainTemplate) ps_intake_add_template_cost($orderId, $domainTemplate, $actor, 'Excel 第' . $row['line'] . '行：域名');
                    if ($serverTemplate) ps_intake_add_template_cost($orderId, $serverTemplate, $actor, 'Excel 第' . $row['line'] . '行：服务器');
                    poi_save($orderId, $row['items'] ?? [], (int)$_SESSION['project_import_file'], $actor);
                    if ($writeBusiness === '商标' && (($row['direct_cost'] ?? '') === '' || (float)$row['direct_cost'] == 0)) ptc_apply($orderId, $row['details']['trademark_count'] ?? '', $actor, 'Excel 第' . $row['line'] . '行', implode(' ', [$row['details']['trademark_name'] ?? '', $row['details']['service_type'] ?? '', $row['contact_note'] ?? '', $row['business_text'] ?? '', $row['resource_note'] ?? '']));
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
                if ($actor['role'] !== 'finance') {
                    $kindCounts = [];
                    foreach ($ready as [$row]) if (!empty($row['layout_signature']) && !empty($row['order_kind'])) $kindCounts[$row['layout_signature']][$row['order_kind']] = ($kindCounts[$row['layout_signature']][$row['order_kind']] ?? 0) + 1;
                    foreach ($kindCounts as $signature => $counts) {
                        arsort($counts);
                        ps_import_kind_preference_save((int)$actor['employee_id'], $selectedBusiness, $signature, (string)array_key_first($counts));
                    }
                }
                $resultFileId = (int)$_SESSION['project_import_file'];
                $importedLines = array_map(function ($item) { return (int)$item[0]['line']; }, $ready);
                $importReport = ps_import_result_save($resultFileId, $preview, $importedLines, $actor, $operator, $imported);
                if ($nested) $pdo->exec('RELEASE SAVEPOINT project_order_import');
                else $pdo->commit();
                // Import remains successful even when financial evidence needs supplementation.
                require_once __DIR__ . '/../includes/ProjectAutoReview.php';
                try {
                    $reviewIds = [];
                    foreach (array_slice($ready, 0, 25) as $reviewEntry) {
                        $reviewLookup = $pdo->prepare('SELECT id FROM project_orders WHERE order_no=?');
                        $reviewLookup->execute([$reviewEntry[0]['order_no']]);
                        if ($reviewId = (int)$reviewLookup->fetchColumn()) $reviewIds[] = $reviewId;
                    }
                    pa_after_save($reviewIds);
                } catch (Throwable $reviewError) { error_log('auto_review_import_followup: ' . $reviewError->getMessage()); }
                // Ancillary renewal data cannot roll back or block valid financial orders.
                require_once __DIR__ . '/../includes/ProjectRenewalImport.php';
                if (pr_ready()) {
                    try {
                        pr_seed();
                        foreach ($ready as $renewalEntry) {
                            $renewalRow=$renewalEntry[0];
                            if (empty($renewalRow['renewal_fields'])) continue;
                            $renewalLookup=$pdo->prepare('SELECT id FROM project_orders WHERE order_no=?');
                            $renewalLookup->execute([$renewalRow['order_no']]); $renewalId=(int)$renewalLookup->fetchColumn();
                            if ($renewalId) pr_import_apply($renewalId,$renewalRow['renewal_fields'],$actor);
                        }
                    } catch (Throwable $renewalError) { error_log('renewal_import_followup: '.get_class($renewalError)); }
                }
                ps_ai_mark_applied($_SESSION['project_import_ai_touched'] ?? []);
                $preferenceEmployeeId = (int)($actor['employee_id'] ?? 0);
                if (!$preferenceEmployeeId && !empty($_SESSION['project_import_file'])) {
                    $ownerQuery = db()->prepare('SELECT employee_id FROM project_import_files WHERE id=?');
                    $ownerQuery->execute([(int)$_SESSION['project_import_file']]);
                    $preferenceEmployeeId = (int)$ownerQuery->fetchColumn();
                }
                if ($preferenceEmployeeId) {
                    $businessSignatures = array_unique(array_filter(array_map(function ($item) { return $item[0]['business_signature'] ?? ''; }, $ready)));
                    foreach ($businessSignatures as $signature) ps_import_business_preference_save($preferenceEmployeeId, $signature, $selectedBusiness);
                }
                $importedLines = array_map(function ($item) { return (int)$item[0]['line']; }, $ready);
                $remaining = array_values(array_filter($preview, function ($row) use ($importedLines) { return !in_array((int)$row['line'], $importedLines, true) && !in_array($row['status'] ?? '', ['已导入过', '他人订单'], true); }));
                // 缺订单号 / 日期的真实订单：记为待补全，站内信 + 弹窗请上传人直接补填（弹窗补填后仍未通过的也继续保留）
                if (!empty($_SESSION['project_import_file'])) ps_import_followup_save((int)$_SESSION['project_import_file'], $actor, $selectedBusiness, $scope, array_keys(array_filter($_SESSION['project_import_sheets'] ?? [], function ($i) { return !empty($i['used']); })), ps_import_followup_rows($remaining, $action === 'followup'));
                if (!empty($_SESSION['project_import_file'])) {
                    ps_import_file_mark((int)$_SESSION['project_import_file'], 'imported', ['imported_count' => count($importReport['written_order_ids']), 'skipped_count' => $importReport['existing'] + $importReport['ignored'] + $importReport['pending']]);
                }
                if ($remaining) {
                    $_SESSION['project_import_preview'] = $remaining;
                    $_SESSION['project_import_pending_lines'] = array_map(function ($row) { return (int)$row['line']; }, $remaining);
                    $preview = $remaining;
                } else {
                    unset($_SESSION['project_import_preview'], $_SESSION['project_import_actor'], $_SESSION['project_import_business'], $_SESSION['project_import_scope'], $_SESSION['project_import_people'], $_SESSION['project_import_rule_month'], $_SESSION['project_import_file'], $_SESSION['project_import_sheets'], $_SESSION['project_import_ai_touched'], $_SESSION['project_import_pending_lines']);
                    $preview = [];
                }
            } catch (Throwable $e) { if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_order_import'); else $pdo->rollBack(); throw $e; }
        } elseif (!in_array($action, ['preview', 'repreview', 'repair_preview', 'followup'], true)) throw new RuntimeException('操作无效');
    } catch (Throwable $e) { $error = $e instanceof PDOException ? '导入发生订单号冲突或保存失败，请重新上传预览' : $e->getMessage(); }
}
$baseValidCount = count(array_filter($preview, function ($row) { return !empty($row['base_valid']); }));
// 已导入并审核过的、写的是他人的行：自动跳过，灰色显示，不算“需处理”
$isSkipRow = function ($row) { return empty($row['base_valid']) && in_array($row['status'] ?? '', ['已导入过', '他人订单'], true); };
$skipCounts = array_count_values(array_map(function ($row) { return $row['status']; }, array_filter($preview, $isSkipRow)));
$invalidCount = count($preview) - $baseValidCount - array_sum($skipCounts);
// 因填写问题无法识别的：按问题归类，弹窗告诉上传人怎么填、给示例和模板下载
$fixGuides = [];
if ($error !== '' && ($guide = ps_import_fix_guide($error, $selectedBusiness))) $fixGuides[$guide['key']] = $guide + ['lines' => []];
foreach ($preview as $previewRow) {
    if (!empty($previewRow['base_valid']) || $isSkipRow($previewRow)) continue;
    foreach (array_filter(explode('；', (string)$previewRow['error'])) as $part) {
        $guide = ps_import_fix_guide(preg_replace('/^同号第 \d+ 行：/u', '', $part), $selectedBusiness);
        if (!$guide) continue;
        $fixGuides[$guide['key']] = $fixGuides[$guide['key']] ?? $guide + ['lines' => []];
        $fixGuides[$guide['key']]['lines'][] = (int)$previewRow['line'] % 10000;
    }
}
$templateUrl = $selectedBusiness ? '?business=' . rawurlencode($selectedBusiness) . '&scope=' . ($departmentMode ? 'department' : 'personal') . '&download=1' : '';
$repairableCount = count(array_filter($preview, function ($row) use ($isSkipRow) { return !$isSkipRow($row) && (empty($row['order_no']) || empty($row['order_date'])); }));
$suggestedDates = []; $lastDateBySheet = [];
foreach ($preview as $previewRow) {
    $sheetKey = (string)($previewRow['sheet'] ?? '');
    if (!empty($previewRow['order_date'])) $lastDateBySheet[$sheetKey] = $previewRow['order_date'];
    elseif (!empty($previewRow['suggested_date'])) $suggestedDates[(int)$previewRow['line']] = $previewRow['suggested_date'];
    elseif (isset($lastDateBySheet[$sheetKey])) $suggestedDates[(int)$previewRow['line']] = $lastDateBySheet[$sheetKey];
}
$page_title = $departmentMode ? '网站售后部门订单' : '导入项目订单';
if (PHP_SAPI === 'cli' && !empty($GLOBALS['project_import_cli'])) return;
include __DIR__ . '/../includes/header.php';
?>
<style>.import-warn{color:#7a4500;font-weight:600}.import-fix{background:#fff;border:1px solid #bfdccb;border-radius:10px;padding:.55rem .75rem;max-width:520px}.import-fix-hint{color:#6b3d00;background:#fff4dc;border-radius:6px;padding:.3rem .5rem;margin-bottom:.4rem;line-height:1.55;font-size:.84rem}.import-fix-title{font-weight:700;color:#17503e;font-size:.84rem;margin-bottom:.2rem}.import-fix-row{display:block;margin:.1rem 0;font-size:.85rem;color:#2b3f38}.import-fix-actions{margin-top:.4rem}.import-fix-foot{color:#6a7a75;font-size:.78rem;margin-top:.3rem}.import-detail{color:#5a1f1f;background:#fff;border:1px dashed #e0a3a3;border-radius:6px;padding:.25rem .5rem;display:inline-block;line-height:1.55}</style>
<?php
?>
<div class="project-intake-page">
<div class="project-hero mb-3"><div><div class="project-eyebrow">项目合作结算中心 · <?php echo $departmentMode ? '网站售后部门订单' : '批量录入'; ?></div><h2><?php echo $departmentMode ? '上传网站售后部门订单' : '导入' . e($selectedBusiness ?: '项目') . '订单'; ?></h2><p><?php echo $departmentMode ? '按网站续费、网站修改或备案模板上传，表格中的参与人逐单匹配；未写姓名时可选择本批默认参与人。部门代录不要求上传人参与每一单，实收仍由财务确认。' : '先选业务模板，再拖入 Excel 逐行核对。已关联人员上传相同订单号时补充原单，不会重复建单；售价不直接作为实收。'; ?></p></div><div class="project-hero-actions"><a class="btn btn-light" href="<?php echo BASE_URL; ?>/project/index.php?business=<?php echo rawurlencode($selectedBusiness ?: ''); ?>">返回订单录入</a></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($importReport): ?><div class="alert alert-<?php echo $importReport['pending'] ? 'warning' : 'success'; ?>">本次已写入 / 补充 <?php echo (int)$importReport['written']; ?> 单，已在库 <?php echo (int)$importReport['existing']; ?> 单<?php echo $importReport['pending'] ? '；还有 ' . (int)$importReport['pending'] . ' 行待补全，其他订单已成功保存' : '，核对已完成'; ?>。实收仍须财务确认。<a class="btn btn-sm btn-success ml-2" href="<?php echo BASE_URL; ?>/project/index.php?import_file=<?php echo $resultFileId; ?>">查看这份表格对应订单</a></div><?php elseif ($imported): ?><div class="alert alert-success">已导入 <?php echo $imported; ?> 个订单。实收仍须财务确认。</div><?php endif; ?>
<?php if ($resumeFile): ?><div class="card mb-3"><div class="card-body"><strong>继续核对：<?php echo e($resumeFile['original_name']); ?></strong><p class="small text-muted mt-2">直接读取已保存原件，无需重传。按原上传人的业务和参与关系导入，已审核订单保持不变。</p><form method="post"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="repreview"><input type="hidden" name="resume_file" value="<?php echo $resumeFileId; ?>"><input type="hidden" name="file_id" value="<?php echo $resumeFileId; ?>"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><input type="hidden" name="scope" value="<?php echo e($scope); ?>"><input type="hidden" name="all_sheets" value="1"><input type="hidden" name="auto_import" value="1"><button class="btn btn-success">核对并导入有效订单</button></form></div></div><?php endif; ?>
<?php if (!$selectedBusiness): ?><div class="alert alert-warning">当前账户尚未分配业务，请联系财务配置。</div><?php else: ?>
<div class="card project-form-card mb-3"><div class="card-body"><div class="project-section-title"><span class="project-step">01</span><div><h5><?php echo $departmentMode ? '部门订单 · 批量上传' : '上传订单表'; ?></h5><p><?php echo $departmentMode ? '支持 .xlsx / .xls / .csv，最多 1500 行、20 MB；只允许网站售后部成员及财务代录。' : '支持 .xlsx / .xls / .csv，最多 1500 行、20 MB。技术和客服只能导入写有本人参与的订单；网站客服新单须指定接单技术。'; ?></p></div></div>
<?php if ($departmentBusinesses): ?><div class="mb-3"><a class="btn btn-sm <?php echo $departmentMode ? 'btn-outline-secondary' : 'btn-success'; ?>" href="?business=<?php echo rawurlencode($departmentMode ? $selectedBusiness : ($departmentBusinesses[0] ?? '网站续费')); ?>&scope=<?php echo $departmentMode ? 'personal' : 'department'; ?>"><?php echo $departmentMode ? '返回个人订单导入' : '切换到网站售后部门订单'; ?></a></div><?php endif; ?>
<form method="get" class="form-inline mb-3"><input type="hidden" name="scope" value="<?php echo $departmentMode ? 'department' : 'personal'; ?>"><label class="mr-2" for="importBusiness">业务模板</label><select id="importBusiness" name="business" class="form-control mr-2" onchange="this.form.submit()"><?php foreach ($departmentMode ? $departmentBusinesses : $allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $selectedBusiness === $businessName ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select><?php if ($departmentMode): ?><label class="mr-2" for="deptRuleMonth">规则月份</label><input id="deptRuleMonth" type="month" name="rule_month" class="form-control mr-2" value="<?php echo e($ruleMonth); ?>" onchange="this.form.submit() "><?php endif; ?><a class="btn btn-outline-success" href="?business=<?php echo rawurlencode($selectedBusiness); ?>&scope=<?php echo $departmentMode ? 'department' : 'personal'; ?>&download=1">下载此业务模板（含示例行）</a><?php $tplExtras = ps_import_role_extras($selectedBusiness, $actor, ps_business_import_headers_base($selectedBusiness)); if ($tplExtras): ?><span class="small text-muted ml-2">已按你的岗位加入“<?php echo e(implode('、', $tplExtras)); ?>”列</span><?php endif; ?></form>
<form method="post" enctype="multipart/form-data" id="projectUploadForm" data-legacy-xls-upload><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="preview"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><input type="hidden" name="scope" value="<?php echo $departmentMode ? 'department' : 'personal'; ?>"><input type="hidden" name="rule_month" value="<?php echo e($ruleMonth); ?>">
<?php if ($departmentMode): ?>
<div class="p-3 mb-3" style="background:#f0f7f2;border:1px solid #d8eadc;border-radius:14px">
  <strong>本批默认参与人</strong>
  <div class="small text-muted mb-2">表格有客服 / 技术姓名时按每行姓名归属；没有姓名时由下方所选人员共同分单，逐单报酬默认等权。网站续费的部门共享比例独立按规则中心计算，选择参与人不会重复分配部门共享提成。</div>
  <div class="d-flex flex-wrap" style="gap:8px 18px"><?php foreach ($departmentChoices as $person): ?><label class="mb-0"><input type="checkbox" name="dept_people[]" value="<?php echo (int)$person['id']; ?>" <?php echo isset($departmentDefaults[(int)$person['id']]) ? 'checked' : ''; ?>> <?php echo e($person['name']); ?><?php if (isset($renewalRates[(int)$person['id']])): ?> <small class="text-success">续费共享 <?php echo e(rtrim(rtrim(number_format($renewalRates[(int)$person['id']] * 100, 4), '0'), '.')); ?>%</small><?php endif; ?></label><?php endforeach; ?></div>
  <div class="small text-muted mt-2">显示的是 <?php echo e($ruleMonth); ?> 生效规则；<?php if ($actor['role'] === 'finance'): ?>修改比例请到 <a href="<?php echo BASE_URL; ?>/project/rules.php">规则中心</a><?php else: ?>比例由财务在规则中心维护<?php endif; ?>。实际结算按结算月份生效规则计算。</div>
</div>
<?php endif; ?>
<input type="file" name="parsed_file" hidden><label for="projectImportFile" id="projectDropZone" class="project-drop-zone"><i class="fas fa-cloud-upload-alt"></i><strong>拖拽 Excel 到这里，或点击选择文件</strong><span id="projectFileName">尚未选择文件</span><input type="file" id="projectImportFile" name="file" accept=".xlsx,.xls,.csv" required></label><label class="d-block mt-3"><input type="checkbox" name="auto_import" value="1" checked> 核对通过的订单直接导入，问题行保留待补全</label><button class="btn btn-success btn-lg mt-2" type="submit">上传并导入有效订单</button><small class="d-block text-muted mt-2" data-xls-status>旧版 XLS 可直接上传，原件会保留。取消上方勾选可先预览、不导入。</small></form></div></div>
<?php endif; ?>
<?php
$previewSheets = $preview ? array_filter($_SESSION['project_import_sheets'] ?? [], function ($i) { return !empty($i['used']); }) : [];
$sheetReport = $preview ? ($_SESSION['project_import_sheets'] ?? []) : [];
$previewFileId = $preview ? (int)($_SESSION['project_import_file'] ?? 0) : 0;
?>
<?php if ($fixGuides): ?>
<div class="modal fade" id="importFixGuide" tabindex="-1" role="dialog" aria-labelledby="importFixGuideTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable" role="document"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title" id="importFixGuideTitle"><i class="fas fa-hand-point-right text-warning mr-1"></i>表格有 <?php echo count($fixGuides); ?> 处填写需要改一下</h5><button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button></div>
<div class="modal-body"><p class="text-muted small mb-3">系统按下面的写法才能自动识别。改好原表后重新上传即可；缺日期、缺订单号的行也可以直接在预览表格里补填。<?php echo $baseValidCount ? '已通过的 ' . $baseValidCount . ' 行可先导入，不受影响。' : ''; ?></p>
<?php foreach ($fixGuides as $guide): $guideLines = array_values(array_unique($guide['lines'])); ?><div class="p-3 mb-2" style="background:#fff8e6;border:1px solid #f3dca4;border-radius:12px"><strong><?php echo e($guide['title']); ?></strong><?php if ($guideLines): ?> <span class="badge badge-warning"><?php echo count($guideLines); ?> 行</span> <small class="text-muted">第 <?php echo e(implode('、', array_slice($guideLines, 0, 10))) . (count($guideLines) > 10 ? ' 等' : ''); ?> 行</small><?php endif; ?><div class="mt-1"><?php echo e($guide['how']); ?></div><?php if ($guide['example'] !== ''): ?><div class="mt-1 small">正确示例：<code style="font-size:.95em"><?php echo e($guide['example']); ?></code></div><?php endif; ?></div><?php endforeach; ?>
</div>
<div class="modal-footer"><?php if ($templateUrl): ?><a class="btn btn-outline-success" href="<?php echo e($templateUrl); ?>"><i class="fas fa-download mr-1"></i>下载“<?php echo e($selectedBusiness); ?>”正确模板（含示例行）</a><?php endif; ?><button type="button" class="btn btn-primary" data-dismiss="modal">我知道了</button></div>
</div></div></div>
<script>document.addEventListener('DOMContentLoaded', function () { if (window.jQuery && jQuery.fn.modal) jQuery('#importFixGuide').modal('show'); });</script>
<?php endif; ?>
<?php if ($businessDetectionNote): ?><div class="alert alert-info"><?php echo e($businessDetectionNote); ?></div><?php endif; ?>
<?php if (count($allowedBusinesses) > 1 && ($previewFileId || $retryFileId)): ?>
<form method="post" class="card project-form-card mb-3"><div class="card-body form-inline"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="action" value="repreview"><input type="hidden" name="all_sheets" value="1"><input type="hidden" name="file_id" value="<?php echo $previewFileId ?: $retryFileId; ?>"><label class="mr-2" for="previewBusiness">这张表实际属于</label><select class="form-control mr-2" name="business" id="previewBusiness"><?php foreach ($allowedBusinesses as $businessName): ?><option value="<?php echo e($businessName); ?>" <?php echo $businessName === $selectedBusiness ? 'selected' : ''; ?>><?php echo e($businessName); ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary" type="submit">按此业务重新核对</button><small class="text-muted ml-2">归属不对时切换一次，不用重传；导入成功后会记住同版式。</small></div></form>
<?php endif; ?>
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
<?php $blankTotal = array_sum(array_map(function ($i) { return (int)($i['blank'] ?? 0); }, $previewSheets)); if ($blankTotal): ?><div class="alert alert-light border small"><i class="fas fa-eraser mr-1 text-muted"></i>已自动略过 <?php echo $blankTotal; ?> 行没有订单号也没有金额的空白 / 备注行（如预先填好姓名的空行），它们不是订单，无需处理。</div><?php endif; ?>
<?php if ($invalidCount): ?><div class="alert alert-info"><strong><?php echo $baseValidCount; ?> 行资料已通过，<?php echo $invalidCount; ?> 行需处理。</strong>可先导入合格行，红色行不会写入订单；也可在缺失处在线补填后点“重新核对”。请填写真实店铺订单号，或微信交易流水号；后者会生成可追溯的内部关联号。</div><?php endif; ?>
<?php if ($skipCounts): ?><div class="alert alert-secondary"><?php echo e(implode('，', array_map(function ($status, $n) { return $n . ' 行' . ($status === '已导入过' ? '已导入并经财务审核' : '写的是其他同事（由本人上传或财务导入）'); }, array_keys($skipCounts), $skipCounts))); ?>，已自动跳过（灰色行），无需处理。</div><?php endif; ?>
<form method="post" class="card project-form-card mb-3"><input type="hidden" name="csrf" value="<?php echo e(ps_csrf_token()); ?>"><input type="hidden" name="business" value="<?php echo e($selectedBusiness); ?>"><div class="card-body pb-2"><?php if ($previewKinds): ?><div class="project-kind-bulk d-flex flex-wrap align-items-center mb-2" style="gap:8px"><span class="small text-muted">订单类型已自动带入，无需逐行选择；发现误判时可修改。</span><select class="form-control form-control-sm" id="kindBulk" style="width:auto" aria-label="批量修正订单类型"><option value="">批量修正类型…</option><?php foreach ($previewKinds as $k): ?><option value="<?php echo e($k); ?>"><?php echo e($k); ?></option><?php endforeach; ?></select><label class="small mb-0"><input type="checkbox" id="kindBulkAll"> 已选的行也改</label></div>
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
<?php foreach ($preview as $row): $skipRow = $isSkipRow($row); ?><tr class="<?php echo $skipRow ? 'table-secondary text-muted' : (empty($row['base_valid']) ? 'table-danger' : ($row['status'] === '可导入' ? '' : 'table-warning')); ?>">
<td><small><?php echo count($previewSheets) > 1 && !empty($row['sheet']) ? '【' . e($row['sheet']) . '】' : ''; ?>第 <?php echo e(implode('、', array_map(function ($l) { return (int)$l % 10000; }, $row['lines'] ?? [$row['line']]))); ?> 行</small><br><?php if (empty($row['order_no'])): ?><input class="form-control form-control-sm mt-1" name="fix_order_no[<?php echo (int)$row['line']; ?>]" maxlength="100" placeholder="店铺订单号（有则填）" aria-label="第<?php echo (int)$row['line']; ?>行店铺订单号"><input class="form-control form-control-sm mt-1" name="fix_payment_reference[<?php echo (int)$row['line']; ?>]" maxlength="200" placeholder="或填微信交易流水号 / 支付订单号" aria-label="第<?php echo (int)$row['line']; ?>行微信交易流水号"><small class="text-danger">二者填一个即可；微信流水号生成内部关联号</small><?php else: ?><strong><?php echo e($row['order_no']); ?></strong><?php if (!empty($row['payment_reference'])): ?><br><small>微信流水号：<?php echo e($row['payment_reference']); ?></small><?php endif; ?><?php endif; ?><br><small><?php echo e($row['project_type'] ?? ''); ?></small></td>
<td><?php if (empty($row['order_date'])): ?><input type="date" class="form-control form-control-sm" name="fix_date[<?php echo (int)$row['line']; ?>]" value="<?php echo e($suggestedDates[(int)$row['line']] ?? ''); ?>" aria-label="第<?php echo (int)$row['line']; ?>行订单日期"><small class="text-warning"><?php echo isset($suggestedDates[(int)$row['line']]) ? '建议上一行日期，请核对' : '请补订单日期'; ?></small><?php else: ?><?php echo e($row['order_date']); ?><?php endif; ?><br><strong>¥<?php echo e(($row['contract_amount'] ?? '') === '' ? '待补' : $row['contract_amount']); ?></strong><?php if ($previewKinds && !empty($row['base_valid'])): $currentKind = ($row['order_kind'] ?? '') !== '' ? $row['order_kind'] : ($row['kind_guess'] ?? ''); ?><br><select class="form-control form-control-sm mt-1 js-kind-choice<?php echo ($row['order_kind'] ?? '') === '' ? ' is-invalid' : ''; ?>" name="kind_choice[<?php echo (int)$row['line']; ?>]" aria-label="订单类型"><option value="">选择订单类型</option><?php foreach ($previewKinds as $k): ?><option value="<?php echo e($k); ?>" <?php echo $currentKind === $k ? 'selected' : ''; ?>><?php echo e($k); ?></option><?php endforeach; ?></select><?php if (($row['order_kind'] ?? '') === '' && !empty($row['kind_guess'])): ?><small class="text-muted"><?php echo !empty($row['kind_from_ai']) ? 'AI 建议' : '按描述猜测'; ?>，请确认</small><?php endif; ?><?php elseif (($row['order_kind'] ?? '') !== ''): ?><br><small class="text-muted"><?php echo e($row['order_kind']); ?></small><?php endif; ?></td>
<td><small>客服：<?php echo e(implode('、', array_column($row['people']['customer_service'], 'name')) ?: '—'); ?><br>技术：<?php echo e(implode('、', array_column($row['people']['technical'], 'name')) ?: '—'); ?></small><?php foreach ($row['items'] ?? [] as $oi): ?><div class="small text-muted mt-1"><?php echo e($oi['item_name']); ?><?php echo $oi['sale_amount']===null ? ' · 整单计价' : ' · ¥' . money($oi['sale_amount']); ?><?php echo $oi['category']==='certificate' ? ' · 证书独立保留' : ''; ?></div><?php endforeach; ?></td>
<?php if ($businessDefinition['fields']): ?><td><small><?php foreach ($businessDefinition['fields'] as $key => $label): ?><?php echo e($label . '：' . ps_contact_for($actor, ($row['details'][$key] ?? '') ?: '—', $key === 'customer_wechat')); ?><br><?php endforeach; ?></small></td><?php endif; ?>
<?php if ($resourceSelection && $usesProgram): ?><td><?php if (!empty($row['resource_locked'])): ?><span class="text-muted">原单已确认</span><?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="program_choice[<?php echo (int)$row['line']; ?>]" aria-label="第<?php echo (int)$row['line']; ?>行程序套餐"><option value="0">不使用程序套餐</option><?php foreach ($programTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo (int)($row['program_template_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name'] . ' · ' . $t['specification'] . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><small class="text-muted">原表：<?php echo e(($row['program_name'] ?? '') ?: '未写程序名称'); ?></small><?php else: ?>—<?php endif; ?></td><?php endif; ?>
<?php if ($resourceSelection): ?>
<td><?php if (!empty($row['resource_locked'])): ?><span class="text-muted">原单已确认</span><?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="domain_choice[<?php echo (int)$row['line']; ?>]" aria-label="第<?php echo (int)$row['line']; ?>行域名"><option value="">请选择域名方式</option><?php if (($row['domain_mode'] ?? '') === 'pending'): ?><option value="pending" selected>待技术确认</option><?php endif; ?><option value="none" <?php echo $row['domain_mode'] === 'none' ? 'selected' : ($row['domain_mode'] === 'template' ? 'disabled' : ''); ?>>无需域名</option><?php foreach ($domainTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$row['domain_template_id'] === (int)$t['id'] ? 'selected' : ($row['domain_mode'] === 'none' ? 'disabled' : ''); ?>><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><small class="text-muted">原表：<?php echo e(($row['domain_used'] ?: '空白') . ' · ' . ($row['resource_note'] ?: '未写域名/空间')); ?></small><?php else: ?>—<?php endif; ?></td>
<td><?php if (!empty($row['resource_locked'])): ?>—<?php elseif (!empty($row['base_valid'])): ?><select class="form-control form-control-sm" name="server_template_id[<?php echo (int)$row['line']; ?>]"><option value="0">不自动计服务器</option><?php foreach ($serverTemplates as $t): ?><option value="<?php echo (int)$t['id']; ?>"><?php echo e(trim($t['name'] . ' ' . $t['specification']) . ' · ¥' . money($t['price'])); ?></option><?php endforeach; ?></select><?php else: ?>—<?php endif; ?></td>
<?php endif; ?>
<td><span class="badge badge-<?php echo $skipRow ? 'secondary' : (empty($row['base_valid']) ? 'danger' : ($row['status'] === '可导入' ? 'success' : 'warning')); ?>"><?php echo e($row['status']); ?></span><?php if ($row['error']): ?><div class="small <?php echo $skipRow ? 'text-muted' : 'text-danger'; ?> mt-1"><?php echo e($row['error']); ?></div><?php if (!$skipRow && ($rowGuide = ps_import_fix_guide($row['error'], $selectedBusiness))): ?><div class="small text-muted mt-1"><i class="fas fa-lightbulb text-warning mr-1"></i>怎么改：<?php echo e($rowGuide['how']); ?><?php echo $rowGuide['example'] !== '' ? '　例：' . e($rowGuide['example']) : ''; ?></div><?php endif; ?><?php if (!$skipRow && !empty($row['conflict_detail'])): ?><div class="small import-detail mt-1"><i class="fas fa-not-equal mr-1"></i><?php echo e($row['conflict_detail']); ?></div><?php endif; ?><?php if (!$skipRow && !empty($row['fix_panel']) && $row['fix_panel']['order_id'] > 0 && $row['fix_panel']['fields']): $fp = $row['fix_panel']; ?>
<div class="import-fix mt-2" data-order-id="<?php echo (int)$fp['order_id']; ?>">
  <?php if ($fp['hint'] !== ''): ?><div class="import-fix-hint"><i class="fas fa-lightbulb"></i> <?php echo e($fp['hint']); ?></div><?php endif; ?>
  <div class="import-fix-title">表里是对的？直接更正原单（勾选要改的）</div>
  <?php foreach ($fp['fields'] as $f): ?><label class="import-fix-row"><input type="checkbox" class="js-fixf" value="<?php echo e($f[0]); ?>" data-new="<?php echo e($f[3]); ?>" <?php echo ($f[6] && !($fp['same_prev'] && $f[0] !== 'contract_amount')) ? 'checked' : ''; ?>> <?php echo e($f[1]); ?>：<s><?php echo e($f[4] !== '' ? $f[4] : '（空）'); ?></s> → <b><?php echo e($f[5]); ?></b></label><?php endforeach; ?>
  <div class="import-fix-actions"><button type="button" class="btn btn-sm btn-success js-fix-submit"><?php echo $actor['role'] === 'finance' ? '立即更正原单' : '提交财务确认'; ?></button> <span class="small js-fix-msg" role="status"></span></div>
  <div class="import-fix-foot"><?php echo $actor['role'] === 'finance' ? '更正后点上方“重新核对”即可导入。' : '财务确认后，回到这里点“重新核对”就能导入；也可以改表格后重新上传。'; ?></div>
</div>
<?php endif; ?><?php endif; ?><?php if ($row['warning']): ?><div class="small import-warn mt-1"><?php echo e($row['warning']); ?></div><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><div class="card-body border-top d-flex flex-wrap justify-content-between align-items-center" style="gap:10px;position:sticky;bottom:0;z-index:20;background:#fff;box-shadow:0 -3px 10px rgba(15,64,40,.12);border-radius:0 0 14px 14px"><small class="text-muted">红色行不会入账；可先处理已通过的行。绿色按钮点击后订单才真正写入，仅上传预览不会入库。售价不会直接变成实收<?php echo $businessDefinition['resources'] ? '，SSL 报备价不会直接入成本' : ''; ?>。</small><div class="d-flex flex-wrap" style="gap:8px"><?php if ($repairableCount): ?><button class="btn btn-outline-primary mt-2" type="submit" name="action" value="repair_preview">应用补填并重新核对</button><?php endif; ?><button class="btn btn-success btn-lg mt-2" type="submit" name="action" value="commit" <?php echo $baseValidCount ? '' : 'disabled'; ?>><?php echo $invalidCount ? '先导入 ' . $baseValidCount . ' 行合格订单' : '确认导入已核对订单'; ?></button></div></div></form>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
  var csrf = <?php echo json_encode(ps_csrf_token()); ?>, url = <?php echo json_encode(BASE_URL . '/project/order_fix_api.php'); ?>;
  document.querySelectorAll('.js-fix-submit').forEach(function (b) { b.addEventListener('click', function () {
    var box = b.closest('.import-fix'), msg = box.querySelector('.js-fix-msg'), changes = {};
    box.querySelectorAll('.js-fixf:checked').forEach(function (c) { changes[c.value] = c.getAttribute('data-new'); });
    if (!Object.keys(changes).length) { msg.textContent = '请至少勾选一项'; msg.className = 'small js-fix-msg text-danger'; return; }
    b.disabled = true; msg.textContent = '提交中…'; msg.className = 'small js-fix-msg text-muted';
    fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf: csrf, action: 'fix', order_id: box.dataset.orderId, changes: changes})}).then(function (r) {
      return r.text().then(function (t) { var d; try { d = JSON.parse(t); } catch (e) { throw new Error(r.status === 403 ? '没有权限更正这个订单（需要是订单参与人）' : '服务器返回了非预期内容，请刷新页面后重试'); } if (d.error) throw new Error(d.error); return d; });
    }).then(function (d) {
      msg.textContent = d.mode === 'applied' ? '✓ 原单已更正，请点上方“重新核对”' : '✓ 已提交，财务确认后点“重新核对”即可';
      msg.className = 'small js-fix-msg text-success';
    }).catch(function (e) { msg.textContent = e.message; msg.className = 'small js-fix-msg text-danger'; b.disabled = false; });
  }); });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
