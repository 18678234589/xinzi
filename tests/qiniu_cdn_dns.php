<?php
/** 离线测试：七牛 CDN 一键解析（方案 + 执行，接口为替身，不联网）。php tests/qiniu_cdn_dns.php */
require_once __DIR__ . '/../includes/QiniuCdn.php';
function ps_audit() {}
require_once __DIR__ . '/../includes/QiniuCdnDns.php';
$checks = 0; function qdt($ok, $m) { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $checks++; }
function rej($fn) { try { $fn(); return false; } catch (RuntimeException $e) { return true; } }
$cname = 'www-x-cn-idv.qiniudns.com';
$ctx = ['provider' => 'bizcn', 'label' => '商务中国', 'zone' => null];
$plan = function ($rows, $state = 'success', $name = 'www.x.cn') use ($cname, $ctx) { return qd_plan($name, $cname, $state, ['context' => $ctx, 'rows' => $rows]); };
qdt(rej(function () use ($plan) { $plan([], 'processing'); }), 'not-ready Qiniu state refused');
qdt($plan([])['action'] === 'add', 'no record -> add');
$r = $plan([['host' => 'www', 'type' => 'CNAME', 'value' => 'cname.bd5.zbwdj.com.'], ['host' => '@', 'type' => 'CNAME', 'value' => 'other.com.'], ['host' => 'verification', 'type' => 'TXT', 'value' => 'v']]);
qdt($r['action'] === 'replace' && count($r['current']) === 1 && $r['current'][0]['value'] === 'cname.bd5.zbwdj.com', 'one CNAME on host -> replace, other hosts untouched');
qdt($plan([['host' => 'www', 'type' => 'CNAME', 'value' => strtoupper($cname) . '.']])['action'] === 'noop', 'already pointing (case/dot insensitive) -> noop');
qdt($plan([['host' => 'www', 'type' => 'A', 'value' => '1.2.3.4'], ['host' => 'www', 'type' => 'A', 'value' => '1.2.3.5']])['action'] === 'refuse', 'multiple records -> refuse');
qdt($plan([], 'success', 'x.cn')['action'] === 'refuse', 'apex refused for non-Cloudflare');
// 执行
$audits = [];
$io = ['audit' => function ($a, $d) use (&$audits) { $audits[] = [$a, $d]; }];
$rc = [];
$recordIo = ['audit' => function () {}, 'call' => function ($s, $m, $p) use (&$rc) {
    $rc[] = [$m, $p];
    if ($m === 'infoDnsRecord') return ['records' => ['record' => [['host' => 'www', 'type' => 'A', 'value' => '1.2.3.4']]]];
    return [];
}];
$msg = qd_apply($plan([['host' => 'www', 'type' => 'A', 'value' => '1.2.3.4']]), ['type' => 'admin', 'id' => 1], $io + ['record_io' => $recordIo]);
$added = null; $deleted = null; foreach ($rc as $c) { if ($c[0] === 'addDnsRecord') $added = $c[1]['add']; if ($c[0] === 'delDnsRecord') $deleted = $c[1]['del']; }
qdt($added && $deleted && $deleted['resolvetype'] === 'A' && $deleted['resolveoldvalue'] === '1.2.3.4' && $added['resolvetype'] === 'CNAME' && $added['resolvevalue'] === $cname && $added['resolvehost'] === 'www', 'real bizcn replace: old A deleted, new CNAME added, this host only');
qdt($audits[0][0] === 'dns_connect_requested' && $audits[0][1]['from'] === 'A 1.2.3.4' && $audits[1][0] === 'dns_connect_success', 'audit keeps the old record for rollback');
$calls = [];
qd_apply($plan([]), ['type' => 'admin', 'id' => 1], $io + ['call' => function ($s, $m, $p) use (&$calls) { $calls[] = [$m, $p]; }]);
qdt($calls[0][0] === 'addDnsRecord' && $calls[0][1]['add']['resolvevalue'] === $cname, 'add path creates the CNAME');
$calls = []; qd_apply($plan([['host' => 'www', 'type' => 'CNAME', 'value' => $cname]]), ['type' => 'admin', 'id' => 1], $io + ['call' => function () use (&$calls) { $calls[] = 1; }]);
qdt(!$calls, 'noop makes no provider call');
qdt(rej(function () use ($plan, $io) { qd_apply($plan([['host' => 'www', 'type' => 'A', 'value' => '1'], ['host' => 'www', 'type' => 'A', 'value' => '2']]), ['type' => 'admin', 'id' => 1], $io); }), 'refused plan cannot be applied');
echo json_encode(['passed' => $checks, 'failed' => 0, 'real_provider_calls' => 0]) . "\n";
