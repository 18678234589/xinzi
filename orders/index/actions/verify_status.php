<?php

        // 核验订单状态（AJAX接口，直接输出JSON并exit）
        header('Content-Type: application/json; charset=utf-8');
        $project    = trim($_POST['project'] ?? '');
        $month      = trim($_POST['month'] ?? '');
        $verifyType = trim($_POST['verify_type'] ?? '');
        $vEmployeeId = (int)($_POST['employee_id'] ?? 0);
        $vDept      = trim($_POST['department'] ?? '');
        $vDeptOrders = (($_POST['dept_orders'] ?? '') === '1');
        $vAbnormal  = (($_POST['abnormal'] ?? '') === '1');
        $vRefund    = (($_POST['refund'] ?? '') === '1');
        $vSearch    = trim($_POST['search_no'] ?? '');
        // 计入项目报酬月份：未核验订单核验通过后归属的项目报酬月份（核验当月）
        $creditMonth = trim($_POST['credit_month'] ?? $month ?? '');
        if ($creditMonth === '') $creditMonth = date('Y-m');
        if ($project === '' || !in_array($verifyType, ['shipped', 'success'])) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        try {
            // 复用页面查询逻辑：排除店铺上传订单、排除已删除
            $vWhere = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '') AND COALESCE(o.is_deleted, 0) = 0";
            $vParams = [];
            if ($vEmployeeId > 0) {
                $vWhere .= " AND o.employee_id = ? AND COALESCE(o.order_scope, 'personal') = 'personal'";
                $vParams[] = $vEmployeeId;
            }
            if ($vDept !== '') {
                $vWhere .= " AND (e.department = ? OR (o.employee_id = 0 AND o.order_scope = 'department' AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?))";
                $vParams[] = $vDept;
                $vParams[] = $vDept;
            }
            if ($vDeptOrders) {
                $vWhere = " WHERE NOT (o.order_scope = 'department' AND o.shop <> '')"
                    . " AND COALESCE(o.is_deleted, 0) = 0"
                    . " AND o.employee_id = 0 AND o.order_scope = 'department'"
                    . " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?";
                $vParams = [$vDept];
            }
            $vWhere .= " AND o.project = ?";
            $vParams[] = $project;
            if ($month !== '') {
                $vWhere .= " AND DATE_FORMAT(o.order_date, '%Y-%m') = ?";
                $vParams[] = $month;
            }
            if ($vAbnormal) {
                $vWhere .= " AND o.is_abnormal = 1";
            }
            if ($vRefund) {
                $vWhere .= " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__is_refund__')) = '1'";
            }
            if ($vSearch !== '') {
                $vWhere .= " AND o.order_no LIKE ?";
                $vParams[] = '%' . $vSearch . '%';
            }
            $q = db()->prepare("SELECT o.id, o.order_no, o.order_amount, o.raw_data, o.is_abnormal FROM orders o LEFT JOIN employees e ON o.employee_id = e.id" . $vWhere);
            $q->execute($vParams);
            $rows = $q->fetchAll();
            $q->closeCursor();
            if (empty($rows)) {
                echo json_encode(['ok' => true, 'updated' => 0, 'msg' => '该模块无订单数据']);
                exit;
            }
            $vr = applyOrderVerification($rows, $verifyType, $creditMonth);
            echo json_encode(['ok' => true, 'updated' => $vr['updated'], 'normal' => $vr['normal'], 'abnormal' => $vr['abnormal'], 'total' => $vr['total']]);
        } catch (PDOException $ex) {
            echo json_encode(['ok' => false, 'msg' => '数据库错误: ' . $ex->getMessage()]);
        }
        exit;
    