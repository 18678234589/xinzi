<?php

        $pendingId = (int)($_POST['pending_id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        try {
            $stmt = db()->prepare("SELECT * FROM cs_perf_pending WHERE id=?");
            $stmt->execute([$pendingId]);
            $p = $stmt->fetch();
            if ($p && $employeeId > 0) {
                $incoming = (int)$p['incoming_count'];
                $replySpeed = 0.0;
                if ($incoming > 0 && (float)$p['total_reply_seconds'] > 0) {
                    $replySpeed = round((float)$p['total_reply_seconds'] / $incoming, 1);
                }
                $up = db()->prepare("INSERT INTO customer_service_performance
                    (employee_id, year, month, reply_speed, incoming_count, deal_count, remark, source_file,
                     net_sales, inquiry_conv, wangwang_reply, order_count)
                    VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    reply_speed=VALUES(reply_speed), incoming_count=VALUES(incoming_count), source_file=VALUES(source_file),
                    net_sales=VALUES(net_sales), inquiry_conv=VALUES(inquiry_conv), wangwang_reply=VALUES(wangwang_reply),
                    order_count=VALUES(order_count)");
                $remark = '由待匹配补录（' . ($p['name'] !== '' ? $p['name'] : $p['wangwang']) . '）';
                // 转化率：待匹配行有 下单人数+询单人数 时按 下单÷询单 计算
                $pInquiryConv = (float)$p['inquiry_conv'];
                $pOrderCount  = (int)($p['order_count'] ?? 0);
                if ($pOrderCount > 0 && $incoming > 0) $pInquiryConv = round($pOrderCount / $incoming * 100, 2);
                $up->execute([$employeeId, (int)$p['year'], (int)$p['month'], $replySpeed, $incoming, $remark, 'pending:' . basename((string)$p['source_file']),
                    (float)$p['net_sales'], $pInquiryConv, (float)$p['wangwang_reply'], $pOrderCount]);
                db()->prepare("DELETE FROM cs_perf_pending WHERE id=?")->execute([$pendingId]);
                cs_perf_cache_reset();
                $msg = '已把待匹配数据归属到合作人员并写入';
            } else {
                $err = '待匹配记录不存在或未选择合作人员';
            }
        } catch (PDOException $ex) {
            $err = '操作失败: ' . $ex->getMessage();
        }
    