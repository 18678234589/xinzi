<?php

            if (ps_business_normalize($order['project_type']) !== '商标') throw new RuntimeException('商标成本快捷录入只用于商标订单');
            if (!in_array($actor['role'], ['customer_service', 'technical', 'finance'], true)) throw new RuntimeException('无权限');
            require_once (dirname(__DIR__, 3)) . '/../includes/ProjectTrademarkCost.php';
            ptc_user_add($id, (int)($_POST['template_id'] ?? 0), $_POST['quantity'] ?? '', $actor);
        