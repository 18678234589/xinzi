<?php

        $csvData = trim($_POST['csv_data'] ?? '');
        $hasFile = isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK;
        $upload_month = trim($_POST['upload_month'] ?? '');

        if ($csvData === '' && !$hasFile) {
            $error = '请选择要上传的文件';
        } elseif ($upload_month !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $upload_month)) {
            $error = '订单归属月份格式不正确';
        } else {
            try {
                $rows = [];

                if ($csvData !== '') {
                    // 前端 SheetJS 传来的 JSON 二维数组
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
                        $error = '文件类型仅支持 .xlsx / .csv';
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
                    // 自动跳过标题行：如果第一行不包含已知列名，取下一行做表头
                    $headerIdx = 0;
                    $knownCols = ['金额','价格','售价','成本','检索号','交易时间','时间','订单号','订单编号','订单金额','交易金额'];
                    for ($ri = 0; $ri < min(5, count($rows)); $ri++) {
                        $rowStr = implode('', $rows[$ri]);
                        foreach ($knownCols as $kc) {
                            if (mb_strpos($rowStr, $kc) !== false) { $headerIdx = $ri; break 2; }
                        }
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

                    // 找金额/价格/成本列（模糊匹配，支持多种表头名称）
                    $idxPrice = null; $idxCost = null; $idxAmount = null;
                    $idxOrderNo = null; $idxTradeTime = null; $idxOrderStatus = null; $idxTradeTimeRank = 9; $idxRefund = null;
                    foreach ($colMap as $k => $idx) {
                        if ($idxAmount === null && (mb_strpos($k, '订单金额') !== false || mb_strpos($k, '交易金额') !== false || mb_strpos($k, '金额') !== false)) $idxAmount
    = $idx;
                        if ($idxPrice === null && (mb_strpos($k, '价格') !== false || mb_strpos($k, '售价') !== false)) $idxPrice = $idx;
                        if ($idxCost  === null && (mb_strpos($k, '成本') !== false)) $idxCost  = $idx;
                        if ($idxOrderNo === null && (mb_strpos($k, '检索号') !== false || mb_strpos($k, '订单编号') !== false)) $idxOrderNo = $idx;
                        // 订单日期取“付款时间”优先（淘宝导出里创建时间常排在前面），其次交易时间、下单 / 创建时间，最后才是其他带“时间”的列（发货时间除外）
                        $timeRank = mb_strpos($k, '付款时间') !== false ? 1 : (mb_strpos($k, '交易时间') !== false ? 2 : (preg_match('/下单时间|创建时间|拍下时间/u'
    , $k) ? 3 : ((mb_strpos($k, '时间') !== false && !preg_match('/发货|收货|确认|结束|完成|更新|修改/u', $k)) ? 4 : 0)));
                        if ($timeRank > 0 && ($idxTradeTime === null || $timeRank < ($idxTradeTimeRank ?? 9))) { $idxTradeTime = $idx; $idxTradeTimeRank = $timeRank; }
                        if ($idxOrderStatus === null && (mb_strpos($k, '订单状态') !== false || mb_strpos($k, '交易状态') !== false)) $idxOrderStatus = $idx;
                        if ($idxRefund === null && mb_strpos($k, '退款金额') !== false) $idxRefund = $idx;
                    }

                    // 校验：要么有订单金额列，要么有价格和成本列
                    if ($idxAmount === null && ($idxPrice === null || $idxCost === null)) {
                        $error = '表头缺少金额字段：需要"订单金额"列，或同时有"价格/售价"和"成本/总成本"列';
                    } else {

                    // 保存表头到 upload_batches
                    $batchHeaders = json_encode(array_values($normalizedHeaders), JSON_UNESCAPED_UNICODE);
                    $batchStmt = db()->prepare("INSERT INTO upload_batches (employee_id, headers) VALUES (?, ?)");
                    $batchStmt->execute([0, $batchHeaders]);

                    $inserted = 0; $skipped = 0; $noDate = 0; $updated = 0; $dups = 0;
                    // 预读：本店铺已有的同订单号流水（按店铺 + 订单号去重；一次查完，避免逐行查询拖慢上传）
                    $existingMap = []; $prepNos = [];
                    foreach ($dataRows as $r0) {
                        if (count(array_filter($r0, fn($v) => trim($v) !== '')) === 0) continue;
                        $raw0 = []; foreach ($normalizedHeaders as $ci0 => $hdr0) $raw0[$hdr0] = $r0[$ci0] ?? '';
                        $no0 = $idxOrderNo !== null ? trim($r0[$idxOrderNo] ?? '') : '';
                        if ($no0 === '') $no0 = extract_order_no($raw0);
                        if ($no0 !== '') $prepNos[$no0] = 1;
                    }
                    foreach (array_chunk(array_keys($prepNos), 500) as $chunk0) {
                        $q0 = db()->prepare('SELECT id,order_no,order_amount,order_date,raw_data FROM orders WHERE employee_id=0 AND order_scope=\'department\' AND shop=? AND COALESCE(is_deleted,0)=0 AND order_no IN ('
    . implode(',', array_fill(0, count($chunk0), '?')) . ')');
                        $q0->execute(array_merge([$shop['name']], $chunk0));
                        foreach ($q0->fetchAll() as $e0) { $e0['raw'] = json_decode((string)$e0['raw_data'], true) ?: []; $existingMap[$e0['order_no']][] = $e0; }
                    }
                    $updateStmt = db()->prepare("UPDATE orders SET order_amount=?, order_date=?, raw_data=?, is_abnormal=CASE WHEN abnormal_reason IN ('','订单金额为0') THEN ? ELSE is_abnormal END, abnormal_reason=CASE WHEN abnormal_reason IN ('','订单金额为0') THEN ? ELSE abnormal_reason END WHERE id=?"
    );
                    $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, shop, order_no, raw_data, is_abnormal, abnormal_reason, order_scope) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'department')"
    );

                    db()->beginTransaction();
                    try {
                    foreach ($dataRows as $row) {
                        if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) continue;

                        // 计算订单金额
                        $originalPrice = 0; // 原始售价（供异常订单对比使用）
                        if ($idxAmount !== null) {
                            $amount = extract_amount($row[$idxAmount] ?? '');
                            $originalPrice = $amount;
                        } else {
                            $price  = extract_amount($row[$idxPrice] ?? '');
                            $cost   = extract_amount($row[$idxCost] ?? '');
                            $originalPrice = $price;
                            $amount = $price - $cost;
                        }

                        $srcNegative = $amount < 0; // 表格里本身就是负数金额的行（单独的退款流水），与订单本身分开去重
                        // 日期：优先用表格里每笔订单自己的付款 / 下单时间；表格没有日期（或日期明显写错）时才用所选月份的首日兜底
                        $parsedDate = '';
                        $dt = '';
                        $tradeTimeStr = '';
                        if ($idxTradeTime !== null) {
                            $tradeTime = trim((string)($row[$idxTradeTime] ?? ''));
                            if ($tradeTime !== '') {
                                $dt = '';
                                if (is_numeric($tradeTime) && $tradeTime > 30000 && $tradeTime < 60000) {
                                    $ts = ((int)floor((float)$tradeTime) - 25569) * 86400;
                                    $frac = (float)$tradeTime - floor((float)$tradeTime);
                                    $ts += (int)round($frac * 86400);
                                    // Excel序列号是时区无关的，用gmdate避免服务器时区重复偏移
                                    $dt = gmdate('Y-m-d', $ts);
                                    $tradeTimeStr = gmdate('Y-m-d H:i:s', $ts);
                                } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $tradeTime, $m)) {
                                    $dt = $m[1]; $tradeTimeStr = $tradeTime;
                                } elseif (preg_match('/(\d{4})\/(\d{1,2})\/(\d{1,2})/', $tradeTime, $m)) {
                                    $dt = sprintf('%s-%02d-%02d', $m[1], $m[2], $m[3]); $tradeTimeStr = $tradeTime;
                                } elseif (preg_match('/(\d{4})(\d{2})(\d{2})\s/', $tradeTime, $m)) {
                                    $dt = "{$m[1]}-{$m[2]}-{$m[3]}"; $tradeTimeStr = $tradeTime;
                                }
                            }
                        }

                        $dateFromRow = false;
                        if ($dt !== '' && $dt >= date('Y-m-d', strtotime('-5 years')) && $dt <= date('Y-m-d', strtotime('+35 days'))) { $parsedDate = $dt; $dateFromRow = true; }
                        elseif ($upload_month !== '') $parsedDate = $upload_month . '-01';
                        if ($parsedDate === '') { $noDate++; continue; } // 既没有可识别的日期、也没选月份：这一行无法确定归属月份，跳过并在结果里提示

                        // 原样存储每列；额外存入店铺名
                        $rawMap = [];
                        foreach ($normalizedHeaders as $ci => $hdr) {
                            $rawMap[$hdr] = $row[$ci] ?? '';
                        }
                        $rawMap['__shop__'] = $shop['name'];
                        // Server-written provenance: only explicit payment columns may confirm receipts.
                        $rawMap['__financial_source__'] = 'shop_statement';
                        $rawMap['__statement_uploaded_at__'] = date('Y-m-d H:i:s');
                        // 始终存储原始售价，供异常订单对比使用（售价匹配，非利润匹配）
                        if ($originalPrice > 0) {
                            $rawMap['__original_price__'] = $originalPrice;
                        }
                        if ($tradeTimeStr !== '') { $rawMap['__trade_time__'] = $tradeTimeStr; }
                        // 提取订单状态（如"交易成功""卖家已发货""等待买家确认"等）
                        $orderStatus = '';
                        if ($idxOrderStatus !== null) {
                            $orderStatus = trim((string)($row[$idxOrderStatus] ?? ''));
                        }
                        // 科恒扫码收款、对公收款：有订单状态按订单状态，没有则默认交易成功
                        if (in_array($shop['name'], ['科恒扫码收款', '对公收款'])) {
                            if ($orderStatus === '') { $orderStatus = '交易成功'; }
                        }
                        if ($orderStatus !== '') { $rawMap['__order_status__'] = $orderStatus; }
                        // 提取订单号：优先使用检索号列，否则用通用提取
                        $orderNo = '';
                        if ($idxOrderNo !== null) {
                            $orderNo = trim($row[$idxOrderNo] ?? '');
                        }
                        if ($orderNo === '') {
                            $orderNo = extract_order_no($rawMap);
                        }

                        // 退款类状态自动折算（与 ETMLL 同口径）：交易关闭 / 退款成功 → 记为退款（负数）；部分退款 → 记净额
                        $refundCell = $idxRefund !== null ? extract_amount($row[$idxRefund] ?? '') : 0.0;
                        if ($refundCell > 0) $rawMap['退款金额'] = $refundCell;
                        if ($amount > 0 && $orderStatus !== '' && ps_shop_status_rank($orderStatus) === 5) { $amount = -$amount; $rawMap['__derived_refund__'] = '1'; }
                        elseif ($amount > 0 && $refundCell > 0 && $refundCell < $amount) { $amount = round($amount - $refundCell, 2); }
                        // 异常标记：金额为0才标记异常，负数金额视为退款订单正常处理
                        $isAbn = 0; $abnReason = '';
                        if ($amount == 0) { $isAbn = 1; $abnReason = '订单金额为0'; }
                        $isRefund = ($amount < 0);
                        if ($isRefund) $rawMap['__is_refund__'] = '1';

                        // 去重：同店铺 + 同订单号只保留一条；重复上传时按“状态只前进、时间取最新”同步，不再重复入账
                        if ($orderNo !== '') {
                            $matchIdx = null;
                            foreach ($existingMap[$orderNo] ?? [] as $mi => $e) {
                                $eKind = ((float)$e['order_amount'] < 0 && empty($e['raw']['__etmll_id__']) && empty($e['raw']['__derived_refund__'])) ? 'refund_row' : 'order';
                                if (($srcNegative ? 'refund_row' : 'order') === $eKind) { $matchIdx = $mi; break; }
                            }
                            if ($matchIdx !== null) {
                                $e = $existingMap[$orderNo][$matchIdx];
                                $eStatus = trim((string)($e['raw']['__order_status__'] ?? '')); $eTime = (string)($e['raw']['__trade_time__'] ?? '');
                                $newer = ps_shop_status_rank($orderStatus) > ps_shop_status_rank($eStatus)
                                    || (ps_shop_status_rank($orderStatus) === ps_shop_status_rank($eStatus) && ($tradeTimeStr === '' || $eTime === '' || $tradeTimeStr >= $eTime));
                                $newDate = $dateFromRow ? $parsedDate : $e['order_date'];
                                $changed = abs((float)$e['order_amount'] - $amount) > 0.004 || $eStatus !== $orderStatus || $e['order_date'] !== $newDate;
                                if (!$newer || !$changed) { $dups++; continue; } // 更旧或没变化的重复行跳过，但不能拒绝 ETMLL 订单的新导出状态。
                                require_once (dirname(__DIR__, 2)) . '/../includes/ProjectShopState.php';
                                $merged = ps_shop_statement_merge($e['raw'], $rawMap);
                                if (!$isRefund) unset($merged['__is_refund__'], $merged['__derived_refund__']);
                                $updateStmt->execute([$amount, $newDate, json_encode($merged, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, (int)$e['id']]);
                                $existingMap[$orderNo][$matchIdx] = ['id' => $e['id'], 'order_no' => $orderNo, 'order_amount' => $amount, 'order_date' => $newDate, 'raw' => $merged
    ];
                                $updated++;
                                ps_sync_project_status_latest($orderNo, $shop['name'], $orderStatus);
                                continue;
                            }
                        }

                        $stmt->execute([0, $amount, $parsedDate, $shop['name'], $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason]);
                        $newId = (int)db()->lastInsertId();
                        if ($orderNo !== '') $existingMap[$orderNo][] = ['id' => $newId, 'order_no' => $orderNo, 'order_amount' => $amount, 'order_date' => $parsedDate, 'raw' => $rawMap
    ];
                        if ($orderNo !== '' && !$isRefund) {
                            ps_sync_project_from_shop_order($newId, $orderNo, $shop['name'], $rawMap, $originalPrice);
                        }
                        if ($orderNo !== '') ps_sync_project_status_latest($orderNo, $shop['name'], $orderStatus);
                        $isAbn ? $skipped++ : $inserted++;
                    }
                    db()->commit();
                    } catch (Exception $txEx) {
                        db()->rollBack();
                        throw $txEx;
                    }

                    if ($error === '') {
                        $rq = ['shop_id' => $shop_id, 'upload_ok' => '1', 'msg' => urlencode("导入完成！为【{$shop['name']}】成功导入 {$inserted} 条" . ($skipped > 0 ?
    "，{$skipped} 条标记为异常" : "") . ($updated > 0 ? "；{$updated} 条已有订单的状态 / 金额按最新数据更新" : "") . ($dups > 0 ? "；{$dups} 条是已有的重复订单（同店铺同订单号），已自动跳过"
    : "") . ($noDate > 0 ? "；另有 {$noDate} 行没有可识别的日期，未导入（请在“归属月份”里选一个月份后重传，或补全日期列）" : ""))];
                        header('Location: ' . BASE_URL . '/shops/upload.php?' . http_build_query($rq));
                        exit;
                    }

                    }
                }
            } catch (Exception $ex) {
                $error = '解析失败: ' . $ex->getMessage();
            }
        }
    