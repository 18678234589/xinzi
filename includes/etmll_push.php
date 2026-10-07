<?php
/**
 * 本站 → ETMLL 订单推送（与 etmll_sync.php 的 ETMLL → 本站 组成双向同步）。
 * 范围：只同步“近一年”的订单（按订单日期）；只推正数金额的店铺订单；退款/负数流水、等待付款的订单不推。
 * 归属：只能确定商户（按店铺名取 ETMLL 里的商户），合伙人 / 归属 assignment 留空，由 ETMLL 自己分配；佣金按 ETMLL 通行的 5% 计，代垫 = 订单金额 − 佣金。
 * 安全：推到 ETMLL 的行打标 remark_tag='本站同步'，可整体识别和撤回；ETMLL 已有的订单号一律不覆盖（ETMLL 是结算口径来源）。
 * 增量：自动运行只推“开启同步之后新上传”的订单（orders.created_at >= etmll_push_since）；历史订单需在同步页手动确认回填。
 */
require_once __DIR__ . '/etmll_sync.php';

const ETMLL_PUSH_TAG = '本站同步';
const ETMLL_PUSH_COMMISSION_RATE = 0.05;

function etmll_sync_cutoff(): string
{
    return date('Y-m-d', strtotime('-1 year'));
}

/** 店铺名 → ETMLL 商户 id（取该店铺订单最多的商户）。 */
function etmll_push_shop_merchants(PDO $epdo): array
{
    $map = [];
    foreach ($epdo->query("SELECT shop_name,merchant_id,COUNT(*) c FROM `order` WHERE shop_name<>'' GROUP BY shop_name,merchant_id ORDER BY c DESC") as $r) {
        if (!isset($map[$r['shop_name']])) $map[$r['shop_name']] = (int)$r['merchant_id'];
    }
    return $map;
}

function etmll_push_since(): string
{
    return (string)ps_setting_get('etmll_push_since', '');
}

/**
 * @param bool $dryRun   只统计不写入
 * @param bool $backfill true=包含开启同步之前上传的历史订单（仍限近一年）
 * @param int  $limit    单次最多推送条数
 */
function etmll_push_run(bool $dryRun = false, bool $backfill = false, int $limit = 3000): array
{
    $pdo = db(); $epdo = etmll_connect();
    $since = etmll_push_since();
    $result = ['dry_run' => $dryRun, 'backfill' => $backfill, 'since' => $since, 'candidates' => 0, 'pushed' => 0, 'would_push' => 0, 'skipped_existing' => 0, 'skipped_unpaid' => 0, 'skipped_no_shop' => 0, 'by_shop' => [], 'amount' => 0.0];
    if (!$backfill && $since === '') { $result['note'] = '尚未开启本站→ETMLL 同步（etmll_push_since 未设置）'; return $result; }
    $lockName = 'etmll_push_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
    if (!$dryRun) {
        $lock = $pdo->prepare('SELECT GET_LOCK(?,0)'); $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('已有推送在进行，请稍后再试');
    }
    try {
        $shops = etmll_push_shop_merchants($epdo);
        if (!$shops) return $result;
        $cutoff = etmll_sync_cutoff();
        $in = implode(',', array_map(function ($s) use ($pdo) { return $pdo->quote($s); }, array_keys($shops)));
        $sql = "SELECT id,shop,order_no,order_amount,order_date,raw_data FROM orders
                WHERE employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 AND order_no<>'' AND order_amount>0
                  AND order_date>=" . $pdo->quote($cutoff) . " AND shop IN ($in)
                  AND raw_data NOT LIKE '%ETMLL自动同步%' AND raw_data NOT LIKE '%\"__etmll_id__\"%' AND raw_data NOT LIKE '%\"__is_refund__\"%'"
             . ($backfill ? '' : ' AND created_at>=' . $pdo->quote($since)) . ' ORDER BY id ASC';
        $existing = array_fill_keys($epdo->query('SELECT order_no FROM `order`')->fetchAll(PDO::FETCH_COLUMN), true);
        $insert = $epdo->prepare("INSERT IGNORE INTO `order` (order_no,merchant_id,total_amount,commission,proxy_amount,status,created_at,product_title,raw_status,refund_amount,confirmed_amount,buyer_paid_amount,order_create_time,order_pay_time,shop_name,shop_id,remark_tag)
            VALUES (?,?,?,?,?,0,?,?,?,0,?,?,?,?,?,'0',?)");
        // 流式读取：近一年订单带 raw_data，缓冲读取会撑爆内存
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $stream = $pdo->query($sql);
        foreach ($stream as $row) {
            $result['candidates']++;
            if (isset($existing[$row['order_no']])) { $result['skipped_existing']++; continue; }
            $raw = json_decode((string)$row['raw_data'], true) ?: [];
            $status = trim((string)($raw['订单状态'] ?? $raw['__order_status__'] ?? ''));
            if (mb_strpos($status, '等待买家付款') !== false) { $result['skipped_unpaid']++; continue; }
            if (($result['would_push'] + $result['pushed']) >= $limit) { $result['truncated'] = true; break; }
            $total = round((float)$row['order_amount'], 2);
            $commission = round($total * ETMLL_PUSH_COMMISSION_RATE, 4);
            $trade = trim((string)($raw['付款时间'] ?? $raw['__trade_time__'] ?? ''));
            $at = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $trade) ? substr(str_replace('T', ' ', $trade), 0, 19) : $row['order_date'] . ' 00:00:00';
            $shopKey = $row['shop'];
            $result['by_shop'][$shopKey] = ($result['by_shop'][$shopKey] ?? 0) + 1;
            $result['amount'] += $total;
            if ($dryRun) { $result['would_push']++; $existing[$row['order_no']] = true; continue; }
            $insert->execute([$row['order_no'], $shops[$shopKey], $total, $commission, round($total - $commission, 4), $at, mb_substr(trim((string)($raw['商品标题'] ?? '')), 0, 500),
                $status !== '' ? mb_substr($status, 0, 50) : '交易成功', $total, $total, $at, $at, $shopKey, ETMLL_PUSH_TAG]);
            if ($insert->rowCount()) { $result['pushed']++; $existing[$row['order_no']] = true; } else $result['skipped_existing']++;
        }
        $stream->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        $result['amount'] = round($result['amount'], 2);
        if (!$dryRun && $result['pushed'] > 0) {
            try { ps_audit('etmll_push', 0, $backfill ? 'backfill' : 'push', ['type' => 'system', 'id' => 0], $result); } catch (Throwable $e) { error_log('etmll_push 审计未写入'); }
        }
        return $result;
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        if (!$dryRun) { $u = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $u->execute([$lockName]); }
    }
}

/** 同步页展示：近一年里已推送过的条数、仍待回填的历史订单数。 */
function etmll_push_status(): array
{
    $epdo = etmll_connect();
    $q = $epdo->prepare('SELECT COUNT(*) FROM `order` WHERE remark_tag=?'); $q->execute([ETMLL_PUSH_TAG]);
    $history = etmll_push_run(true, true, 100000);
    $fresh = etmll_push_since() !== '' ? etmll_push_run(true, false, 100000) : ['would_push' => 0];
    return ['pushed_total' => (int)$q->fetchColumn(), 'since' => etmll_push_since(), 'pending_new' => (int)$fresh['would_push'],
            'pending_history' => max(0, (int)$history['would_push'] - (int)$fresh['would_push']), 'history_amount' => $history['amount'], 'history_by_shop' => $history['by_shop']];
}
