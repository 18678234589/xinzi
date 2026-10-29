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
function qt_all_domains()
{
    $names = [];
    $marker = '';
    for ($page = 0; $page < 40; $page++) {
        $list = qc_list($marker);
        foreach ($list['rows'] as $r) $names[] = strtolower($r['name']);
        $marker = $list['marker'];
        if ($marker === '') break;
    }
    return array_values(array_unique($names));
}
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
function qt_sync($pdo, $days, $now = null)
{
    $now = $now ?? time();
    $days = max(1, min(31, (int)$days));
    $from = qt_day($now - ($days - 1) * 86400);
    $to = qt_day($now);
    $domains = qt_all_domains();
    $rows = 0;
    foreach (array_chunk($domains, 50) as $chunk) {
        list($flux, $bw) = qt_fetch_chunk($chunk, $from, $to);
        $rows += qt_store($pdo, $flux, $bw, $now);
    }
    return ['domains' => count($domains), 'rows' => $rows, 'from' => $from, 'to' => $to];
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
