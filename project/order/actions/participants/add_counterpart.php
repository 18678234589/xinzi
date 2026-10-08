<?php

            if (!ps_business_requires_technical($order['project_type'])) throw new RuntimeException('此业务暂不支持快捷关联');
            $group = $actor['role'] === 'customer_service' ? 'technical' : 'customer_service';
            if ($finance || !in_array($actor['role'], ['customer_service', 'technical'], true)) throw new RuntimeException('请由本单客服或技术关联协作人员');
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            if (!ps_active_employee_for_business($employeeId, $group, ps_business_normalize($order['project_type']))) throw new RuntimeException('所选合作人员未开通此业务的有效账号'
    );
            $count = db()->prepare('SELECT COUNT(*) FROM project_participants WHERE order_id=? AND commission_group=?');
            $count->execute([$id, $group]);
            if ((int)$count->fetchColumn() > 0) throw new RuntimeException('此组已有关联人员；多人分配请由财务调整权重');
            $person = db()->prepare('SELECT name FROM employees WHERE id=?');
            $person->execute([$employeeId]);
            $defaultRole = ps_employee_default_role($employeeId, $order['project_type'], $group) ?? ($group === 'technical' ? ps_business_people_labels(ps_business_normalize($order
    ['project_type']))['frontend'] : '客服');
            db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,1)')
                ->execute([$id, $employeeId, $group, $defaultRole]);
            ps_audit('order', $id, 'link_counterpart', $actor, ['employee_id' => $employeeId, 'group' => $group]);
        