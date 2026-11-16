<?php
/** Cron: every 5 minutes; --dry-run is strictly read-only, --refresh records checks only. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('仅命令行'); }
require_once __DIR__ . '/../includes/ProjectSpotCheck.php';
$apply = in_array('--apply', $argv, true);
$refresh = in_array('--refresh', $argv, true);
$month = ''; $limit = 200;
foreach ($argv as $arg) {
    if (strpos($arg, '--month=') === 0) $month = substr($arg, 8);
    if (strpos($arg, '--limit=') === 0) $limit = (int)substr($arg, 8);
}
if (in_array('--dry-run', $argv, true) && ($apply || $refresh)) { fwrite(STDERR, 'dry-run cannot write'); exit(2); }
try {
    if (($apply || $refresh) && !pa_storage_available()) throw new RuntimeException('请先运行自动核对迁移');
    if ($apply && !ps_setting_get('auto_review_enabled', false)) {
        echo json_encode(['paused' => true, 'reason' => '自动核对尚未启用'], JSON_UNESCAPED_UNICODE) . "\n"; exit;
    }
    $p = db();
    if ($apply || $refresh) {
        if (!(int)$p->query("SELECT GET_LOCK('project_auto_review_job',0)")->fetchColumn()) { echo "{\"busy\":true}\n"; exit; }
    } else $p->exec('START TRANSACTION READ ONLY');
    try {
        // 重复上传造成的重复草稿订单（同业务 / 日期 / 售价 / 昵称 / 参与人、两次上传相隔 10 分钟以上）：先自动合并，多出来的进回收站（30 天可还原），不用财务逐个清；已审核的重复不动
        if ($apply) {
            try {
                require_once __DIR__ . '/../includes/ProjectDuplicateCleanup.php';
                $merged = mdd_run(true, date('Y-m-d', strtotime('-75 days')), 10)[0];
                if ($merged) { echo json_encode(['duplicate_orders_merged' => count($merged)], JSON_UNESCAPED_UNICODE) . "
"; }
            } catch (Throwable $mergeError) { fwrite(STDERR, 'duplicate_cleanup_failed: ' . $mergeError->getMessage() . "
"); }
        }
        $out = pa_batch($limit, $apply, $refresh, $month);
        if ($apply) { try { require_once __DIR__ . '/../includes/ProjectOrderNudge.php'; $nudged = pon_send(); if ($nudged) $out['order_check_messages'] = $nudged; } catch (Throwable $nudgeError) { fwrite(STDERR, 'order_nudge_failed: ' . $nudgeError->getMessage() . "
"); } }
        // 自动结算的免审业务订单，每个业务每月补抽到 5 单交财务抽查（本月和上月）
        if ($apply) $out['spot_checks_assigned'] = psc_assign(date('Y-m')) + psc_assign(date('Y-m', strtotime('first day of last month')));
        if ($apply) { require_once __DIR__ . '/../includes/ProjectMonthly.php'; foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $ucMonth) { try { puc_sync($ucMonth); } catch (Throwable $e) { error_log('puc_sync ' . $ucMonth . ' ' . $e->getMessage()); } } }
    }
    finally {
        if ($apply || $refresh) $p->query("SELECT RELEASE_LOCK('project_auto_review_job')");
        elseif ($p->inTransaction()) $p->rollBack();
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if ($out['errors']) exit(1);
} catch (Throwable $e) { fwrite(STDERR, 'auto_review_failed: ' . $e->getMessage() . "\n"); exit(1); }
