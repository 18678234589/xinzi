<?php
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
                    if (!empty($row['site_key'])) {
                        $rootId = !empty($row['multi_site_parent_no']) ? (int)($siteParentId ?? 0) : $orderId;
                        if (!$rootId) throw new RuntimeException('同一付款号的原网站项目尚未建单，请重新预览');
                        psp_register($orderId, $rootId, (string)$row['site_external_no'], (string)$row['site_key'], $actor);
                    }
                    if (!empty($row['split_parent_id'])) {
                        $parentCheck = $pdo->prepare('SELECT id FROM project_orders WHERE id=? AND order_no=?');
                        $parentCheck->execute([(int)$row['split_parent_id'], (string)$row['split_parent_no']]);
                        if (!$parentCheck->fetchColumn()) throw new RuntimeException('第 ' . $row['line'] . ' 行同号原订单已变化，请重新预览');
                        pos_link((int)$row['split_parent_id'], $orderId, $actor);
                        ps_audit('order', (int)$row['split_parent_id'], 'split_child_added', $actor, ['child_order_id' => $orderId, 'business' => $row['project_type'], 'amount' => $row['contract_amount']]);
                    }
                    if (count($row['lines'] ?? []) > 1 && !empty($actor['employee_id'])) { try { require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/dup_feedback.php'; pd_ask($orderId, $row['order_no'], (int)$actor['employee_id'], $row['lines'], $row['contract_amount']); } catch (Throwable $e) { /* 通知失败不影响导入 */ } }
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
                require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectAutoReview.php';
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
                require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectRenewalImport.php';
                // 续费资料只在确有可补的行时才预置（pr_seed 要扫全部订单，备案等表没有续费列，不必每次导入都跑）
                if (pr_ready() && array_filter($ready, function ($renewalEntry) { $f = $renewalEntry[0]['renewal_fields'] ?? []; return !empty($f) && (($f['phone'] ?? '') !== '' || !empty($f['resources'])); })) {
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
