<?php
/** 离线测试：商务中国子账号域名的写操作回退、只读重试（接口替身，不联网）。php tests/bizcn_sub_accounts.php */
$c = 0; function st($ok, $m) { global $c; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $c++; }
function str_($fn) { try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
$GLOBALS['bd_test_main'] = 'main'; $GLOBALS['bd_test_owners'] = ['sub.com' => 'jishu003', 'own.com' => 'main', 'other.com' => 'jishu009'];
$GLOBALS['subs'] = 'jishu003:pw3;jishu004:pw4';
function pas_get($p, $f) { return $GLOBALS['subs']; }
require __DIR__ . '/../includes/BizcnDomainValues.php';
require __DIR__ . '/../includes/BizcnTransport.php';
function bd_call($s, $m, $p, $t = 18) { return bd_transport($s, $m, $p, $t); }
$calls = [];
$GLOBALS['bd_raw_mock'] = function ($service, $method, $params, $config) use (&$calls) {
    $user = $config['username'] ?? 'main'; $calls[] = [$method, $user];
    if ($method === 'addDnsRecord' && $user === 'main' && in_array(bd_params_domain($params), ['sub.com', 'other.com'], true)) throw new RuntimeException('商务中国：Operating Entity is not exist or Authorization failed');
    if ($method === 'infoDomainWhois' && ($GLOBALS['flaky'] ?? 0) > 0) { $GLOBALS['flaky']--; throw new RuntimeException('商务中国连接暂未成功，请重试或检查接口 IP 白名单'); }
    if ($method === 'addDnsRecord' && $user !== 'main' && $user !== 'jishu003') throw new RuntimeException('商务中国：bad sub credentials');
    return ['result' => ['code' => '200'], 'by' => $user];
};
$add = function ($d) { return bd_call('addDnsRecordService', 'addDnsRecord', ['add' => ['domainname' => $d, 'resolvetype' => 'TXT', 'resolvehost' => 'v', 'resolvevalue' => 'x']]); };
st(bd_params_domain(['add' => ['domainname' => 'A.com']]) === 'a.com' && bd_params_domain(['infoDomainWhois' => ['domainname' => 'b.com']]) === 'b.com' && bd_params_domain(['x' => 1]) === '', 'domain found in nested params');
st($add('own.com')['by'] === 'main' && count($calls) === 1, 'own domain: one call with the main account');
$calls = []; st($add('sub.com')['by'] === 'jishu003' && $calls === [['addDnsRecord', 'main'], ['addDnsRecord', 'jishu003']], 'sub-account domain: main refused, retried with that sub-account credentials');
$msg = str_(function () use ($add) { $add('other.com'); });
st(strpos((string)$msg, 'jishu009') !== false && strpos($msg, '接口设置') !== false, 'no credentials saved for the owner: clear instruction naming the sub-account');
$GLOBALS['subs'] = '';
$msg = str_(function () use ($add) { $add('sub.com'); }); st(strpos((string)$msg, 'jishu003') !== false, 'nothing configured: instruction, not the raw English error');
$GLOBALS['subs'] = 'jishu004:pw4'; $calls = [];
st(strpos((string)str_(function () use ($add) { $add('sub.com'); }), 'jishu003') !== false && count($calls) === 1, 'other sub-account configured does not get used for this owner');
$GLOBALS['subs'] = 'jishu003:pw3;jishu004:pw4;bad';
st(bd_sub_accounts() === ['jishu003' => 'pw3', 'jishu004' => 'pw4'], 'credential list parsed, junk entries ignored');
$calls = []; $GLOBALS['flaky'] = 1;
st(bd_call('infoDomainWhoisService', 'infoDomainWhois', ['infoDomainWhois' => ['domainname' => 'own.com']])['by'] === 'main' && count($calls) === 2, 'read call retried once after a transient connection failure');
$calls = []; $GLOBALS['flaky'] = 2;
st(str_(function () { bd_call('infoDomainWhoisService', 'infoDomainWhois', ['infoDomainWhois' => ['domainname' => 'own.com']]); }) !== null && count($calls) === 2, 'read call gives up after one retry');
$GLOBALS['connfail'] = true; $calls = [];
$GLOBALS['bd_raw_mock'] = function ($s, $m, $p, $cfg) use (&$calls) { $calls[] = $m; throw new RuntimeException('商务中国连接暂未成功，请重试或检查接口 IP 白名单'); };
str_(function () use ($add) { $add('own.com'); }); st(count($calls) === 1, 'write calls are never retried automatically');
echo json_encode(['passed' => $c, 'failed' => 0, 'real_provider_calls' => 0]) . "\n";
