<?php

        $csvData = trim($_POST['csv_data'] ?? '');
        $hasFile = isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK;

        if ($csvData === '' && !$hasFile) {
            $error = '请选择要上传的文件';
        } else {
            try {
                $rows = [];
                // 优先使用上传的文件；仅在无文件时才用粘贴的表格数据
                if ($hasFile) {
                    $file = $_FILES['excel_file'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $tmp = $file['tmp_name'];
                    if (!in_array($ext, ['xlsx', 'csv'])) { $error = '仅支持 .xlsx / .csv'; }
                    else {
                        if ($ext === 'csv') {
                            // 读取原始内容，自动处理编码（中文 Excel 常为 GBK）
                            $raw = file_get_contents($tmp);
                            // 去掉 UTF-8 BOM
                            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
                            // 非 UTF-8 则尝试从 GBK 转码
                            if (!mb_check_encoding($raw, 'UTF-8')) {
                                $converted = @iconv('GBK', 'UTF-8//IGNORE', $raw);
                                if ($converted !== false) $raw = $converted;
                            }
                            // 自动检测分隔符（Tab / 逗号 / 分号）
                            $firstLine = strtok($raw, "\n");
                            $delim = ',';
                            $tabN = substr_count($firstLine, "\t");
                            $comN = substr_count($firstLine, ',');
                            $semN = substr_count($firstLine, ';');
                            if ($tabN >= $comN && $tabN >= $semN && $tabN > 0) $delim = "\t";
                            elseif ($semN > $comN) $delim = ';';
                            // 解析（按检测到的分隔符切分）
                            $fp = fopen('php://temp', 'r+');
                            fwrite($fp, $raw);
                            rewind($fp);
                            while (($d = fgetcsv($fp, 0, $delim)) !== false) $rows[] = $d;
                            fclose($fp);
                        } else {
                            // 多工作表处理：优先选择含"满勤天数/实际出勤天数"或"应出勤/请假"列的汇总表，
                            // 而非原始打卡明细表（打卡时间表）。
                            $sheetNames = SimpleXLSX::sheetNames($tmp);
                            $rows = null;
                            if (count($sheetNames) > 1) {
                                $allSheets = SimpleXLSX::parseAll($tmp);
                                $bestScore = -1;
                                foreach ($allSheets as $sName => $sRows) {
                                    if (empty($sRows)) continue;
                                    // 检查表头行是否含文档记录格式列
                                    $headerRow = null;
                                    foreach ($sRows as $sr) {
                                        $rowText = implode(' ', array_filter($sr, function($v){ return trim($v) !== ''; }));
                                        if (mb_strpos($rowText, '姓名') !== false || mb_strpos($rowText, '员工') !== false || mb_strpos($rowText, '合作人员') !== false) {
                                            $headerRow = $sr;
                                            break;
                                        }
                                    }
                                    if ($headerRow === null) continue;
                                    $score = 0;
                                    foreach ($headerRow as $cv) {
                                        $cv = trim($cv);
                                        if ($cv === '') continue;
                                        if (mb_strpos($cv, '满勤天数') !== false || mb_strpos($cv, '满勤') !== false
                                            || mb_strpos($cv, '应出勤天数') !== false || mb_strpos($cv, '应出勤') !== false
                                            || mb_strpos($cv, '实际出勤') !== false || mb_strpos($cv, '实到') !== false
                                            || mb_strpos($cv, '出勤天数') !== false
                                            || mb_strpos($cv, '应出勤小时') !== false || mb_strpos($cv, '出勤小时') !== false
                                            || mb_strpos($cv, '请假') !== false || mb_strpos($cv, '缺勤') !== false) {
                                            $score++;
                                        }
                                    }
                                    if ($score > $bestScore) {
                                        $bestScore = $score;
                                        $rows = $sRows;
                                    }
                                }
                            }
                            // 没有匹配到汇总表，退回第一个工作表
                            if ($rows === null) {
                                $rows = SimpleXLSX::parse($tmp, 0);
                            }
                        }
                    }
                } else {
                    $decoded = json_decode($csvData, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $r) {
                            if (is_array($r) && count(array_filter($r, fn($v) => trim($v) !== '')) > 0) $rows[] = $r;
                        }
                    } else { $error = '数据格式错误'; }
                }

                if ($error === '' && !empty($rows)) {
                    // 自动定位表头行：查找包含"姓名"的行作为表头（跳过标题/说明行）
                    $headerRowIdx = 0;
                    foreach ($rows as $ri => $row) {
                        $rowText = implode(' ', array_filter($row, function($v){ return trim($v) !== ''; }));
                        if (mb_strpos($rowText, '姓名') !== false || mb_strpos($rowText, '员工') !== false || mb_strpos($rowText, '合作人员') !== false) {
                            $headerRowIdx = $ri;
                            break;
                        }
                    }
                    $firstRow = $rows[$headerRowIdx];
                    $dataRows = array_slice($rows, $headerRowIdx + 1);
                    // 跳过日期行：若第一行数据姓名列为空且其余列多为纯数字/星期，视为日期子表头
                    if (!empty($dataRows)) {
                        $firstData = $dataRows[0];
                        $nameCell = trim($firstData[$idxName ?? 0] ?? '');
                        $numCount = 0;
                        $nonEmptyCount = 0;
                        for ($ci = 0; $ci < count($firstData); $ci++) {
                            $v = trim($firstData[$ci] ?? '');
                            if ($v === '') continue;
                            $nonEmptyCount++;
                            if (preg_match('/^\d{1,2}$/', $v) || in_array($v, ['六','日','端午节'], true)) $numCount++;
                        }
                        if ($nameCell === '' && $nonEmptyCount > 0 && $numCount / $nonEmptyCount > 0.5) {
                            array_shift($dataRows);
                        }
                    }
                    // 过滤掉空行和尾部说明行
                    $dataRows = array_values(array_filter($dataRows, function($r) {
                        $nonEmpty = array_filter($r, function($v) { return trim($v) !== ''; });
                        return count($nonEmpty) > 0;
                    }));

                    // 规范化表头 + 列映射
                    $colMap = [];
                    foreach ($firstRow as $ci => $cv) {
                        $cv = trim(preg_replace('/[\x00-\x1F\x80-\x9F\xEF\xBB\xBF\xC2\xA0]/u', '', $cv));
                        if ($cv === '') continue;
                        $colMap[$cv] = $ci;
                    }
                    // 模糊匹配列
                    $idxName = $idxWork = $idxAbsent = $idxRemark = null;
                    $idxFullDays = $idxActualDays = null;
                    foreach ($colMap as $k => $idx) {
                        if ($idxName === null && (mb_strpos($k, '姓名') !== false || mb_strpos($k, '员工') !== false || mb_strpos($k, '合作人员') !== false || mb_strpos($k,
    '名字') !== false || stripos($k, 'name') !== false)) $idxName = $idx;
                        // 满勤天数（新格式）—— 支持"满勤天数/满勤/应出勤天数/应出勤/全勤天数/全勤"等多种表头
                        if ($idxFullDays === null && (mb_strpos($k, '满勤天数') !== false || mb_strpos($k, '满勤') !== false || mb_strpos($k, '应出勤天数') !== false ||
    mb_strpos($k, '应出勤') !== false || mb_strpos($k, '全勤天数') !== false || mb_strpos($k, '全勤') !== false)) $idxFullDays = $idx;
                        // 实际出勤天数（新格式）
                        if ($idxActualDays === null && (mb_strpos($k, '实际出勤') !== false || mb_strpos($k, '实到') !== false || mb_strpos($k, '实际') !== false || mb_strpos
    ($k, '出勤天数') !== false)) $idxActualDays = $idx;
                        // 应出勤小时（旧格式兼容）
                        if ($idxWork === null && (mb_strpos($k, '应出勤小时') !== false || mb_strpos($k, '出勤小时') !== false || mb_strpos($k, '应到') !== false)) $idxWork
    = $idx;
                        // 请假小时（旧格式兼容）
                        if ($idxAbsent === null && (mb_strpos($k, '请假') !== false || mb_strpos($k, '缺勤') !== false)) $idxAbsent = $idx;
                        if ($idxRemark === null && (mb_strpos($k, '备注') !== false || mb_strpos($k, '说明') !== false || stripos($k, 'remark') !== false)) $idxRemark = $idx;
                    }
                    if ($idxName === null) {
                        $heads = array_keys($colMap);
                        $preview = '';
                        foreach (array_slice($rows, 0, 3) as $ri => $row) {
                            $preview .= '第' . ($ri+1) . '行: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "<br>";
                        }
                        $error = '表头缺少"姓名"列。识别到的表头：' . implode('、', $heads) . "<br>前3行内容：<br>" . $preview;
                    } else {
                        // 若没有"满勤天数/实际出勤天数"或"应出勤/请假"列，则自动从每日打卡列统计
                        $autoDayMode = ($idxFullDays === null && $idxActualDays === null && $idxWork === null && $idxAbsent === null);
                        if ($autoDayMode) {
                            // 已知表头列：姓名 + 其他命名列，剩下的当每日打卡列
                            $namedCols = array_values($colMap);
                            $maxNamed = $namedCols ? max($namedCols) : $idxName;
                            $dayColStart = $maxNamed + 1;
                            $dayColCount = max(0, count($firstRow) - $dayColStart);
                            if ($dayColCount <= 0) $dayColCount = 22; // 兜底
                            // 识别节假日列：日期行（表头下一行）里值为"六/日/端午节"等的是休息日，不算满勤也不算请假
                            $holidayCols = [];
                            if (isset($rows[$headerRowIdx + 1])) {
                                $dateRow = $rows[$headerRowIdx + 1];
                                for ($ci = $dayColStart; $ci < count($dateRow); $ci++) {
                                    $dv = trim($dateRow[$ci] ?? '');
                                    if (in_array($dv, ['六','日','端午节','春节','国庆','中秋','元旦','清明','劳动','五一','十一'], true)) {
                                        $holidayCols[$ci] = true;
                                    }
                                }
                            }
                            $workDayCount = $dayColCount - count($holidayCols); // 应出勤天数 = 总天数 - 节假日
                        }

                        // 预载合作人员名单（按名查ID）
                        $empList = get_employees();
                        $empByName = [];
                        foreach ($empList as $e) $empByName[trim($e['name'])] = (int)$e['id'];

                        // 上传前清空该月所有考勤记录，确保上传的数据就是最终数据
                        db()->prepare("DELETE FROM attendances WHERE year=? AND month=?")->execute([$year, $month]);
                        // 同步清空该月的待匹配记录（避免重复堆积）
                        db()->prepare("DELETE FROM attendance_pending WHERE year=? AND month=?")->execute([$year, $month]);

                        $inserted = 0; $skipped = 0; $notFound = [];
                        $ins = db()->prepare("INSERT INTO attendances (employee_id, year, month, work_hours, absent_hours, remark)
                                              VALUES (?, ?, ?, ?, ?, ?)
                                              ON DUPLICATE KEY UPDATE work_hours=VALUES(work_hours), absent_hours=VALUES(absent_hours), remark=VALUES(remark)");
                        $insPending = db()->prepare("INSERT INTO attendance_pending (employee_name, year, month, work_hours, absent_hours, remark)
                                                     VALUES (?, ?, ?, ?, ?, ?)");
                        db()->beginTransaction();
                        // 解析天数/小时值，支持 "25+2.5" 这类简单加减表达式（避免被 preg_replace 误拼成 252.5）
                        $parseNum = function($val) {
                            $cleaned = preg_replace('/[^\d.+\-]/', '', trim($val ?? ''));
                            if ($cleaned === '' || !preg_match('/\d/', $cleaned)) return 0.0;
                            $sum = 0.0;
                            foreach (explode('+', $cleaned) as $part) {
                                $sub = explode('-', $part);
                                $partSum = (float)array_shift($sub);
                                foreach ($sub as $neg) $partSum -= (float)$neg;
                                $sum += $partSum;
                            }
                            return $sum;
                        };
                        foreach ($dataRows as $r) {
                            if (count(array_filter($r, fn($v) => trim($v) !== '')) === 0) continue;
                            $empName = trim($r[$idxName] ?? '');
                            if ($empName === '') continue;

                            // 优先按"天数"格式计算（满勤天数 × 8 = 应出勤小时；请假小时 = (满勤-实际)×8）
                            if ($idxFullDays !== null || $idxActualDays !== null) {
                                $fullDays  = $idxFullDays  !== null ? $parseNum($r[$idxFullDays]  ?? '') : 0;
                                $actDays   = $idxActualDays !== null ? $parseNum($r[$idxActualDays] ?? '') : $fullDays;
                                $wh  = $fullDays * 8;                       // 应出勤小时 = 满勤天数 × 8
                                $ah  = max(0, ($fullDays - $actDays) * 8);  // 请假小时 = (满勤-实际出勤) × 8
                            } elseif ($autoDayMode) {
                                $fullDays = $workDayCount > 0 ? $workDayCount : $dayColCount;
                                $actDays  = 0;
                                for ($di = $dayColStart; $di < $dayColStart + $dayColCount; $di++) {
                                    // 节假日列跳过（不算满勤也不算请假）
                                    if (isset($holidayCols[$di])) continue;
                                    $v = trim($r[$di] ?? '');
                                    // 有打卡时间/标记（非空、非"-"、"无"等）算出勤
                                    if ($v !== '' && $v !== '-' && $v !== '无' && mb_strpos($v, '请假') === false && mb_strpos($v, '缺勤') === false) {
                                        $actDays++;
                                    }
                                }
                                $wh = $fullDays * 8;
                                $ah = max(0, ($fullDays - $actDays) * 8);
                            } else {
                                // 旧格式：直接读小时数
                                $wh = $idxWork !== null ? $parseNum($r[$idxWork] ?? '') : 0;
                                $ah = $idxAbsent !== null ? $parseNum($r[$idxAbsent] ?? '') : 0;
                            }
                            $rm = $idxRemark !== null ? trim($r[$idxRemark] ?? '') : '';

                            $empId = $empByName[$empName] ?? 0;
                            if ($empId <= 0) {
                                // 合作人员尚未添加：暂存到待匹配表，合作人员添加后自动补录
                                $notFound[] = $empName;
                                $skipped++;
                                $insPending->execute([$empName, $year, $month, $wh, $ah, $rm]);
                                continue;
                            }
                            $ins->execute([$empId, $year, $month, $wh, $ah, $rm]);
                            $inserted++;
                        }
                        db()->commit();
                        $msg = "导入完成：成功 {$inserted} 条";
                        if ($skipped > 0) $msg .= "，暂存待匹配 {$skipped} 条（合作人员添加后自动补录）";
                        if (!empty($notFound)) $msg .= "，未匹配合作人员：" . implode('、', array_slice($notFound, 0, 5)) . (count($notFound) > 5 ? ' 等' : '');
                        // 附加识别信息便于排查
                        $mode = $autoDayMode ? '自动统计(每日打卡列)' : '天数列直读';
                        $msg .= "【模式:{$mode}；姓名列:{$idxName}；满勤列:" . ($idxFullDays ?? '无') . "；实际出勤列:" . ($idxActualDays ?? '无') . "；数据行:"
    . count($dataRows) . "】";
                        // 预览解析到的表头和首行数据，便于确认文件内容正确
                        $previewHead = json_encode($firstRow, JSON_UNESCAPED_UNICODE);
                        $previewFirst = !empty($dataRows) ? json_encode($dataRows[0], JSON_UNESCAPED_UNICODE) : '(空)';
                        $msg .= "【表头:{$previewHead}；首行:{$previewFirst}】";
                        $success = $msg;
                    }
                } elseif ($error === '') {
                    $error = '文件无数据';
                }
            } catch (Exception $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $error = '解析失败: ' . $ex->getMessage();
            }
        }
    