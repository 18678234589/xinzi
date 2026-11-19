<?php
/**
 * 七牛账户余额与消费（财务对外 API，host=api.qiniu.com，Qiniu 鉴权，只读）：
 *   GET /billing-api/v1/account/balance-overview      余额（可用 / 现金 / 赠送金 / 信用额度）
 *   GET /billing-api/v2/bill/snapshot?date=今天       本月累计预估消费（官方每月 4 号出账，之前是预估）
 *   GET /billing-api/v1/bill/detail?start=&end=       上月出账账单
 * 金额单位：整数，精确到小数点后 8 位（除以 1e8 得到元）。结果缓存 10 分钟，避免每次刷新页面都请求七牛。
 */
function qb_yuan($v) { return round(((float)$v) / 100000000, 2); }
function qb_cache_file() { return dirname(__DIR__) . '/storage/private/qiniu_billing.json'; }

function qb_call($path)
{
    if (!empty($GLOBALS['qb_mock'])) [$http, $data] = call_user_func($GLOBALS['qb_mock'], $path);
    else { require_once __DIR__ . '/ProjectApiSettings.php'; [$http, $data] = pas_qiniu_call('GET', $path); }
    if (!is_array($data) || $http !== 200 || (int)($data['code'] ?? -1) !== 0 || !isset($data['data'])) {
        throw new RuntimeException('七牛财务接口：' . mb_substr((string)(is_array($data) ? ($data['message'] ?? $data['error'] ?? '') : '') ?: ('HTTP ' . $http), 0, 120));
    }
    return $data['data'];
}
/** 账单明细（快照或月账单）→ [合计, 按产品合计]，单位元。 */
function qb_bill_summary(array $data)
{
    $by = [];
    foreach ((array)($data['list'] ?? []) as $it) {
        $p = (string)($it['product'] ?? '其他');
        $by[$p] = ($by[$p] ?? 0) + (int)($it['item_money'] ?? 0);
    }
    arsort($by);
    $total = isset($data['total_money']) ? (int)$data['total_money'] : array_sum($by);
    return [qb_yuan($total), array_map('qb_yuan', $by)];
}
function qb_fetch($now = null)
{
    $now = $now ?? time();
    $tz = new DateTimeZone('Asia/Shanghai');
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
    $out = ['updated_at' => $now, 'balance' => null, 'month' => null, 'last' => null, 'errors' => []];
    try {
        $b = qb_call('/billing-api/v1/account/balance-overview');
        $out['balance'] = ['available' => qb_yuan($b['available_balance'] ?? $b['balance'] ?? 0), 'cash' => qb_yuan($b['cash_balance'] ?? 0), 'present' => qb_yuan($b['present_balance'] ?? 0), 'credit' => qb_yuan($b['credit_line'] ?? 0)];
    } catch (Throwable $e) { $out['errors'][] = $e->getMessage(); }
    try {
        $s = qb_call('/billing-api/v2/bill/snapshot?date=' . $today->format('Y-m-d') . 'T00:00:00');
        list($total, $by) = qb_bill_summary($s);
        $first = $s['list'][0] ?? null;
        // 每月 1 号的快照是上月整月；其余日期是 本月 1 号 到 昨天
        $label = $first ? substr((string)$first['start'], 0, 7) : $today->format('Y-m');
        $through = $first ? (new DateTimeImmutable(substr((string)$first['end'], 0, 10), $tz))->modify('-1 day')->format('Y-m-d') : $today->modify('-1 day')->format('Y-m-d');
        $out['month'] = ['label' => $label, 'through' => $through, 'total' => $total, 'by_product' => $by];
    } catch (Throwable $e) { $out['errors'][] = $e->getMessage(); }
    try {
        $start = $today->modify('first day of last month')->format('Y-m-d'); $end = $today->modify('first day of this month')->format('Y-m-d');
        $d = qb_call('/billing-api/v1/bill/detail?start=' . $start . 'T00:00:00&end=' . $end . 'T00:00:00');
        list($total, $by) = qb_bill_summary($d);
        $out['last'] = ['label' => substr($start, 0, 7), 'total' => $total, 'by_product' => $by];
    } catch (Throwable $e) { $out['errors'][] = $e->getMessage(); }
    return $out;
}
/** 带 10 分钟缓存；七牛读不到时用旧缓存（标注更新时间），再没有就返回只含错误的结果。 */
function qb_overview($now = null, $ttl = 600)
{
    $now = $now ?? time();
    $file = qb_cache_file();
    $cached = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (is_array($cached) && ($now - (int)($cached['updated_at'] ?? 0)) < $ttl && empty($cached['errors'])) return $cached;
    $fresh = qb_fetch($now);
    if ($fresh['balance'] || $fresh['month'] || $fresh['last']) {
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
        if (@file_put_contents($file, json_encode($fresh)) !== false) @chmod($file, 0666);
        return $fresh;
    }
    if (is_array($cached) && ($cached['balance'] || $cached['month'])) { $cached['errors'] = $fresh['errors']; $cached['stale'] = true; return $cached; }
    return $fresh;
}
