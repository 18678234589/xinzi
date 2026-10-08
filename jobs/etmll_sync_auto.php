<?php
// ETMLL 双向订单同步（仅近一年）：先 ETMLL→本站，再 本站→ETMLL（只推开启同步之后新上传的）。
// 用法：php jobs/etmll_sync_auto.php [--dry]    定时任务：每 5 分钟一次。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/etmll_push.php';
$dry = in_array('--dry', $argv, true);
$line = ['at' => date('Y-m-d H:i:s'), 'dry' => $dry];
try {
    $pull = etmll_sync_run($dry);
    $line['pull'] = ['scanned' => $pull['scanned'], 'inserted' => $pull['inserted'], 'updated' => $pull['updated'], 'linked' => $pull['linked_existing'],
        'project_filled' => $pull['project_filled'], 'project_status_updated' => $pull['project_status_updated'], 'linked_status_updated' => $pull['linked_status_updated']];
} catch (Throwable $e) { $line['pull_error'] = mb_substr($e->getMessage(), 0, 160); }
try {
    $push = etmll_push_run($dry, false);
    $line['push'] = ['pushed' => $push['pushed'], 'would_push' => $push['would_push'], 'existing' => $push['skipped_existing'], 'note' => $push['note'] ?? ''];
} catch (Throwable $e) { $line['push_error'] = mb_substr($e->getMessage(), 0, 160); }
echo json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
if (isset($line['pull_error']) || isset($line['push_error'])) exit(1);
