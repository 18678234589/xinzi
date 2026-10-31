<?php
/**
 * 七牛 CDN 域名自动 HTTPS（命令行）。用法：
 *   php jobs/qiniu_https.php --domain=www.example.com            签发 Let's Encrypt 证书并绑定
 *   php jobs/qiniu_https.php --domain=www.example.com --staging   只用测试环境验证流程（不上传、不绑定）
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$domain = ''; $staging = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--domain=([a-z0-9.-]{3,253})$/Di', $arg, $m)) $domain = strtolower($m[1]);
    if ($arg === '--staging') $staging = true;
}
if ($domain === '') { fwrite(STDERR, "usage: --domain=<加速域名> [--staging]\n"); exit(2); }
if ($staging) define('ACME_DIRECTORY', 'https://acme-staging-v02.api.letsencrypt.org/directory');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnHttps.php';
require_once __DIR__ . '/../includes/ProjectApiSettings.php';
$log = function ($m) { echo date('H:i:s') . ' ' . $m . "\n"; };
try {
    if ($staging) {
        list(, $zone) = qc_split_host(qc_domain($domain));
        list($chain, $key) = acme_issue($domain, dirname(__DIR__) . '/storage/private/acme/account-staging.pem', qh_txt_io($zone) + ['log' => $log]);
        $log('测试环境签发成功，证书链 ' . strlen($chain) . ' 字节（未上传、未绑定）');
    } else {
        $log(qh_enable($domain, $log));
    }
} catch (Throwable $e) { fwrite(STDERR, 'FAILED: ' . get_class($e) . ' ' . $e->getMessage() . "\n"); exit(1); }
