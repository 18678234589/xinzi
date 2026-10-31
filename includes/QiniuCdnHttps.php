<?php
/**
 * 七牛 CDN 域名自动 HTTPS：Let's Encrypt 单域名证书（DNS-01，验证记录写入域名当前的解析服务商）→ 上传七牛 → 绑定到加速域名。
 * 前置：七牛状态「配置成功」且公网已 CNAME 到七牛（否则开了 HTTPS 也没流量，验证也无意义）。证书 90 天有效，到期前由 jobs 续签。
 */
require_once __DIR__ . '/AcmeClient.php';
require_once __DIR__ . '/BizcnDnsRouting.php';
require_once __DIR__ . '/QiniuCdnDns.php';

function qh_account_key_file() { return dirname(__DIR__) . '/storage/private/acme/account.pem'; }

/** 验证 TXT 的增删与公网可见性检查，按域名当前 NS 的解析服务商实现。 */
function qh_txt_io($zone, $log = null)
{
    $context = bdr_context($zone);
    $rel = function ($fqdn) use ($zone) { return substr($fqdn, 0, -(strlen($zone) + 1)); };
    $ids = [];
    return [
        'add_txt' => function ($name, $value) use ($context, $zone, $rel, &$ids) {
            $host = $rel($name);
            if ($context['provider'] === 'bizcn') {
                bd_call('addDnsRecordService', 'addDnsRecord', ['add' => ['domainname' => $zone, 'resolvetype' => 'TXT', 'resolvehost' => $host, 'resolvevalue' => $value, 'mxlevel' => '10']]);
                return;
            }
            $rec = dns_validate_record('TXT', $host, $value, $context['provider'] === 'cloudflare' ? 1 : 600, null, false, $context['provider']);
            dns_record_create($context['provider'], $context['zone'], $rec);
        },
        'del_txt' => function ($name, $value) use ($context, $zone, $rel) {
            $host = $rel($name);
            if ($context['provider'] === 'bizcn') {
                bd_call('delDnsRecordService', 'delDnsRecord', ['del' => ['domainname' => $zone, 'resolvetype' => 'TXT', 'resolvehost' => $host, 'resolveoldvalue' => $value, 'mxlevel' => '10']]);
                return;
            }
            foreach (dns_records($context['provider'], $context['zone']) as $r) if ($r['type'] === 'TXT' && $r['name'] === $host && $r['value'] === $value) dns_record_delete($context['provider'], $context['zone'], $r['id']);
        },
        'txt_visible' => function ($name, $value) {
            foreach ((array)@dns_get_record($name, DNS_TXT) as $r) if (isset($r['txt']) && trim($r['txt'], '"') === $value) return true;
            return false;
        },
    ];
}

function qh_points_to_qiniu($name, $cname)
{
    foreach ((array)@dns_get_record($name, DNS_CNAME) as $r) if (!empty($r['target']) && strtolower(rtrim($r['target'], '.')) === strtolower(rtrim($cname, '.'))) return true;
    return false;
}
function qh_cert_name($name) { return 'auto-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) . '-' . date('Ymd'); }
function qh_https_enabled(array $raw) { return !empty($raw['https']['certId']); }

/** 签发并启用 HTTPS。$log 回调接收进度文字。返回结果文字。 */
function qh_enable($name, $log = null, array $override = [])
{
    $log = $log ?: function () {};
    $name = qc_domain($name);
    $detail = qc_call('domain', 'GET', '/domain/' . rawurlencode($name));
    if (($detail['operatingState'] ?? '') !== 'success') throw new RuntimeException('七牛还没有配置成功，暂不能申请证书');
    if (qh_https_enabled($detail)) return '已启用 HTTPS（证书 ' . $detail['https']['certId'] . '）';
    // 以解析服务商里的记录为准（公网递归解析器有缓存，刚改完会滞后）；验证 TXT 走权威服务器，不受影响。
    $plan = qd_plan($name, (string)$detail['cname'], (string)$detail['operatingState']);
    if ($plan['action'] !== 'noop') throw new RuntimeException('域名还没有解析到七牛（需要 CNAME → ' . $detail['cname'] . '），请先完成解析再申请证书');
    list(, $zone) = qc_split_host($name);
    $log('签发证书（Let\'s Encrypt，DNS 验证）');
    $txt = $override['txt_io'] ?? qh_txt_io($zone);
    list($chain, $keyPem) = acme_issue($name, qh_account_key_file(), $txt + ['log' => $log]);
    $log('上传证书到七牛');
    $up = qc_call('fusion', 'POST', '/sslcert', ['name' => qh_cert_name($name), 'common_name' => $name, 'pri' => $keyPem, 'ca' => $chain]);
    $certId = (string)($up['certID'] ?? $up['certid'] ?? '');
    if ($certId === '') throw new RuntimeException('七牛没有返回证书编号');
    $log('绑定证书 ' . $certId);
    $body = ['certId' => $certId, 'forceHttps' => false, 'http2Enable' => true];
    try { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/sslize', $body); }
    catch (QiniuCdnError $e) { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/httpsconf', $body); }
    return '证书已签发并绑定（证书编号 ' . $certId . '），七牛配置生效一般需要几分钟';
}

/** 续签：重新签发并把新证书绑定到域名（不检查“已启用”）。 */
function qh_renew($name, $log = null)
{
    $log = $log ?: function () {};
    $name = qc_domain($name);
    list(, $zone) = qc_split_host($name);
    list($chain, $keyPem) = acme_issue($name, qh_account_key_file(), qh_txt_io($zone) + ['log' => $log]);
    $up = qc_call('fusion', 'POST', '/sslcert', ['name' => qh_cert_name($name), 'common_name' => $name, 'pri' => $keyPem, 'ca' => $chain]);
    $certId = (string)($up['certID'] ?? $up['certid'] ?? '');
    if ($certId === '') throw new RuntimeException('七牛没有返回证书编号');
    $body = ['certId' => $certId, 'forceHttps' => false, 'http2Enable' => true];
    try { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/sslize', $body); }
    catch (QiniuCdnError $e) { qc_call('domain', 'PUT', '/domain/' . rawurlencode($name) . '/httpsconf', $body); }
    return '续签完成，新证书 ' . $certId;
}
