<?php
/**
 * 自动签发的七牛证书（名称以 auto- 开头）到期前（剩余不足总有效期 1/3）自动续签、重新绑定并清理旧证书。每天运行。
 * 续签失败会记到 qiniu_https_jobs，CDN 管理页显示原因；每天重试，直到成功或证书过期。
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnHttps.php';
require_once __DIR__ . '/../includes/ProjectApiSettings.php';
try {
    $now = time(); $done = 0; $failed = 0; $pdo = db();
    foreach (qc_certificates() as $cert) {
        $name = (string)($cert['name'] ?? '');
        if (strpos($name, 'auto-') !== 0 || !qh_needs_renewal($cert, $now)) continue;
        $domain = strtolower((string)($cert['common_name'] ?? ''));
        try {
            $detail = qc_call('domain', 'GET', '/domain/' . rawurlencode($domain));
            if (($detail['https']['certId'] ?? '') !== ($cert['certid'] ?? '')) continue; // 已换成别的证书，不管
            echo date('c') . ' renew ' . $domain . ' ' . qh_renew($domain) . "\n"; $done++;
            try { $pdo->prepare("UPDATE qiniu_https_jobs SET state='done',message='已自动续签',updated_at=? WHERE domainname=?")->execute([time(), $domain]); } catch (Throwable $e) {}
        } catch (Throwable $e) {
            $failed++;
            $days = max(0, (int)floor(((int)$cert['not_after'] - $now) / 86400));
            fwrite(STDERR, 'renew failed ' . $domain . ' (证书剩余 ' . $days . ' 天): ' . $e->getMessage() . "\n");
            try { qh_note_failure($pdo, $domain, '自动续签失败（证书剩余 ' . $days . ' 天）：' . mb_substr($e->getMessage(), 0, 150) . '。每天会自动重试，也可点“重新申请”'); } catch (Throwable $ne) {}
        }
    }
    echo date('c') . ' ' . json_encode(['renewed' => $done, 'failed' => $failed]) . "\n";
    if ($failed) exit(1);
} catch (Throwable $e) { fwrite(STDERR, 'renew_job_failed: ' . get_class($e) . ' ' . $e->getMessage() . "\n"); exit(1); }
