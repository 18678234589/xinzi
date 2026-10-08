<?php

        $order_id = (int)($_POST['order_id'] ?? 0);
        try {
            $stmt = db()->prepare("UPDATE orders SET is_deleted=1 WHERE id = ? AND shop = ?");
            $stmt->execute([$order_id, $shop['name']]);
            $success = '订单已删除（移入回收站）';
        } catch (PDOException $ex) {
            $error = '删除失败: ' . $ex->getMessage();
        }
    