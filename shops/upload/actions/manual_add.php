<?php

        $order_amount = (float)($_POST['order_amount'] ?? 0);
        $order_date   = $_POST['order_date'] ?? '';

        if ($order_amount <= 0 || $order_date === '') {
            $error = '请填写完整且有效的订单信息';
        } else {
            try {
                $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, shop, order_no, order_scope) VALUES (?, ?, ?, ?, ?, 'department')");
                $stmt->execute([0, $order_amount, $order_date, $shop['name'], '']);
                $success = '订单添加成功';
            } catch (PDOException $ex) {
                $error = '添加失败: ' . $ex->getMessage();
            }
        }
    