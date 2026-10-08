<?php
                try {
                    ensureProjectColumn();
                    // 对应项目分成模块（多选，支持比例项目分成+单量补贴等同时关联）
                    $projectArr = $_POST['upload_project'] ?? [];
                    if (!is_array($projectArr)) $projectArr = [$projectArr];
                    $projectArr = array_values(array_unique(array_filter(array_map('trim', $projectArr))));

                    // 部门订单归属匹配字段（从前端接收，逗号分隔的Excel列名）
                    $ownershipFields = [];
                    if ($order_scope === 'department') {
                        $ownFieldsRaw = trim($_POST['ownership_fields'] ?? '');
                        if ($ownFieldsRaw !== '') {
                            $ownershipFields = array_values(array_filter(array_map('trim', explode(',', $ownFieldsRaw))));
                        }
                    }

                    // 部门订单多合作人员配置：[{employee_id, module}, ...]
                    $deptEmpModules = [];
                    if ($order_scope === 'department') {
                        $dem = trim($_POST['dept_emp_modules'] ?? '');
                        if ($dem !== '') {
                            $decoded = json_decode($dem, true);
                            if (is_array($decoded)) {
                                foreach ($decoded as $item) {
                                    $eid = (int)($item['employee_id'] ?? 0);
                                    $mod = trim($item['module'] ?? '');
                                    if ($eid > 0) $deptEmpModules[] = ['employee_id' => $eid, 'module' => $mod];
                                }
                            }
                        }
                        // 过滤掉不参与部门订单项目分成的合作人员（dept_share=0）
                        if (!empty($deptEmpModules)) {
                            $filtered = [];
                            foreach ($deptEmpModules as $dem) {
                                $cfg = SalaryCalculator::readModulesConfig($dem['employee_id']);
                                $share = $cfg['dept_share'] ?? 1; // 默认参与
                                if ($share == 1) {
                                    $filtered[] = $dem;
                                }
                            }
                            $deptEmpModules = $filtered;
                        }
                    }
                    $rows = [];

                    if ($csvData !== '') {
                        // 前端 SheetJS 传来的 JSON 二维数组，每行是一个数组
                        $decoded = json_decode($csvData, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $row) {
                                if (is_array($row) && count(array_filter($row, fn($v) => trim($v) !== '')) > 0) {
                                    $rows[] = $row;
                                }
                            }
                        } else {
                            $error = '数据格式错误，请重新上传';
                        }
                    } else {
                        $file     = $_FILES['excel_file'];
                        $filename = $file['name'];
                        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $tmp      = $file['tmp_name'];
                        if (!in_array($ext, ['xlsx', 'csv'])) {
                            $error = '文件类型仅支持 .xls / .xlsx / .csv';
                        } else {
                            if ($ext === 'csv') {
                                $fp = fopen($tmp, 'r');
                                $bom = fread($fp, 3);
                                if ($bom !== "\xEF\xBB\xBF") { rewind($fp); }
                                while (($data = fgetcsv($fp)) !== false) { $rows[] = $data; }
                                fclose($fp);
                            } else {
                                $rows = SimpleXLSX::parse($tmp);
                            }
                        }
                    }

                    if ($error === '' && !empty($rows)) {
                        // 自动查找表头行：第一行可能不是真正的表头（如标题行），往下找含已知列名的行
                        $headerKeywords = ['姓名', '价格', '售价', '成本', '域名', '建站', '订单', '金额', '日期', '时间', '店铺', '备注', '员工', '合作人员'
    ];
                        $headerIdx = 0;
                        for ($hi = 0; $hi < min(count($rows), 5); $hi++) {
                            $rowText = implode(' ', array_map('trim', $rows[$hi]));
                            $matchCount = 0;
                            foreach ($headerKeywords as $kw) {
                                if (mb_strpos($rowText, $kw) !== false) $matchCount++;
                            }
                            if ($matchCount >= 2) { $headerIdx = $hi; break; }
                        }
                        $firstRow = $rows[$headerIdx];
                        $dataRows = array_slice($rows, $headerIdx + 1);

                        // 原样保存表头（空列用"列N"占位）
                        $normalizedHeaders = [];
                        $colMap = [];
                        foreach ($firstRow as $ci => $cv) {
                            $cv = trim(preg_replace('/[\x00-\x1F\x80-\x9F\xEF\xBB\xBF\xC2\xA0]/u', '', $cv));
                            $normalizedHeaders[$ci] = $cv !== '' ? $cv : ('列' . ($ci + 1));
                            if ($cv !== '') $colMap[$cv] = $ci;
                        }

                        // 找价格/成本/时间列（模糊匹配，支持多种表头名称）
                        $idxPrice = null; $idxCost = null; $idxDate = null; $idxAmount = null;
                        foreach ($colMap as $k => $idx) {
                            // 订单金额列：精确匹配"订单金额"，排除"分单备注金额"等干扰列
                            if ($idxAmount === null && (mb_strpos($k, '订单金额') !== false || $k === '金额')) $idxAmount = $idx;
                            // 价格列：支持"价格"或"售价"
                            if ($idxPrice === null && (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false)) $idxPrice = $idx;
                            // 成本列：支持"成本"或"总成本"
                            if ($idxCost  === null && (mb_strpos($k, '成本') !== false)) $idxCost  = $idx;
                            // 日期列：支持"时间"或"日期"
                            if ($idxDate  === null && (mb_strpos($k, '时间') !== false || mb_strpos($k, '日期') !== false)) $idxDate = $idx;
                        }

                        // 校验：要么有订单金额列，要么有价格和成本列
                        // 例外：如果选中的模块全部是"按数量计算"类型（单量补贴/引流订单），则不需要金额列
                        $countOnlyModules = false;
                        if (!empty($projectArr) && $idxAmount === null && ($idxPrice === null || $idxCost === null)) {
                            $modCfg = SalaryCalculator::readModulesConfig($employee_id);
                            $modTypeMap = [];
                            if ($modCfg && !empty($modCfg['modules'])) {
                                foreach ($modCfg['modules'] as $m) {
                                    $modTypeMap[trim($m['name'])] = $m['type'] ?? '';
                                }
                            }
                            $allCountBased = true;
                            foreach ($projectArr as $proj) {
                                $type = $modTypeMap[$proj] ?? '';
                                if (!in_array($type, ['per_order', 'referral_order'])) {
                                    $allCountBased = false;
                                    break;
                                }
                            }
                            $countOnlyModules = $allCountBased;
                        }
                        if (!$countOnlyModules && $idxAmount === null && ($idxPrice === null || $idxCost === null)) {
                            $error = '表头缺少金额字段：需要"订单金额"列，或同时有"价格/售价"和"成本/总成本"列';
                            goto upload_done;
                        }

                        // 保存表头到 upload_batches
                        $batchHeaders = json_encode(array_values($normalizedHeaders), JSON_UNESCAPED_UNICODE);
                        $batchStmt = db()->prepare("INSERT INTO upload_batches (employee_id, headers) VALUES (?, ?)");
                        $batchStmt->execute([$employee_id, $batchHeaders]);

                        // 清空当月同模块旧数据，避免重复上传导致数据累加（不同模块互不影响）
                        $monthPattern = $upload_month . '%';
                        if ($order_scope === 'department' && $dept_name !== '') {
                            // 部门订单：软删除该部门当月的汇总行 + 归属合作人员的拆分行（移入回收站）
                            $del = db()->prepare("UPDATE orders SET is_deleted=1 WHERE DATE_FORMAT(order_date, '%Y-%m') = ? AND order_scope = 'department' AND employee_id = 0 AND raw_data LIKE ?"
    );
                            $del->execute([$upload_month, '%\"__dept__\":\"' . $dept_name . '\"%']);
                            $del2 = db()->prepare("UPDATE orders SET is_deleted=1 WHERE DATE_FORMAT(order_date, '%Y-%m') = ? AND order_scope = 'personal' AND raw_data LIKE ?");
                            $del2->execute([$upload_month, '%\"__from_dept__\":\"' . $dept_name . '\"%']);
                        } else {
                            // 个人订单：软删除该合作人员当月旧数据（移入回收站，排除部门拆分行 __from_dept__）
                            // 如果选了模块，只清这些模块对应的 project，避免多次上传不同表时互相覆盖；
                            // 如果没选模块，清全部个人订单（整批替换）
                            if (!empty($projectArr)) {
                                $placeholders = implode(',', array_fill(0, count($projectArr), '?'));
                                $del = db()->prepare("UPDATE orders SET is_deleted=1 WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(order_scope, 'personal') = 'personal' AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__\"%') AND project IN ({$placeholders})"
    );
                                $del->execute(array_merge([$employee_id, $upload_month], $projectArr));
                            } else {
                                $del = db()->prepare("UPDATE orders SET is_deleted=1 WHERE employee_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(order_scope, 'personal') = 'personal' AND (raw_data IS NULL OR raw_data NOT LIKE '%\"__from_dept__\"%')"
    );
                                $del->execute([$employee_id, $upload_month]);
                            }
                        }

                        $inserted = 0; $skipped = 0; $unmatched = 0; $noOrderNoRows = [];
                        $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, project, order_no, raw_data, is_abnormal, abnormal_reason, order_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

                        // 部门汇总行 project：优先用勾选模块名，为空则从 deptEmpModules 取
                        $deptProjStr = '';
                        if ($order_scope === 'department' && !empty($deptEmpModules)) {
                            $deptProjStr = !empty($projectArr) ? implode(',', $projectArr) : implode(',', array_values(array_unique(array_filter(array_map(fn($d) => trim($d['module'
    ]), $deptEmpModules)))));
                        }

                        // 循环外预加载：避免每行重复查询
                        $modCfg = (!empty($projectArr) && $employee_id > 0) ? SalaryCalculator::readModulesConfig($employee_id) : null;

                        // 预构建合作人员姓名→模块配置映射，避免循环内每行重复查 get_employee
                        $empNameMap = [];
                        if ($order_scope === 'department' && !empty($deptEmpModules)) {
                            foreach ($deptEmpModules as $dem) {
                                $emp = get_employee($dem['employee_id']);
                                if ($emp) {
                                    $empNameMap[$emp['name']] = $dem;
                                }
                            }
                        }

                        db()->beginTransaction();
                        try {/* split: orders/index/actions/upload/parse_rows/fields.php */ include __DIR__ . '/fields.php';} catch (Exception $txEx) {
                            db()->rollBack();
                            throw $txEx;
                        }
                    } // end if (!empty($rows))

                    if ($error === '') {
                        $modCount = count($projectArr);
                        $modNote = $modCount > 1 ? "（{$modCount}个模块，每条订单复制{$modCount}份）" : "";
                        $noOrderNoMsg = '';
                        if (!empty($noOrderNoRows)) {
                            $rowList = implode(',', $noOrderNoRows);
                            $noOrderNoMsg = "。以下行因无订单编号上传失败：第 {$rowList} 行";
                        }
                        if ($order_scope === 'department') {
                            $empName = $dept_name . '（部门）';
                            $msg = "导入完成！为【{$empName}】成功导入 {$inserted} 条{$modNote}";
                            if ($skipped > 0) $msg .= "，{$skipped} 条标记为异常";
                            if ($unmatched > 0) $msg .= "，{$unmatched} 条未匹配归属（已分配给所有归属合作人员）";
                            $msg .= $noOrderNoMsg;
                            $rq = ['upload_ok' => '1', 'msg' => urlencode($msg)];
                        } else {
                            $emp = get_employee($employee_id);
                            $empName = $emp ? $emp['name'] : '';
                            $msg = "导入完成！为【{$empName}】成功导入 {$inserted} 条{$modNote}" . ($skipped > 0 ? "，{$skipped} 条标记为异常" : "") . $noOrderNoMsg
    ;
                            $rq = ['employee_id' => $employee_id, 'upload_ok' => '1', 'msg' => urlencode($msg)];
                        }
                        if ($per_page !== 20) $rq['per_page'] = $per_page;
                        header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                        exit;
                    }
                } catch (Exception $ex) {
                    $error = '解析失败: ' . $ex->getMessage();
                }
                upload_done:
            