<?php
/** 离线测试：七牛 CDN 流量（SQLite，七牛接口为替身）。php -d extension=pdo_sqlite tests/qiniu_cdn_traffic.php */
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnTraffic.php';
$checks = 0; function qtt($ok, $m) { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $checks++; }
$now = strtotime('2026-10-28 12:00:00 +0800'); $calls = [];
$GLOBALS['qc_mock'] = function ($service, $method, $path, $body) use (&$calls) {
    $calls[] = [$path, $body];
    if ($path === '/v2/tune/flux' || $path === '/v2/tune/bandwidth') {
        $times = []; for ($d = strtotime($body['startDate']); $d <= strtotime($body['endDate']); $d += 86400) $times[] = date('Y-m-d 00:00:00', $d);
        $isFlux = $path === '/v2/tune/flux'; $data = [];
        foreach (explode(';', $body['domains']) as $dom) $data[$dom] = ['china' => array_map(function () use ($isFlux) { return $isFlux ? 1000 : 10; }, $times), 'oversea' => array_map(function () use ($isFlux) { return $isFlux ? 500 : 5; }, $times)];
        return [200, ['code' => 200, 'error' => '', 'time' => $times, 'data' => $data]];
    }
    if (strpos($path, '/domain?') === 0) {
        $marker = $GLOBALS['marker_state'] ?? 0; $GLOBALS['marker_state'] = $marker + 1;
        return [200, ['domains' => [['name' => $marker ? 'b.example.com' : 'a.example.com', 'cname' => $marker ? 'b-example.qiniudns.com' : 'a-example.qiniudns.com']], 'marker' => $marker ? '' : 'next']];
    }
    throw new RuntimeException('unexpected ' . $path);
};
$p = new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE qiniu_cdn_traffic (domainname TEXT, day TEXT, flux_bytes INTEGER, peak_bps INTEGER, updated_at INTEGER, PRIMARY KEY(domainname,day))');
$p->exec('CREATE TABLE qiniu_cdn_dns (domainname TEXT PRIMARY KEY, expected_cname TEXT, resolved TEXT, state TEXT, checked_at INTEGER)');
$fake = function ($d, $type) { if ($d === 'a.example.com' && $type === DNS_CNAME) return [['target' => 'A-Example.QiniuDNS.com.']]; if ($d === 'b.example.com' && $type === DNS_A) return [['ip' => '1.2.3.4']]; return []; };
$r = qt_sync($p, 3, $now, $fake);
qtt($r['domains'] === 2 && $r['rows'] === 6 && $r['from'] === '2026-10-26' && $r['to'] === '2026-10-28', 'paged domains listed, 3 days x 2 domains stored');
qtt(count($calls) >= 4, 'flux and bandwidth fetched per chunk');
qtt((int)$p->query("SELECT flux_bytes FROM qiniu_cdn_traffic WHERE domainname='a.example.com' AND day='2026-10-28'")->fetchColumn() === 1500, 'regions summed per day');
qtt((int)$p->query("SELECT peak_bps FROM qiniu_cdn_traffic WHERE domainname='a.example.com' AND day='2026-10-28'")->fetchColumn() === 15, 'bandwidth stored');
qt_sync($p, 3, $now + 3600, $fake); qtt((int)$p->query('SELECT COUNT(*) FROM qiniu_cdn_traffic')->fetchColumn() === 6, 'rerun updates instead of duplicating');
// 汇总、异常、序列
$p->exec("DELETE FROM qiniu_cdn_traffic");
$ins = $p->prepare('INSERT INTO qiniu_cdn_traffic VALUES (?,?,?,?,?)');
for ($i = 1; $i <= 7; $i++) $ins->execute(['spike.com', qt_day($now - $i * 86400), 10 * 1048576, 100, $now]);
$ins->execute(['spike.com', '2026-10-28', 800 * 1048576, 9000, $now]);
$ins->execute(['quiet.com', '2026-10-28', 50 * 1048576, 10, $now]);
$ins->execute(['busy.com', '2026-10-28', 2000 * 1048576, 10, $now]); for ($i = 1; $i <= 7; $i++) $ins->execute(['busy.com', qt_day($now - $i * 86400), 1800 * 1048576, 10, $now]);
$s = qt_summary($p, ['spike.com', 'quiet.com', 'busy.com', 'none.com'], $now);
qtt($s['spike.com']['spike'] === true && $s['spike.com']['today'] === 800 * 1048576, 'sudden jump flagged');
qtt($s['quiet.com']['spike'] === false, 'small traffic never flagged');
qtt($s['busy.com']['spike'] === false, 'steady heavy traffic not flagged');
qtt($s['none.com']['has_data'] === false && $s['none.com']['week'] === 0, 'unknown domain has no data');
qtt($s['spike.com']['week'] === (6 * 10 + 800) * 1048576, 'week total covers 7 days incl. today');
$ser = qt_series($p, 'spike.com', 30, $now); qtt(count($ser) === 30 && $ser[29]['flux'] === 800 * 1048576 && $ser[0]['known'] === false, '30-day series padded with zeros');
$o = qt_overview($p, $now); qtt($o['top'][0]['domain'] === 'busy.com' && $o['week'] > 0 && $o['month'] >= $o['week'], 'overview ranks heaviest domain first');
qtt(qt_bytes(1536) === '1.50 KB' && qt_bytes(5 * 1073741824) === '5.00 GB' && qt_bps(11964) === '11.96 Kbps', 'unit formatting');
// 解析接入检查
qtt(qt_dns_check('cdn.x.com', 'a-x.qiniudns.com', function ($d, $t) { return $t === DNS_CNAME ? [['target' => 'a-x.qiniudns.com.']] : []; })[0] === 'ok', 'CNAME to expected Qiniu target = ok (case/dot insensitive)');
$o = qt_dns_check('cdn.x.com', 'a-x.qiniudns.com', function ($d, $t) { return $t === DNS_CNAME ? [['target' => 'other.cdn.net']] : []; }); qtt($o[0] === 'other' && strpos($o[1], 'other.cdn.net') !== false, 'CNAME elsewhere shown');
$o = qt_dns_check('cdn.x.com', 'a-x.qiniudns.com', function ($d, $t) { return $t === DNS_A ? [['ip' => '9.9.9.9']] : []; }); qtt($o[0] === 'other' && strpos($o[1], '9.9.9.9') !== false, 'A record only is not Qiniu');
qtt(qt_dns_check('cdn.x.com', 'x', function () { return []; })[0] === 'none', 'no records = none');
qtt(qt_dns_check('8siq2z.com2.z0.glb.qiniucdn.com', 'x', function () { throw new RuntimeException('no lookup'); })[0] === 'builtin', 'Qiniu test domains skip DNS');
$m = qt_dns_map($p, ['a.example.com', 'b.example.com']); qtt($m['a.example.com']['state'] === 'ok' && $m['b.example.com']['state'] === 'other' && $r['dns_checked'] === 2, 'sync stores DNS state per domain');
echo json_encode(['passed' => $checks, 'failed' => 0, 'real_provider_calls' => 0]) . "\n";
