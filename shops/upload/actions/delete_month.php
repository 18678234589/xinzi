<?php

        $del_month = trim($_POST['del_month'] ?? '');
        if ($del_month === '' || !preg_match('/^\d{4}-\d{2}$/', $del_month)) {
            $error = '无效的月份';
        } else {
            try {
                $stmt = db()->prepare("UPDATE orders SET is_deleted=1 WHERE shop = ? AND DATE_FORMAT(order_date, '%Y-%m') = ? AND COALESCE(is_deleted, 0) = 0");
                $stmt->execute([$shop['name'], $del_month]);
                $cnt = $stmt->rowCount();
                $success = "已删除 {$del_month} 的 {$cnt} 条订单（移入回收站）";
            } catch (PDOException $ex) {
                $error = '删除失败: ' . $ex->getMessage();
            }
        }
    