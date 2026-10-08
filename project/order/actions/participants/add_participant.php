<?php

            if (!$finance) throw new RuntimeException('无权限');
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $group = (string)($_POST['commission_group'] ?? '');
            $weight = (string)($_POST['group_weight'] ?? '');
            if (!in_array($group, ['technical','customer_service'], true) || !is_numeric($weight) || (float)$weight <= 0 || (float)$weight > 100) throw new RuntimeException('请选择组别和有效权重'
    );
            $check = db()->prepare('SELECT id FROM employees WHERE id=?'); $check->execute([$employeeId]);
            if (!$check->fetchColumn()) throw new RuntimeException('合作人员不存在');
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            if ($roleName === '') $roleName = ps_employee_default_role($employeeId, $order['project_type'], $group) ?? '';
            if (mb_strlen($roleName) > 80) throw new RuntimeException('岗位名称过长');
            $q = db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name),group_weight=VALUES(group_weight)'
    );
            $q->execute([$id, $employeeId, $group, $roleName, round((float)$weight / 100, 6)]);
            ps_audit('order', $id, 'participant', $actor, ['employee_id' => $employeeId, 'group' => $group, 'weight_percent' => $weight]);
        