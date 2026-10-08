<?php

        // 按筛选条件批量删除：合作人员+月份+project（可不指定project则删该合作人员该月全部）
        $delEmployeeId = (int)($_POST['del_employee_id'] ?? 0);
        $delMonth      = $_POST['del_month'] ?? '';
        $delProject    = $_POST['del_project'] ?? '';
        $delScope      = $_POST['del_scope'] ?? ''; // 'personal' 或 'department' 或 ''
        $backQ = [];
        if ($delEmployeeId) $backQ['employee_id'] = $delEmployeeId;
        if ($delMonth)      $backQ['month']       = $delMonth;
        try {
            $sql = "UPDATE orders SET is_deleted=1 WHERE 1=1";
            $params = [];
            if ($delEmployeeId > 0) {
                $sql .= " AND employee_id = ?";
                $params[] = $delEmployeeId;
            }
            if ($delMonth !== '') {
                $sql .= " AND DATE_FORMAT(order_date, '%Y-%m') = ?";
                $params[] = $delMonth;
            }
            if ($delProject !== '') {
                $sql .= " AND project = ?";
                $params[] = $delProject;
            }
            if ($delScope !== '') {
                $sql .= " AND COALESCE(order_scope, 'personal') = ?";
                $params[] = $delScope;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $deleted = $stmt->rowCount();
            $rq = $backQ;
            if ($delProject && $deleted > 0) {} // 删除后回到列表
            header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
            exit;
        } catch (PDOException $ex) {
            $error = '批量删除失败: ' . $ex->getMessage();
        }
    