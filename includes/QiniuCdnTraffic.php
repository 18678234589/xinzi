<?php
/**
 * 七牛 CDN 流量：后台任务按天拉取每个域名的流量（字节）与峰值带宽（bit/s）存入 qiniu_cdn_traffic，页面只读本表。
 * 七牛接口：POST /v2/tune/flux、/v2/tune/bandwidth（fusion.qiniuapi.com，按天粒度，一次不超过 31 天）。
 */
function qt_tz() { return new DateTimeZone('Asia/Shanghai'); }
function qt_day($ts) { return (new DateTimeImmutable('@' . $ts))->setTimezone(qt_tz())->format('Y-m-d'); }

/** 把七牛返回的 {time:[…], data:{域名:{区域:[…]}}} 按天合并各区域：流量求和，带宽取各区域峰值之和。 */
function qt_parse($reply, $mode)
{
    $days = [];
    foreach ((array)($reply['time'] ?? []) as $i => $t) $days[$i] = substr((string)$t, 0, 10);
    $out = [];
    foreach ((array)($reply['data'] ?? []) as $domain => $regions) {
        foreach ((array)$regions as $series) {
            foreach ((array)$series as $i => $v) {
                if (!isset($days[$i]) || !is_numeric($v)) continue;
                $key = strtolower($domain);
                $out[$key][$days[$i]] = ($out[$key][$days[$i]] ?? 0) + (int)$v;
            }
        }
    }
    return $out;
}
function qt_fetch_chunk(array $domains, $from, $to)
{
    $body = ['startDate' => $from, 'endDate' => $to, 'granularity' => 'day', 'domains' => implode(';', $domains)];
    $flux = qt_parse(qc_call('fusion', 'POST', '/v2/tune/flux', $body), 'flux');
    $bw = qt_parse(qc_call('fusion', 'POST', '/v2/tune/bandwidth', $body), 'bandwidth');
    return [$flux, $bw];
}
function qt_all_rows()
{
    $all = [];
    $marker = '';
    for ($page = 0; $page < 40; $page++) {
        $list = qc_list($marker);
        foreach ($list['rows'] as $r) $all[strtolower($r['name'])] = $r;
        $marker = $list['marker'];
        if ($marker === '') break;
    }
    return array_values($all);
}
function qt_all_domains() { return array_map(function ($r) { return strtolower($r['name']); }, qt_all_rows()); }
function qt_store($pdo, $flux, $bw, $now)
{
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO qiniu_cdn_traffic (domainname,day,flux_bytes,peak_bps,updated_at) VALUES (?,?,?,?,?) ON CONFLICT(domainname,day) DO UPDATE SET flux_bytes=excluded.flux_bytes,peak_bps=excluded.peak_bps,updated_at=excluded.updated_at'
        : 'INSERT INTO qiniu_cdn_traffic (domainname,day,flux_bytes,peak_bps,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE flux_bytes=VALUES(flux_bytes),peak_bps=VALUES(peak_bps),updated_at=VALUES(updated_at)';
    $q = $pdo->prepare($sql);
    $n = 0;
    foreach ($flux as $domain => $byDay) foreach ($byDay as $day => $bytes) { $q->execute([$domain, $day, max(0, $bytes), max(0, (int)($bw[$domain][$day] ?? 0)), $now]); $n++; }
    return $n;
}
/** 拉取最近 $days 天（最多 31）所有域名的流量；每批最多 50 个域名。 */
function qt_sync($pdo, $days, $now = null, $dnsLookup = null)
{
    $now = $now ?? time();
    $days = max(1, min(31, (int)$days));
    $from = qt_day($now - ($days - 1) * 86400);
    $to = qt_day($now);
    $listing = qt_all_rows();
    $domains = array_map(function ($r) { return strtolower($r['name']); }, $listing);
    $rows = 0;
    foreach (array_chunk($domains, 50) as $chunk) {
        list($flux, $bw) = qt_fetch_chunk($chunk, $from, $to);
        $rows += qt_store($pdo, $flux, $bw, $now);
    }
    $dns = 0;
    try { $dns = qt_dns_refresh($pdo, $listing, $now, $dnsLookup); } catch (PDOException $e) { if ($e->getCode() !== '42S02') throw $e; }
    return ['domains' => count($domains), 'rows' => $rows, 'dns_checked' => $dns, 'from' => $from, 'to' => $to];
}

/* ---------- 页面读取（只读数据库） ---------- */

/** 近 N 天每个域名的流量合计与今日流量：[域名 => [week, today, spike]]。 */
function qt_summary($pdo, array $domains, $now = null)
{
    $now = $now ?? time();
    $out = [];
    if (!$domains) return $out;
    $from = qt_day($now - 6 * 86400);
    $today = qt_day($now);
    $in = implode(',', array_fill(0, count($domains), '?'));
    $q = $pdo->prepare("SELECT domainname, day, flux_bytes FROM qiniu_cdn_traffic WHERE domainname IN ($in) AND day>=?");
    $q->execute(array_merge(array_map('strtolower', $domains), [qt_day($now - 14 * 86400)]));
    $by = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['domainname']][$r['day']] = (int)$r['flux_bytes'];
    foreach ($domains as $d) {
        $d = strtolower($d);
        $days = $by[$d] ?? [];
        $week = 0;
        foreach ($days as $day => $b) if ($day >= $from) $week += $b;
        $out[$d] = ['week' => $week, 'today' => $days[$today] ?? 0, 'has_data' => (bool)$days, 'spike' => qt_is_spike($days, $today)];
    }
    return $out;
}
/** 今日流量 ≥ 前 7 天日均的 5 倍且 ≥ 100MB 视为异常（今日数据未走完，只会偏低，不会误报）。 */
function qt_is_spike(array $days, $today)
{
    $prev = [];
    for ($i = 1; $i <= 7; $i++) $prev[] = $days[qt_day(strtotime($today . ' 12:00:00 +0800') - $i * 86400)] ?? 0;
    $avg = array_sum($prev) / 7;
    $t = $days[$today] ?? 0;
    return $t >= 100 * 1048576 && $t >= 5 * max($avg, 1);
}
/** 单个域名最近 $days 天的逐日数据（缺的天补 0）。 */
function qt_series($pdo, $domain, $days = 30, $now = null)
{
    $now = $now ?? time();
    $q = $pdo->prepare('SELECT day, flux_bytes, peak_bps FROM qiniu_cdn_traffic WHERE domainname=? AND day>=?');
    $q->execute([strtolower($domain), qt_day($now - ($days - 1) * 86400)]);
    $have = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['day']] = ['flux' => (int)$r['flux_bytes'], 'bps' => (int)$r['peak_bps']];
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) { $d = qt_day($now - $i * 86400); $out[] = ['day' => $d, 'flux' => $have[$d]['flux'] ?? 0, 'bps' => $have[$d]['bps'] ?? 0, 'known' => isset($have[$d])]; }
    return $out;
}
/** 所有域名近 7 / 30 天合计与排行前几名。 */
function qt_overview($pdo, $now = null)
{
    $now = $now ?? time();
    $q = $pdo->prepare('SELECT domainname, SUM(flux_bytes) total, SUM(CASE WHEN day>=? THEN flux_bytes ELSE 0 END) week FROM qiniu_cdn_traffic WHERE day>=? GROUP BY domainname ORDER BY total DESC');
    $q->execute([qt_day($now - 6 * 86400), qt_day($now - 29 * 86400)]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $week = 0; $month = 0;
    foreach ($rows as $r) { $week += (int)$r['week']; $month += (int)$r['total']; }
    return ['week' => $week, 'month' => $month, 'top' => array_slice(array_map(function ($r) { return ['domain' => $r['domainname'], 'month' => (int)$r['total'], 'week' => (int)$r['week']]; }, $rows), 0, 5), 'updated_at' => (int)$pdo->query('SELECT MAX(updated_at) FROM qiniu_cdn_traffic')->fetchColumn()];
}
function qt_bytes($b)
{
    $b = (float)$b;
    if ($b < 1024) return (int)$b . ' B';
    foreach (['KB', 'MB', 'GB', 'TB'] as $i => $u) { $b /= 1024; if ($b < 1024 || $u === 'TB') return ($b >= 100 ? number_format($b, 0) : number_format($b, 2)) . ' ' . $u; }
}
function qt_bps($b)
{
    $b = (float)$b;
    if ($b < 1000) return (int)$b . ' bps';
    foreach (['Kbps', 'Mbps', 'Gbps'] as $u) { $b /= 1000; if ($b < 1000 || $u === 'Gbps') return ($b >= 100 ? number_format($b, 0) : number_format($b, 2)) . ' ' . $u; }
}

/* ---------- 解析接入检查：公网上这个域名现在到底解析到哪 ---------- */

function qt_dns_label($state)
{
    return ['ok' => '已指向七牛', 'other' => '指向其他地址', 'none' => '未解析', 'builtin' => '七牛测试域名', '' => '未检查'][$state] ?? '未检查';
}
/** 公网 CNAME / A 记录：返回 [state, 说明]。$lookup 可注入（测试）。 */
function qt_dns_check($domain, $expected, $lookup = null)
{
    $domain = strtolower($domain);
    if (preg_match('/\.(qiniucdn|clouddn)\.com$/', $domain)) return ['builtin', '七牛提供的测试域名，无需解析'];
    $lookup = $lookup ?: function ($d, $type) { return @dns_get_record($d, $type) ?: []; };
    $expected = strtolower(rtrim($expected, '.'));
    $targets = [];
    foreach ($lookup($domain, DNS_CNAME) as $r) if (!empty($r['target'])) $targets[] = strtolower(rtrim($r['target'], '.'));
    if ($targets) {
        $hit = $expected !== '' && in_array($expected, $targets, true);
        return [$hit ? 'ok' : 'other', 'CNAME → ' . implode(', ', $targets)];
    }
    $ips = [];
    foreach ($lookup($domain, DNS_A) as $r) if (!empty($r['ip'])) $ips[] = $r['ip'];
    foreach ($lookup($domain, DNS_AAAA) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    if ($ips) return ['other', 'A / AAAA → ' . implode(', ', array_slice($ips, 0, 4)) . '（不是 CNAME，Cloudflare 开代理或 CNAME 拉平时也会这样）'];
    return ['none', '公网查不到解析记录'];
}
function qt_dns_refresh($pdo, array $rows, $now = null, $lookup = null)
{
    $now = $now ?? time();
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO qiniu_cdn_dns (domainname,expected_cname,resolved,state,checked_at) VALUES (?,?,?,?,?) ON CONFLICT(domainname) DO UPDATE SET expected_cname=excluded.expected_cname,resolved=excluded.resolved,state=excluded.state,checked_at=excluded.checked_at'
        : 'INSERT INTO qiniu_cdn_dns (domainname,expected_cname,resolved,state,checked_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE expected_cname=VALUES(expected_cname),resolved=VALUES(resolved),state=VALUES(state),checked_at=VALUES(checked_at)';
    $q = $pdo->prepare($sql);
    $n = 0;
    foreach ($rows as $r) {
        list($state, $text) = qt_dns_check($r['name'], $r['cname'] ?? '', $lookup);
        $q->execute([strtolower($r['name']), mb_substr((string)($r['cname'] ?? ''), 0, 255), mb_substr($text, 0, 255), $state, $now]);
        $n++;
    }
    return $n;
}
function qt_dns_map($pdo, array $domains)
{
    if (!$domains) return [];
    $in = implode(',', array_fill(0, count($domains), '?'));
    $q = $pdo->prepare("SELECT domainname, expected_cname, resolved, state, checked_at FROM qiniu_cdn_dns WHERE domainname IN ($in)");
    $q->execute(array_map('strtolower', $domains));
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['domainname']] = $r;
    return $out;
}
