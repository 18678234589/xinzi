<?php
require_once __DIR__ . '/ProjectSettlement.php';

function ps_source_record($orderId, $priceSource, $nickname, $tradeStatus)
{
    $q = db()->prepare('INSERT INTO project_order_sources (order_id,payment_nickname,trade_status,price_source,nickname_source,status_source) VALUES (?,?,?,?,?,?)');
    $q->execute([(int)$orderId, $nickname, $tradeStatus, $priceSource, $nickname !== '' ? 'manual' : 'missing', $tradeStatus !== '' ? 'manual' : 'missing']);
}

function ps_source_nickname($raw)
{
    foreach (['付款昵称','买家付款昵称','买家昵称','买家会员名','买家用户名','买家','会员名','客户昵称'] as $needle) {
        foreach ($raw as $key => $value) {
            if (strpos((string)$key, '__') === 0 || mb_strpos((string)$key, $needle) === false) continue;
            $value = trim((string)$value);
            if ($value !== '') return mb_substr($value, 0, 200);
        }
    }
    return '';
}

/** 只同步空字段；成交价与已确认收款是两套数据。调用方负责事务。 */
function ps_sync_project_from_shop_order($legacyId, $orderNo, $shop, $raw, $price)
{
    $orderNo = trim((string)$orderNo);
    if ($orderNo === '' || !is_array($raw) || !empty($raw['__is_refund__'])) return false;
    $q = db()->prepare("SELECT id,shop,settlement_status,contract_amount FROM project_orders WHERE order_no=? FOR UPDATE");
    $q->execute([$orderNo]);
    $order = $q->fetch();
    if (!$order || in_array($order['settlement_status'], ['approved','locked'], true)) return false;
    if ($order['shop'] !== '' && $shop !== '' && $order['shop'] !== $shop) return false;
    $source = db()->prepare('SELECT * FROM project_order_sources WHERE order_id=? FOR UPDATE');
    $source->execute([$order['id']]);
    $meta = $source->fetch();
    if (!$meta) {
        db()->prepare("INSERT INTO project_order_sources (order_id,price_source) VALUES (?,?)")
            ->execute([$order['id'], (float)$order['contract_amount'] > 0 ? 'manual' : 'missing']);
        $source->execute([$order['id']]);
        $meta = $source->fetch();
    }
    $nickname = ps_source_nickname($raw);
    $status = mb_substr(trim((string)($raw['__order_status__'] ?? '')), 0, 100);
    $price = is_numeric($price) ? round((float)$price, 2) : null;
    $newPrice = $meta['price_source'] === 'missing' && $price !== null && $price > 0;
    $newNickname = $meta['nickname_source'] === 'missing' && $nickname !== '';
    $newStatus = $meta['status_source'] === 'missing' && $status !== '';
    $newShop = $order['shop'] === '' && $shop !== '';
    if (!$newPrice && !$newNickname && !$newStatus && !$newShop) return false;
    if ($newPrice || $newShop) {
        db()->prepare('UPDATE project_orders SET contract_amount=?,shop=?,row_version=row_version+1 WHERE id=?')
            ->execute([$newPrice ? $price : $order['contract_amount'], $newShop ? $shop : $order['shop'], $order['id']]);
    }
    db()->prepare('UPDATE project_order_sources SET payment_nickname=?,trade_status=?,price_source=?,nickname_source=?,status_source=?,source_order_id=?,synced_at=NOW() WHERE order_id=?')
        ->execute([$newNickname ? $nickname : $meta['payment_nickname'], $newStatus ? $status : $meta['trade_status'], $newPrice ? 'shop_upload' : $meta['price_source'], $newNickname ? 'shop_upload' : $meta['nickname_source'], $newStatus ? 'shop_upload' : $meta['status_source'], (int)$legacyId, $order['id']]);
    return true;
}

function ps_sync_existing_shop_order($projectOrderId, $orderNo, $shop)
{
    $q = db()->prepare("SELECT id,shop,raw_data FROM orders WHERE order_no=? AND employee_id=0 AND order_scope='department' AND COALESCE(is_deleted,0)=0 AND raw_data IS NOT NULL ORDER BY id DESC LIMIT 10");
    $q->execute([$orderNo]);
    $matches = [];
    foreach ($q->fetchAll() as $row) {
        if ($shop !== '' && $row['shop'] !== $shop) continue;
        $raw = json_decode((string)$row['raw_data'], true);
        if (!is_array($raw) || !array_key_exists('__original_price__', $raw)) continue;
        $matches[$row['shop']] ??= $row + ['parsed_raw' => $raw];
    }
    if (count($matches) !== 1) return false; // 同号跨店铺时不猜测来源。
    $match = reset($matches);
    return ps_sync_project_from_shop_order((int)$match['id'], $orderNo, $match['shop'], $match['parsed_raw'], $match['parsed_raw']['__original_price__']);
}
