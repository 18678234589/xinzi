<?php
/** 自动签发的七牛证书（名称以 auto- 开头）到期前 30 天自动续签并重新绑定。建议每天运行一次。 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnHttps.php';
require_once __DIR__ . '/../includes/ProjectApiSettings.php';
try {
    $now = time(); $done = 0; $failed = 0;
    foreach (qc_certificates() as $cert) {
        $name = (string)($cert['name'] ?? '');
        if (strpos($name, 'auto-') !== 0 || (int)($cert['not_after'] ?? 0) > $now + 30 * 86400) continue;
        $domain = strtolower((string)($cert['common_name'] ?? ''));
        try {
            $detail = qc_call('domain', 'GET', '/domain/' . rawurlencode($domain));
            if (($detail['https']['certId'] ?? '') !== ($cert['certid'] ?? '')) continue; // 已换成别的证书，不管
            echo date('c') . ' renew ' . $domain . ' ' . qh_renew($domain) . "\n"; $done++;
        } catch (Throwable $e) { $failed++; fwrite(STDERR, 'renew failed ' . $domain . ': ' . $e->getMessage() . "\n"); }
    }
    echo date('c') . ' ' . json_encode(['renewed' => $done, 'failed' => $failed]) . "\n";
    if ($failed) exit(1);
} catch (Throwable $e) { fwrite(STDERR, 'renew_job_failed: ' . get_class($e) . ' ' . $e->getMessage() . "\n"); exit(1); }
