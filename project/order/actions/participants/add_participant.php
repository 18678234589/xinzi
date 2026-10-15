<?php

            if (!$finance) throw new RuntimeException('无权限');
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $group = (string)($_POST['commission_group'] ?? '');
            $weight = (string)($_POST['group_weight'] ?? '');
            $requestedRole = trim((string)($_POST['role_name'] ?? ''));
            if (in_array('软件测试', ps_role_keys($requestedRole), true) && !ps_software_testing_role($requestedRole)) throw new RuntimeException('软件测试必须作为独立岗位指派');
            $testing = ps_software_testing_role($requestedRole);
            if ($testing) {
                if ($group !== 'technical' || !ps_software_testing_enabled($employeeId, $order['project_type'])) throw new RuntimeException('该人员未开通本业务的软件测试角色');
                if (!ps_rule_for($group, $order['project_type'], $order['order_date'], '软件测试', $order['order_kind'] ?? '')) throw new RuntimeException('该订单日期早于软件测试规则生效日期');
                $existing = db()->prepare("SELECT role_name FROM project_participants WHERE order_id=? AND employee_id=? AND commission_group='technical'");
                $existing->execute([$id, $employeeId]);
                $existingRole = $existing->fetchColumn();
                if ($existingRole !== false && !ps_software_testing_role($existingRole)) throw new RuntimeException('该人员已担任本单技术岗位，请先核对岗位，不能直接覆盖为软件测试');
                $weight = '100';
            }
            if (!in_array($group, ['technical','customer_service'], true) || !is_numeric($weight) || (float)$weight <= 0 || (float)$weight > 100) throw new RuntimeException('请选择组别和有效权重'
    );
            $check = db()->prepare('SELECT id FROM employees WHERE id=?'); $check->execute([$employeeId]);
            if (!$check->fetchColumn()) throw new RuntimeException('合作人员不存在');
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            if ($roleName === '') $roleName = ps_employee_default_role($employeeId, $order['project_type'], $group) ?? '';
            if (mb_strlen($roleName) > 80) throw new RuntimeException('岗位名称过长');
            $q = db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name),group_weight=VALUES(group_weight)'
    );
            $q->execute([$id, $employeeId, $group, $roleName, $testing ? 0 : round((float)$weight / 100, 6)]);
            ps_audit('order', $id, 'participant', $actor, ['employee_id' => $employeeId, 'group' => $group, 'weight_percent' => $weight]);
