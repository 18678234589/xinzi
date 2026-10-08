<?php
/** Cron: every 5 minutes; --dry-run is strictly read-only, --refresh records checks only. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('仅命令行'); }
require_once __DIR__ . '/../includes/ProjectAutoReview.php';
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
    try { $out = pa_batch($limit, $apply, $refresh, $month); }
    finally {
        if ($apply || $refresh) $p->query("SELECT RELEASE_LOCK('project_auto_review_job')");
        elseif ($p->inTransaction()) $p->rollBack();
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    if ($out['errors']) exit(1);
} catch (Throwable $e) { fwrite(STDERR, 'auto_review_failed: ' . $e->getMessage() . "\n"); exit(1); }
