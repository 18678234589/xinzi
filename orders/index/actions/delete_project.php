<?php

        // 删除指定模块（project）的全部订单（软删除→回收站）
        $delProject    = trim($_POST['project'] ?? '');
        $delEmp        = (int)($_POST['employee_id'] ?? 0);
        $delDept       = trim($_POST['department'] ?? '');
        $delDeptOrders = ($_POST['dept_orders'] ?? '') === '1';
        if ($delProject === '') {
            $error = '缺少模块名称';
        } else {
            try {
                $where  = " WHERE (o.project = ?";
                $params = [$delProject];
                if ($delProject === '订单') {
                    $where .= " OR o.project = '' OR o.project IS NULL";
                }
                $where .= ")";
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
                $sql = "UPDATE orders o LEFT JOIN employees e ON o.employee_id = e.id SET o.is_deleted=1" . $where;
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                $deleted = $stmt->rowCount();
                $rq = [];
                if ($delEmp)        $rq['employee_id'] = $delEmp;
                if ($delDept)       $rq['department']   = $delDept;
                if ($delDeptOrders) $rq['dept_orders'] = '1';
                $rq['upload_ok'] = '1';
                $rq['msg'] = urlencode("已删除模块「{$delProject}」{$deleted}条订单");
                header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                exit;
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
        }
    