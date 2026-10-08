<?php

        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $y = (int)($_POST['year'] ?? 0);
        $m = (int)($_POST['month'] ?? 0);
        $replySpeed = (float)($_POST['reply_speed'] ?? 0);
        $incoming   = (int)($_POST['incoming_count'] ?? 0);
        $netSales = (float)($_POST['net_sales'] ?? 0);
        $inquiryConv = parse_percent((string)($_POST['inquiry_conv'] ?? ''));
        $wangReply = parse_percent((string)($_POST['wangwang_reply'] ?? ''));
        $deal = trim($_POST['deal_count'] ?? '');
        $remark = trim($_POST['remark'] ?? '');
        if ($employeeId > 0 && $y >= 2000 && $m >= 1 && $m <= 12) {
            $dealVal = $deal === '' ? null : (int)$deal;
            $orderCount = (int)($_POST['order_count'] ?? 0);
            $incoming   = (int)($_POST['incoming_count'] ?? 0);
            // 转化率：有「下单人数 + 询单人数」时按 下单÷询单 计算，并展示计算过程；否则用直接录入值
            if ($orderCount > 0 && $incoming > 0) $inquiryConv = round($orderCount / $incoming * 100, 2);
            $stmt = db()->prepare("INSERT INTO customer_service_performance
                (employee_id, year, month, reply_speed, incoming_count, deal_count, remark,
                 net_sales, inquiry_conv, wangwang_reply, order_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count),
                deal_count=VALUES(deal_count), remark=VALUES(remark), source_file='admin编辑',
                net_sales=VALUES(net_sales), inquiry_conv=VALUES(inquiry_conv), wangwang_reply=VALUES(wangwang_reply),
                order_count=VALUES(order_count)");
            $stmt->execute([$employeeId, $y, $m, $replySpeed, $incoming, $dealVal, $remark, $netSales, $inquiryConv, $wangReply, $orderCount]);
            cs_perf_cache_reset();
            $msg = '已保存合作人员绩效';
        } else {
            $err = '参数错误';
        }
    