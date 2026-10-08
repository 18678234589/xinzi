<?php

/** 保存 / 关闭待补全记录；新建时发站内信。只对合作人员本人上传的表格。表未迁移时静默跳过。 */
function ps_import_followup_save($fileId, $actor, $business, $scope, $sheets, $rows)
{
    if (($actor['type'] ?? '') !== 'employee' || !$fileId) return null;
    try {
        if (!$rows) { db()->prepare("UPDATE project_import_followups SET status='done' WHERE file_id=?")->execute([(int)$fileId]); return null; }
        $existing = db()->prepare('SELECT id,status FROM project_import_followups WHERE file_id=?');
        $existing->execute([(int)$fileId]);
        $before = $existing->fetch();
        db()->prepare("INSERT INTO project_import_followups (file_id,user_id,employee_id,business_name,scope,sheets,rows_json,status) VALUES (?,?,?,?,?,?,?,'open') ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),employee_id=VALUES(employee_id),business_name=VALUES(business_name),scope=VALUES(scope),sheets=VALUES(sheets),rows_json=VALUES(rows_json),status='open'"
    )
            ->execute([(int)$fileId, (int)$actor['id'], (int)$actor['employee_id'], $business, $scope, mb_substr(implode('、', (array)$sheets), 0, 500), json_encode(array_values($rows
    ), JSON_UNESCAPED_UNICODE)]);
        $existing->execute([(int)$fileId]);
        $id = (int)$existing->fetchColumn();
        if (!$before || $before['status'] !== 'open') {
            $name = db()->prepare('SELECT original_name FROM project_import_files WHERE id=?');
            $name->execute([(int)$fileId]);
            db()->prepare('INSERT IGNORE INTO project_messages (employee_id,category,title,body,link,dedupe_key) VALUES (?,?,?,?,?,?)')->execute([(int)$actor['employee_id'], 'import_fix'
    , mb_substr('《' . $name->fetchColumn() . '》有 ' . count($rows) . ' 行缺订单号或日期，请补全', 0, 160), '其余正确的订单已导入。缺订单号或日期的行没有入账，打开任意页面会弹出补填窗口，填好后点“提交并导入”即可；不是订单的行勾选“不是订单”即可。'
    , '/project/import.php?followup=' . $id, 'import-fix:' . $id . ':' . date('YmdHis')]);
        }
        return $id;
    } catch (PDOException $e) {
        return null;
    }
}

/** 本人名下的一条待补全记录（含解码后的行）。 */
function ps_import_followup_get($id, $actor)
{
    $q = db()->prepare("SELECT * FROM project_import_followups WHERE id=? AND user_id=? AND status='open'");
    $q->execute([(int)$id, (int)$actor['id']]);
    $row = $q->fetch();
    if (!$row || ($actor['type'] ?? '') !== 'employee') return null;
    $row['rows'] = json_decode($row['rows_json'], true) ?: [];
    return $row;
}
