<?php

            if (!$finance && $actor['role'] !== 'technical') throw new RuntimeException('只有技术或财务可以录入成本');
            $templateId = (int)($_POST['template_id'] ?? 0);
            $quantity = (string)($_POST['quantity'] ?? '1');
            if (!is_numeric($quantity) || (float)$quantity <= 0 || (float)$quantity > 10000) throw new RuntimeException('数量必须大于0');
            $proof = null;
            if ($templateId > 0) {
                $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND is_active=1'); $q->execute([$templateId]);
                $template = $q->fetch();
                if (!$template) throw new RuntimeException('成本模板不可用');
                [$unitPrice, $amount, $supplierAmount] = ps_template_cost_amount($template, $order['contract_amount'], $quantity);
                if ((int)$template['requires_proof'] === 1) $proof = ps_upload_proof('proof');
                $status = ps_template_cost_status($template, $amount);
                $values = [$id, $templateId, $template['version'], $template['category'], $template['name'] . ($template['specification'] ? ' · ' . $template['specification'] : ''
    ), (float)$quantity, $template['unit'], $unitPrice, $amount, $supplierAmount, $template['cost_kind'], 0, '', $proof, $status, $actor['employee_id']];
            } else {
                $name = trim((string)($_POST['item_name'] ?? ''));
                $price = (string)($_POST['unit_price'] ?? '');
                $reason = trim((string)($_POST['reason'] ?? ''));
                $customCategory = (string)($_POST['custom_category'] ?? 'other');
                if (!in_array($customCategory, ['domain','server','certificate','certification','api','plugin','outsourcing','other'], true)) throw new RuntimeException('自定义成本类别无效'
    );
                if ($name === '' || $reason === '' || !is_numeric($price) || (float)$price < 0) throw new RuntimeException('自定义成本须填写名称、单价和原因');
                $proof = ps_upload_proof('proof');
                $amount = round((float)$price * (float)$quantity, 2);
                $kind = in_array($_POST['cost_kind'] ?? '', ['one_time','annual','monthly'], true) ? $_POST['cost_kind'] : 'one_time';
                $values = [$id, null, null, $customCategory, $name, (float)$quantity, '项', round((float)$price, 2), $amount, null, $kind, 1, $reason, $proof, 'pending', $actor['employee_id'
    ]];
            }
            $q = db()->prepare('INSERT INTO project_costs (order_id,template_id,template_version,category,item_name,quantity,unit,unit_price,amount,supplier_amount,cost_kind,is_custom,reason,proof_path,review_status,submitted_by_employee) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
            $q->execute($values);
            ps_audit('cost', (int)db()->lastInsertId(), 'create', $actor, ['order_id' => $id, 'amount' => $amount, 'status' => $status ?? 'pending']);
        