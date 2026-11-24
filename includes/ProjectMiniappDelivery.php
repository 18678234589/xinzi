<?php
require_once __DIR__ . '/ProjectBusiness.php';

function ps_miniapp_custom_order(array $order)
{
    return in_array(trim((string)($order['project_type'] ?? '')), ['小程序开发','小程序客服'], true)
        && in_array(trim((string)($order['order_kind'] ?? '')), ['定制','开发定制','AI定制'], true);
}

/** 网站定制、AI网站定制、网站模板、以及小程序开发定制业务（需要发货到账后才结算） */
function ps_deferred_delivery_order(array $order)
{
    $biz = trim((string)($order['project_type'] ?? ''));
    if (ps_is_website_order($biz)) return true;
    return ps_miniapp_custom_order($order);
}

function ps_miniapp_custom_unshipped(array $order, array $source = [])
{
    return ps_deferred_order_unshipped($order, $source);
}

/**
 * 预计分成 / 预期结算口径的“未发货”：网站类订单不要求技术已点“已完成”；AI网站定制还不要求已有收款记录——
 * 店铺状态已付款 / 已发货 / 交易成功，或根本没有店铺状态（对公、微信等非店铺付款，由财务另行确认到账）都计入预计；
 * 待发货 / 未付款 / 交易关闭仍不计。审核通过仍必须交付完成、收款确认，不受这里影响。小程序定制照旧以店铺发货状态为准。
 */
/**
 * 预计分成里某个分成组要不要跳过：网站类业务（模板 / AI定制 / 网站定制）交付没完成，客服、技术都不计提成——即使客户已付款、店铺已发货也不计，
 * 必须技术标了“已完成”才算（小程序定制按店铺发货状态，不在此列）。
 */
function ps_forecast_group_skip(array $order, $group)
{
    return ps_deferred_delivery_order($order) && !ps_miniapp_custom_order($order) && ps_website_unfinished($order);
}

/**
 * 预计分成 / 预期结算口径的“未发货”：小程序定制看店铺发货状态；网站类订单交付没完成一律不计（见 ps_forecast_group_skip），
 * 交付完成的 AI网站定制还允许没有收款记录（店铺已发货 / 交易成功，或对公、微信等非店铺付款由财务另行确认到账）。
 * 待发货 / 未付款 / 交易关闭不计。审核通过仍必须交付完成、收款确认。
 */
function ps_forecast_unshipped(array $order, array $source = [])
{
    if (ps_deferred_delivery_order($order) && !ps_miniapp_custom_order($order)) {
        if (ps_website_unfinished($order)) return true;
        $status = trim((string)($source['trade_status'] ?? $order['trade_status'] ?? ''));
        $custom = in_array(ps_business_normalize((string)($order['project_type'] ?? '')), ['AI网站定制', '网站定制'], true);
        if ($custom && (float)($order['receipt_amount'] ?? 0) - (float)($order['refund_amount'] ?? 0) <= 0 && !preg_match('/关闭|取消/u', $status)
            && ($status === '' || preg_match('/已发货|等待买家确认|等待确认收货|交易成功|已收货|确认收货|交易完成|已签收|已完成|已付款|买家已付款/u', $status))) {
            $order['receipt_amount'] = (float)($order['contract_amount'] ?? 0);
        }
    }
    return ps_deferred_order_unshipped($order, $source);
}
/**
 * 小程序定制是否已发货：以店铺订单状态里的发货证据为准——已发货 / 待收货 / 已收货 / 交易成功等就算已发货（已发货的都结算，不再另看到账和交付确认）；
 * 待发货 / 未付款不算；状态只写“到账 / 金额”不算发货；状态里什么都没写的，才看技术 / 财务有没有确认交付完成。
 */
function ps_miniapp_custom_shipped(array $order, array $source = [])
{
    if (!ps_miniapp_custom_order($order)) return false;
    $status = trim((string)($source['trade_status'] ?? $order['trade_status'] ?? ''));
    if (preg_match('/未发货|待发货|等待卖家发货|等待发货|未付款|待付款|待支付/u', $status)) return false;
    if (preg_match('/已发货|等待买家确认|等待确认收货|交易成功|已收货|确认收货|交易完成|已签收|已完成/u', $status)) return true;
    if ($status !== '' && (preg_match('/^(?:到账|已到账|付款成功|支付成功|已付款|已支付|买家已付款)(?:\d+(?:\.\d+)?)?$/u', $status) || is_numeric(str_replace([',', '¥', '￥'], '', $status)))) return false;
    return ($order['delivery_status'] ?? '') === 'finished';
}

function ps_deferred_order_unshipped(array $order, array $source = [])
{
    if (!ps_deferred_delivery_order($order)) return false;
    // 小程序定制：发货的都结算，没发货的不结算（不再附加“未到账”等条件）
    if (ps_miniapp_custom_order($order)) return !ps_miniapp_custom_shipped($order, $source);
    $biz = trim((string)($order['project_type'] ?? ''));
    $status = trim((string)($source['trade_status'] ?? $order['trade_status'] ?? ''));

    // 1. 交付状态未完成（必须技术/财务确认为 finished）
    if (($order['delivery_status'] ?? '') !== 'finished') return true;

    // 2. 明确处于未发货/待发货状态的流水优先于误标的“已完成”
    if (preg_match('/未发货|待发货|等待卖家发货|等待发货|未付款|待付款/u', $status)) return true;

    // 3. 小程序开发定制：单纯“到账”字样或数字到账（无发货证据）不代表已发货
    if (in_array($biz, ['小程序开发', '小程序客服'], true)) {
        if (preg_match('/^(?:到账|已到账|付款成功|支付成功|已付款|已支付|买家已付款)(?:\d+(?:\.\d+)?)?$/u', $status)
            || ($status !== '' && is_numeric(str_replace([',', '¥', '￥'], '', $status)))) {
            return true;
        }
    }

    // 4. 未到账：净实收 <= 0 且售价 > 0（尚未收到任何款项）
    $net = (float)($order['receipt_amount'] ?? 0) - (float)($order['refund_amount'] ?? 0);
    if ($net <= 0 && (float)($order['contract_amount'] ?? 0) > 0) return true;

    return false;
}
