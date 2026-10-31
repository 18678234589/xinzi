<?php
/** 七牛 HTTPS 证书任务处理：每 2 分钟运行；等待七牛配置成功 / 解析生效后自动签发并绑定，失败自动退避重试。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnHttps.php';
require_once __DIR__ . '/../includes/ProjectApiSettings.php';
try {
    if (!pas_is_set('qiniu', 'access_key') || !pas_is_set('qiniu', 'secret_key')) { echo json_encode(['skipped' => 'qiniu not configured']) . "\n"; exit(0); }
    $pdo = db();
    if (!$pdo->query("SELECT GET_LOCK('qiniu_https_worker',0)")->fetchColumn()) { echo json_encode(['skipped' => 'another run is active']) . "\n"; exit(0); }
    // 上次异常中断留下的“签发中”超过 15 分钟视为失败，回到排队重试。
    $pdo->prepare("UPDATE qiniu_https_jobs SET state='pending',message='上次签发中断，自动重试',next_try_at=0 WHERE state='running' AND updated_at<?")->execute([time() - 900]);
    $r = qh_work($pdo, 5);
    if (array_sum($r)) echo date('c') . ' ' . json_encode($r) . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'qiniu_https_worker_failed: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 120) . "\n"); exit(1); }
