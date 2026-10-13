<?php
/**
 * 域名解析管理：DNSPod（腾讯云 API 3.0）与 Cloudflare 的统一封装。
 * 凭据沿用“接口设置”（ProjectApiSettings.php，密钥加密保存），本文件不保存、不输出任何密钥。
 * 记录统一格式：['id','type','name'（相对名，根域为 @）,'value','ttl'（Cloudflare 1=自动）,'priority','proxied','line','enabled','editable']
 * 所有网络调用经 dns_call()；测试时设置 $GLOBALS['dns_mock'] = function ($provider, $method, $path, $body) 即可替换为假服务。
 */
// Only real provider calls need settings and the authentication/database bootstrap.
// Keep validation and fake-provider tests independent of production credentials.

const DNS_PROVIDERS = ['dnspod' => 'DNSPod', 'cloudflare' => 'Cloudflare'];
const DNS_EDIT_TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS'];
const DNS_PROXY_TYPES = ['A', 'AAAA', 'CNAME'];

class DnsApiError extends RuntimeException
{
    /** 服务商返回的错误码（腾讯云 Code / Cloudflare 无则空）。 */
    public $apiCode = '';
}

function dns_provider_configured($provider)
{
    if (!empty($GLOBALS['dns_mock'])) return true;
    require_once __DIR__ . '/ProjectApiSettings.php';
    if ($provider === 'dnspod') return pas_is_set('tencent', 'secret_id') && pas_is_set('tencent', 'secret_key');
    if ($provider === 'cloudflare') return pas_is_set('cloudflare', 'dns_token');
    return false;
}

function dns_cf_http($method, $path, $body = null, $tokenField = 'dns_token')
{
    require_once __DIR__ . '/ProjectApiSettings.php';
    $token = pas_get('cloudflare', $tokenField);
    if ($token === '') throw new RuntimeException('请先在“接口设置”里保存 Cloudflare 的 API 令牌');
    $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$code, $res] = pas_http($method, 'https://api.cloudflare.com/client/v4' . $path, ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], $json);
    $decoded = json_decode($res, true);
    if (!is_array($decoded)) throw new RuntimeException('Cloudflare 返回异常（HTTP ' . $code . '）');
    return $decoded;
}

/**
 * 统一调用入口。dnspod：$path 为接口名（如 DescribeDomainList），$body 为参数，返回 Response；
 * cloudflare：$path 为 /zones/... 路径，返回完整 JSON。出错统一抛 DnsApiError。
 */
function dns_call($provider, $method, $path, $body = null)
{
    if (empty($GLOBALS['dns_mock'])) require_once __DIR__ . '/ProjectApiSettings.php';
    if (!empty($GLOBALS['dns_mock'])) $res = call_user_func($GLOBALS['dns_mock'], $provider, $method, $path, $body);
    elseif ($provider === 'dnspod') $res = pas_tencent_call('dnspod', $path, '2021-03-23', (array)$body);
    else $res = dns_cf_http($method, $path, $body);
    if (!is_array($res)) throw new DnsApiError('服务商返回异常');
    if ($provider === 'dnspod') {
        if (!empty($res['Error'])) {
            $e = new DnsApiError('DNSPod：' . (string)($res['Error']['Message'] ?? '请求失败'));
            $e->apiCode = (string)($res['Error']['Code'] ?? '');
            throw $e;
        }
        return $res;
    }
    if (empty($res['success'])) {
        $first = $res['errors'][0] ?? [];
        $e = new DnsApiError('Cloudflare：' . (string)($first['message'] ?? '请求失败'));
        $e->apiCode = (string)($first['code'] ?? '');
        throw $e;
    }
    return $res;
}

// ---------------------------------------------------------------- 域名 / 区域

/** 服务商账号下的全部域名：[['domain','id','status','records','ns'（Cloudflare 自带，DNSPod 需 dns_zone_ns）,'dns_error']]。 */
function dns_zones($provider)
{
    $zones = [];
    if ($provider === 'dnspod') {
        for ($offset = 0; $offset < 3000; $offset += 100) {
            $r = dns_call('dnspod', 'POST', 'DescribeDomainList', ['Offset' => $offset, 'Limit' => 100]);
            foreach ((array)($r['DomainList'] ?? []) as $d) {
                $zones[] = ['domain' => strtolower((string)$d['Name']), 'id' => (string)$d['DomainId'], 'status' => (string)($d['Status'] ?? ''), 'records' => (int)($d['RecordCount'] ?? 0), 'ns' => [], 'dns_error' => (($d['DNSStatus'] ?? $d['DnsStatus'] ?? '') === 'DNSERROR')];
            }
            if (count((array)($r['DomainList'] ?? [])) < 100) break;
        }
        return $zones;
    }
    for ($page = 1; $page <= 30; $page++) {
        $r = dns_call('cloudflare', 'GET', '/zones?per_page=50&page=' . $page);
        foreach ((array)$r['result'] as $z) {
            $zones[] = ['domain' => strtolower((string)$z['name']), 'id' => (string)$z['id'], 'status' => (string)($z['status'] ?? ''), 'records' => null, 'ns' => array_map('strtolower', (array)($z['name_servers'] ?? [])), 'dns_error' => false];
        }
        if ($page >= (int)($r['result_info']['total_pages'] ?? 1)) break;
    }
    return $zones;
}

function dns_zone_find($provider, $domain)
{
    foreach (dns_zones($provider) as $zone) if ($zone['domain'] === strtolower($domain)) return $zone;
    return null;
}

/** 该服务商为域名分配的 NS（需要去注册商处改成的值）。 */
function dns_zone_ns($provider, $zone)
{
    if ($provider === 'cloudflare') return $zone['ns'];
    $r = dns_call('dnspod', 'POST', 'DescribeDomain', ['Domain' => $zone['domain']]);
    return array_map('strtolower', array_map('dns_trim_dot', (array)($r['DomainInfo']['DnspodNsList'] ?? [])));
}

function dns_trim_dot($host)
{
    return rtrim(trim((string)$host), '.');
}

/** 主域名校验（只收 ASCII / 已转换为 punycode 的域名）。 */
function dns_validate_domain($domain)
{
    $domain = strtolower(dns_trim_dot($domain));
    if ($domain !== '' && preg_match('/[^\x00-\x7f]/', $domain) && function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii) $domain = $ascii;
    }
    if (strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) throw new RuntimeException('域名格式不正确，请填写主域名，如 example.com');
    return $domain;
}

/** 在服务商创建域名，返回 ['domain','id','ns']。已存在则抛错（由页面提示去“管理”）。 */
function dns_create_zone($provider, $domain)
{
    $domain = dns_validate_domain($domain);
    if ($provider === 'dnspod') {
        dns_call('dnspod', 'POST', 'CreateDomain', ['Domain' => $domain]);
        $zone = dns_zone_find('dnspod', $domain);
        if (!$zone) throw new RuntimeException('已提交创建，但暂时读不到该域名，请稍后刷新');
        return ['domain' => $domain, 'id' => $zone['id'], 'ns' => dns_zone_ns('dnspod', $zone)];
    }
    $account = '';
    if (empty($GLOBALS['dns_mock'])) require_once __DIR__ . '/ProjectApiSettings.php';
    foreach (['dns_token', 'tunnel_token'] as $field) {
        if (empty($GLOBALS['dns_mock']) && !pas_is_set('cloudflare', $field)) continue;
        try {
            $r = empty($GLOBALS['dns_mock']) ? dns_cf_http('GET', '/accounts?per_page=5', null, $field) : dns_call('cloudflare', 'GET', '/accounts?per_page=5');
            if (!empty($r['result'][0]['id'])) { $account = (string)$r['result'][0]['id']; break; }
        } catch (RuntimeException $e) {
        }
    }
    if ($account === '') throw new RuntimeException('Cloudflare 令牌读不到账号信息，没有“创建区域”权限。请在 Cloudflare 新建一个带“区域：编辑”权限的 API 令牌，到“接口设置”里替换后再添加新域名（已有区域不受影响）');
    try {
        $r = dns_call('cloudflare', 'POST', '/zones', ['name' => $domain, 'account' => ['id' => $account], 'type' => 'full']);
    } catch (DnsApiError $e) {
        if (strpos($e->getMessage(), 'zone.create') !== false) throw new RuntimeException('当前 Cloudflare 令牌没有“创建区域”权限。请在 Cloudflare 新建一个带“区域：编辑”权限的 API 令牌，到“接口设置”里替换后再添加新域名（已有区域不受影响）');
        throw $e;
    }
    return ['domain' => $domain, 'id' => (string)$r['result']['id'], 'ns' => array_map('strtolower', (array)($r['result']['name_servers'] ?? []))];
}

// ---------------------------------------------------------------- 公网 NS 检测

function dns_public_ns($domain)
{
    $rows = @dns_get_record($domain, DNS_NS);
    $out = [];
    foreach ((array)$rows as $row) if (!empty($row['target'])) $out[] = strtolower(dns_trim_dot($row['target']));
    sort($out);
    return $out;
}

/** 根据公网 NS 判断当前由谁解析：dnspod / cloudflare / mixed / other / none。 */
function dns_ns_resolver(array $ns)
{
    if (!$ns) return 'none';
    $kinds = [];
    foreach ($ns as $host) {
        if (preg_match('/\.ns\.cloudflare\.com$/', $host)) $kinds['cloudflare'] = true;
        elseif (preg_match('/(\.dnspod\.(net|com|cn)|\.dnsv\d+\.(com|net|cn))$/', $host)) $kinds['dnspod'] = true;
        else $kinds['other'] = true;
    }
    return count($kinds) === 1 ? (string)key($kinds) : 'mixed';
}

// ---------------------------------------------------------------- 解析记录

function dns_relative_name($fqdn, $domain)
{
    $fqdn = strtolower(dns_trim_dot($fqdn)); $domain = strtolower($domain);
    if ($fqdn === $domain || $fqdn === '@' || $fqdn === '') return '@';
    $suffix = '.' . $domain;
    return substr($fqdn, -strlen($suffix)) === $suffix ? substr($fqdn, 0, -strlen($suffix)) : $fqdn;
}

function dns_records($provider, $zone)
{
    $domain = $zone['domain']; $out = [];
    if ($provider === 'dnspod') {
        for ($offset = 0; $offset < 6000; $offset += 300) {
            try {
                $r = dns_call('dnspod', 'POST', 'DescribeRecordList', ['Domain' => $domain, 'Offset' => $offset, 'Limit' => 300]);
            } catch (DnsApiError $e) {
                if ($e->apiCode === 'ResourceNotFound.NoDataOfRecord') break; // 没有任何记录
                throw $e;
            }
            foreach ((array)($r['RecordList'] ?? []) as $x) {
                $type = strtoupper((string)$x['Type']);
                $value = (string)$x['Value'];
                if (in_array($type, ['CNAME', 'MX', 'NS'], true)) $value = dns_trim_dot($value);
                $name = (string)$x['Name'];
                $out[] = ['id' => (string)$x['RecordId'], 'type' => $type, 'name' => $name === '' ? '@' : $name, 'value' => $value, 'ttl' => (int)$x['TTL'], 'priority' => $type === 'MX' ? (int)($x['MX'] ?? 0) : null,
                    'proxied' => null, 'line' => (string)($x['Line'] ?? '默认'), 'enabled' => (($x['Status'] ?? 'ENABLE') === 'ENABLE'),
                    'editable' => in_array($type, DNS_EDIT_TYPES, true) && !($type === 'NS' && $name === '@')];
            }
            if (count((array)($r['RecordList'] ?? [])) < 300) break;
        }
        return $out;
    }
    for ($page = 1; $page <= 60; $page++) {
        $r = dns_call('cloudflare', 'GET', '/zones/' . rawurlencode($zone['id']) . '/dns_records?per_page=100&page=' . $page);
        foreach ((array)$r['result'] as $x) {
            $type = strtoupper((string)$x['type']);
            $value = (string)($x['content'] ?? '');
            if ($type === 'TXT' && strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') $value = substr($value, 1, -1);
            $out[] = ['id' => (string)$x['id'], 'type' => $type, 'name' => dns_relative_name((string)$x['name'], $domain), 'value' => $value, 'ttl' => (int)($x['ttl'] ?? 1), 'priority' => isset($x['priority']) ? (int)$x['priority'] : null,
                'proxied' => !empty($x['proxied']), 'line' => '', 'enabled' => true, 'editable' => in_array($type, DNS_EDIT_TYPES, true)];
        }
        if ($page >= (int)($r['result_info']['total_pages'] ?? 1)) break;
    }
    return $out;
}

/** 校验并规范化一条记录；失败抛 RuntimeException（中文提示）。 */
function dns_validate_record($type, $name, $value, $ttl, $priority = null, $proxied = false, $provider = '')
{
    $type = strtoupper(trim((string)$type));
    if (!in_array($type, DNS_EDIT_TYPES, true)) throw new RuntimeException('暂不支持的记录类型：' . $type);
    $name = strtolower(trim((string)$name));
    if ($name === '') $name = '@';
    if ($name !== '@' && !preg_match('/^(\*|[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*$/', $name)) throw new RuntimeException('主机记录格式不正确（根域填 @，如 www、*、a.b）');
    if (strlen($name) > 200) throw new RuntimeException('主机记录过长');
    if ($type === 'CNAME' && $name === '@' && $provider !== 'cloudflare') throw new RuntimeException('根域（@）不能设置 CNAME，请改用 A / AAAA 记录');
    $value = trim((string)$value);
    if ($value === '') throw new RuntimeException('请填写记录值');
    if ($type === 'A') { if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new RuntimeException('A 记录的值必须是 IPv4 地址'); }
    elseif ($type === 'AAAA') { if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) throw new RuntimeException('AAAA 记录的值必须是 IPv6 地址'); }
    elseif ($type === 'TXT') { if (strlen($value) > 2048 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) throw new RuntimeException('TXT 记录值过长或含有不可见字符'); }
    else {
        $value = strtolower(dns_trim_dot($value));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)*[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/', $value) || filter_var($value, FILTER_VALIDATE_IP)) throw new RuntimeException($type . ' 记录的值必须是域名（不是 IP 地址）');
    }
    $ttl = (int)$ttl;
    if ($ttl !== 1 && ($ttl < 60 || $ttl > 86400)) throw new RuntimeException('TTL 请填 60–86400 秒（Cloudflare 可填 1 表示自动）');
    $priority = $type === 'MX' ? (int)$priority : null;
    if ($type === 'MX' && ($priority < 0 || $priority > 65535)) throw new RuntimeException('MX 优先级范围 0–65535');
    $proxied = (bool)$proxied && in_array($type, DNS_PROXY_TYPES, true);
    return ['type' => $type, 'name' => $name, 'value' => $value, 'ttl' => $ttl, 'priority' => $priority, 'proxied' => $proxied];
}

function dns_dnspod_value($type, $value)
{
    return in_array($type, ['CNAME', 'MX', 'NS'], true) ? dns_trim_dot($value) . '.' : $value;
}

function dns_cf_payload($zone, array $rec)
{
    $payload = ['type' => $rec['type'], 'name' => $rec['name'] === '@' ? $zone['domain'] : $rec['name'] . '.' . $zone['domain'], 'content' => $rec['value'], 'ttl' => $rec['proxied'] ? 1 : $rec['ttl']];
    if (in_array($rec['type'], DNS_PROXY_TYPES, true)) $payload['proxied'] = (bool)$rec['proxied'];
    if ($rec['type'] === 'MX') $payload['priority'] = (int)$rec['priority'];
    return $payload;
}

function dns_record_create($provider, $zone, array $rec)
{
    if ($provider === 'dnspod') {
        $ttl = $rec['ttl'] === 1 ? 600 : $rec['ttl'];
        $body = ['Domain' => $zone['domain'], 'RecordType' => $rec['type'], 'RecordLine' => '默认', 'Value' => dns_dnspod_value($rec['type'], $rec['value']), 'SubDomain' => $rec['name'], 'TTL' => $ttl];
        if ($rec['type'] === 'MX') $body['MX'] = (int)$rec['priority'];
        $r = dns_call('dnspod', 'POST', 'CreateRecord', $body);
        return (string)($r['RecordId'] ?? '');
    }
    $r = dns_call('cloudflare', 'POST', '/zones/' . rawurlencode($zone['id']) . '/dns_records', dns_cf_payload($zone, $rec));
    return (string)($r['result']['id'] ?? '');
}

function dns_record_update($provider, $zone, $recordId, array $rec, $line = '默认')
{
    if ($provider === 'dnspod') {
        $ttl = $rec['ttl'] === 1 ? 600 : $rec['ttl'];
        $body = ['Domain' => $zone['domain'], 'RecordId' => (int)$recordId, 'RecordType' => $rec['type'], 'RecordLine' => $line !== '' ? $line : '默认', 'Value' => dns_dnspod_value($rec['type'], $rec['value']), 'SubDomain' => $rec['name'], 'TTL' => $ttl];
        if ($rec['type'] === 'MX') $body['MX'] = (int)$rec['priority'];
        dns_call('dnspod', 'POST', 'ModifyRecord', $body);
        return;
    }
    dns_call('cloudflare', 'PATCH', '/zones/' . rawurlencode($zone['id']) . '/dns_records/' . rawurlencode($recordId), dns_cf_payload($zone, $rec));
}

function dns_record_delete($provider, $zone, $recordId)
{
    if ($provider === 'dnspod') { dns_call('dnspod', 'POST', 'DeleteRecord', ['Domain' => $zone['domain'], 'RecordId' => (int)$recordId]); return; }
    dns_call('cloudflare', 'DELETE', '/zones/' . rawurlencode($zone['id']) . '/dns_records/' . rawurlencode($recordId));
}

// ---------------------------------------------------------------- 商务中国解析 → 统一格式

/** 商务中国 infoDnsRecord 的行（host/type/value/mxlevel）转成统一记录；只用于“复制到另一家”的来源。 */
function dns_normalize_bizcn_rows(array $rows)
{
    $out = [];
    foreach ($rows as $i => $x) {
        $type = strtoupper(trim((string)($x['type'] ?? '')));
        $value = trim((string)($x['value'] ?? ''));
        if ($type === '' || $value === '') continue;
        if (in_array($type, ['CNAME', 'MX', 'NS'], true)) $value = dns_trim_dot($value);
        if ($type === 'TXT' && strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') $value = substr($value, 1, -1);
        $name = strtolower(trim((string)($x['host'] ?? '@')));
        $out[] = ['id' => 'bizcn' . $i, 'type' => $type, 'name' => ($name === '' ? '@' : $name), 'value' => $value, 'ttl' => 1,
            'priority' => $type === 'MX' ? (int)($x['mxlevel'] ?? 10) : null, 'proxied' => null, 'line' => '', 'enabled' => true,
            'editable' => in_array($type, DNS_EDIT_TYPES, true)];
    }
    return $out;
}

// ---------------------------------------------------------------- 复制到另一家

function dns_record_key(array $r)
{
    return $r['type'] . '|' . $r['name'] . '|' . ($r['type'] === 'TXT' ? $r['value'] : strtolower($r['value'])) . '|' . ($r['type'] === 'MX' ? (int)$r['priority'] : '');
}

/**
 * 计划把 $from 服务商的记录复制到 $toProvider：返回 ['add'=>[...可新增], 'existing'=>已存在条数, 'skipped'=>[['record','reason']], 'proxied'=>被代理的条数]。
 * 不复制根域 NS（服务商自带）、不支持的类型；已存在（类型 + 主机记录 + 值）的跳过；停用的记录不复制。
 */
function dns_copy_plan(array $fromRecords, array $toRecords, $toProvider)
{
    $have = [];
    foreach ($toRecords as $r) $have[dns_record_key($r)] = true;
    $plan = ['add' => [], 'existing' => 0, 'skipped' => [], 'proxied' => 0];
    foreach ($fromRecords as $r) {
        if ($r['type'] === 'NS' && $r['name'] === '@') continue;
        if (!$r['editable']) { $plan['skipped'][] = [$r, '类型 ' . $r['type'] . ' 暂不支持自动复制']; continue; }
        if (!$r['enabled']) { $plan['skipped'][] = [$r, '该记录已停用']; continue; }
        if (isset($r['line']) && $r['line'] !== '' && $r['line'] !== '默认') { $plan['skipped'][] = [$r, '分线路记录（' . $r['line'] . '）不复制']; continue; }
        $ttl = $r['ttl'];
        if ($toProvider === 'dnspod') $ttl = $ttl <= 1 ? 600 : max(600, $ttl);
        else $ttl = $ttl < 60 ? 1 : $ttl;
        $copy = ['type' => $r['type'], 'name' => $r['name'], 'value' => $r['value'], 'ttl' => $ttl, 'priority' => $r['priority'], 'proxied' => false];
        if (!empty($r['proxied'])) $plan['proxied']++;
        $key = dns_record_key($copy);
        if (isset($have[$key])) { $plan['existing']++; continue; }
        $have[$key] = true;
        $plan['add'][] = $copy;
    }
    return $plan;
}
