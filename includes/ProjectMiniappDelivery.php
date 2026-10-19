<?php
function ps_miniapp_custom_order(array $order)
{
    return in_array(trim((string)($order['project_type'] ?? '')), ['小程序开发','小程序客服'], true)
        && in_array(trim((string)($order['order_kind'] ?? '')), ['定制','开发定制','AI定制'], true);
}

function ps_miniapp_custom_unshipped(array $order, array $source = [])
{
    if (!ps_miniapp_custom_order($order)) return false;
    $status = trim((string)($source['trade_status'] ?? $order['trade_status'] ?? ''));
    // 到账是收款证据；明确待发货的流水优先于误标的“已完成”。
    return ($order['delivery_status'] ?? '') !== 'finished'
        || preg_match('/未发货|待发货|等待卖家发货|等待发货|未付款|待付款/u', $status)
        || preg_match('/^(?:到账|已到账|付款成功|支付成功|已付款|已支付|买家已付款)(?:\d+(?:\.\d+)?)?$/u', $status)
        || ($status !== '' && is_numeric(str_replace([',', '¥', '￥'], '', $status)));
}
