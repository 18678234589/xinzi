<?php

            if (!ps_software_testing_pick_business($order['project_type'])) throw new RuntimeException('此业务暂不支持添加软件测试员');
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            // 本单参与人可添加测试员；测试员本人可自行加入。财务走“指派软件测试”。
            $isParticipant = db()->prepare('SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=? LIMIT 1');
            $isParticipant->execute([$id, $actor['employee_id']]);
            if ($finance || (!$isParticipant->fetchColumn() && $employeeId !== (int)$actor['employee_id'])) throw new RuntimeException('无权限添加软件测试员');
            if (!ps_software_testing_enabled($employeeId, $order['project_type'])) throw new RuntimeException('该人员未开通本业务的软件测试角色');
            if (!ps_rule_for('technical', $order['project_type'], $order['order_date'], '软件测试', $order['order_kind'] ?? '')) throw new RuntimeException('该订单日期早于软件测试规则生效日期');
            $existing = db()->prepare("SELECT role_name FROM project_participants WHERE order_id=? AND employee_id=? AND commission_group='technical'");
            $existing->execute([$id, $employeeId]);
            $existingRole = $existing->fetchColumn();
            if ($existingRole !== false && !ps_software_testing_role($existingRole)) throw new RuntimeException('该人员已担任本单技术岗位，不能直接覆盖为软件测试');
            db()->prepare("INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,'technical','软件测试',0) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name),group_weight=0")->execute([$id, $employeeId]);
            ps_audit('order', $id, 'add_software_tester', $actor, ['employee_id' => $employeeId]);
