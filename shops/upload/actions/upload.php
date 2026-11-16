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
                            // 文件内容统一转成 UTF-8 再解析：拼多多等平台的官方导出常是 GBK 编码，直接读会整表乱码
                            $csvText = (string)file_get_contents($tmp);
                            if (substr($csvText, 0, 3) === "\xEF\xBB\xBF") $csvText = substr($csvText, 3);
                            if (!mb_check_encoding($csvText, 'UTF-8')) $csvText = mb_convert_encoding($csvText, 'UTF-8', 'GB18030');
                            $fp = fopen('php://temp', 'r+');
                            fwrite($fp, $csvText); rewind($fp);
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
                    $idxPddAmount = null; $idxPddPaid = null; $idxAfterSale = null; $idxTitle = null;
                    foreach ($colMap as $k => $idx) {
                        // 拼多多官方导出：订单金额取“商家实收金额(元)”（没有就取“用户实付金额(元)”）；“售后状态”列并入订单状态；“商品”列当商品标题
                        if ($idxPddAmount === null && mb_strpos($k, '商家实收金额') !== false) $idxPddAmount = $idx;
                        if ($idxPddPaid === null && mb_strpos($k, '用户实付金额') !== false) $idxPddPaid = $idx;
                        if ($idxAfterSale === null && $k === '售后状态') $idxAfterSale = $idx;
                        if ($idxTitle === null && $k === '商品') $idxTitle = $idx;
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

                    if ($idxPddAmount !== null) $idxAmount = $idxPddAmount; elseif ($idxPddPaid !== null) $idxAmount = $idxPddPaid;
                    // 校验：要么有订单金额列，要么有价格和成本列
                    if ($idxAmount === null && ($idxPrice === null || $idxCost === null)) {
                        $error = '表头缺少金额字段：需要"订单金额"列，或同时有"价格/售价"和"成本/总成本"列';
                    } else {

                    // 保存表头到 upload_batches
                    $batchHeaders = json_encode(array_values($normalizedHeaders), JSON_UNESCAPED_UNICODE);
                    $batchStmt = db()->prepare("INSERT INTO upload_batches (employee_id, headers) VALUES (?, ?)");
                    $batchStmt->execute([0, $batchHeaders]);

                    $inserted = 0; $skipped = 0; $noDate = 0; $updated = 0; $dups = 0; $dupSame = 0; $dupOlder = []; $dupOlderN = 0;
                    require_once (dirname(__DIR__, 2)) . '/../includes/ShopOrderDate.php';
                    $monthFallback = 0; $fallbackSamples = []; $byMonth = []; $seenInFile = []; $conflicts = []; $touchedNos = [];
                    // 预读：本店铺已有的同订单号流水（按店铺 + 订单号去重；一次查完，避免逐行查询拖慢上传）
                    $existingMap = []; $prepNos = [];
                    foreach ($dataRows as $r0) {
                        if (count(array_filter($r0, fn($v) => trim($v) !== '')) === 0) continue;
                        $raw0 = []; foreach ($normalizedHeaders as $ci0 => $hdr0) $raw0[$hdr0] = $r0[$ci0] ?? '';
                        $no0 = $idxOrderNo !== null ? clean_order_no($r0[$idxOrderNo] ?? '') : '';
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
                                // 其余写法（2026-8-7 10:00 月日不补零、2026081012:21:30 / 20260703093827 日期时间连写、2026/7/1 934 等）统一交给 sod_parse
                                if ($dt === '' && ($sod = sod_parse($tradeTime)) !== null) { $dt = $sod['date']; $tradeTimeStr = $sod['time']; }
                            }
                        }

                        $dateFromRow = false;
                        if ($dt !== '' && $dt >= date('Y-m-d', strtotime('-5 years')) && $dt <= date('Y-m-d', strtotime('+35 days'))) { $parsedDate = $dt; $dateFromRow = true; }
                        elseif ($upload_month !== '') {
                            // 这一行的时间认不出来，才按所选月份入账——必须在结果里提示，否则订单会悄悄记到错误的月份
                            $parsedDate = $upload_month . '-01';
                            $monthFallback++;
                            if (count($fallbackSamples) < 3) $fallbackSamples[] = '“' . mb_substr(trim((string)($row[$idxTradeTime] ?? '')), 0, 24) . '”';
                        }
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
                        // 收款流水类店铺（科恒扫码收款、对公收款、赫鸣支付宝等微信 / 支付宝 / 对公收款账单）：有订单状态按订单状态，没有则默认交易成功。
                        // 不能只认固定的两个店铺名：新增的收款店铺账单没有“订单状态”列，漏掉就永远不被当作收款凭证
                        require_once (dirname(__DIR__, 2)) . '/../includes/ProjectOrderSource.php';
                        if (in_array($shop['name'], ['科恒扫码收款', '对公收款'], true) || ps_shop_channel($shop['name']) !== '') {
                            if ($orderStatus === '') { $orderStatus = '交易成功'; }
                        }
                        // 拼多多：售后状态写了退款成功 / 退款中的，订单按退款处理（订单状态列只有 待发货 / 已发货 / 已收货）
                        if ($idxAfterSale !== null) {
                            $afterSale = trim((string)($row[$idxAfterSale] ?? ''));
                            if (preg_match('/退款成功|已退款|售后成功/u', $afterSale)) $orderStatus = '退款成功';
                            elseif (preg_match('/退款中|申请|售后中|处理中/u', $afterSale) && !preg_match('/取消|无售后/u', $afterSale)) $orderStatus = '退款中';
                        }
                        if ($idxTitle !== null && !isset($rawMap['商品标题'])) $rawMap['商品标题'] = trim((string)($row[$idxTitle] ?? ''));
                        if ($orderStatus !== '') { $rawMap['__order_status__'] = $orderStatus; }
                        // 提取订单号：优先使用检索号列，否则用通用提取
                        $orderNo = '';
                        if ($idxOrderNo !== null) {
                            $orderNo = clean_order_no($row[$idxOrderNo] ?? '');
                        }
                        if ($orderNo === '') {
                            $orderNo = extract_order_no($rawMap);
                        }
                        if ($orderNo !== '' && $amount > 0) $touchedNos[$orderNo] = true; // 上传后要让等收款的项目订单重新核对

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
                                // 同一张表里订单号重复、金额却不同：不拿后一行覆盖前一行（会丢一笔钱），保留先出现的并在结果里列出，请核对表格
                                if (isset($seenInFile[$orderNo . '|' . ($srcNegative ? 'r' : 'o')]) && abs((float)$e['order_amount'] - $amount) > 0.004) {
                                    if (count($conflicts) < 5) $conflicts[] = $orderNo . '（' . number_format((float)$e['order_amount'], 2) . ' / ' . number_format($amount, 2) . '）';
                                    $dups++; continue;
                                }
                                $eStatus = trim((string)($e['raw']['__order_status__'] ?? '')); $eTime = (string)($e['raw']['__trade_time__'] ?? '');
                                $newer = ps_shop_status_rank($orderStatus) > ps_shop_status_rank($eStatus)
                                    || (ps_shop_status_rank($orderStatus) === ps_shop_status_rank($eStatus) && ($tradeTimeStr === '' || $eTime === '' || $tradeTimeStr >= $eTime));
                                $newDate = $dateFromRow ? $parsedDate : $e['order_date'];
                                $changed = abs((float)$e['order_amount'] - $amount) > 0.004 || $eStatus !== $orderStatus || $e['order_date'] !== $newDate;
                                if (!$newer || !$changed) {
                                    // 更旧或没变化的重复行跳过，但不能拒绝 ETMLL 订单的新导出状态。分开计数：没变化的不用管；表格里的状态比库里更旧的要让上传人看到（保留了库里的状态）。
                                    $dups++;
                                    if (!$changed) $dupSame++;
                                    else { $dupOlderN++; if (count($dupOlder) < 5) $dupOlder[] = $orderNo . '（库里“' . $eStatus . '”，表格“' . $orderStatus . '”）'; }
                                    continue;
                                }
                                require_once (dirname(__DIR__, 2)) . '/../includes/ProjectShopState.php';
                                $merged = ps_shop_statement_merge($e['raw'], $rawMap);
                                if (!$isRefund) unset($merged['__is_refund__'], $merged['__derived_refund__']);
                                $updateStmt->execute([$amount, $newDate, json_encode($merged, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason, (int)$e['id']]);
                                $existingMap[$orderNo][$matchIdx] = ['id' => $e['id'], 'order_no' => $orderNo, 'order_amount' => $amount, 'order_date' => $newDate, 'raw' => $merged
    ];
                                $updated++;
                                $seenInFile[$orderNo . '|' . ($srcNegative ? 'r' : 'o')] = 1;
                                ps_sync_project_status_latest($orderNo, $shop['name'], $orderStatus);
                                continue;
                            }
                        }

                        if (!$dateFromRow) $rawMap['__date_fallback__'] = '1';
                        $stmt->execute([0, $amount, $parsedDate, $shop['name'], $orderNo, json_encode($rawMap, JSON_UNESCAPED_UNICODE), $isAbn, $abnReason]);
                        $byMonth[substr($parsedDate, 0, 7)] = ($byMonth[substr($parsedDate, 0, 7)] ?? 0) + 1;
                        if ($orderNo !== '') $seenInFile[$orderNo . '|' . ($srcNegative ? 'r' : 'o')] = 1;
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
                    // 新到的收款流水：对应的项目订单（订单号或支付流水号对得上、还没审核的）马上重新核对，
                    // 不用等定时任务轮到——否则“等待收款确认”的订单在流水上传后还会停很久
                    if ($touchedNos) {
                        try {
                            require_once (dirname(__DIR__, 2)) . '/../includes/ProjectAutoReview.php';
                            $reIds = [];
                            foreach (array_chunk(array_keys($touchedNos), 400) as $chunk) {
                                $mark = implode(',', array_fill(0, count($chunk), '?'));
                                $rq2 = db()->prepare("SELECT DISTINCT o.id FROM project_orders o LEFT JOIN project_order_sources s ON s.order_id=o.id WHERE o.settlement_status NOT IN ('approved','locked') AND (o.order_no IN ($mark) OR s.payment_reference IN ($mark))");
                                $rq2->execute(array_merge($chunk, $chunk));
                                foreach ($rq2->fetchAll(PDO::FETCH_COLUMN) as $rid) $reIds[] = (int)$rid;
                            }
                            if ($reIds) pa_after_save($reIds, 12);
                        } catch (Throwable $reEx) { error_log('shop_upload_recheck ' . $reEx->getMessage()); }
                    }

                    if ($error === '') {
                        $rq = ['shop_id' => $shop_id, 'upload_ok' => '1', 'msg' => urlencode("导入完成！为【{$shop['name']}】成功导入 {$inserted} 条" . ($skipped > 0 ?
    "，{$skipped} 条标记为异常" : "") . ($updated > 0 ? "；{$updated} 条已有订单的状态 / 金额按最新数据更新" : "") . ($dupSame > 0 ? "；{$dupSame} 条是已有订单，状态 / 金额 / 日期都和库里一致，已跳过" : "") . ($dupOlderN > 0 ? "；{$dupOlderN} 条表格里的状态或金额比库里已有的更旧，已保留库里的（如 " . implode('、', $dupOlder) . "），请核对是不是表格导出得太早" : "") . (($dups - $dupSame - $dupOlderN) > 0 ? "；另有 " . ($dups - $dupSame - $dupOlderN) . " 条是同表重复的订单号，已跳过" : "") . ($noDate > 0 ? "；另有 {$noDate} 行没有可识别的日期，未导入（请在“归属月份”里选一个月份后重传，或补全日期列）" : "") . ($monthFallback > 0 ? "；注意：有 {$monthFallback} 行的时间认不出来（如 " . implode('、', $fallbackSamples) . "），已按你选的归属月份 {$upload_month} 入账，请核对" : "") . ($conflicts ? "；表格里有订单号重复且金额不同的行，只保留了先出现的一行，请核对：" . implode('、', $conflicts) : "") . ($byMonth ? "；本次新增按月份：" . implode('、', array_map(function ($mk, $mc) { return $mk . ' ' . $mc . ' 条'; }, array_keys($byMonth), $byMonth)) : ""))];
                        header('Location: ' . BASE_URL . '/shops/upload.php?' . http_build_query($rq));
                        exit;
                    }

                    }
                }
            } catch (Exception $ex) {
                $error = '解析失败: ' . $ex->getMessage();
            }
        }
    