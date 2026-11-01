<?php
/**
 * 雅云（智简魔方财务，finance 类型）上游接口客户端。凭据来自「接口设置」里的 yayun：站点地址、API 账号、API 密码（密码加密保存）。
 * 登录：POST {站点}/zjmf_api_login → jwt（缓存约 100 分钟，401 / 405 自动重新登录一次）；之后 Authorization: Bearer。
 * 只做请求转发与错误归一，不做业务判断；调用方必须先检查返回的 status == 200。账号、密码、jwt 不写日志。
 */
if (!function_exists('pas_get')) require_once __DIR__ . '/ProjectApiSettings.php';

function ya_base()
{
    $url = rtrim(pas_get('yayun', 'url'), '/');
    if (!preg_match('~^https://[a-z0-9.-]+(?::\d+)?$~i', $url)) throw new RuntimeException('请先在“接口设置”里保存雅云的站点地址（https://…）、API 账号和密码');
    return $url;
}
function ya_jwt_file() { return dirname(__DIR__) . '/storage/private/yayun_jwt.json'; }

function ya_login($force = false)
{
    $file = ya_jwt_file();
    if (!$force && is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && !empty($c['jwt']) && (int)$c['expires'] > time() && ($c['base'] ?? '') === ya_base()) return $c['jwt'];
    }
    $user = pas_get('yayun', 'username'); $pass = pas_get('yayun', 'password');
    if ($user === '' || $pass === '') throw new RuntimeException('请先在“接口设置”里保存雅云的 API 账号和密码');
    $res = ya_raw('POST', ya_base() . '/zjmf_api_login', ['username' => $user, 'password' => $pass], []);
    if ((int)($res['status'] ?? 0) !== 200 || empty($res['jwt'])) throw new RuntimeException('雅云登录失败：' . mb_substr((string)($res['msg'] ?? '账号或密码不正确'), 0, 100));
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
    file_put_contents($file, json_encode(['jwt' => $res['jwt'], 'expires' => time() + 6000, 'base' => ya_base()]));
    @chmod($file, 0600);
    return $res['jwt'];
}
/** 底层 HTTP；返回解码后的 JSON 数组。GET 参数走 query，其他方法走表单体。 */
function ya_raw($method, $url, array $data, array $headers, $timeout = 30)
{
    $ch = curl_init();
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false];
    if ($method === 'GET') $url .= ($data ? (strpos($url, '?') === false ? '?' : '&') . http_build_query($data) : '');
    else $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    $opts[CURLOPT_URL] = $url;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('无法连接雅云：' . $err);
    $json = json_decode((string)$body, true);
    if (!is_array($json)) throw new RuntimeException('雅云返回异常（HTTP ' . $code . '）');
    return $json;
}
function ya_call($path, array $data = [], $method = 'POST', $timeout = 30)
{
    $url = ya_base() . '/' . ltrim($path, '/');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $res = ya_raw($method, $url, $data, ['Authorization: Bearer ' . ya_login($attempt > 0)], $timeout);
        if (!in_array((int)($res['status'] ?? 0), [401, 405], true)) return $res;
    }
    throw new RuntimeException('雅云登录已失效，请检查“接口设置”里的账号密码');
}
