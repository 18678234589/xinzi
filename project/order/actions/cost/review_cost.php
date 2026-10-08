<?php

            if (!$finance) throw new RuntimeException('无权限');
            $costId = (int)($_POST['cost_id'] ?? 0);
            $decision = (string)($_POST['decision'] ?? '');
            if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('审核结果无效');
            $q = db()->prepare('UPDATE project_costs SET review_status=?,reviewed_by_admin=?,review_note=? WHERE id=? AND order_id=? AND review_status=\'pending\'');
            $q->execute([$decision, $actor['id'], trim((string)($_POST['review_note'] ?? '')), $costId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('成本已处理，请刷新页面');
            ps_audit('cost', $costId, 'review', $actor, ['decision' => $decision]);
        