<?php

        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $order_amount = (float)($_POST['order_amount'] ?? 0);
        $order_date = $_POST['order_date'] ?? '';
        $project = trim($_POST['project'] ?? '');

        if ($employee_id <= 0 || $order_amount < 0 || $order_date === '') {
            $error = '请填写完整且有效的订单信息';
        } else {
            try {
                // 自动创建 project 字段（如果表结构还没升级）
                ensureProjectColumn();
                $stmt = db()->prepare("INSERT INTO orders (employee_id, order_amount, order_date, project) VALUES (?, ?, ?, ?)");
                $stmt->execute([$employee_id, $order_amount, $order_date, $project]);
                $success = '订单添加成功' . ($project ? "（项目: {$project}）" : '');
                if ($locked_employee_id === 0) { $locked_employee_id = $employee_id; $locked_employee = get_employee($employee_id); }
            } catch (PDOException $ex) {
                $error = '添加失败: ' . $ex->getMessage();
            }
        }
    