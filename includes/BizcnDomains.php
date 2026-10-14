<?php
/* Bizcn SOAP adapter. Endpoints and argument names verified against provider WSDL. */
require_once __DIR__ . '/ProjectVault.php';
require_once __DIR__ . '/BizcnDomainValues.php';
require_once __DIR__ . '/BizcnDnsRecords.php';
require_once __DIR__ . '/BizcnDomainCatalog.php';
require_once __DIR__ . '/BizcnDomainAggregator.php';
require_once __DIR__ . '/BizcnDomainStatus.php';

function bd_config()
{
    $file = dirname(__DIR__) . '/config/bizcn.php';
    $config = is_file($file) ? include $file : [];
    if (is_array($config) && !empty($config['password_enc'])) $config['password'] = pv_decrypt($config['password_enc']);
    if (!is_array($config) || empty($config['username']) || empty($config['password'])) throw new RuntimeException('尚未配置商务中国连接，请联系超级管理员');
    return $config;
}

function bd_can_access($actor)
{
    if (!$actor) return false;
    if ($actor['type'] === 'admin') return pv_admin_username($actor['id']) === 'admin';
    if ($actor['type'] !== 'employee') return false;
    $q = db()->prepare('SELECT department FROM employees WHERE id=?');
    $q->execute([(int)$actor['employee_id']]);
    $department = (string)$q->fetchColumn();
    $q = db()->prepare('SELECT business_name FROM project_user_businesses WHERE user_id=?');
    $q->execute([(int)$actor['id']]);
    return bd_policy($actor['role'], $department, $q->fetchAll(PDO::FETCH_COLUMN), pv_can_access($actor));
}

function bd_call($service, $method, $params, $timeout = 18)
{
    $allowed = ['domainService'=>['getDomainList','getDomainCount','domainAuditQuery','jumpPanel','modDomainID','checkPremium'], 'domainTemplateService'=>['getTemplateList','queryTemplateAuthStatus','addTemplate'], 'orderService'=>['queryDomainOrder'], 'infoDomainWhoisService'=>['infoDomainWhois'], 'infoDnsRecordService'=>['infoDnsRecord'], 'modDomainDnsService'=>['modDomainDns'], 'addDnsRecordService'=>['addDnsRecord'], 'modDnsRecordService'=>['modDnsRecord'], 'delDnsRecordService'=>['delDnsRecord']];
    if (!isset($allowed[$service]) || !in_array($method, $allowed[$service], true)) throw new RuntimeException('不支持此操作');
    return bd_transport($service,$method,$params,$timeout);
}

// Internal transport; paid registration may only call this after br_reserve + br_mark_started.
function bd_transport($service,$method,$params,$timeout = 18)
{
    $config = bd_config();
    $params = ['user'=>['name'=>$config['username'], 'password'=>$config['password']]] + $params;
    $body = '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><m:' . $method . ' xmlns:m="http://ws/">' . bd_xml($params) . '</m:' . $method . '></soap:Body></soap:Envelope>';
    $curl = curl_init('https://api.bizcn.com/rrpservices/' . $service);
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>$timeout, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_FOLLOWLOCATION=>false]);
    $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($response === false || $status !== 200) throw new RuntimeException('商务中国连接暂未成功，请重试或检查接口 IP 白名单');
    return bd_parse($response, $method, [$config['password'], md5($config['password'])]);
}

function bd_list($search, $page, $sourceFilter = 'all', $forceRefresh = false)
{
    return bda_unified_list($search, $page, $sourceFilter, $forceRefresh);
}

function bd_require_owned($domain)
{
    // 本 API 账号没有“域名列表”权限（getDomainList / getDomainCount 对任何域名都返回 531），不能用列表核验归属。
    // 改用 infoDomainWhois 逐域名实时核验：属于本账户的域名返回资料，其他域名返回 531（线上实测）。
    try {
        $info = bd_call('infoDomainWhoisService', 'infoDomainWhois', ['infoDomainWhois' => ['domainname' => $domain, 'detailed' => 'false']]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), '531') !== false) throw new RuntimeException('该域名不在当前商务中国账户中，无法管理');
        throw $e; // 网络或其他接口错误：原样提示，避免误判为“不是我们的域名”
    }
    return ['domainname' => $domain] + (array)$info;
}
