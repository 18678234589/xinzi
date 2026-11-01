<?php
/** 雅云账户余额与充值。 */
require_once __DIR__ . '/YayunApi.php';

/** 雅云账号余额与可用支付方式。 */
function ya_balance($call = 'ya_call')
{
    $res = $call('user_info', [], 'GET', 30);
    if ((int)($res['status'] ?? 0) !== 200 || !is_array($res['user'] ?? null)) throw new RuntimeException('雅云账户信息读取失败');
    $gateways = [];
    foreach ((array)($res['gateways'] ?? []) as $g) if (!empty($g['status']) && !empty($g['name'])) $gateways[] = ['name' => (string)$g['name'], 'title' => (string)($g['title'] ?? $g['name'])];
    return ['balance' => number_format((float)($res['user']['credit'] ?? 0), 2, '.', ''), 'gateways' => $gateways, 'credit_limit' => !empty($res['user']['is_open_credit_limit'])];
}
/**
 * 创建充值账单（只生成一张未支付账单，不动余额）。接口没有直接返回支付页，所以返回雅云的账单页地址：
 * 在浏览器里登录过雅云网站的人打开后用支付宝等方式支付，到账后余额自动增加。
 */
function ya_recharge($amount, $call = 'ya_call', $base = null)
{
    if (!is_numeric($amount) || (float)$amount < 1 || (float)$amount > 50000 || round((float)$amount, 2) != (float)$amount) throw new InvalidArgumentException('充值金额请填写 1–50000 元（最多两位小数）');
    $info = ya_balance($call);
    if (!$info['gateways']) throw new RuntimeException('雅云没有可用的支付方式');
    $res = $call('recharge', ['amount' => number_format((float)$amount, 2, '.', ''), 'payment' => $info['gateways'][0]['name']], 'POST', 30);
    $invoice = (int)($res['data']['invoice_id'] ?? 0);
    if ((int)($res['status'] ?? 0) !== 200 || $invoice < 1) throw new RuntimeException('生成充值账单失败：' . mb_substr((string)($res['msg'] ?? ''), 0, 100));
    return ['invoice_id' => $invoice, 'amount' => number_format((float)$amount, 2, '.', ''), 'gateway' => $info['gateways'][0]['title'], 'url' => ($base ?? ya_base()) . '/viewbilling?id=' . $invoice];
}
