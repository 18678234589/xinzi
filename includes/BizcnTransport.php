<?php
/** 商务中国 SOAP 传输层：子账号回退、只读重试。不依赖应用框架，便于离线测试；bd_config / bc_find / pas_get 由应用提供。 */
require_once __DIR__ . '/BizcnDomainValues.php';

// Internal transport; paid registration may only call this after br_reserve + br_mark_started.
function bd_transport($service,$method,$params,$timeout = 18)
{
    $read = ['getDomainList','getDomainCount','domainAuditQuery','infoDomainWhois','infoDnsRecord','getTemplateList','queryTemplateAuthStatus','queryDomainOrder','checkPremium'];
    $call = function ($config) use ($service, $method, $params, $timeout, $read) {
        try { return bd_transport_raw($service, $method, $params, $timeout, $config); }
        catch (RuntimeException $e) {
            // 只读接口遇到一次性的连接失败再试一次；写操作绝不自动重试，避免重复提交。
            if (!in_array($method, $read, true) || strpos($e->getMessage(), '连接暂未成功') === false) throw $e;
            usleep(400000);
            return bd_transport_raw($service, $method, $params, $timeout, $config);
        }
    };
    try { return $call(null); }
    catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'Operating Entity is not exist') === false) throw $e;
        // 域名挂在商务中国子账号下：主账号能读不能写，改用该子账号自己的 API 账号密码。
        $domain = bd_params_domain($params);
        $owner = $domain === '' ? '' : bd_domain_owner($domain);
        $main = !empty($GLOBALS['bd_test_main']) ? $GLOBALS['bd_test_main'] : bd_config()['username'];
        if ($owner === '' || strcasecmp($owner, $main) === 0) throw $e;
        $subs = bd_sub_accounts();
        if (isset($subs[strtolower($owner)])) return $call(['username' => $owner, 'password' => $subs[strtolower($owner)]]);
        throw new RuntimeException('这个域名（' . $domain . '）属于商务中国子账号「' . $owner . '」，主账号接口没有修改权限。请到「接口设置 › 商务中国子账号」添加它的 API 账号和 API 密码（在商务中国“用户管理中心”里设置 API 密码），保存后重试。');
    }
}
function bd_transport_raw($service,$method,$params,$timeout,$config = null)
{
    if (!empty($GLOBALS['bd_raw_mock'])) return call_user_func($GLOBALS['bd_raw_mock'], $service, $method, $params, $config);
    $config = $config ?? bd_config();
    $params = ['user'=>['name'=>$config['username'], 'password'=>$config['password']]] + $params;
    $body = '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><m:' . $method . ' xmlns:m="http://ws/">' . bd_xml($params) . '</m:' . $method . '></soap:Body></soap:Envelope>';
    $curl = curl_init('https://api.bizcn.com/rrpservices/' . $service);
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>$timeout, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false]);
    $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($response === false || $status !== 200) throw new RuntimeException('商务中国连接暂未成功，请重试或检查接口 IP 白名单');
    return bd_parse($response, $method, [$config['password'], md5($config['password'])]);
}
/** 请求参数里的域名（顶层或嵌套的 domainname）。 */
function bd_params_domain($params)
{
    foreach ((array)$params as $key => $value) {
        if ($key === 'domainname' && is_string($value)) return strtolower($value);
        if (is_array($value)) { $d = bd_params_domain($value); if ($d !== '') return $d; }
    }
    return '';
}
/** 目录里记录的该域名所属的商务中国账号（子账号用户名）；不在目录里返回空。 */
function bd_domain_owner($domain)
{
    if (isset($GLOBALS['bd_test_owners'])) return (string)($GLOBALS['bd_test_owners'][$domain] ?? '');
    try { $row = bc_find($domain); return $row ? (string)$row['owner_username'] : ''; } catch (Throwable $e) { return ''; }
}
/** 接口设置里保存的子账号 API 凭据：用户名 => API 密码。格式 `jishu003:密码;jishu004:密码`。 */
function bd_sub_accounts()
{
    if (!function_exists('pas_get')) require_once __DIR__ . '/ProjectApiSettings.php';
    $out = [];
    try { $raw = pas_get('bizcn_sub', 'accounts'); } catch (Throwable $e) { return []; }
    foreach (preg_split('/[;\n]+/', (string)$raw) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || strpos($pair, ':') === false) continue;
        list($user, $pw) = explode(':', $pair, 2);
        $user = strtolower(trim($user));
        if ($user !== '' && $pw !== '') $out[$user] = $pw;
    }
    return $out;
}
