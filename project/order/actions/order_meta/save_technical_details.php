<?php

            if (!$finance && $actor['role'] !== 'technical') throw new RuntimeException('只有技术或财务可补交技术资料');
            if ($order['project_type'] !== '网站模板') throw new RuntimeException('此订单不是网站模板业务');
            $details = ps_business_details('网站模板', $_POST['details'] ?? []);
            $q = db()->prepare('SELECT details_json FROM project_order_details WHERE order_id=? FOR UPDATE');
            $q->execute([$id]);
            $existing = json_decode((string)($q->fetchColumn() ?: '{}'), true) ?: [];
            foreach ($details as $key => $value) if ($value !== '') $existing[$key] = $value;
            db()->prepare('INSERT INTO project_order_details (order_id,business_name,details_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)')
                ->execute([$id, '网站模板', json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            ps_audit('order', $id, 'technical_details', $actor, ['fields' => array_keys(array_filter($details, 'strlen'))]);
        