<?php

function ps_order_requests($orderId)
{
    $sql = "SELECT r.*, a.username AS reviewer_username,
            COALESCE(e.name, a.username) AS reviewer_name
            FROM project_order_requests r
            LEFT JOIN admins a ON a.id=r.reviewer_id
            LEFT JOIN employees e ON (a.username='songwenna' AND e.name='宋文娜')
                                  OR (a.username='liuqun' AND e.name='刘群')
                                  OR (a.username='sunman' AND e.name='孙曼')
                                  OR (a.username='yaolin' AND e.name='姚琳')
                                  OR (a.username='wangfang' AND e.name='王芳')
                                  OR (a.username='weihuizi' AND e.name='魏慧子')
                                  OR (a.username='wangguimei' AND e.name='王桂美')
            WHERE r.order_id=? ORDER BY r.id DESC";
    $q = db()->prepare($sql);
    $q->execute([(int)$orderId]);
    $list = $q->fetchAll();
    foreach ($list as $i => $row) {
        $list[$i]['data'] = json_decode((string)$row['data_json'], true) ?: [];
    }
    return $list;
}

function ps_order_pending_request($orderId, $type = null)
{
    if ($type) {
        $q = db()->prepare("SELECT * FROM project_order_requests WHERE order_id=? AND request_type=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$orderId, $type]);
    } else {
        $q = db()->prepare("SELECT * FROM project_order_requests WHERE order_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
        $q->execute([(int)$orderId]);
    }
    $row = $q->fetch();
    if ($row) {
        $row['data'] = json_decode((string)$row['data_json'], true) ?: [];
        return $row;
    }
    return null;
}

function ps_create_order_request($orderId, $type, $actor, array $data)
{
    if (!in_array($type, ['delivery_completion', 'product_upgrade'], true)) {
        throw new RuntimeException('申请类型无效');
    }
    $existing = ps_order_pending_request($orderId, $type);
    if ($existing) {
        throw new RuntimeException('该订单已有待审核的同类申请，请勿重复提交');
    }

    $applicantType = $actor['type'];
    $applicantId = (int)($actor['employee_id'] ?? $actor['id']);
    $applicantName = (string)($actor['username'] ?? '');
    if (!empty($actor['employee_id'])) {
        $nameQuery = db()->prepare('SELECT name FROM employees WHERE id=?');
        $nameQuery->execute([(int)$actor['employee_id']]);
        $applicantName = (string)($nameQuery->fetchColumn() ?: $applicantName);
    }

    $q = db()->prepare("INSERT INTO project_order_requests (order_id, request_type, status, applicant_type, applicant_id, applicant_name, data_json) VALUES (?, ?, 'pending', ?, ?, ?, ?)"
    );
    $q->execute([
        (int)$orderId,
        $type,
        $applicantType,
        $applicantId,
        $applicantName,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    $requestId = (int)db()->lastInsertId();
    ps_audit('order', (int)$orderId, 'apply_' . $type, $actor, ['request_id' => $requestId] + $data);
    return $requestId;
}

function ps_review_order_request($requestId, $decision, $actor, $reviewNote, array $extraData = [])
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅财务或审核人可审核');
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('审核决定无效');

    $pdo = db();
    $nested = $pdo->inTransaction();
    if ($nested) $pdo->exec('SAVEPOINT project_review_order_req');
    else $pdo->beginTransaction();

    try {
        $q = $pdo->prepare('SELECT * FROM project_order_requests WHERE id=? FOR UPDATE');
        $q->execute([(int)$requestId]);
        $req = $q->fetch();
        if (!$req || $req['status'] !== 'pending') throw new RuntimeException('申请不存在或已被处理');

        $orderId = (int)$req['order_id'];
        $orderQuery = $pdo->prepare('SELECT * FROM project_orders WHERE id=? FOR UPDATE');
        $orderQuery->execute([$orderId]);
        $order = $orderQuery->fetch();
        if (!$order) throw new RuntimeException('对应订单不存在');

        $reqData = json_decode((string)$req['data_json'], true) ?: [];

        if ($decision === 'approved') {
            if ($req['request_type'] === 'delivery_completion') {
                $pdo->prepare("UPDATE project_orders SET delivery_status='finished', row_version=row_version+1 WHERE id=?")->execute([$orderId]);
                ps_audit('order', $orderId, 'approve_delivery_completion', $actor, ['request_id' => $requestId, 'note' => $reviewNote]);
            } elseif ($req['request_type'] === 'product_upgrade') {
                $diffAmount = isset($extraData['diff_amount']) ? round((float)$extraData['diff_amount'], 2) : 0.0;
                if ($diffAmount < 0) throw new RuntimeException('补差金额不能为负数');

                $toTemplateId = (int)($reqData['to_template_id'] ?? 0);
                $fromName = (string)($reqData['from_name'] ?? '原程序');
                $toName = (string)($reqData['to_name'] ?? '新程序');

                $costName = '产品升级补差成本（' . $fromName . ' → ' . $toName . '）';
                $costReason = '后台查验实付补差成本' . ($reviewNote ? '：' . $reviewNote : '');
                $proofPath = !empty($extraData['proof_path']) ? (string)$extraData['proof_path'] : null;

                $costStmt = $pdo->prepare("INSERT INTO project_costs (order_id, template_id, template_version, category, item_name, quantity, unit, unit_price, amount, supplier_amount, cost_kind, is_custom, reason, proof_path, review_status, submitted_by_employee, reviewed_by_admin, review_note) VALUES (?, ?, 1, 'program', ?, 1, '项', ?, ?, ?, 'one_time', 1, ?, ?, 'approved', ?, ?, ?)"
    );
                $costStmt->execute([
                    $orderId,
                    $toTemplateId ?: null,
                    $costName,
                    $diffAmount,
                    $diffAmount,
                    $diffAmount,
                    $costReason,
                    $proofPath,
                    $actor['employee_id'] ?? null,
                    $actor['id'],
                    '产品升级自动审核入账'
                ]);

                if ($toTemplateId > 0) {
                    $pdo->prepare("UPDATE project_order_resources SET program_template_id=? WHERE order_id=?")->execute([$toTemplateId, $orderId]);
                }

                ps_audit('order', $orderId, 'approve_product_upgrade', $actor, [
                    'request_id' => $requestId,
                    'diff_amount' => $diffAmount,
                    'from_name' => $fromName,
                    'to_name' => $toName,
                    'note' => $reviewNote
                ]);
            }
        } else {
            ps_audit('order', $orderId, 'reject_' . $req['request_type'], $actor, ['request_id' => $requestId, 'note' => $reviewNote]);
        }

        $upd = $pdo->prepare("UPDATE project_order_requests SET status=?, reviewer_id=?, reviewed_at=NOW(), review_note=? WHERE id=?");
        $upd->execute([$decision, $actor['id'], $reviewNote, (int)$requestId]);

        if ($nested) $pdo->exec('RELEASE SAVEPOINT project_review_order_req');
        else $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT project_review_order_req');
        elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
