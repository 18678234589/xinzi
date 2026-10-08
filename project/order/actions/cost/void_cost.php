<?php

            if (!$finance) throw new RuntimeException('无权限');
            $costId = (int)($_POST['cost_id'] ?? 0);
            $reason = trim((string)($_POST['review_note'] ?? ''));
            if ($reason === '') throw new RuntimeException('请填写作废原因');
            $q = db()->prepare("UPDATE project_costs SET review_status='rejected',reviewed_by_admin=?,review_note=? WHERE id=? AND order_id=? AND review_status IN ('approved','pending')"
    );
            $q->execute([$actor['id'], $reason, $costId, $id]);
            if (!$q->rowCount()) throw new RuntimeException('成本已处理，请刷新页面');
            ps_audit('cost', $costId, 'void', $actor, ['reason' => $reason]);
        