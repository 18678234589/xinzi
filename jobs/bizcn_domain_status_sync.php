<?php
/** 域名状态同步：逐个查询商务中国实名 / 命名审核状态并保存。用法：php jobs/bizcn_domain_status_sync.php [--limit=1200] [--seconds=420] */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
$limit = 1200; $seconds = 420;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d{1,5})$/D', $arg, $m)) $limit = (int)$m[1];
    if (preg_match('/^--seconds=(\d{1,4})$/D', $arg, $m)) $seconds = (int)$m[1];
}
try {
    $pdo = db();
    $lock = $pdo->query("SELECT GET_LOCK('bizcn_domain_status_sync',0)")->fetchColumn();
    if (!$lock) { echo json_encode(['skipped' => 'another run is active']) . "
"; exit(0); }
    $result = bs_sync($pdo, bd_config()['username'], $limit, $seconds);
    echo date('c') . ' ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "
";
} catch (Throwable $e) { fwrite(STDERR, 'bizcn_status_sync_failed: ' . get_class($e) . "
"); exit(1); }
