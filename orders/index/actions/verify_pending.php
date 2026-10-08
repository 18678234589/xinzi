<?php

        // 待核验清单批量核验（AJAX接口，直接输出JSON并exit）
        header('Content-Type: application/json; charset=utf-8');
        $verifyType = trim($_POST['verify_type'] ?? '');
        $creditMonth = trim($_POST['credit_month'] ?? date('Y-m'));
        $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? [])));
        if (!in_array($verifyType, ['shipped', 'success']) || empty($ids)) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $q = db()->prepare("SELECT id, order_no, order_amount, raw_data, is_abnormal, abnormal_reason
                                FROM orders WHERE id IN ($ph) AND COALESCE(is_deleted, 0) = 0");
            $q->execute($ids);
            $rows = $q->fetchAll();
            $q->closeCursor();
            if (empty($rows)) {
                echo json_encode(['ok' => true, 'updated' => 0, 'msg' => '未找到待核验订单']);
                exit;
            }
            $vr = applyOrderVerification($rows, $verifyType, $creditMonth);
            echo json_encode(['ok' => true, 'updated' => $vr['updated'], 'normal' => $vr['normal'], 'abnormal' => $vr['abnormal'], 'total' => $vr['total']]);
        } catch (PDOException $ex) {
            echo json_encode(['ok' => false, 'msg' => '数据库错误: ' . $ex->getMessage()]);
        }
        exit;
    