<?php
// 侧栏“重复订单申诉”：财务始终可见（带待处理数）；业务人员有过申诉记录时可见。导入预览里的“去申诉”链接是主要入口。
$dupAppealActor = ps_actor();
if ($dupAppealActor) {
    try {
        require_once __DIR__ . '/ProjectDupAppeal.php';
        $dupAppealShow = ($dupAppealActor['type'] ?? '') === 'admin';
        $dupAppealPending = pda_pending_count();
        if (!$dupAppealShow && !empty($dupAppealActor['employee_id'])) {
            pda_ensure();
            $dupAppealQ = db()->prepare('SELECT COUNT(*) FROM project_dup_appeals WHERE employee_id=?');
            $dupAppealQ->execute([(int)$dupAppealActor['employee_id']]);
            $dupAppealShow = (int)$dupAppealQ->fetchColumn() > 0;
        }
        if ($dupAppealShow) echo $nav('/project/dup_appeals.php', 'fa-balance-scale', '重复订单申诉' . (($dupAppealActor['type'] ?? '') === 'admin' && $dupAppealPending ? '（' . $dupAppealPending . '）' : ''), $_rel === 'project/dup_appeals.php');
    } catch (Throwable $e) {}
}
