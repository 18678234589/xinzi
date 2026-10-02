<?php
// 导入回执只记录核对结果，不修改订单金额、参与人、分成规则或已审核快照。
function ps_import_upload_actor($file, $operator)
{
    if ($file['uploaded_by_type'] === $operator['type'] && (int)$file['uploaded_by_id'] === (int)$operator['id']) return $operator;
    if ($operator['role'] !== 'finance') throw new RuntimeException('只能继续导入自己上传的表格');
    if ($file['uploaded_by_type'] === 'admin') return $operator;
    $q = db()->prepare('SELECT id,employee_id,role FROM project_users WHERE id=? AND employee_id=?');
    $q->execute([(int)$file['uploaded_by_id'], (int)$file['employee_id']]);
    $user = $q->fetch();
    if (!$user) throw new RuntimeException('原上传账号不存在，请先由财务核对合作人员');
    return ['type' => 'employee', 'id' => (int)$user['id'], 'employee_id' => (int)$user['employee_id'], 'role' => $user['role']];
}

function ps_import_result_get($fileId)
{
    $q = db()->prepare("SELECT details_json FROM project_audit_logs WHERE entity_type='import_file' AND entity_id=? AND action='import_result' ORDER BY id DESC LIMIT 1");
    $q->execute([(int)$fileId]);
    return json_decode((string)$q->fetchColumn(), true) ?: [];
}

function ps_import_order_visible($orderId, $actor)
{
    if ($actor['role'] === 'finance') return true;
    $q = db()->prepare('SELECT EXISTS(SELECT 1 FROM project_participants WHERE order_id=? AND employee_id=?) OR EXISTS(SELECT 1 FROM project_department_uploaders WHERE order_id=? AND employee_id=?)');
    $q->execute([(int)$orderId, (int)$actor['employee_id'], (int)$orderId, (int)$actor['employee_id']]);
    return (bool)$q->fetchColumn();
}

function ps_import_result_save($fileId, $preview, $importedLines, $actor, $operator, $written = 0)
{
    $numbers = []; $writtenNumbers = []; $existing = 0; $pending = 0; $ignored = 0;
    foreach ($preview as $row) {
        if (in_array((int)$row['line'], $importedLines, true)) { $numbers[] = $row['order_no']; $writtenNumbers[] = $row['order_no']; }
        elseif (($row['status'] ?? '') === '已导入过') { $numbers[] = $row['order_no']; $existing++; }
        elseif (($row['status'] ?? '') === '他人订单') $ignored++;
        else $pending++;
    }
    $previous = ps_import_result_get($fileId);
    $ids = (array)($previous['order_ids'] ?? []);
    $writtenIds = (array)($previous['written_order_ids'] ?? []);
    foreach (array_chunk(array_values(array_unique(array_filter($numbers))), 500) as $batch) {
        $q = db()->prepare('SELECT id,order_no FROM project_orders WHERE order_no IN (' . implode(',', array_fill(0, count($batch), '?')) . ')');
        $q->execute($batch);
        foreach ($q->fetchAll() as $order) { $ids[] = (int)$order['id']; if (in_array($order['order_no'], $writtenNumbers, true)) $writtenIds[] = (int)$order['id']; }
    }
    $report = ['order_ids' => array_values(array_unique(array_map('intval', $ids))), 'written' => (int)$written,
        'written_order_ids' => array_values(array_unique(array_map('intval', $writtenIds))),
        'existing' => $existing, 'pending' => $pending, 'ignored' => $ignored,
        'uploader' => $actor, 'operator' => $operator];
    ps_audit('import_file', (int)$fileId, 'import_result', $operator, $report);
    return $report;
}

// 无订单标识、无日期且整行只有至少三项金额的尾部汇总，不作为一笔订单。
function ps_import_numeric_summary($row, $orderNo, $paymentReference, $date)
{
    if ($orderNo !== '' || $paymentReference !== '' || $date !== '') return false;
    $cells = array_values(array_filter(array_map('trim', array_map('strval', $row)), function ($v) { return $v !== ''; }));
    return count($cells) >= 3 && !array_filter($cells, function ($v) { return !is_numeric(str_replace([',', '¥', '￥', ' '], '', $v)); });
}
