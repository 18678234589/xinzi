<?php

            if (!$finance) throw new RuntimeException('无权限');
            $cashId = (int)($_POST['cash_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('审核结果无效');
            $q = db()->prepare("UPDATE project_cash_movements SET review_status=?,reviewed_by_admin=?,reviewed_at=NOW() WHERE id=? AND order_id=? AND review_status='pending'");
            $q->execute([$decision, $actor['id'], $cashId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('收退款记录已处理，请刷新页面');
            if ($decision === 'approved') ps_recalculate_cash($id);
            ps_audit('cash', $cashId, 'review', $actor, ['decision' => $decision]);
        