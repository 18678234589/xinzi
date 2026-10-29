<?php
/** 七牛 CDN 流量同步。用法：php jobs/qiniu_cdn_traffic_sync.php [--days=3]（首次用 --days=31 补历史）。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnTraffic.php';
require_once __DIR__ . '/../includes/ProjectApiSettings.php';
$days = 3;
foreach (array_slice($argv, 1) as $arg) if (preg_match('/^--days=(\d{1,2})$/D', $arg, $m)) $days = (int)$m[1];
try {
    if (!pas_is_set('qiniu', 'access_key') || !pas_is_set('qiniu', 'secret_key')) { echo json_encode(['skipped' => 'qiniu not configured']) . "\n"; exit(0); }
    $pdo = db();
    if (!$pdo->query("SELECT GET_LOCK('qiniu_cdn_traffic_sync',0)")->fetchColumn()) { echo json_encode(['skipped' => 'another run is active']) . "\n"; exit(0); }
    echo date('c') . ' ' . json_encode(qt_sync($pdo, $days), JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'qiniu_traffic_sync_failed: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120) . "\n"); exit(1); }
