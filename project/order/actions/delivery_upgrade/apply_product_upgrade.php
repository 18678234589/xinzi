<?php

            $toTemplateId = (int)($_POST['to_template_id'] ?? 0);
            if ($toTemplateId <= 0) throw new RuntimeException('请选择升级的目标产品');
            $tplQuery = db()->prepare("SELECT * FROM project_cost_templates WHERE id=? AND category='program'");
            $tplQuery->execute([$toTemplateId]);
            $targetTpl = $tplQuery->fetch();
            if (!$targetTpl) throw new RuntimeException('所选升级产品不存在或不可用');

            $currentProgram = '基础版/未指定';
            $resQuery = db()->prepare("SELECT r.program_template_id, t.name, t.specification FROM project_order_resources r LEFT JOIN project_cost_templates t ON t.id=r.program_template_id WHERE r.order_id=?"
    );
            $resQuery->execute([$id]);
            $resRow = $resQuery->fetch();
            if ($resRow && !empty($resRow['name'])) {
                $currentProgram = $resRow['name'] . ($resRow['specification'] ? ' · ' . $resRow['specification'] : '');
            } else {
                foreach (ps_costs($id) as $c) {
                    if ($c['category'] === 'program' && $c['review_status'] !== 'rejected') {
                        $currentProgram = $c['item_name'];
                        break;
                    }
                }
            }

            $reason = trim((string)($_POST['upgrade_reason'] ?? ''));
            $paymentNote = trim((string)($_POST['customer_payment_note'] ?? ''));
            $toName = $targetTpl['name'] . ($targetTpl['specification'] ? ' · ' . $targetTpl['specification'] : '');
            ps_create_order_request($id, 'product_upgrade', $actor, [
                'from_name' => $currentProgram,
                'to_template_id' => $toTemplateId,
                'to_name' => $toName,
                'upgrade_reason' => $reason,
                'customer_payment_note' => $paymentNote
            ]);
        