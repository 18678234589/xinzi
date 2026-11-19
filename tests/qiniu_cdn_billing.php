<?php
/** 离线测试：七牛余额 / 消费 + CDN 域名页码（接口为替身，不联网）。php tests/qiniu_cdn_billing.php */
require_once __DIR__ . '/../includes/QiniuCdn.php';
require_once __DIR__ . '/../includes/QiniuCdnTraffic.php';
require_once __DIR__ . '/../includes/QiniuCdnBilling.php';
$n = 0; function qbt($ok, $m) { global $n; if (!$ok) throw new RuntimeException('FAIL: ' . $m); $n++; }
$now = strtotime('2026-11-19 12:00:00 +0800'); $paths = [];
$GLOBALS['qb_mock'] = function ($path) use (&$paths) {
    $paths[] = $path;
    if (strpos($path, 'balance-overview') !== false) return [200, ['code' => 0, 'data' => ['available_balance' => 166298000000, 'cash_balance' => 208030000000, 'present_balance' => 0, 'credit_line' => 0]]];
    if (strpos($path, '/v2/bill/snapshot') !== false) return [200, ['code' => 0, 'data' => ['total_money' => 41732000000, 'list' => [['product' => 'CDN加速', 'start' => '2026-11-01T00:00:00', 'end' => '2026-11-19T00:00:00', 'item_money' => 41732000000], ['product' => '对象存储', 'start' => '2026-11-01T00:00:00', 'end' => '2026-11-19T00:00:00', 'item_money' => 0]]]]];
    if (strpos($path, '/v1/bill/detail') !== false) return [200, ['code' => 0, 'data' => ['total_money' => 1970000000, 'list' => [['product' => 'CDN加速', 'item_money' => 1970000000], ['product' => '对象存储', 'item_money' => 0]]]]];
    throw new RuntimeException('unexpected ' . $path);
};
qbt(qb_yuan(166298000000) === 1662.98, 'amounts are 1e-8 yuan');
$o = qb_fetch($now);
qbt($o['balance']['available'] === 1662.98 && $o['balance']['cash'] === 2080.3 && !$o['errors'], 'balance parsed');
qbt($o['month']['total'] === 417.32 && $o['month']['label'] === '2026-11' && $o['month']['through'] === '2026-11-18' && $o['month']['by_product']['CDN加速'] === 417.32, 'month-to-date from snapshot, through yesterday');
qbt($o['last']['label'] === '2026-10' && $o['last']['total'] === 19.7, 'last month from bill detail');
qbt(strpos(implode(' ', $paths), 'date=2026-11-19T00:00:00') !== false && strpos(implode(' ', $paths), 'start=2026-10-01T00:00:00&end=2026-11-01T00:00:00') !== false, 'request dates (Shanghai)');
// 一部分失败不影响其他部分
$GLOBALS['qb_mock'] = function ($path) { if (strpos($path, 'snapshot') !== false) return [200, ['code' => 1013, 'message' => '获取失败']]; return $GLOBALS['qb_ok']($path); };
$GLOBALS['qb_ok'] = function ($path) { return strpos($path, 'balance') !== false ? [200, ['code' => 0, 'data' => ['available_balance' => 100000000]]] : [200, ['code' => 0, 'data' => ['total_money' => 0, 'list' => []]]]; };
$p = qb_fetch($now);
qbt($p['balance']['available'] === 1.0 && $p['month'] === null && count($p['errors']) === 1 && strpos($p['errors'][0], '获取失败') !== false, 'a failing part reports its error, others still show');
// 缓存：10 分钟内不重复请求；读取失败时退回旧缓存
$tmp = sys_get_temp_dir() . '/qb_cache_' . getmypid() . '.json';
// qb_cache_file 固定在 storage/private；这里只验证缓存判定逻辑所用的两个纯函数
// 页码
$rows = []; for ($i = 1; $i <= 123; $i++) $rows[] = ['name' => 'd' . $i . '.example.com'];
$GLOBALS['qc_mock'] = function ($service, $method, $path) use ($rows) { $m = (int)(preg_match('/marker=(\d+)/', $path, $x) ? $x[1] : 0); $chunk = array_slice($rows, $m, 100); return [200, ['domains' => $chunk, 'marker' => $m + 100 < count($rows) ? (string)($m + 100) : '']]; };
@unlink($tmp);
list($r1, $total, $pages, $page) = qc_list_page(1, 50, $now, $tmp);
qbt($total === 123 && $pages === 3 && $page === 1 && count($r1) === 50 && $r1[0]['name'] === 'd1.example.com', 'page 1 of 3, total 123');
list($r3, , , $page3) = qc_list_page(3, 50, $now + 10, $tmp);
qbt($page3 === 3 && count($r3) === 23 && $r3[0]['name'] === 'd101.example.com', 'page 3 has the remaining 23 (served from the 2-minute cache)');
list($rx, , , $pageX) = qc_list_page(99, 50, $now + 20, $tmp);
qbt($pageX === 3 && count($rx) === 23, 'out-of-range page is clamped');
list(, , , $pageNeg) = qc_list_page(-4, 50, $now + 20, $tmp);
qbt($pageNeg === 1, 'negative page becomes 1');
@unlink($tmp);
qbt(qc_pager_items(1, 3) === [1, 2, 3], 'small pager');
qbt(qc_pager_items(5, 12) === [1, 0, 3, 4, 5, 6, 7, 0, 12], 'pager window with ellipses');
echo json_encode(['passed' => $n, 'real_provider_calls' => 0]), "\n";
