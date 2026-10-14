<?php
/**
 * 订单域名同步：重建订单与域名的对应关系、用公网 NS 刷新 NS 归属，并预热解析托管 / 续费台账缓存，
 * 让域名页面读缓存和数据库即可，不在用户请求里等待外部接口。用法：php jobs/order_domains_sync.php [--ns=120] [--seconds=240]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
$nsLimit = 120; $seconds = 240;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--ns=(\d{1,4})$/D', $arg, $m)) $nsLimit = (int)$m[1];
    if (preg_match('/^--seconds=(\d{1,4})$/D', $arg, $m)) $seconds = (int)$m[1];
}
try {
    $pdo = db();
    if (!$pdo->query("SELECT GET_LOCK('order_domains_sync',0)")->fetchColumn()) { echo json_encode(['skipped' => 'another run is active']) . "\n"; exit(0); }
    $started = time();
    bda_get_dns_domains(true);
    bda_get_renewal_domains(true);
    list($add, $upd, $del) = bo_rebuild($pdo);
    $ns = bo_ns_refresh($pdo, $nsLimit, max(10, $seconds - (time() - $started)));
    echo date('c') . ' ' . json_encode(['links_added' => $add, 'links_updated' => $upd, 'links_removed' => $del, 'ns_checked' => $ns, 'seconds' => time() - $started], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'order_domains_sync_failed: ' . get_class($e) . "\n"); exit(1); }
