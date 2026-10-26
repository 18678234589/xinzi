<?php
/**
 * 撤销上传申请：合作人员传错表、订单已被系统自动核对 / 自动登记收款（自己撤不了）时，在“撤销上传”窗口里一键申请，
 * 财务在“分成更正申请”栏目的“撤销上传”页签里一键同意（或不同意）；同意后系统立即撤销，订单进回收站（30 天内可还原），并给申请人发站内信。
 * 能不能撤的判断全在 pu_plan（见 ProjectImportUndo.php）：只有“系统自动生成、没有人工操作、月份未锁定、没被申请结算”的订单才能申请。
 */
require_once __DIR__ . '/ProjectImportUndo.php';

function piu_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_import_undo_requests (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      file_id BIGINT UNSIGNED NOT NULL,
      file_name VARCHAR(200) NOT NULL DEFAULT '',
      business_name VARCHAR(100) NOT NULL DEFAULT '',
      order_count INT NOT NULL DEFAULT 0,
      reason VARCHAR(500) NOT NULL DEFAULT '',
      delete_file TINYINT(1) NOT NULL DEFAULT 1,
      applicant_type VARCHAR(20) NOT NULL,
      applicant_id INT NOT NULL,
      applicant_employee_id INT NULL,
      applicant_name VARCHAR(80) NOT NULL DEFAULT '',
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      handler_name VARCHAR(80) NULL,
      handle_note TEXT NULL,
      result_json TEXT NULL,
      handled_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_undo_status (status, created_at),
      KEY idx_undo_file (file_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function piu_pending_count()
{
    try { piu_ensure(); return (int)db()->query("SELECT COUNT(*) FROM project_import_undo_requests WHERE status='pending'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** 这些文件里哪些有待财务同意的撤销申请：[file_id => 申请时间]。 */
function piu_pending_by_file(array $fileIds)
{
    $fileIds = array_values(array_filter(array_map('intval', $fileIds)));
    if (!$fileIds) return [];
    try {
        piu_ensure();
        return db()->query("SELECT file_id,created_at FROM project_import_undo_requests WHERE status='pending' AND file_id IN (" . implode(',', $fileIds) . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { return []; }
}

function piu_actor_name(array $actor)
{
    if (!empty($actor['employee_id'])) {
        $q = db()->prepare('SELECT name FROM employees WHERE id=?'); $q->execute([(int)$actor['employee_id']]);
        $n = (string)$q->fetchColumn(); if ($n !== '') return $n;
    }
    return (string)($actor['username'] ?? '财务');
}

/** 给财务发站内信：和“申请结算”一样，发给规则中心配置的财务审核人。 */
function piu_notify_finance($requestId, $applicant, $fileName, $count)
{
    try {
        if (!function_exists('ps_admin_reviewers')) require_once __DIR__ . '/ProjectSystem.php';
        $names = array_values(array_unique(array_map(function ($r) { return $r['real_name']; }, ps_admin_reviewers())));
        if (!$names) return;
        $q = db()->prepare('SELECT id FROM employees WHERE name IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $q->execute($names);
        $ins = db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)');
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $eid) {
            $ins->execute([(int)$eid, 'import_undo', $applicant . ' 申请撤销上传', $applicant . ' 申请撤销上传的表格“' . $fileName . '”（' . $count . ' 单已被系统自动核对）。请到“分成更正申请 → 撤销上传”同意或不同意。', '/project/import_undo_requests.php', 'import_undo_req:' . (int)$requestId . ':' . (int)$eid]);
        }
    } catch (Throwable $e) { /* 站内信失败不影响申请 */ }
}

/** 合作人员提交撤销申请：这张表格里要有“财务同意后可撤”的订单；同一张表格只留一条待处理申请。 */
function piu_submit($fileId, array $actor, $reason, $deleteFile = true)
{
    piu_ensure();
    $plan = pu_plan((int)$fileId, $actor); // 同时校验“只能操作本人上传的表格”
    if (!$plan['supported']) throw new RuntimeException($plan['message'] ?: '这张表格没有可撤销的订单');
    if (($actor['role'] ?? '') === 'finance') throw new RuntimeException('你是财务，可以直接在“撤销上传”窗口里撤销，不需要申请');
    if (!$plan['approvable']) throw new RuntimeException($plan['removable'] ? '这张表格的订单可以直接撤销，不需要申请财务同意' : '这张表格的订单已有人工登记的收退款、已申请结算或所在月份已核算 / 锁定，不能撤销；需要处理请直接联系财务');
    $reason = mb_substr(trim((string)$reason), 0, 500);
    $dup = db()->prepare("SELECT id FROM project_import_undo_requests WHERE file_id=? AND status='pending' LIMIT 1");
    $dup->execute([(int)$fileId]);
    if ($dup->fetchColumn()) throw new RuntimeException('这张表格已提交过撤销申请，请等财务同意');
    $name = piu_actor_name($actor);
    db()->prepare('INSERT INTO project_import_undo_requests (file_id,file_name,business_name,order_count,reason,delete_file,applicant_type,applicant_id,applicant_employee_id,applicant_name) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$fileId, mb_substr((string)$plan['file']['original_name'], 0, 200), (string)$plan['file']['business_name'], (int)$plan['approvable'] + (int)$plan['removable'], $reason, $deleteFile ? 1 : 0, $actor['type'], (int)$actor['id'], $actor['employee_id'] ?? null, $name]);
    $id = (int)db()->lastInsertId();
    ps_audit('import_file', (int)$fileId, 'undo_request', $actor, ['request_id' => $id, 'reason' => $reason, 'approvable' => $plan['approvable'], 'removable' => $plan['removable']]);
    piu_notify_finance($id, $name, (string)$plan['file']['original_name'], (int)$plan['approvable']);
    return ['id' => $id, 'approvable' => (int)$plan['approvable'], 'removable' => (int)$plan['removable']];
}

function piu_list($status)
{
    piu_ensure();
    $where = in_array($status, ['pending', 'approved', 'rejected'], true) ? 'status=' . db()->quote($status) : '1=1';
    return db()->query("SELECT * FROM project_import_undo_requests WHERE $where ORDER BY (status='pending') DESC, id DESC LIMIT 200")->fetchAll();
}

/** 原件已被删除、但订单还在库里的上传（近 3 天内删除的；更早的多半是确认无误后清理原件，不列出来免得误撤）：财务可以直接撤销。返回 [file_id, 文件名, 业务, 上传人, 删除时间, 还在库的订单数]。 */
function piu_deleted_uploads()
{
    $out = [];
    try {
        $q = db()->query("SELECT d.entity_id,d.details_json,d.created_at FROM project_audit_logs d WHERE d.entity_type='import_file' AND d.action='delete' AND d.created_at>=(NOW() - INTERVAL 3 DAY) ORDER BY d.id DESC LIMIT 300");
        foreach ($q->fetchAll() as $d) {
            $fid = (int)$d['entity_id'];
            if (isset($out[$fid])) continue;
            $rep = ps_import_result_get($fid);
            $ids = array_values(array_unique(array_map('intval', (array)($rep['written_order_ids'] ?? []))));
            if (!$ids) continue;
            $alive = (int)db()->query('SELECT COUNT(*) FROM project_orders WHERE id IN (' . implode(',', $ids) . ')')->fetchColumn();
            if (!$alive) continue;
            $info = json_decode((string)$d['details_json'], true) ?: [];
            $op = (array)($rep['operator'] ?? []);
            $name = '';
            if (!empty($op['employee_id'])) { $n = db()->prepare('SELECT name FROM employees WHERE id=?'); $n->execute([(int)$op['employee_id']]); $name = (string)$n->fetchColumn(); }
            $out[$fid] = ['file_id' => $fid, 'name' => (string)($info['original_name'] ?? ''), 'business' => (string)($info['business_name'] ?? ''), 'uploader' => $name, 'deleted_at' => (string)$d['created_at'], 'alive' => $alive];
        }
    } catch (Throwable $e) { /* 审计表读取失败不影响页面 */ }
    return array_values($out);
}

/** 财务同意 / 不同意。同意 = 立即撤销（带上“自动核对”的订单，进回收站）；返回撤销结果。 */
function piu_handle($id, $decision, $note, array $actor)
{
    if (($actor['role'] ?? '') !== 'finance') throw new RuntimeException('仅管理员或财务可以处理');
    piu_ensure();
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('处理结果无效');
    $note = mb_substr(trim((string)$note), 0, 500);
    if ($decision === 'rejected' && $note === '') throw new RuntimeException('不同意时请写明原因');
    $pdo = db();
    // 先“占住”这条申请，避免两个人同时点；撤销失败再还原为待处理
    $claim = $pdo->prepare("UPDATE project_import_undo_requests SET status=?,handler_name=?,handle_note=?,handled_at=NOW() WHERE id=? AND status='pending'");
    $handler = piu_actor_name($actor);
    $claim->execute([$decision, $handler, $note, (int)$id]);
    if (!$claim->rowCount()) throw new RuntimeException('申请不存在或已处理');
    $q = $pdo->prepare('SELECT * FROM project_import_undo_requests WHERE id=?'); $q->execute([(int)$id]); $row = $q->fetch();
    $result = null;
    if ($decision === 'approved') {
        try {
            $result = pu_execute((int)$row['file_id'], $actor, !empty($row['delete_file']), true);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE project_import_undo_requests SET status='pending',handler_name=NULL,handle_note=NULL,handled_at=NULL WHERE id=?")->execute([(int)$id]);
            throw $e;
        }
        $pdo->prepare('UPDATE project_import_undo_requests SET result_json=? WHERE id=?')->execute([json_encode($result, JSON_UNESCAPED_UNICODE), (int)$id]);
    }
    ps_audit('import_file', (int)$row['file_id'], 'undo_request_' . $decision, $actor, ['request_id' => (int)$id, 'note' => $note, 'result' => $result]);
    if (!empty($row['applicant_employee_id'])) {
        try {
            $body = $decision === 'approved'
                ? '财务已同意并撤销了你上传的表格“' . $row['file_name'] . '”：撤销 ' . (int)$result['removed'] . ' 单' . ($result['kept'] ? '，保留 ' . (int)$result['kept'] . ' 单' : '') . '。请核对表格后重新上传正确的表格。（处理人：' . $handler . '）'
                : '财务没有同意撤销你上传的表格“' . $row['file_name'] . '”。原因：' . $note . '（处理人：' . $handler . '）';
            $pdo->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')->execute([
                (int)$row['applicant_employee_id'], 'import_undo', '撤销上传申请已' . ($decision === 'approved' ? '同意并完成' : '回复（未同意）'), $body, '/project/files.php', 'import_undo_done:' . (int)$id]);
        } catch (Throwable $e) { /* 站内信失败不影响处理 */ }
    }
    return $result;
}
