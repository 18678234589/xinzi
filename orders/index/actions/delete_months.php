<?php

        // 按月份批量删除（支持多选月份），仅删除当前视图可见范围（软删除→回收站）
        $months = array_values(array_filter(array_map('trim', (array)($_POST['months'] ?? []))));
        $delEmp        = (int)($_POST['employee_id'] ?? 0);
        $delDept       = trim($_POST['department'] ?? '');
        $delDeptOrders = ($_POST['dept_orders'] ?? '') === '1';
        if (empty($months)) {
            $error = '请先勾选要删除的月份';
        } else {
            try {
                $where  = " WHERE 1=1";
                $params = [];
                if ($delDeptOrders) {
                    // 部门订单视图：employee_id=0，用 raw_data.__dept__ 匹配部门
                    $where .= " AND o.employee_id = 0 AND o.order_scope = 'department'"
                            . " AND JSON_UNQUOTE(JSON_EXTRACT(o.raw_data, '$.__dept__')) = ?";
                    $params[] = $delDept;
                } elseif ($delEmp > 0) {
                    $where .= " AND (o.employee_id = ? OR (o.order_scope = 'department' AND (o.shop IS NULL OR o.shop = '')))";
                    $params[] = $delEmp;
                } elseif ($delDept !== '') {
                    $where .= " AND e.department = ?";
                    $params[] = $delDept;
                }
                // 与列表一致：排除店铺上传的订单
                $where .= " AND NOT (o.order_scope = 'department' AND o.shop <> '')";
                $ph = implode(',', array_fill(0, count($months), '?'));
                $where .= " AND DATE_FORMAT(o.order_date, '%Y-%m') IN ($ph)";
                foreach ($months as $m) { $params[] = $m; }
                $sql = "UPDATE orders o LEFT JOIN employees e ON o.employee_id = e.id SET o.is_deleted=1" . $where;
                db()->prepare($sql)->execute($params);
                $rq = [];
                if ($delEmp)        $rq['employee_id'] = $delEmp;
                if ($delDept)       $rq['department']   = $delDept;
                if ($delDeptOrders) $rq['dept_orders'] = '1';
                $rq['upload_ok'] = '1';
                $rq['msg'] = urlencode('已删除所选月份订单');
                header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                exit;
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
        }
    