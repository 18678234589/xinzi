<?php
function applyOrderVerification(array $rows, $verifyType, $creditMonth) {
    $orderNos = [];
    foreach ($rows as $r) {
        $rd = json_decode($r['raw_data'], true) ?: [];
        $ono = trim($r['order_no'] ?? '');
        if ($ono === '') {
            foreach ($rd as $k => $v) {
                if (mb_strpos($k, '订单号') !== false || mb_strpos($k, '订单编号') !== false) {
                    $ono = trim((string)$v);
                    if ($ono !== '') break;
                }
            }
        }
        if ($ono !== '') $orderNos[$r['id']] = $ono;
    }
    $updated = 0;
    $normalCount = 0;
    $abnormalCount = 0;
    if (empty($orderNos)) return ['updated' => $updated, 'normal' => $normalCount, 'abnormal' => $abnormalCount, 'total' => count($rows)];
    $shopStatusMap = [];
    $shopAmountMap = [];
    // array_unique 会保留原 key，拆分订单（重复订单号）会让 key 不连续；
    // PDO EMULATE_PREPARES=false 时 execute() 按 key 绑定参数，非连续 key 会导致 IN 查询绑定错乱（店铺查询返回空→全部误判店铺无此订单），必须强制重排 key
    $onoList = array_values(array_unique(array_values($orderNos)));
    $ph = implode(',', array_fill(0, count($onoList), '?'));
    $shopQ = db()->prepare("SELECT order_no, raw_data, order_amount FROM orders WHERE shop <> '' AND order_no IN ($ph) AND COALESCE(is_deleted, 0) = 0");
    $shopQ->execute($onoList);
    $shopRows = $shopQ->fetchAll();
    $shopQ->closeCursor();
    foreach ($shopRows as $sr) {
        $srRaw = json_decode($sr['raw_data'], true) ?: [];
        $status = '';
        if (isset($srRaw['__order_status__']) && $srRaw['__order_status__'] !== '') {
            $status = $srRaw['__order_status__'];
        } else {
            foreach ($srRaw as $k => $v) {
                if (strlen($k) > 4 && substr($k, 0, 2) === '__' && substr($k, -2) === '__') continue;
                if (mb_strpos($k, '订单状态') !== false && trim((string)$v) !== '') {
                    $status = trim((string)$v);
                    break;
                }
            }
        }
        if ($status !== '') $shopStatusMap[$sr['order_no']] = $status;
        // 店铺侧金额：同一订单号只取第一条（部门上传会重复/多店铺，取首条避免把重复行累加虚高）
        if (isset($shopAmountMap[$sr['order_no']])) continue;
        $shopOrig = (float)($srRaw['__original_price__'] ?? 0);
        if ($shopOrig <= 0) $shopOrig = (float)$sr['order_amount'];
        $shopAmountMap[$sr['order_no']] = $shopOrig;
    }
    // 合作人员侧原始售价合计（按订单号求和，处理一单拆多行）
    $empOrigSum = [];
    foreach ($rows as $r) {
        $rd = json_decode($r['raw_data'], true) ?: [];
        $orig = (float)($rd['__original_price__'] ?? 0);
        if ($orig <= 0) $orig = (float)$r['order_amount'];
        $ono = $orderNos[(int)$r['id']] ?? '';
        if ($ono !== '') $empOrigSum[$ono] = ($empOrigSum[$ono] ?? 0) + $orig;
    }
    foreach ($rows as $r) {
        $rid = (int)$r['id'];
        $ono = $orderNos[$rid] ?? '';
        $status = $shopStatusMap[$ono] ?? '';
        $shopAmt = $shopAmountMap[$ono] ?? null;
        $wasAbnormal = (int)$r['is_abnormal'] === 1;
        // 当前已是正常状态（人工已确认或此前已核验通过）的订单：重新核验时保持正常，
        // 不再用店铺侧数据覆盖 is_abnormal（否则手工改成正常的订单一核验就被标回异常，改动等于没保存）。
        // 但若仍带着"未核验"标记且店铺侧有状态可依，则把 __order_status__ 更新为店铺状态，
        // 避免项目报酬预览里"未核验订单"提示一直存在（这些正常订单本就计入项目报酬）。
        if (!$wasAbnormal) {
            $raw = json_decode($r['raw_data'], true) ?: [];
            $oldStatus = trim((string)($raw['__order_status__'] ?? ''));
            if ($oldStatus === '未核验' || $oldStatus === '') {
                // 有店铺状态的可对照则写入店铺状态；
                // 对公/财务等无店铺状态可对照的订单，点击核验即确认到账，按"交易成功"处理，
                // 以清除"未核验"标记（否则项目报酬预览会一直提示未核验）
                $setStatus = ($status !== '') ? $status : '交易成功';
                $raw['__order_status__'] = $setStatus;
                $raw['__shop_order_status__'] = $setStatus;
                // 记录计入项目报酬的月份（核验当月），供结算按核验月归月
                $raw['__verified_month__'] = $creditMonth;
                $upd = db()->prepare("UPDATE orders SET raw_data = ? WHERE id = ?");
                $upd->execute([json_encode($raw, JSON_UNESCAPED_UNICODE), $rid]);
                $upd->closeCursor();
                $updated++;
            }
            $normalCount++;
            continue;
        }
        $nowAbnormal = 0;
        $reasons = [];
        // 1. 核验订单状态
        if ($status === '') {
            $nowAbnormal = 1;
            $reasons[] = '店铺无此订单';
        } elseif ($verifyType === 'shipped') {
            if (mb_strpos($status, '已发货') !== false || mb_strpos($status, '交易成功') !== false || mb_strpos($status, '已到账') !== false) {
                // 状态达标
            } else {
                $nowAbnormal = 1;
                $reasons[] = "状态[{$status}]不达标";
            }
        } elseif ($verifyType === 'success') {
            if (mb_strpos($status, '交易成功') !== false || mb_strpos($status, '已到账') !== false) {
                // 状态达标
            } else {
                $nowAbnormal = 1;
                $reasons[] = "状态[{$status}]未交易成功";
            }
        }
        // 2. 核验订单金额（用原始售价求和比对）
        if ($shopAmt !== null) {
            $curAmt = $empOrigSum[$ono] ?? (float)$r['order_amount'];
            if (abs($curAmt - $shopAmt) > 0.005) {
                $nowAbnormal = 1;
                $reasons[] = "金额不符(本:" . number_format($curAmt, 2) . "/店:" . number_format($shopAmt, 2) . ")";
            }
        }
        $reason = implode('；', $reasons);
        if ($nowAbnormal !== $wasAbnormal || ($nowAbnormal && ($r['abnormal_reason'] ?? '') !== $reason) || $status !== '') {
            if ($status !== '') {
                $raw = json_decode($r['raw_data'], true) ?: [];
                $raw['__shop_order_status__'] = $status;
                // 核验通过(非异常)的订单：把本人订单状态更新为已核验状态，
                // 否则项目结算仍按 __order_status__='未核验' 把本正常订单拦掉
                if ($nowAbnormal === 0) {
                    $prevStatus = trim((string)($raw['__order_status__'] ?? ''));
                    // 首次从未核验通过核验：记录计入项目报酬的月份（核验当月）
                    if ($prevStatus === '未核验' || $prevStatus === '') {
                        $raw['__verified_month__'] = $creditMonth;
                    }
                    $raw['__order_status__'] = $status;
                }
                $newRawJson = json_encode($raw, JSON_UNESCAPED_UNICODE);
                $upd = db()->prepare("UPDATE orders SET is_abnormal = ?, abnormal_reason = ?, raw_data = ? WHERE id = ?");
                $upd->execute([$nowAbnormal, $reason, $newRawJson, $rid]);
            } else {
                $upd = db()->prepare("UPDATE orders SET is_abnormal = ?, abnormal_reason = ? WHERE id = ?");
                $upd->execute([$nowAbnormal, $reason, $rid]);
            }
            $upd->closeCursor();
            $updated++;
        }
        if ($nowAbnormal) $abnormalCount++; else $normalCount++;
    }
    return ['updated' => $updated, 'normal' => $normalCount, 'abnormal' => $abnormalCount, 'total' => count($rows)];
}
