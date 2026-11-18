<?php
/**
 * 开通新服务器（真实扣费：雅云账号余额）。流程：选配置 → 报价（只读，写入 yayun_orders=quoted）→ 勾选确认 + 输入商品编号 → 提交。
 * 提交：先把 quoted 原子改成 started（防重复点击 / 并发），再依次 cart/add_to_shop → cart/settle → 核对账单金额 → apply_credit 余额支付。
 * 安全：账单金额必须等于报价才会支付（购物车里混进别的商品时不会误付）；超过单笔上限直接拒绝；任何一步异常都不自动重试，状态保留为 unknown / failed 供人工核对。
 */
require_once __DIR__ . '/YayunApi.php';
require_once __DIR__ . '/YayunCatalog.php';
require_once __DIR__ . '/YayunHosts.php';

const YO_MAX_CENTS = 300000; // 单笔上限 ¥3000，更贵的请到雅云后台下单
const YO_QUOTE_TTL = 600;

function yo_cents($v) { return (int)round((float)$v * 100); }

/** 解析商品配置：可选周期 + 每个配置项（下拉 / 数值范围 / 固定值）。 */
function yo_options($pid, $call = 'ya_call', $withCost = false)
{
    $res = $call('cart/get_product_config', ['pid' => (int)$pid], 'GET', 30);
    if ((int)($res['status'] ?? 0) !== 200 || empty($res['data']['products']) || (int)$res['data']['products']['id'] !== (int)$pid) throw new RuntimeException('雅云没有返回这个商品的配置，可能已下架');
    $d = $res['data']; $p = $d['products'];
    $cycles = ya_cycles_from_pricing((array)($d['product_pricings'][0] ?? []));
    $options = [];
    foreach ((array)($d['config_groups'] ?? []) as $g) foreach ((array)($g['options'] ?? []) as $o) {
        if (!empty($o['hidden'])) continue;
        $subs = []; $ranges = [];
        foreach ((array)($o['sub'] ?? []) as $s) {
            if (!empty($s['hidden'])) continue;
            $min = (int)($s['qty_minimum'] ?? 0); $max = (int)($s['qty_maximum'] ?? 0);
            if ($min > 0 || $max > 0) $ranges[] = ['min' => $min, 'max' => $max ?: $min, 'sub' => (int)$s['id']];
            else $subs[] = ['id' => (int)$s['id'], 'label' => str_replace('^', ' · ', preg_replace('/^[^|]*\|/', '', (string)($s['option_name'] ?? '')))];
        }
        $name = (string)($o['option_name'] ?? '');
        $label = preg_match('/\|(.+)$/u', $name, $m) ? $m[1] : $name;
        $key = preg_replace('/\|.*/', '', $name);
        if ($ranges) {
            $min = min(array_column($ranges, 'min')); $max = max(array_column($ranges, 'max'));
            $options[] = ['id' => (int)$o['id'], 'key' => $key, 'label' => $label, 'kind' => $min === $max ? 'fixed' : 'range', 'min' => $min, 'max' => $max, 'default' => $min, 'unit' => (string)($o['unit'] ?? '')];
        } elseif (count($subs) === 1) $options[] = ['id' => (int)$o['id'], 'key' => $key, 'label' => $label, 'kind' => 'fixed_sub', 'sub' => $subs[0]['id'], 'text' => $subs[0]['label']];
        elseif ($subs) $options[] = ['id' => (int)$o['id'], 'key' => $key, 'label' => $label, 'kind' => 'select', 'subs' => $subs, 'default' => $subs[0]['id']];
    }
    $list = $cycles; $costOk = false;
    if ($withCost && $cycles) {
        // 成本价 = 账号折扣后的实际扣费价（cart/get_total 的 sale_total），按默认配置逐周期取；取不到的周期退回标价。
        try {
            $defaults = yo_build_config($options, []);
            foreach ($cycles as $cycle => $listPrice) {
                try { $cycles[$cycle] = yo_total($pid, $cycle, $defaults, $call)['cents'] / 100; $costOk = true; } catch (Throwable $e) { /* 保留标价 */ }
            }
        } catch (Throwable $e) { /* 保留标价 */ }
    }
    return ['pid' => (int)$pid, 'name' => (string)$p['name'], 'cycles' => $cycles, 'list_cycles' => $list, 'cost_ok' => $costOk, 'stock' => (int)($p['qty'] ?? 0), 'stock_control' => (int)($p['stock_control'] ?? 0), 'options' => $options];
}
/** 把用户选择变成 configoption（option id => sub id 或数量），只接受目录里存在的值。 */
function yo_build_config(array $options, array $selection)
{
    $out = [];
    foreach ($options as $o) {
        $given = $selection[$o['id']] ?? null;
        if ($o['kind'] === 'fixed_sub') $out[$o['id']] = $o['sub'];
        elseif ($o['kind'] === 'fixed') $out[$o['id']] = $o['min'];
        elseif ($o['kind'] === 'range') {
            $n = $given === null || $given === '' ? $o['default'] : (int)$given;
            if ($n < $o['min'] || $n > $o['max']) throw new InvalidArgumentException($o['label'] . '需在 ' . $o['min'] . '–' . $o['max'] . ' 之间');
            $out[$o['id']] = $n;
        } else {
            $sub = $given === null || $given === '' ? $o['default'] : (int)$given;
            if (!in_array($sub, array_column($o['subs'], 'id'), true)) throw new InvalidArgumentException('请选择有效的' . $o['label']);
            $out[$o['id']] = $sub;
        }
    }
    return $out;
}
function yo_describe(array $options, array $config)
{
    $parts = [];
    foreach ($options as $o) {
        $v = $config[$o['id']] ?? null;
        if ($v === null) continue;
        if ($o['kind'] === 'select') { foreach ($o['subs'] as $sub) if ($sub['id'] === (int)$v) $parts[] = $o['label'] . '：' . $sub['label']; }
        elseif ($o['kind'] === 'fixed_sub') $parts[] = $o['label'] . '：' . $o['text'];
        else $parts[] = $o['label'] . '：' . $v . $o['unit'];
    }
    return $parts;
}
function yo_total($pid, $cycle, array $config, $call = 'ya_call')
{
    $res = $call('cart/get_total', ['pid' => (int)$pid, 'billingcycle' => $cycle, 'qty' => 1, 'configoption' => $config], 'POST', 30);
    if ((int)($res['status'] ?? 0) !== 200 || !isset($res['products']['sale_total'])) throw new RuntimeException('雅云报价失败：' . mb_substr((string)($res['msg'] ?? ''), 0, 100));
    $cents = yo_cents($res['products']['sale_total']);
    if ($cents <= 0) throw new RuntimeException('雅云没有返回有效价格');
    return ['cents' => $cents, 'setup_cents' => yo_cents($res['products']['setupfee_total'] ?? 0), 'stock' => (int)($res['products']['qty'] ?? 0), 'stock_control' => (int)($res['products']['stock_control'] ?? 0)];
}
function yo_quote($pdo, $actor, $pid, $cycle, array $selection, $now = null, $call = 'ya_call')
{
    $now = $now ?? time();
    if (!ya_can_provision($actor)) throw new RuntimeException('只有可开通名单里的人才能开通服务器');
    if (!ya_find_product($pid)) throw new InvalidArgumentException('商品不在目录里');
    $info = yo_options($pid, $call);
    if (!isset($info['cycles'][$cycle])) throw new InvalidArgumentException('该商品不支持这个付款周期');
    if ($info['stock_control'] && $info['stock'] <= 0) throw new RuntimeException('这个商品暂时缺货');
    $config = yo_build_config($info['options'], $selection);
    $total = yo_total($pid, $cycle, $config, $call);
    if ($total['cents'] > YO_MAX_CENTS) throw new RuntimeException('单笔超过 ¥' . (YO_MAX_CENTS / 100) . '，请到雅云后台下单');
    $token = bin2hex(random_bytes(24));
    $summary = ['name' => $info['name'], 'cycle' => $cycle, 'cycle_label' => ya_cycle_labels()[$cycle] ?? $cycle, 'details' => yo_describe($info['options'], $config), 'config' => $config];
    $pdo->prepare("INSERT INTO yayun_orders (token_hash,actor_key,pid,cycle,quote_json,amount_cents,status,created_at) VALUES (?,?,?,?,?,?,'quoted',?)")
        ->execute([hash('sha256', $token), $actor['type'] . ':' . (int)$actor['id'], (int)$pid, $cycle, json_encode($summary, JSON_UNESCAPED_UNICODE), $total['cents'], $now]);
    return ['token' => $token, 'amount' => number_format($total['cents'] / 100, 2, '.', ''), 'expires_in' => YO_QUOTE_TTL] + $summary;
}
function yo_set($pdo, $id, $status, $message, array $extra = [])
{
    $pdo->prepare('UPDATE yayun_orders SET status=?,message=?,upstream_host_id=COALESCE(?,upstream_host_id),invoice_id=COALESCE(?,invoice_id),finished_at=? WHERE id=?')
        ->execute([$status, mb_substr($message, 0, 300), $extra['host'] ?? null, $extra['invoice'] ?? null, time(), (int)$id]);
}
/** 提交购买。只会向雅云发起一次；返回订单行（status: done / failed / unknown）。 */
function yo_submit($pdo, $actor, $token, $confirm, $ack, $now = null, $call = 'ya_call')
{
    $now = $now ?? time();
    if (!ya_can_provision($actor)) throw new RuntimeException('只有可开通名单里的人才能开通服务器');
    $q = $pdo->prepare('SELECT * FROM yayun_orders WHERE token_hash=?');
    $q->execute([hash('sha256', (string)$token)]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o || $o['actor_key'] !== $actor['type'] . ':' . (int)$actor['id']) throw new RuntimeException('报价不存在，请重新报价');
    if ($o['status'] !== 'quoted') return $o; // 已提交过：只返回状态，绝不重复购买
    if ($ack !== '1' || $confirm !== (string)(int)$o['pid']) throw new InvalidArgumentException('请勾选确认并输入商品编号 ' . (int)$o['pid']);
    if ($now - (int)$o['created_at'] > YO_QUOTE_TTL) throw new RuntimeException('报价已过期，请重新报价');
    $summary = json_decode($o['quote_json'], true);
    $config = array_map('intval', (array)$summary['config']);
    $config = array_combine(array_map('intval', array_keys($config)), array_values($config));
    // 提交前再核一次价格，变了就让用户重新确认
    $fresh = yo_total((int)$o['pid'], $o['cycle'], $config, $call);
    if ($fresh['cents'] !== (int)$o['amount_cents']) throw new RuntimeException('雅云价格已变化（现为 ¥' . number_format($fresh['cents'] / 100, 2) . '），请重新报价确认');
    // 原子占位：只有一个请求能把 quoted 改成 started
    $claim = $pdo->prepare("UPDATE yayun_orders SET status='started',started_at=? WHERE id=? AND status='quoted'");
    $claim->execute([$now, (int)$o['id']]);
    if ($claim->rowCount() !== 1) { $q->execute([hash('sha256', (string)$token)]); return $q->fetch(PDO::FETCH_ASSOC); }
    $id = (int)$o['id'];
    try {
        // 提交前记下已有主机编号：雅云 cart/settle 的返回里不一定带 hostid，届时靠“多出来的那一台”确认。
        $beforeIds = null; try { $beforeIds = array_column(yh_list($call)[0], 'id'); } catch (Throwable $e) { /* 读不到就没有兜底，按原逻辑处理 */ }
        $add = $call('cart/add_to_shop', ['pid' => (int)$o['pid'], 'billingcycle' => $o['cycle'], 'qty' => 1, 'configoption' => $config, 'customfield' => [], 'host' => '', 'password' => yo_password(), 'currencyid' => 1, 'is_api' => true], 'POST', 30);
        if ((int)($add['status'] ?? 0) !== 200) { yo_set($pdo, $id, 'failed', '加入购物车失败：' . ($add['msg'] ?? '')); return yo_row($pdo, $id); }
        $set = $call('cart/settle', [], 'POST', 60);
        if ((int)($set['status'] ?? 0) !== 200 && (int)($set['status'] ?? 0) !== 1001) { yo_set($pdo, $id, 'failed', '生成订单失败：' . ($set['msg'] ?? '')); return yo_row($pdo, $id); }
        $hostIds = array_values(array_filter((array)($set['data']['hostid'] ?? []), 'is_numeric'));
        if (!$hostIds && $beforeIds !== null && (int)($set['data']['invoiceid'] ?? 0) > 0) {
            try { $new = array_values(array_filter(yh_list($call)[0], function ($h) use ($beforeIds, $o) { return !in_array($h['id'], $beforeIds) && (int)$h['pid'] === (int)$o['pid']; })); if (count($new) === 1) $hostIds = [(int)$new[0]['id']]; } catch (Throwable $e) { /* 保持 unknown，不付款 */ }
        }
        $invoice = (int)($set['data']['invoiceid'] ?? 0);
        $extra = ['host' => $hostIds ? (int)$hostIds[0] : null, 'invoice' => $invoice ?: null];
        if ((int)($set['status'] ?? 0) === 1001) { yo_set($pdo, $id, 'done', '已付款，主机开通中（雅云处理需几分钟）', $extra); return yo_row($pdo, $id); }
        if ($invoice < 1 || count($hostIds) !== 1) { yo_set($pdo, $id, 'unknown', '订单里的主机数量异常（' . count($hostIds) . ' 台），为避免多扣费没有支付，请到雅云后台核对订单 / 账单 ' . $invoice, $extra); return yo_row($pdo, $id); }
        // 能读到账单金额时必须等于报价才支付；读不到（接口不返回）时靠“订单里恰好一台主机”兜底。
        $total = $set['data']['total'] ?? $set['data']['amount'] ?? $set['data']['invoice']['total'] ?? null;
        if ($total === null) { try { $detail = $call('invoice/detail', ['invoiceid' => $invoice], 'GET', 30); $total = $detail['data']['invoice']['total'] ?? $detail['invoice']['total'] ?? $detail['data']['total'] ?? null; } catch (Throwable $ie) { $total = null; } }
        if ($total !== null && yo_cents($total) !== (int)$o['amount_cents']) { yo_set($pdo, $id, 'unknown', '账单金额 ¥' . number_format(yo_cents($total) / 100, 2) . ' 与报价 ¥' . number_format($o['amount_cents'] / 100, 2) . ' 不一致，没有支付，请到雅云后台核对账单 ' . $invoice, $extra); return yo_row($pdo, $id); }
        $pay = $call('apply_credit', ['invoiceid' => $invoice, 'use_credit' => 1], 'POST', 60);
        if ((int)($pay['status'] ?? 0) === 1001) { yo_set($pdo, $id, 'done', '已付款，主机开通中（雅云处理需几分钟）', $extra); return yo_row($pdo, $id); }
        yo_set($pdo, $id, 'unknown', '订单已生成但余额支付未完成：' . mb_substr((string)($pay['msg'] ?? ''), 0, 100) . '（账单 ' . $invoice . '）。请到雅云后台处理，不要重复提交', $extra);
    } catch (Throwable $e) {
        yo_set($pdo, $id, 'unknown', '提交过程中断，结果不确定：' . mb_substr($e->getMessage(), 0, 120) . '。请先到「我的服务器」或雅云后台核对，不要重复开通');
    }
    return yo_row($pdo, $id);
}
function yo_row($pdo, $id)
{
    $q = $pdo->prepare('SELECT * FROM yayun_orders WHERE id=?');
    $q->execute([(int)$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
function yo_password()
{
    $chars = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
    $pw = '';
    foreach ($chars as $set) for ($i = 0; $i < 4; $i++) $pw .= $set[random_int(0, strlen($set) - 1)];
    return str_shuffle($pw . bin2hex(random_bytes(2)));
}
function yo_public(array $o)
{
    $s = json_decode($o['quote_json'], true) ?: [];
    return ['id' => (int)$o['id'], 'pid' => (int)$o['pid'], 'name' => $s['name'] ?? '', 'cycle' => $s['cycle_label'] ?? $o['cycle'], 'details' => $s['details'] ?? [], 'amount' => number_format($o['amount_cents'] / 100, 2, '.', ''), 'status' => $o['status'], 'message' => (string)$o['message'], 'host_id' => $o['upstream_host_id'] === null ? null : (int)$o['upstream_host_id'], 'created' => date('Y-m-d H:i', (int)$o['created_at']), 'actor' => $o['actor_key']];
}
/** status=done 只表示“已付款、订单已提交”；主机是否真的开通以雅云主机状态为准，这里带上实时状态（读不到就不带）。 */
function yo_history($pdo, $limit = 50, $call = 'ya_call')
{
    $rows = array_map('yo_public', $pdo->query("SELECT * FROM yayun_orders WHERE status<>'quoted' ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC));
    $live = null;
    foreach ($rows as &$r) {
        if ($r['status'] !== 'done' || !$r['host_id']) continue;
        if ($live === null) { $live = []; try { foreach (yh_list($call)[0] as $h) $live[$h['id']] = $h; } catch (Throwable $e) { /* 读不到就只显示已付款 */ } }
        if (isset($live[$r['host_id']])) { $r['host_status'] = $live[$r['host_id']]['status']; $r['host_status_text'] = $live[$r['host_id']]['status_text']; }
    }
    unset($r);
    return $rows;
}

/* ---------- 价格 / 库存同步（jobs/yayun_sync.php 调用，页面只读 yayun_prices） ---------- */

function yo_sync_prices($pdo, $seconds = 240, $now = null, $call = 'ya_call')
{
    $now = $now ?? time(); $started = time(); $done = 0; $errors = 0;
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO yayun_prices (pid,cycles_json,stock,stock_control,from_price,updated_at,error) VALUES (?,?,?,?,?,?,?) ON CONFLICT(pid) DO UPDATE SET cycles_json=excluded.cycles_json,stock=excluded.stock,stock_control=excluded.stock_control,from_price=excluded.from_price,updated_at=excluded.updated_at,error=excluded.error'
        : 'INSERT INTO yayun_prices (pid,cycles_json,stock,stock_control,from_price,updated_at,error) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE cycles_json=VALUES(cycles_json),stock=VALUES(stock),stock_control=VALUES(stock_control),from_price=VALUES(from_price),updated_at=VALUES(updated_at),error=VALUES(error)';
    $save = $pdo->prepare($sql);
    $old = []; foreach ($pdo->query('SELECT pid,updated_at FROM yayun_prices')->fetchAll(PDO::FETCH_ASSOC) as $r) $old[(int)$r['pid']] = (int)$r['updated_at'];
    $pids = array_column(ya_products(), 'id');
    usort($pids, function ($a, $b) use ($old) { return ($old[$a] ?? 0) <=> ($old[$b] ?? 0); }); // 最久没更新的优先
    foreach ($pids as $pid) {
        if (time() - $started >= $seconds) break;
        try {
            $info = yo_options($pid, $call, true);
            $cycles = $info['cycles'];
            $save->execute([$pid, json_encode(['cost' => $cycles, 'list' => $info['list_cycles']]), $info['stock'], $info['stock_control'], $cycles ? min($cycles) : null, $now, '']);
        } catch (Throwable $e) {
            $errors++;
            $save->execute([$pid, '{}', null, 0, null, $now, mb_substr($e->getMessage(), 0, 120)]);
        }
        $done++;
        usleep(40000);
    }
    return ['synced' => $done, 'errors' => $errors, 'seconds' => time() - $started, 'total' => count($pids)];
}
