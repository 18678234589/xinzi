<?php

        $oid            = (int)($_POST['id'] ?? 0);
        $backPage       = max(1, (int)($_POST['_page'] ?? 1));
        $backPerPage    = (int)($_POST['_per_page'] ?? 20);
        $backMonth      = $_POST['_month'] ?? '';
        $backEmployeeId = (int)($_POST['_employee_id'] ?? 0);
        $backProject    = $_POST['_project'] ?? '';
        try {
            db()->prepare("UPDATE orders SET is_deleted=1 WHERE id = ?")->execute([$oid]);
            $rq = ['page' => $backPage];
            if ($backEmployeeId) $rq['employee_id'] = $backEmployeeId;
            if ($backMonth)      $rq['month']       = $backMonth;
            if ($backProject)    $rq['project']     = $backProject;
            if ($backPerPage && $backPerPage !== 20) $rq['per_page'] = $backPerPage;
            header('Location: ' . BASE_URL . '/orders/index.php?' . http_build_query($rq));
            exit;
        } catch (PDOException $ex) {
            $error = '删除失败: ' . $ex->getMessage();
        }
    