<?php
/**
 * 最小 ACME v2（Let's Encrypt）客户端，只做 DNS-01 单域名证书。账号 / 证书私钥为 RSA 2048。
 * 网络与 DNS 记录操作通过 $io 注入（http / add_txt / del_txt / txt_visible / sleep），便于离线测试。
 * 私钥只放在站点目录外的服务器文件（账号）或内存（证书私钥，签发后直接上传七牛，不落盘）。
 */
if (!defined('ACME_DIRECTORY')) define('ACME_DIRECTORY', 'https://acme-v02.api.letsencrypt.org/directory');

function acme_b64($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }

function acme_account_key($file)
{
    if (is_file($file)) {
        $key = openssl_pkey_get_private(file_get_contents($file));
        if ($key) return $key;
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
    file_put_contents($file, $pem);
    @chmod($file, 0600);
    return $key;
}
function acme_jwk($key)
{
    $d = openssl_pkey_get_details($key);
    return ['e' => acme_b64($d['rsa']['e']), 'kty' => 'RSA', 'n' => acme_b64($d['rsa']['n'])];
}
function acme_thumbprint($key) { return acme_b64(hash('sha256', json_encode(acme_jwk($key), JSON_UNESCAPED_SLASHES), true)); }
/** DNS-01 的 TXT 记录值。 */
function acme_dns_value($token, $key) { return acme_b64(hash('sha256', $token . '.' . acme_thumbprint($key), true)); }

function acme_http($method, $url, $body = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/jose+json', 'User-Agent: hezuoshang-acme/1']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    if ($out === false) throw new RuntimeException('无法连接证书机构：' . curl_error($ch));
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headers = [];
    foreach (explode("\r\n", substr($out, 0, $size)) as $line) if (strpos($line, ':') !== false) { list($k, $v) = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
    return [$status, $headers, substr($out, $size)];
}

class AcmeClient
{
    private $key; private $io; private $dir; private $kid = ''; private $nonce = '';
    public function __construct($accountKey, array $io = [])
    {
        $this->key = $accountKey;
        $this->io = $io + ['http' => 'acme_http', 'sleep' => 'sleep'];
        list(, , $body) = call_user_func($this->io['http'], 'GET', ACME_DIRECTORY);
        $this->dir = json_decode($body, true);
        if (empty($this->dir['newOrder'])) throw new RuntimeException('证书机构目录读取失败');
    }
    private function nonce()
    {
        if ($this->nonce !== '') { $n = $this->nonce; $this->nonce = ''; return $n; }
        list(, $h) = call_user_func($this->io['http'], 'HEAD', $this->dir['newNonce']);
        return $h['replay-nonce'] ?? '';
    }
    /** 带签名的请求；$payload 为 null 表示 POST-as-GET。 */
    public function post($url, $payload, $useJwk = false)
    {
        $protected = ['alg' => 'RS256', 'nonce' => $this->nonce(), 'url' => $url] + ($useJwk ? ['jwk' => acme_jwk($this->key)] : ['kid' => $this->kid]);
        $p64 = acme_b64(json_encode($protected, JSON_UNESCAPED_SLASHES));
        $d64 = $payload === null ? '' : acme_b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        openssl_sign($p64 . '.' . $d64, $sig, $this->key, OPENSSL_ALGO_SHA256);
        $res = call_user_func($this->io['http'], 'POST', $url, json_encode(['protected' => $p64, 'payload' => $d64, 'signature' => acme_b64($sig)]));
        if (!empty($res[1]['replay-nonce'])) $this->nonce = $res[1]['replay-nonce'];
        if ($res[0] >= 400) {
            $err = json_decode($res[2], true);
            throw new RuntimeException('证书机构返回错误：' . mb_substr((string)($err['detail'] ?? $err['type'] ?? $res[0]), 0, 200));
        }
        return $res;
    }
    public function register()
    {
        $res = $this->post($this->dir['newAccount'], ['termsOfServiceAgreed' => true], true);
        $this->kid = $res[1]['location'] ?? '';
        if ($this->kid === '') throw new RuntimeException('证书机构账号注册失败');
    }
    /** 返回 [orderUrl, order, [authz...]]。 */
    public function newOrder($domain)
    {
        $res = $this->post($this->dir['newOrder'], ['identifiers' => [['type' => 'dns', 'value' => $domain]]]);
        $order = json_decode($res[2], true);
        $authz = [];
        foreach ($order['authorizations'] as $u) $authz[] = json_decode($this->post($u, null)[2], true) + ['url' => $u];
        return [$res[1]['location'] ?? '', $order, $authz];
    }
    public function dnsChallenge(array $authz)
    {
        foreach ($authz['challenges'] as $c) if ($c['type'] === 'dns-01') return $c;
        throw new RuntimeException('证书机构没有提供 DNS 验证方式');
    }
    public function respond($challengeUrl) { $this->post($challengeUrl, new stdClass()); }
    public function waitStatus($url, array $done, $tries = 30)
    {
        for ($i = 0; $i < $tries; $i++) {
            $obj = json_decode($this->post($url, null)[2], true);
            if (in_array($obj['status'] ?? '', $done, true)) return $obj;
            if (($obj['status'] ?? '') === 'invalid') throw new RuntimeException('证书机构验证未通过：' . json_encode($obj['challenges'][0]['error'] ?? $obj['error'] ?? $obj, JSON_UNESCAPED_UNICODE));
            call_user_func($this->io['sleep'], 3);
        }
        throw new RuntimeException('等待证书机构处理超时');
    }
    public function finalize($orderUrl, $order, $csrDer)
    {
        $this->post($order['finalize'], ['csr' => acme_b64($csrDer)]);
        $done = $this->waitStatus($orderUrl, ['valid'], 40);
        return $this->post($done['certificate'], null)[2];
    }
}

/** 为单个域名签发证书：返回 [fullchain PEM, 私钥 PEM]。$io: add_txt($name,$value) / del_txt($name,$value) / txt_visible($name,$value) / log。 */
function acme_issue($domain, $accountKeyFile, array $io, array $clientIo = [])
{
    $log = isset($io['log']) ? $io['log'] : function () {};
    $client = new AcmeClient(acme_account_key($accountKeyFile), $clientIo);
    $client->register();
    list($orderUrl, $order, $authzList) = $client->newOrder($domain);
    $key = openssl_pkey_get_private(acme_account_key($accountKeyFile));
    $txtName = '_acme-challenge.' . $domain;
    $added = [];
    try {
        foreach ($authzList as $authz) {
            if (($authz['status'] ?? '') === 'valid') continue;
            $ch = $client->dnsChallenge($authz);
            $value = acme_dns_value($ch['token'], $key);
            $log('添加验证记录 ' . $txtName);
            $io['add_txt']($txtName, $value);
            $added[] = [$txtName, $value];
            $visible = false;
            for ($i = 0; $i < 40 && !$visible; $i++) { $visible = $io['txt_visible']($txtName, $value); if (!$visible) call_user_func(isset($clientIo['sleep']) ? $clientIo['sleep'] : 'sleep', 5); }
            if (!$visible) $log('公网暂未查到验证记录，仍尝试让证书机构验证');
            $client->respond($ch['url']);
            $client->waitStatus($authz['url'], ['valid'], 40);
            $log('域名验证通过');
        }
        $certKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        // CSR 带 SAN（现代客户端只认 SAN）：用临时 openssl 配置，用完即删。
        $cnf = tempnam(sys_get_temp_dir(), 'acme');
        file_put_contents($cnf, "[req]
distinguished_name=dn
req_extensions=ext
prompt=no
[dn]
CN=" . $domain . "
[ext]
subjectAltName=DNS:" . $domain . "
");
        try {
            $csr = openssl_csr_new(['commonName' => $domain], $certKey, ['digest_alg' => 'sha256', 'config' => $cnf, 'req_extensions' => 'ext']);
            if (!$csr) throw new RuntimeException('生成证书请求失败');
            openssl_csr_export($csr, $csrPem);
        } finally { @unlink($cnf); }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $csrPem));
        $chain = $client->finalize($orderUrl, $order, $der);
        openssl_pkey_export($certKey, $keyPem);
        return [$chain, $keyPem];
    } finally {
        foreach ($added as $a) { try { $io['del_txt']($a[0], $a[1]); } catch (Throwable $e) { error_log('ACME TXT cleanup failed'); } }
    }
}
