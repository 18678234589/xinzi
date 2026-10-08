<?php

            if (!$finance && $actor['role'] !== 'customer_service') throw new RuntimeException('只有客服或财务可提交收款与退款');
            $kind = (string)($_POST['movement_type'] ?? '');
            $amount = (string)($_POST['amount'] ?? '');
            $note = trim((string)($_POST['note'] ?? ''));
            if (!in_array($kind, ['receipt','refund'], true) || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount) || (float)$amount <= 0 || (float)$amount > 999999999999.99
    || strlen($note) > 500 || ($kind === 'refund' && $note === '')) throw new RuntimeException('请填写有效金额（最多两位小数）；退款须说明原因');
            $status = $finance ? 'approved' : 'pending';
            $q = db()->prepare('INSERT INTO project_cash_movements (order_id,movement_type,amount,note,review_status,submitted_by_type,submitted_by_id,reviewed_by_admin,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?)'
    );
            $q->execute([$id, $kind, round((float)$amount, 2), $note, $status, $actor['type'], $actor['id'], $finance ? $actor['id'] : null, $finance ? date('Y-m-d H:i:s') : null])
    ;
            $cashId = (int)db()->lastInsertId();
            if ($finance) ps_recalculate_cash($id);
            ps_audit('cash', $cashId, 'create', $actor, ['order_id' => $id, 'type' => $kind, 'amount' => $amount, 'status' => $status]);
        