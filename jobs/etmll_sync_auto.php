<?php
// 双向同步：先推送新订单和已有状态，再拉取；与按钮采用同一流程。
// 用法：php jobs/etmll_sync_auto.php [--dry]    定时任务：每 5 分钟一次。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/etmll_bidirectional.php';
$dry = in_array('--dry', $argv, true);
$line = ['at' => date('Y-m-d H:i:s'), 'dry' => $dry];
try {
    $both = etmll_bidirectional_run($dry);
    $pull = $both['pull'];
    $line['pull'] = ['scanned' => $pull['scanned'], 'inserted' => $pull['inserted'], 'updated' => $pull['updated'], 'linked' => $pull['linked_existing'],
        'project_filled' => $pull['project_filled'], 'project_status_updated' => $pull['project_status_updated'], 'linked_status_updated' => $pull['linked_status_updated'], 'linked_evidence_updated' => $pull['linked_evidence_updated']];
    $push = $both['push'];
    $line['push'] = ['pushed'=>$push['pushed'],'updated'=>$push['updated'],'would_push'=>$push['would_push'],'would_update'=>$push['would_update'],'existing'=>$push['skipped_existing'],'note'=>$push['note'] ?? ''];
} catch (Throwable $e) { $line['sync_error'] = mb_substr($e->getMessage(), 0, 160); }
echo json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
if (isset($line['sync_error'])) exit(1);
