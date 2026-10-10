<?php
require_once __DIR__ . '/ProjectSettlement.php';

/** 公告只展示给实际登录的本人，不向财务的人员预览泄漏或代为已读。 */
function pna_employee_id($actor)
{
    return ($actor['type'] ?? '') === 'employee' ? max(0, (int)($actor['employee_id'] ?? 0)) : 0;
}

function pna_pending($actor)
{
    $eid = pna_employee_id($actor);
    if (!$eid) return null;
    $q = db()->prepare("SELECT id,title,body,link FROM project_messages WHERE employee_id=? AND category='announcement' AND read_at IS NULL ORDER BY created_at,id LIMIT 20");
    $q->execute([$eid]);
    foreach ($q->fetchAll() as $message) {
        if (empty($_SESSION['announcement_deferred'][$eid][(int)$message['id']])) return $message;
    }
    return null;
}

/** 确认只处理本人公告；稍后查看不消除站内信未读提示。 */
function pna_acknowledge($actor, $messageId, $action)
{
    $eid = pna_employee_id($actor);
    if (!$eid) throw new RuntimeException('请使用本人的合作人员账号登录');
    if (!in_array($action, ['read', 'defer'], true)) throw new InvalidArgumentException('请选择有效操作');
    $q = db()->prepare("SELECT id FROM project_messages WHERE id=? AND employee_id=? AND category='announcement'");
    $q->execute([(int)$messageId, $eid]);
    if (!$q->fetchColumn()) throw new RuntimeException('通知不存在或无权操作');
    if ($action === 'read') {
        db()->prepare("UPDATE project_messages SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND employee_id=? AND category='announcement'")->execute([(int)$messageId, $eid]);
    }
    $_SESSION['announcement_deferred'][$eid][(int)$messageId] = true;
}
