<?php

        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $backPage       = max(1, (int)($_POST['_page'] ?? 1));
        $backPerPage    = (int)($_POST['_per_page'] ?? 20);
        $backMonth      = $_POST['_month'] ?? '';
        $backEmployeeId = (int)($_POST['_employee_id'] ?? 0);
        $backProject    = $_POST['_project'] ?? '';
        if (empty($ids)) {
            $error = '请选择要删除的订单';
        } else {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                db()->prepare("UPDATE orders SET is_deleted=1 WHERE id IN ({$placeholders})")->execute($ids);
                $rq = ['page' => $backPage];
                if ($backEmployeeId) $rq['employee_id'] = $backEmployeeId;
                if ($backMonth)      $rq['month']       = $backMonth;
                if ($backProject)    $rq['project']     = $backProject;
                if ($backPerPage && $backPerPage !== 20) $rq['per_page'] = $backPerPage;
                header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
                exit;
            } catch (PDOException $ex) {
                $error = '批量删除失败: ' . $ex->getMessage();
            }
        }
    