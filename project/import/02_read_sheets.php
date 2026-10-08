<?php
            // 原始表格先保存（财务可在“原始表格”页查看 / 下载）；重新选择工作表时直接读已保存的文件，不必重新上传。
            if ($action === 'preview') {
                if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || $_FILES['file']['size'] > 20 * 1024 * 1024) throw new RuntimeException('请选择不超过 20 MB 的 XLSX、XLS 或 CSV 文件'
    );
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
                if ($departmentMode && !ps_department_import_allowed($actor, $selectedBusiness)) throw new RuntimeException('表格识别到非网站售后业务，请选择正确业务模板后重新上传'
    );
                $businessDetectionNote = '已按' . $detected['reason'] . '归入“' . $selectedBusiness . '”。若不对，下方可切换业务后重新核对，无需重传表格。'
    ;
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
            $chosenSheets = $action === 'repreview' ? (!empty($_POST['all_sheets']) ? null : array_map('strval', (array)($_POST['sheets'] ?? []))) : ($action === 'repair_preview' ?
    array_keys(array_filter($_SESSION['project_import_sheets'] ?? [], function ($entry) { return !empty($entry['used']); })) : null);
            if ($action === 'followup') $chosenSheets = $followup['sheets'] !== '' ? explode('、', $followup['sheets']) : null;
            $fixOrderNos = in_array($action, ['repair_preview', 'followup'], true) ? (array)($_POST['fix_order_no'] ?? []) : [];
            $fixDates = in_array($action, ['repair_preview', 'followup'], true) ? (array)($_POST['fix_date'] ?? []) : [];
            $fixPaymentReferences = in_array($action, ['repair_preview', 'followup'], true) ? (array)($_POST['fix_payment_reference'] ?? []) : [];
            $fixSiteKeys = in_array($action, ['repair_preview', 'followup'], true) ? (array)($_POST['fix_site_key'] ?? []) : [];
            $sheetReport = [];
            $usedSheets = 0;
            $totalRows = 0;
            $orderKinds = ps_business_order_kinds($selectedBusiness);
            $departmentDefaults = $departmentMode
                ? ps_department_import_people($actor, $selectedBusiness, $action === 'preview' ? (array)($_POST['dept_people'] ?? []) : array_keys((array)($_SESSION['project_import_people'
    ] ?? [])))
                : [];
            $employeesByName = ps_import_employee_index();
            $actorName = null;
            if ($actor['role'] !== 'finance') { $nameQuery = db()->prepare('SELECT name FROM employees WHERE id=?'); $nameQuery->execute([(int)$actor['employee_id']]); $actorName =
    $nameQuery->fetchColumn() ?: '本人'; }
            $knownShops = db()->query('SELECT name FROM shops')->fetchAll(PDO::FETCH_COLUMN);
            $seen = [];
            $wxSeq = []; // 无订单号行的同键序号：同一张表里完全相同的行也各得一个稳定的内部号
            $siteSeq = []; // 只计重复号；网站身份由项目标识决定，不能用行顺序决定
            $siteSeenKeys = [];
            $blankRows = [];
            $preview = [];
            $exists = db()->prepare('SELECT o.id,o.project_type,o.settlement_status,o.shop,o.contract_amount,o.order_date,s.payment_nickname,s.payment_reference,s.price_source FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.order_no=? LIMIT 1'
    );
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
                if (in_array($selectedBusiness, ['森动备案', '备案-单量'], true) && $head && in_array('域名', $head, true) && !array_intersect(['订单编号', '订单号'
    , '订单', '淘宝订单号', '微信交易流水号', '微信支付订单号', '支付订单号'], $head)) {
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
                                if ($first !== '' && count(array_filter(array_slice($r, 1, $sbWidth - 2), $nonEmpty)) === 0 && preg_match('/(\d{4})\s*年\s*(\d{1,2})\s*月/u', $first
    , $mm)) { $sbMonth = sprintf('%04d-%02d', $mm[1], $mm[2]); $raw[$k] = $blank; continue; }
                                if ($sbMonth !== '') {
                                    if ($sbSettled !== '' && $sbMonth <= $sbSettled) { $sbSettledRows++; $raw[$k] = $blank; continue; }
                                    $r[$sbDate] = date('Y-m-t', strtotime($sbMonth . '-01'));
                                    if ($sbNoIdx !== false && $sbDom !== false && trim((string)$r[$sbDom]) !== '') $r[$sbNoIdx] = 'EB-' . strtoupper(substr(md5(mb_strtolower(trim((string)
    $r[$sbDom])) . '|' . ($sbCon !== false ? trim((string)$r[$sbCon]) : '') . '|' . $sbMonth), 0, 12));
                                    $sbMonths[$sbMonth] = ($sbMonths[$sbMonth] ?? 0) + 1;
                                }
                                $raw[$k] = $r;
                            } elseif ($sbStatus !== false && mb_strpos(trim((string)$r[$sbStatus]), '未备案') !== false && ($sbNoIdx === false || trim((string)$r[$sbNoIdx]) ===
    '') && ($sbDate === false || trim((string)$r[$sbDate]) === '')) {
                                $sbPlaceholder++; $raw[$k] = $blank;
                            }
                        }
                        $sbNotes = [];
                        if ($sbSecond && $sbMonths) { ksort($sbMonths); $sbNotes[] = '已按月份标题取日期：' . implode('、', array_map(function ($m, $c) { return $m . ' '
    . $c . ' 行'; }, array_keys($sbMonths), $sbMonths)); }
                        if ($sbSettledRows) $sbNotes[] = $sbSettledRows . ' 行属于已核算月份（' . $sbSettled . ' 及以前），不再导入';
                        if ($sbPlaceholder) $sbNotes[] = $sbPlaceholder . ' 行“未备案”占位行已忽略，备案完成后再上传';
                        if ($sbNotes) $entry['ai'] = implode('；', $sbNotes) . '。';
                        $entry['raw'] = $raw; $entry['head'] = $head;
                    }
                }
                // 没有表头、第 1 行就是订单（首行有订单号样式的长数字串）：按各列内容识别，首行也作为订单读取
                $headerlessMap = $head && ps_import_row_is_data($head) ? ps_import_headerless_map(array_merge([$head], $raw), $knownShops, $employeesByName) : null;
                if ($headerlessMap) $entry = ['raw' => array_merge([$head], $raw), 'head' => array_fill(0, count($head), ''), 'map' => $headerlessMap, 'reason' => '', 'ai' => '表格没有表头，已按各列内容识别订单号、日期、售价、客服、技术等。'
    , 'line_base' => 1];
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
                    if ($aiMap && (!$requirePeople || isset($aiMap['customer_service']) || isset($aiMap['frontend']) || isset($aiMap['backend']))) { $parsedSheets[$name]['map'] = $aiMap
    ; $parsedSheets[$name]['ai'] = $aiNote; }
                    elseif (!$aiMap) $parsedSheets[$name]['reason'] .= function_exists('ps_ai_ready') && ps_ai_ready() ? '；AI 也未能识别' : '';
                }
            }
