<?php
            if (!$usedSheets) {
                $reasons = [];
                foreach ($sheetReport as $name => $info) $reasons[] = '“' . $name . '”' . $info['reason'];
                throw new RuntimeException('没有与“' . $selectedBusiness . '”表头对应的工作表（' . implode('；', $reasons) . '）。请确认业务类型，或下载该业务模板对照表头');
            }
            if (in_array($selectedBusiness, ['网站模板', 'AI网站定制'], true)) {
                $siteKeysByExternal = [];
                foreach ($preview as $candidate) if (!empty($candidate['site_external_no'])) $siteKeysByExternal[$candidate['site_external_no']][] = (string)($candidate['site_key'] ?? '');
                foreach ($preview as &$siteRow) {
                    $external = (string)($siteRow['site_external_no'] ?? '');
                    if ($external === '' || ($siteSeq[$external] ?? 0) < 2) continue;
                    $keys = $siteKeysByExternal[$external] ?? [];
                    if (in_array('', $keys, true) || count(array_unique($keys)) !== count($keys)) {
                        $siteRow['base_valid'] = false; $siteRow['status'] = '需确认网站项目';
                        $siteRow['error'] = trim(($siteRow['error'] ?? '') . '；同一付款号的每个网站须填写互不相同的“网站项目标识”（建议用域名）', '；');
                    }
                }
                unset($siteRow);
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
                    require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderFix.php';
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
