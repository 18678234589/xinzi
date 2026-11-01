<?php
/** 雅云商品价格 / 库存同步（378 个商品，约 2–3 分钟）。用法：php jobs/yayun_sync.php [--seconds=240]。建议每 6 小时一次。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/YayunOrder.php';
$seconds = 240;
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--seconds=(\d{1,4})$/D', $a, $m)) $seconds = (int)$m[1];
try {
    if (!pas_is_set('yayun', 'username') || !pas_is_set('yayun', 'password')) { echo json_encode(['skipped' => 'yayun not configured']) . "\n"; exit(0); }
    $pdo = db();
    if (!$pdo->query("SELECT GET_LOCK('yayun_sync',0)")->fetchColumn()) { echo json_encode(['skipped' => 'another run is active']) . "\n"; exit(0); }
    echo date('c') . ' ' . json_encode(yo_sync_prices($pdo, $seconds), JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'yayun_sync_failed: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120) . "\n"); exit(1); }
