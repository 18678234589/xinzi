<?php
/**
 * 第三方接口设置（域名解析 / 域名到期 / CDN 等自动化的凭据）。
 * 密钥字段用保险库密钥加密（AES-256-GCM）后存入 project_settings.api_integrations；页面只显示掩码；只有管理员可查看和修改。
 * 后续功能（自动取域名到期日、域名解析、添加 CDN 域名）通过 pas_get($provider,$field) 取明文，不要把明文写进日志。
 */
require_once __DIR__ . '/ProjectSystem.php';
require_once __DIR__ . '/ProjectVault.php';

function pas_providers()
{
    return [
        'tencent' => ['title' => '腾讯云 / DNSPod', 'desc' => '域名解析（DNSPod）与域名信息查询；SecretId / SecretKey 为腾讯云永久访问密钥。', 'fields' => [
            'app_id' => ['AppId', false], 'secret_id' => ['SecretId', true], 'secret_key' => ['SecretKey', true], 'domain' => ['默认域名', false]]],
        'cloudflare' => ['title' => 'Cloudflare', 'desc' => 'DNS 记录管理与隧道；区域令牌需有“DNS 编辑”权限。', 'fields' => [
            'zone_id' => ['区域 Zone ID', false], 'zone' => ['区域域名', false], 'dns_token' => ['DNS API 令牌', true], 'tunnel_token' => ['隧道 API 令牌', true]]],
        'qiniu' => ['title' => '七牛云 CDN', 'desc' => '添加 CDN 域名、域名配置与批量复制（融合 CDN 接口，QBox 鉴权）。', 'fields' => [
            'access_key' => ['AccessKey', true], 'secret_key' => ['SecretKey', true]]],
    ];
}

function pas_store()
{
    $v = ps_setting_get('api_integrations', []);
    return is_array($v) ? $v : [];
}

/** 取明文（仅服务端内部使用）。 */
function pas_get($provider, $field)
{
    $def = pas_providers()[$provider]['fields'][$field] ?? null;
    if (!$def) return '';
    $raw = (string)(pas_store()[$provider][$field] ?? '');
    return $def[1] ? pv_decrypt($raw) : $raw;
}

function pas_is_set($provider, $field)
{
    return (string)(pas_store()[$provider][$field] ?? '') !== '';
}

/** 页面展示用：密钥只显示末 4 位。 */
function pas_mask($provider, $field)
{
    $def = pas_providers()[$provider]['fields'][$field] ?? null;
    if (!$def || !pas_is_set($provider, $field)) return '';
    $plain = pas_get($provider, $field);
    if (!$def[1]) return $plain;
    return '••••••••' . mb_substr($plain, -4);
}

/** 保存：密钥字段留空表示保持原值。返回被修改的字段名列表。 */
function pas_save($provider, array $input, $adminId)
{
    $defs = pas_providers()[$provider]['fields'] ?? null;
    if (!$defs) throw new RuntimeException('未知的接口类型');
    $store = pas_store();
    $changed = [];
    foreach ($defs as $field => [$label, $secret]) {
        $value = trim((string)($input[$field] ?? ''));
        if ($value === '') continue;
        if (mb_strlen($value) > 400 || preg_match('/[\r\n]/', $value)) throw new RuntimeException($label . ' 格式不正确');
        $store[$provider][$field] = $secret ? pv_encrypt($value) : $value;
        $changed[] = $field;
    }
    if ($changed) ps_setting_set('api_integrations', $store, (int)$adminId);
    return $changed;
}

function pas_http($method, $url, array $headers, $body = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($out === false) throw new RuntimeException('网络请求失败：' . $err);
    return [$code, (string)$out];
}

/** 腾讯云 API 3.0（TC3-HMAC-SHA256）签名调用。 */
function pas_tencent_call($service, $action, $version, array $payload)
{
    $sid = pas_get('tencent', 'secret_id'); $sk = pas_get('tencent', 'secret_key');
    if ($sid === '' || $sk === '') throw new RuntimeException('请先保存腾讯云 SecretId / SecretKey');
    $host = $service . '.tencentcloudapi.com'; $ts = time(); $date = gmdate('Y-m-d', $ts);
    $body = json_encode($payload ?: new stdClass(), JSON_UNESCAPED_UNICODE);
    $canonical = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:$host\nx-tc-action:" . strtolower($action) . "\n\ncontent-type;host;x-tc-action\n" . hash('sha256', $body);
    $scope = "$date/$service/tc3_request";
    $toSign = "TC3-HMAC-SHA256\n$ts\n$scope\n" . hash('sha256', $canonical);
    $k = hash_hmac('sha256', 'tc3_request', hash_hmac('sha256', $service, hash_hmac('sha256', $date, 'TC3' . $sk, true), true), true);
    $sig = hash_hmac('sha256', $toSign, $k);
    [$code, $res] = pas_http('POST', "https://$host/", [
        'Authorization: TC3-HMAC-SHA256 Credential=' . $sid . '/' . $scope . ', SignedHeaders=content-type;host;x-tc-action, Signature=' . $sig,
        'Content-Type: application/json; charset=utf-8', 'Host: ' . $host, 'X-TC-Action: ' . $action, 'X-TC-Timestamp: ' . $ts, 'X-TC-Version: ' . $version,
    ], $body);
    $json = json_decode($res, true);
    if (!is_array($json)) throw new RuntimeException('腾讯云返回异常（HTTP ' . $code . '）');
    return $json['Response'] ?? [];
}

/** 七牛 URL 安全 Base64（保留尾部 =）。 */
function pas_qiniu_b64($raw) { return strtr(base64_encode($raw), '+/', '-_'); }

/**
 * 七牛 CDN「域名管理 / 域名配置」接口：host=api.qiniu.com，Qiniu 鉴权（对完整请求内容签名）。
 * 签名串：{Method} {PathWithQuery}\nHost: {Host}[\nContent-Type: {CT}]\nX-Qiniu-Date: {Date}\n\n{Body}
 * $body 为 null 表示无请求体（GET / DELETE）；POST / PUT 传数组，按紧凑 JSON 发送并参与签名。
 * @return array [HTTP 状态码, 解码后的 JSON（可能为 null）]
 */
function pas_qiniu_call($method, $pathQuery, $body = null)
{
    $ak = pas_get('qiniu', 'access_key'); $sk = pas_get('qiniu', 'secret_key');
    if ($ak === '' || $sk === '') throw new RuntimeException('请先保存七牛 AccessKey / SecretKey');
    $host = 'api.qiniu.com'; $date = gmdate('Ymd\THis\Z');
    $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $data = $method . ' ' . $pathQuery . "\nHost: " . $host . ($json !== null ? "\nContent-Type: application/json" : '') . "\nX-Qiniu-Date: " . $date . "\n\n" . ($json ?? '');
    $token = $ak . ':' . pas_qiniu_b64(hash_hmac('sha1', $data, $sk, true));
    $headers = ['Host: ' . $host, 'X-Qiniu-Date: ' . $date, 'Authorization: Qiniu ' . $token];
    if ($json !== null) $headers[] = 'Content-Type: application/json';
    [$code, $res] = pas_http($method, 'https://' . $host . $pathQuery, $headers, $json);
    return [$code, json_decode($res, true)];
}

/** 七牛 CDN「用量统计 / 缓存刷新 / 证书 / 日志」接口：host=fusion.qiniuapi.com，QBox 鉴权（仅对路径+query 签名）。 */
function pas_qiniu_qbox_call($method, $pathQuery, $body = null)
{
    $ak = pas_get('qiniu', 'access_key'); $sk = pas_get('qiniu', 'secret_key');
    if ($ak === '' || $sk === '') throw new RuntimeException('请先保存七牛 AccessKey / SecretKey');
    $token = $ak . ':' . pas_qiniu_b64(hash_hmac('sha1', $pathQuery . "\n", $sk, true));
    $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$code, $res] = pas_http($method, 'https://fusion.qiniuapi.com' . $pathQuery, ['Authorization: QBox ' . $token, 'Content-Type: application/json'], $json);
    return [$code, json_decode($res, true)];
}

/** 只读连通性测试，返回 [bool 成功, string 说明]。不改动任何远端数据。 */
function pas_test($provider)
{
    try {
        if ($provider === 'tencent') {
            $r = pas_tencent_call('dnspod', 'DescribeDomainList', '2021-03-23', ['Limit' => 5]);
            if (!empty($r['Error'])) return [false, '腾讯云返回：' . ($r['Error']['Code'] ?? '') . ' ' . ($r['Error']['Message'] ?? '')];
            $n = (int)($r['DomainCountInfo']['AllTotal'] ?? count($r['DomainList'] ?? []));
            return [true, '连接成功：DNSPod 账号下共有 ' . $n . ' 个域名'];
        }
        if ($provider === 'cloudflare') {
            $zone = pas_get('cloudflare', 'zone_id'); $tok = pas_get('cloudflare', 'dns_token');
            if ($zone === '' || $tok === '') return [false, '请先保存 Zone ID 和 DNS API 令牌'];
            [$code, $res] = pas_http('GET', 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone) . '/dns_records?per_page=1', ['Authorization: Bearer ' . $tok, 'Content-Type: application/json']);
            $j = json_decode($res, true);
            if (!empty($j['success'])) return [true, '连接成功：可读取区域 ' . (pas_get('cloudflare', 'zone') ?: $zone) . ' 的 DNS 记录（共 ' . (int)($j['result_info']['total_count'] ?? 0) . ' 条）'];
            return [false, 'Cloudflare 返回：' . (($j['errors'][0]['message'] ?? '') ?: 'HTTP ' . $code)];
        }
        if ($provider === 'qiniu') {
            [$code, $j] = pas_qiniu_call('GET', '/domain?limit=10');
            if ($code === 200) return [true, '连接成功：七牛 CDN 鉴权通过，账号下 CDN 域名 ' . count($j['domains'] ?? []) . (!empty($j['marker']) ? '+' : '') . ' 个'];
            return [false, '七牛返回：' . ((is_array($j) ? ($j['error'] ?? $j['message'] ?? '') : '') ?: 'HTTP ' . $code)];
        }
        return [false, '未知的接口类型'];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}
