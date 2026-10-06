<?php
// 退款自动对号并扣减：把待审退款关联到后补上传的原订单，并对能确认的退款自动记入订单退款（预计分成随之扣减）。
// 用法：php jobs/refund_auto_apply.php [--dry]    定时任务：每 30 分钟一次。
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectRefundImport.php';
try {
    $dry = in_array('--dry', $argv, true);
    $summary = ps_refund_auto_apply(500, $dry);
    $line = ['at' => date('Y-m-d H:i:s'), 'dry' => $dry, 'linked' => $summary['linked'], 'applied' => $summary['applied'], 'would_apply' => $summary['would_apply'], 'skipped' => count($summary['skipped'])];
    echo json_encode($line, JSON_UNESCAPED_UNICODE) . "\n";
    if ($dry) foreach (array_slice($summary['skipped'], 0, 30) as $s) echo '  跳过 #' . $s['id'] . ' ' . $s['order_no'] . ' ¥' . $s['amount'] . '：' . $s['reason'] . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'refund_auto_apply_failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n"); exit(1); }
