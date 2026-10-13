<?php
// 域名解析管理（DNSPod / Cloudflare）：用内存里的假服务商验证校验、增删改、新增域名、复制计划，不联网、不连库、不读任何凭据。
// 运行：php tests/dns_management.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/DnsProviders.php';

$n = 0;
$check = function ($ok, $label) use (&$n) { if (!$ok) throw new RuntimeException('未通过：' . $label); $n++; echo "  [OK] $label\n"; };
$throws = function (callable $fn, $needle, $label) use ($check) {
    try { $fn(); } catch (Throwable $e) { $check($needle === '' || mb_strpos($e->getMessage(), $needle) !== false, $label . '（' . $e->getMessage() . '）'); return; }
    $check(false, $label . '（应当报错）');
};

// ---- 假服务商（与页面测试共用）-------------------------------------------------------
require_once __DIR__ . '/dns_fake_provider.php';
$state = dns_fake_state();
dns_fake_install($state);


try {
    echo "== 校验\n";
    $v = dns_validate_record('a', 'WWW', '1.2.3.4', 600);
    $check($v['type'] === 'A' && $v['name'] === 'www' && $v['ttl'] === 600 && $v['priority'] === null, 'A 记录规范化（类型大写、主机记录小写）');
    $check(dns_validate_record('MX', '', 'Mail.Example.com.', 600, 10)['value'] === 'mail.example.com', 'MX：空主机记录当 @，值去掉末尾点并小写');
    $check(dns_validate_record('A', '*', '1.2.3.4', 1)['ttl'] === 1, '允许泛解析 * 与 TTL=1（自动）');
    $check(dns_validate_record('A', 'a.b', '1.2.3.4', 600, null, true)['proxied'] === true && dns_validate_record('TXT', 'a', 'v=x', 600, null, true)['proxied'] === false, '代理标记只对 A / AAAA / CNAME 生效');
    $throws(function () { dns_validate_record('A', 'www', '999.1.1.1', 600); }, 'IPv4', 'A 记录填了无效 IP');
    $throws(function () { dns_validate_record('A', 'www', '::1', 600); }, 'IPv4', 'A 记录填了 IPv6');
    $throws(function () { dns_validate_record('AAAA', 'www', '1.2.3.4', 600); }, 'IPv6', 'AAAA 记录填了 IPv4');
    $throws(function () { dns_validate_record('CNAME', '@', 'a.example.com', 600); }, '根域', '根域不能 CNAME');
    $throws(function () { dns_validate_record('CNAME', 'www', '1.2.3.4', 600); }, '域名', 'CNAME 的值不能是 IP');
    $throws(function () { dns_validate_record('TXT', 'www', '', 600); }, '记录值', '空记录值');
    $throws(function () { dns_validate_record('A', 'bad name!', '1.2.3.4', 600); }, '主机记录', '主机记录含非法字符');
    $throws(function () { dns_validate_record('A', '-a', '1.2.3.4', 600); }, '主机记录', '主机记录以 - 开头');
    $throws(function () { dns_validate_record('A', 'www', '1.2.3.4', 5); }, 'TTL', 'TTL 过小');
    $throws(function () { dns_validate_record('SRV', 'www', 'x', 600); }, '暂不支持', '不支持的类型');
    $throws(function () { dns_validate_record('MX', '@', 'm.example.com', 600, 70000); }, '优先级', 'MX 优先级越界');
    $check(dns_validate_record('CNAME', '@', 'a.example.com', 1, null, false, 'cloudflare')['name'] === '@', 'Cloudflare 允许根域 CNAME（展平），其他服务商不允许');
    $bz = dns_normalize_bizcn_rows([
        ['host' => '@', 'type' => 'cname', 'value' => 'x.lo0.faipod.com.'], ['host' => 'WWW', 'type' => 'A', 'value' => '1.2.3.4'],
        ['host' => '@', 'type' => 'MX', 'value' => 'mx.example.com.', 'mxlevel' => '5'], ['host' => 'verify', 'type' => 'TXT', 'value' => '"abc"'],
        ['host' => '_s', 'type' => 'SRV', 'value' => 'x'], ['host' => 'a', 'type' => '', 'value' => 'x'],
    ]);
    $check(count($bz) === 5 && $bz[0]['type'] === 'CNAME' && $bz[0]['value'] === 'x.lo0.faipod.com' && $bz[1]['name'] === 'www' && $bz[2]['priority'] === 5 && $bz[3]['value'] === 'abc' && $bz[4]['editable'] === false, '商务中国记录转统一格式（小写、去末尾点与引号、MX 优先级、SRV 只读，缺类型的丢弃）');
    $bzPlan = dns_copy_plan($bz, [], 'cloudflare');
    $check(count($bzPlan['add']) === 4 && $bzPlan['add'][0]['ttl'] === 1 && count($bzPlan['skipped']) === 1, '商务中国 → Cloudflare：4 条可复制（含根域 CNAME），SRV 跳过，TTL 自动');
    $check(dns_copy_plan($bz, [], 'dnspod')['add'][0]['ttl'] === 600, '商务中国 → DNSPod：TTL 按 600');
    $check(dns_validate_domain('Example.COM.') === 'example.com', '域名规范化');
    foreach (['example', 'a b.com', '-a.com', 'http://a.com', 'a..com', ''] as $bad) $throws(function () use ($bad) { dns_validate_domain($bad); }, '域名格式', '拒绝无效域名 “' . $bad . '”');

    echo "== 公网 NS 判断\n";
    $check(dns_ns_resolver(['paloma.ns.cloudflare.com', 'roman.ns.cloudflare.com']) === 'cloudflare', 'Cloudflare NS');
    $check(dns_ns_resolver(['sixteen.dnspod.net', 'carl.dnspod.net']) === 'dnspod' && dns_ns_resolver(['bizcn1.dnspod.net', 'bizcn2.dnspod.net']) === 'dnspod', 'DNSPod NS（含商务中国智能 DNS）');
    $check(dns_ns_resolver(['ns7.cnmsn.net', 'ns8.cnmsn.net']) === 'other', '其他 NS（商务中国默认 DNS）');
    $check(dns_ns_resolver(['a.dnspod.net', 'b.ns.cloudflare.com']) === 'mixed' && dns_ns_resolver([]) === 'none', '混合 / 无 NS');

    echo "== 新增域名\n";
    $r = dns_create_zone('dnspod', 'Example.com');
    $check($r['domain'] === 'example.com' && $r['ns'] === ['alpha.dnspod.net', 'beta.dnspod.net'], 'DNSPod 创建域名并取回分配的 NS（去掉末尾点）');
    $throws(function () { dns_create_zone('dnspod', 'example.com'); }, '已存在', '重复创建给出服务商的提示');
    $state['cf_can_create'] = false;
    $throws(function () { dns_create_zone('cloudflare', 'cf-new.com'); }, '没有“创建区域”权限', 'Cloudflare 令牌无创建权限：给出可操作的提示');
    $state['cf_accounts'] = [];
    $throws(function () { dns_create_zone('cloudflare', 'cf-new.com'); }, '读不到账号', 'Cloudflare 读不到账号信息');
    $state['cf_accounts'] = [['id' => 'acc1']]; $state['cf_can_create'] = true;
    $r = dns_create_zone('cloudflare', 'cf-new.com');
    $check($r['ns'] === ['paloma.ns.cloudflare.com', 'roman.ns.cloudflare.com'], 'Cloudflare 创建区域并取回 NS');
    $check(count(dns_zones('dnspod')) === 1 && count(dns_zones('cloudflare')) === 1 && dns_zone_find('cloudflare', 'CF-NEW.com')['domain'] === 'cf-new.com', '列表与按域名查找（不区分大小写）');

    echo "== DNSPod 记录增删改\n";
    $zone = dns_zone_find('dnspod', 'example.com');
    $check(dns_records('dnspod', $zone) === [], '没有记录时（服务商返回“列表为空”错误）按空列表处理');
    $id1 = dns_record_create('dnspod', $zone, dns_validate_record('A', '@', '1.1.1.1', 600));
    $id2 = dns_record_create('dnspod', $zone, dns_validate_record('CNAME', 'www', 'cdn.example.net', 600));
    $id3 = dns_record_create('dnspod', $zone, dns_validate_record('MX', '@', 'mail.example.com', 600, 10));
    $recs = dns_records('dnspod', $zone);
    $by = []; foreach ($recs as $x) $by[$x['type']] = $x;
    $check(count($recs) === 3 && $by['CNAME']['value'] === 'cdn.example.net' && $by['MX']['priority'] === 10 && $by['A']['name'] === '@', '创建后读回：CNAME 值去掉末尾点、MX 带优先级');
    $check($state['dnspod']['records']['example.com'][$id2]['Value'] === 'cdn.example.net.', '写入 DNSPod 时 CNAME 值补末尾点');
    dns_record_update('dnspod', $zone, $id1, dns_validate_record('A', '@', '2.2.2.2', 900), '默认');
    $check(dns_records('dnspod', $zone)[0]['value'] === '2.2.2.2' && dns_records('dnspod', $zone)[0]['ttl'] === 900, '修改记录');
    dns_record_delete('dnspod', $zone, $id2);
    $check(count(dns_records('dnspod', $zone)) === 2, '删除记录');
    $check(dns_record_create('dnspod', $zone, dns_validate_record('A', 'auto', '3.3.3.3', 1)) > 0 && $state['dnspod']['records']['example.com'][$id3 + 1]['TTL'] === 600, 'TTL 自动(1) 写入 DNSPod 时按 600');

    echo "== Cloudflare 记录增删改\n";
    $cz = dns_zone_find('cloudflare', 'cf-new.com');
    $cid = dns_record_create('cloudflare', $cz, dns_validate_record('A', 'www', '4.4.4.4', 600, null, true));
    $stored = $state['cloudflare']['records'][$cz['id']][$cid];
    $check($stored['name'] === 'www.cf-new.com' && $stored['proxied'] === true && $stored['ttl'] === 1, '写入 Cloudflare：全名、代理开启时 TTL 自动');
    dns_record_create('cloudflare', $cz, dns_validate_record('A', '@', '5.5.5.5', 300));
    $state['cloudflare']['records'][$cz['id']]['t1'] = ['id' => 't1', 'type' => 'TXT', 'name' => 'cf-new.com', 'content' => '"v=spf1 -all"', 'ttl' => 1, 'proxied' => false];
    $state['cloudflare']['records'][$cz['id']]['s1'] = ['id' => 's1', 'type' => 'SRV', 'name' => '_sip._tcp.cf-new.com', 'content' => '10 5 5060 s.cf-new.com', 'ttl' => 1];
    $recs = dns_records('cloudflare', $cz);
    $by = []; foreach ($recs as $x) $by[$x['id']] = $x;
    $check($by[$cid]['name'] === 'www' && $by['t1']['name'] === '@' && $by['t1']['value'] === 'v=spf1 -all', '读回：相对主机记录，TXT 去掉服务商加的引号');
    $check($by['s1']['editable'] === false && $by[$cid]['editable'] === true, 'SRV 等暂不支持的类型标为只读');
    dns_record_update('cloudflare', $cz, $cid, dns_validate_record('A', 'www', '6.6.6.6', 600, null, false));
    $check($state['cloudflare']['records'][$cz['id']][$cid]['content'] === '6.6.6.6' && $state['cloudflare']['records'][$cz['id']][$cid]['proxied'] === false, '修改记录（关闭代理）');
    dns_record_delete('cloudflare', $cz, $cid);
    $check(!isset($state['cloudflare']['records'][$cz['id']][$cid]), '删除记录');

    echo "== 复制到另一家\n";
    $from = [
        ['id' => '1', 'type' => 'NS', 'name' => '@', 'value' => 'a.dnspod.net', 'ttl' => 86400, 'priority' => null, 'proxied' => null, 'line' => '默认', 'enabled' => true, 'editable' => false],
        ['id' => '2', 'type' => 'A', 'name' => '@', 'value' => '1.1.1.1', 'ttl' => 600, 'priority' => null, 'proxied' => null, 'line' => '默认', 'enabled' => true, 'editable' => true],
        ['id' => '3', 'type' => 'A', 'name' => '@', 'value' => '9.9.9.9', 'ttl' => 600, 'priority' => null, 'proxied' => null, 'line' => '联通', 'enabled' => true, 'editable' => true],
        ['id' => '4', 'type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 -all', 'ttl' => 600, 'priority' => null, 'proxied' => null, 'line' => '默认', 'enabled' => true, 'editable' => true],
        ['id' => '5', 'type' => 'MX', 'name' => '@', 'value' => 'mx.example.com', 'ttl' => 600, 'priority' => 10, 'proxied' => null, 'line' => '默认', 'enabled' => true, 'editable' => true],
        ['id' => '6', 'type' => 'CNAME', 'name' => 'old', 'value' => 'x.example.net', 'ttl' => 600, 'priority' => null, 'proxied' => null, 'line' => '默认', 'enabled' => false, 'editable' => true],
        ['id' => '7', 'type' => 'SRV', 'name' => '_s', 'value' => 'x', 'ttl' => 600, 'priority' => null, 'proxied' => null, 'line' => '默认', 'enabled' => true, 'editable' => false],
        ['id' => '8', 'type' => 'A', 'name' => 'dup', 'value' => '7.7.7.7', 'ttl' => 600, 'priority' => null, 'proxied' => null, 'line' => '默认', 'enabled' => true, 'editable' => true],
    ];
    $to = [['id' => 'x', 'type' => 'A', 'name' => 'dup', 'value' => '7.7.7.7', 'ttl' => 1, 'priority' => null, 'proxied' => false, 'line' => '', 'enabled' => true, 'editable' => true]];
    $plan = dns_copy_plan($from, $to, 'cloudflare');
    $check(count($plan['add']) === 3 && $plan['existing'] === 1, '计划：新增 3 条（A / TXT / MX），已存在 1 条跳过，根域 NS 不计入');
    $reasons = array_map(function ($s) { return $s[1]; }, $plan['skipped']);
    $check(count($reasons) === 3 && mb_strpos(implode('|', $reasons), '分线路') !== false && mb_strpos(implode('|', $reasons), '已停用') !== false && mb_strpos(implode('|', $reasons), 'SRV') !== false, '跳过：分线路、停用、不支持的类型，并说明原因');
    $check($plan['add'][0]['ttl'] === 600 && dns_copy_plan($from, [], 'dnspod')['add'][0]['ttl'] === 600, 'TTL 换算：DNSPod 最少 600');
    $cfFrom = [['id' => '1', 'type' => 'A', 'name' => 'www', 'value' => '8.8.8.8', 'ttl' => 1, 'priority' => null, 'proxied' => true, 'line' => '', 'enabled' => true, 'editable' => true]];
    $p2 = dns_copy_plan($cfFrom, [], 'dnspod');
    $check($p2['proxied'] === 1 && $p2['add'][0]['ttl'] === 600 && $p2['add'][0]['proxied'] === false, 'Cloudflare → DNSPod：统计被代理的记录，TTL 自动变 600');
    // 真正执行并核对目标
    foreach ($plan['add'] as $rec) dns_record_create('cloudflare', $cz, dns_validate_record($rec['type'], $rec['name'], $rec['value'], $rec['ttl'], $rec['priority']));
    $again = dns_copy_plan($from, dns_records('cloudflare', $cz), 'cloudflare');
    $left = array_map(function ($r) { return $r['type'] . ' ' . $r['name'] . ' ' . $r['value']; }, $again['add']);
    $check($left === ['A dup 7.7.7.7'], '复制后再次预览：刚复制的 A / TXT / MX 不会重复新增（剩下的只有夹具里手写、未真正写入的 dup 记录：' . implode(',', $left) . '）');

    $check(!function_exists('pas_get') && !function_exists('db'), '假服务商测试未加载真实凭据或数据库连接');
    echo "\n=== 域名解析管理测试全部通过（$n 项）===\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
