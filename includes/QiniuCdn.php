<?php
/** Qiniu CDN creation/query adapter. Credentials stay in ProjectApiSettings. */
require_once __DIR__ . '/DnsProviders.php';

const QC_PLATFORMS = ['web'=>'静态网站', 'vod'=>'音视频点播', 'download'=>'文件下载', 'dynamic'=>'动态加速'];
const QC_REGIONS = ['china'=>'中国大陆（需备案）', 'global'=>'全球（需备案）', 'foreign'=>'海外'];

class QiniuCdnError extends RuntimeException
{
    public $httpStatus;
    public $apiCode;
    public function __construct($message, $httpStatus, $apiCode = 0)
    {
        parent::__construct($message);
        $this->httpStatus = (int)$httpStatus;
        $this->apiCode = (int)$apiCode;
    }
}

function qc_call($service, $method, $path, $body = null)
{
    if (!empty($GLOBALS['qc_mock'])) {
        [$http, $data] = call_user_func($GLOBALS['qc_mock'], $service, $method, $path, $body);
    } else {
        require_once __DIR__ . '/ProjectApiSettings.php';
        [$http, $data] = $service === 'fusion' ? pas_qiniu_qbox_call($method, $path, $body) : pas_qiniu_call($method, $path, $body);
    }
    if (!is_array($data)) throw new QiniuCdnError('七牛返回异常，请稍后刷新查询；已提交的添加请求不要立即重试。', $http);
    // The live Fusion certificate API returns code=0; other Fusion versions use 200.
    $successCodes = $service === 'fusion' ? [0, 200] : [200];
    if ($http < 200 || $http >= 300 || (isset($data['code']) && !in_array((int)$data['code'], $successCodes, true))) {
        $message = (string)($data['error'] ?? $data['message'] ?? ('HTTP ' . $http));
        throw new QiniuCdnError('七牛：' . mb_substr($message, 0, 250), $http, $data['code'] ?? 0);
    }
    return $data;
}

function qc_domain($value)
{
    return dns_validate_domain($value);
}

function qc_payload(array $input)
{
    $name = qc_domain($input['domain'] ?? '');
    $platform = (string)($input['platform'] ?? 'web');
    $region = (string)($input['geo_cover'] ?? 'china');
    $protocol = (string)($input['protocol'] ?? 'http');
    if (!isset(QC_PLATFORMS[$platform]) || !isset(QC_REGIONS[$region]) || !in_array($protocol, ['http','https'], true)) throw new RuntimeException('请选择有效的业务类型、加速区域和协议');
    $registerNo = trim((string)($input['register_no'] ?? ''));
    if ($region !== 'foreign' && ($registerNo === '' || mb_strlen($registerNo) > 100 || preg_match('/[\x00-\x1f]/', $registerNo))) throw new RuntimeException('中国大陆或全球加速需要填写该域名的真实 ICP 备案号');
    $sourceType = (string)($input['source_type'] ?? 'ip');
    $sourceValue = trim((string)($input['source_value'] ?? ''));
    $testPath = trim((string)($input['test_path'] ?? ''));
    if ($testPath === '' || $testPath[0] !== '/' || strlen($testPath) > 1000 || preg_match('/[\x00-\x20\x7f#]/', $testPath) || strpos($testPath, '//') === 0) throw new RuntimeException('请填写源站可访问的检测路径，例如 /index.html，不要填写完整网址');
    $source = ['sourceType'=>$sourceType, 'testURLPath'=>$testPath];
    if ($sourceType === 'ip') {
        $ips = preg_split('/[\s,]+/', $sourceValue, -1, PREG_SPLIT_NO_EMPTY);
        if (!$ips || count($ips) > 10) throw new RuntimeException('请填写 1–10 个源站 IPv4 地址');
        foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('源站 IP 格式不正确：' . $ip);
        $source['sourceIPs'] = array_values(array_unique($ips));
        $source['skipCheckSource'] = true;
    } elseif ($sourceType === 'domain') {
        $origin = qc_domain($sourceValue);
        if ($origin === $name) throw new RuntimeException('源站域名不能与加速域名相同，以免配置 CNAME 后回源循环');
        $source['sourceDomain'] = $origin;
        $source['skipCheckSource'] = true;
    } elseif ($sourceType === 'qiniuBucket') {
        if (!preg_match('/^[a-zA-Z0-9_-]{3,63}$/D', $sourceValue)) throw new RuntimeException('请填写已有七牛存储空间名称');
        if ($platform === 'dynamic') throw new RuntimeException('动态加速不能使用七牛存储空间作为源站');
        $source['sourceQiniuBucket'] = $sourceValue;
    } else throw new RuntimeException('不支持此源站类型');
    if ($sourceType !== 'qiniuBucket') {
        $scheme = (string)($input['source_scheme'] ?? 'http');
        if (!in_array($scheme, ['http','https'], true)) throw new RuntimeException('回源协议不正确');
        $source['sourceURLScheme'] = $scheme;
        $host = trim((string)($input['source_host'] ?? ''));
        $source['sourceHost'] = $host !== '' ? qc_domain($host) : $name;
    }
    $cacheMode = (string)($input['cache_mode'] ?? 'follow');
    if (!in_array($cacheMode, ['follow','static'], true)) throw new RuntimeException('缓存方式不正确');
    $body = ['type'=>'normal', 'platform'=>$platform, 'geoCover'=>$region, 'protocol'=>$protocol, 'source'=>$source,
        'cache'=>['cacheControls'=>[['type'=>$cacheMode === 'follow' ? 'follow' : 'all', 'rule'=>'*', 'time'=>$cacheMode === 'follow' ? -1 : 3600, 'timeunit'=>0]], 'ignoreParam'=>false]];
    if ($region !== 'foreign') $body['registerNo'] = $registerNo;
    if ($protocol === 'https') {
        $cert = trim((string)($input['cert_id'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $cert)) throw new RuntimeException('HTTPS 需要选择账号中已有的有效证书');
        $body['https'] = ['certId'=>$cert, 'forceHttps'=>!empty($input['force_https']), 'http2Enable'=>true];
    }
    return ['name'=>$name, 'body'=>$body];
}

function qc_public_domain(array $row)
{
    $out = [];
    foreach (['name','cname','protocol','platform','geoCover','operatingState','operatingStateDesc','freezeType','createAt'] as $key) $out[$key] = (string)($row[$key] ?? '');
    $source = $row['source'] ?? [];
    $out['source_type'] = (string)($source['sourceType'] ?? '');
    $out['source_value'] = $out['source_type'] === 'ip' ? implode(', ', (array)($source['sourceIPs'] ?? [])) : (string)(($source['sourceDomain'] ?? '') ?: ($source['sourceQiniuBucket'] ?? ''));
    $out['test_path'] = (string)($source['testURLPath'] ?? '');
    $out['source_host'] = (string)($source['sourceHost'] ?? '');
    $out['source_scheme'] = (string)($source['sourceURLScheme'] ?? '');
    return $out;
}

function qc_list($marker = '')
{
    if (strlen($marker) > 2048) throw new RuntimeException('分页标记无效，请返回第一页');
    $data = qc_call('domain', 'GET', '/domain?' . http_build_query(['limit'=>50, 'marker'=>$marker], '', '&', PHP_QUERY_RFC3986));
    if (!isset($data['domains']) || !is_array($data['domains'])) throw new RuntimeException('七牛域名列表返回异常');
    return ['rows'=>array_map('qc_public_domain', $data['domains']), 'marker'=>(string)($data['marker'] ?? '')];
}

function qc_detail($name)
{
    $name = qc_domain($name);
    $data = qc_call('domain', 'GET', '/domain/' . rawurlencode($name));
    if (($data['name'] ?? '') !== $name) throw new RuntimeException('七牛返回的域名信息不匹配');
    return qc_public_domain($data);
}

function qc_certificates()
{
    $out = []; $marker = '';
    for ($page = 0; $page < 20; $page++) {
        $data = qc_call('fusion', 'GET', '/sslcert?' . http_build_query(['marker'=>$marker, 'limit'=>100], '', '&', PHP_QUERY_RFC3986));
        if (!isset($data['certs']) || !is_array($data['certs'])) throw new RuntimeException('七牛证书目录返回异常');
        foreach ($data['certs'] as $cert) $out[] = array_intersect_key($cert, array_flip(['certid','name','common_name','dnsnames','not_before','not_after']));
        $next = (string)($data['marker'] ?? '');
        // Fusion may return a numeric next marker even on the final short page.
        if ($next === '' || count($data['certs']) < 100) return $out;
        if ($next === $marker) throw new RuntimeException('七牛证书目录分页异常');
        $marker = $next;
    }
    throw new RuntimeException('证书目录过大，暂不能完成校验，请联系管理员');
}

function qc_certificate_matches(array $cert, $domain, $now)
{
    if ((int)($cert['not_before'] ?? 0) > $now || (int)($cert['not_after'] ?? 0) <= $now) return false;
    foreach (array_unique(array_merge((array)($cert['dnsnames'] ?? []), [(string)($cert['common_name'] ?? '')])) as $name) {
        $name = strtolower($name);
        if ($name === $domain) return true;
        if (strpos($name, '*.') === 0 && substr($domain, strpos($domain, '.') + 1) === substr($name, 2) && strpos($domain, '.') !== false) return true;
    }
    return false;
}

function qc_create(array $payload)
{
    // Payloads come from server-side previews. Never blindly retry a non-idempotent POST.
    $name = qc_domain($payload['name']);
    try { qc_detail($name); throw new RuntimeException('此域名已在七牛账号中，请从列表进入管理，不要重复添加'); }
    catch (QiniuCdnError $e) { if ($e->httpStatus !== 404 || $e->apiCode !== 404001) throw $e; }
    if (($payload['body']['protocol'] ?? '') === 'https') {
        $valid = false;
        foreach (qc_certificates() as $cert) if (($cert['certid'] ?? '') === ($payload['body']['https']['certId'] ?? '') && qc_certificate_matches($cert, $name, time())) $valid = true;
        if (!$valid) throw new RuntimeException('所选证书已失效或不覆盖此加速域名，请重新选择');
    }
    try {
        $result = qc_call('domain', 'POST', '/domain/' . rawurlencode($name), $payload['body']);
    } catch (QiniuCdnError $e) {
        if ($e->apiCode === 400062 || $e->apiCode === 400063) {
            return ['code' => 200, 'message' => '域名已存在'];
        }
        if ($e->apiCode === 400932) {
            // 所有权验证未绑定时，尝试使用匹配产品类型自动补验一次后重试
            $product = qc_verify_product($payload['body']['platform'] ?? 'web');
            try {
                qc_verify_check($name, $product, true);
                $result = qc_call('domain', 'POST', '/domain/' . rawurlencode($name), $payload['body']);
                if (isset($result['code']) && (int)$result['code'] === 200) return $result;
            } catch (Throwable $retryErr) {
                // 重试未成功则继续抛出原始错误
            }
        }
        throw $e;
    }
    if (!isset($result['code']) || (int)$result['code'] !== 200) throw new RuntimeException('七牛未返回明确的添加结果，请先查询域名状态，不要立即重试');
    return $result;
}

function qc_ticket_take(array &$tickets, $token, $actorKey, $confirm, $ack, $now)
{
    $ticket = $tickets[$token] ?? null;
    if (!$ticket || $ticket['actor'] !== $actorKey || $now > $ticket['expires']) throw new RuntimeException('预览已过期或请求已提交，请刷新列表查询结果');
    if (qc_domain($confirm) !== $ticket['payload']['name'] || $ack !== '1') throw new RuntimeException('请核对配置，输入加速域名并勾选确认');
    unset($tickets[$token]);
    return $ticket['payload'];
}

function qc_state_label($state)
{
    return ['processing'=>'配置处理中', 'success'=>'配置成功', 'failed'=>'配置失败', 'frozen'=>'已冻结', 'offlined'=>'已下线'][$state] ?? ('未知状态：' . $state);
}

/** 把加速域名拆成「主机记录」和「所在域名」，如 cx.ousweixin.com → [cx, ousweixin.com]（识别 com.cn 这类二级后缀）。 */
function qc_split_host($name)
{
    $labels = explode('.', strtolower($name));
    $keep = (count($labels) >= 3 && in_array(implode('.', array_slice($labels, -2)), ['com.cn', 'net.cn', 'org.cn', 'gov.cn', 'ac.cn'], true)) ? 3 : 2;
    if (count($labels) <= $keep) return ['@', $name];
    return [implode('.', array_slice($labels, 0, count($labels) - $keep)), implode('.', array_slice($labels, -$keep))];
}

/* ---------- 域名所有权验证（七牛要求先验证主域名，才能添加其下的加速域名） ---------- */

/** 映射业务类型到验证产品类型：动态加速为 dcdn，其他（静态网站、文件下载、点播）为 cdn。 */
function qc_verify_product($productOrPlatform = 'cdn')
{
    return ($productOrPlatform === 'dynamic' || $productOrPlatform === 'dcdn') ? 'dcdn' : 'cdn';
}

function qc_verify_state_label($state)
{
    return ['success' => '已验证', 'no_need' => '无需验证', 'doing' => '待验证'][$state] ?? '未知';
}
/** 主域名的验证信息：state（success / no_need / doing）和要添加的 TXT 记录。
 * 支持 cdn（静态加速，包括 web / vod / download）和 dcdn（动态加速）。
 */
function qc_verify_info($name, $product = 'cdn')
{
    $product = qc_verify_product($product);
    list(, $zone) = qc_split_host(qc_domain($name));
    $d = qc_call('domain', 'GET', '/domain/' . rawurlencode($zone) . '/verify/info?product=' . rawurlencode($product));
    return [
        'zone' => $zone,
        'product' => $product,
        'state' => (string)($d['state'] ?? ''),
        'host' => (string)($d['dns']['host'] ?? 'verification'),
        'type' => (string)($d['dns']['recordType'] ?? 'TXT'),
        'value' => (string)($d['dns']['recordValue'] ?? '')
    ];
}
/** 用户加好 TXT 后让七牛校验（七牛要求先查看过验证信息）。
 * $force=true 时即使当前为 success 也向七牛发起 POST /domain/<zone>/verify/check 触发服务端固化验证状态。
 * 成功返回最新验证信息，失败抛带中文提示的异常。
 */
function qc_verify_check($name, $product = 'cdn', $force = false)
{
    $product = qc_verify_product($product);
    $info = qc_verify_info($name, $product);
    if (!$force && in_array($info['state'], ['success', 'no_need'], true)) return $info;
    try {
        qc_call('domain', 'POST', '/domain/' . rawurlencode($info['zone']) . '/verify/check', ['type' => 'dns', 'product' => $product]);
    } catch (QiniuCdnError $e) {
        if ($e->apiCode === 400932) {
            $label = $product === 'dcdn' ? '动态加速（DCDN）' : 'CDN';
            throw new RuntimeException('七牛暂时没查到 ' . $label . ' 正确的 TXT 记录：请确认主机记录填 ' . $info['host'] . '、类型 TXT、记录值完全一致（' . $info['value'] . '），解析生效一般要几分钟，稍后再点“立即验证”。');
        }
        throw $e;
    }
    return qc_verify_info($name, $product);
}
/** 公网上是否已能查到验证用的 TXT 记录（只用于提示，不影响七牛的判断）。 */
function qc_verify_visible(array $info)
{
    foreach ((array)@dns_get_record($info['host'] . '.' . $info['zone'], DNS_TXT) as $r) if (isset($r['txt']) && trim($r['txt']) === $info['value']) return true;
    return false;
}

/** 检查该主域名是否已在系统支持的 DNS 托管服务商中（商务中国、DNSPod、Cloudflare）。 */
function qc_find_dns_provider($zone)
{
    if (!empty($GLOBALS['qc_dns_provider_mock'])) {
        return call_user_func($GLOBALS['qc_dns_provider_mock'], $zone);
    }
    $zone = strtolower(trim((string)$zone));
    if ($zone === '') return null;

    // 1. 商务中国（Bizcn）
    if (function_exists('bd_config') || file_exists(dirname(__DIR__) . '/config/bizcn.php')) {
        try {
            require_once __DIR__ . '/BizcnDomains.php';
            $r = bd_call('infoDnsRecordService', 'infoDnsRecord', ['domainname' => $zone], 5);
            if (is_array($r) && isset($r['records'])) {
                return ['provider' => 'bizcn', 'label' => '商务中国', 'zone' => $zone];
            }
        } catch (Throwable $e) {}
    }

    // 2. DNSPod
    try {
        if (dns_provider_configured('dnspod')) {
            foreach (dns_zones('dnspod') as $z) {
                if (strcasecmp($z['domain'], $zone) === 0) {
                    return ['provider' => 'dnspod', 'label' => 'DNSPod', 'zone' => $zone];
                }
            }
        }
    } catch (Throwable $e) {}

    // 3. Cloudflare
    try {
        if (dns_provider_configured('cloudflare')) {
            foreach (dns_zones('cloudflare') as $z) {
                if (strcasecmp($z['domain'], $zone) === 0) {
                    return ['provider' => 'cloudflare', 'label' => 'Cloudflare', 'zone' => $zone, 'id' => (string)$z['id']];
                }
            }
        }
    } catch (Throwable $e) {}

    return null;
}

/** 自动在托管服务商为该域名添加 TXT 记录。 */
function qc_auto_add_txt_record(array $providerInfo, $host, $value)
{
    if (!empty($GLOBALS['qc_auto_txt_mock'])) {
        return call_user_func($GLOBALS['qc_auto_txt_mock'], $providerInfo, $host, $value);
    }
    $provider = $providerInfo['provider'] ?? '';
    $zone = $providerInfo['zone'] ?? '';
    try {
        if ($provider === 'bizcn') {
            require_once __DIR__ . '/BizcnDomains.php';
            bd_call('addDnsRecordService', 'addDnsRecord', [
                'add' => [
                    'domainname' => $zone,
                    'resolvetype' => 'TXT',
                    'resolvehost' => $host,
                    'resolvevalue' => $value,
                    'mxlevel' => '10'
                ]
            ], 10);
            return true;
        }
        if ($provider === 'dnspod') {
            dns_call('dnspod', 'POST', 'CreateRecord', [
                'Domain' => $zone,
                'RecordType' => 'TXT',
                'SubDomain' => $host,
                'RecordLine' => '默认',
                'Value' => $value
            ]);
            return true;
        }
        if ($provider === 'cloudflare') {
            dns_call('cloudflare', 'POST', '/zones/' . rawurlencode($providerInfo['id'] ?? '') . '/dns_records', [
                'type' => 'TXT',
                'name' => ($host === '@' ? $zone : ($host . '.' . $zone)),
                'content' => $value,
                'ttl' => 120
            ]);
            return true;
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'exist') !== false || stripos($msg, '已存在') !== false) {
            return true;
        }
        throw $e;
    }
    throw new RuntimeException('未知的解析服务商：' . $provider);
}
