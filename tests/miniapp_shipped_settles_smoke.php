<?php
// 小程序定制：发货的都结算，没发货的不结算。已发货 / 待收货 / 已收货 / 交易成功都算已发货（不再另看到账、交付确认）；
// 待发货 / 未付款不算；状态只写“到账 / 金额”不算发货；状态为空时才看交付确认。其他业务（网站类）的规则不变。
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectExpectedSettlement.php';
require_once $root . '/includes/ProjectAutoReviewMath.php';
function ship_check($name, $ok) { if (!$ok) throw new RuntimeException('未通过：' . $name); echo "  [OK] $name\n"; }
$order = ['id' => 1, 'order_no' => 'SHIP-TEST', 'project_type' => '小程序开发', 'order_kind' => '定制', 'delivery_status' => 'unfinished', 'settlement_status' => 'draft', 'order_date' => '2026-10-10', 'contract_amount' => 100, 'receipt_amount' => 0, 'refund_amount' => 0, 'shop' => '美呀美旗舰店'];
$un = function ($o, $status) { return ps_deferred_order_unshipped($o, ['trade_status' => $status]); };
foreach (['卖家已发货，等待买家确认', '已发货，待收货', '已收货', '交易成功', '等待买家确认收货'] as $st) ship_check("“{$st}”算已发货（交付没确认、没到账也结算）", !$un($order, $st));
foreach (['待发货', '买家已付款,等待卖家发货', '等待买家付款', '未发货'] as $st) ship_check("“{$st}”不结算", $un($order, $st));
ship_check('状态只写“到账 / 金额”不算发货（即使交付已确认）', $un(array_replace($order, ['delivery_status' => 'finished']), '到账') && $un(array_replace($order, ['delivery_status' => 'finished']), '￥1,000.00'));
ship_check('状态为空：交付确认了才算，没确认不算', !$un(array_replace($order, ['delivery_status' => 'finished']), '') && $un($order, ''));
ship_check('已发货但状态也写了未发货类字样时，以未发货为准', $un($order, '待发货，已发货'));
ship_check('非定制的小程序订单（如续费）不适用这条规则', !ps_deferred_order_unshipped(array_replace($order, ['order_kind' => '续费']), ['trade_status' => '待发货']));
ship_check('网站类订单的规则不变：待发货仍不结算', ps_deferred_order_unshipped(array_replace($order, ['project_type' => 'AI网站定制', 'order_kind' => '博山定制', 'delivery_status' => 'finished', 'receipt_amount' => 100]), ['trade_status' => '待发货']));
// 自动审核：已发货的小程序定制，付款证据成立，不再被“尚未确认业务已完成”拦住
$rule = ['id' => 1, 'rate' => .1, 'calc_mode' => 'pool', 'service_fee_rate' => 0, 'per_order_subsidy' => 0];
$person = ['employee_id' => 1, 'role_name' => '技术', 'group_weight' => 1, 'rule' => $rule, 'estimated_calc' => ps_calc_person($rule, 100, 0, 100, 1, 0)];
$summary = ['income' => 100, 'profit' => 100, 'service_fee_rate' => 0, 'direct_cost' => 0, 'pending_cost' => 0, 'groups' => ['technical' => ['people' => [$person], 'weight' => 1, 'missing_rule' => false, 'subsidy' => 0, 'subsidy_rule' => false]]];
$ctx = ['order' => $order, 'source' => ['trade_status' => '已发货，待收货'], 'cash' => [], 'costs' => [], 'resource' => [], 'catalog' => [], 'technical_count' => 1, 'pending_requests' => 0, 'pending_refunds' => 0, 'snapshot_count' => 0, 'period_status' => 'open', 'today' => '2026-10-18', 'later_refund' => 0, 'payment' => ['paid_cents' => 10000, 'refund_cents' => 0, 'warnings' => [], 'references' => [], 'matched_sources' => 1], 'summary' => $summary];
$shipped = pa_evaluate($ctx);
$codes = array_column($shipped['reasons'], 'code');
ship_check('已发货：没有“未发货”和“尚未确认业务已完成”两条拦截：' . implode(',', $codes), !in_array('order_unshipped', $codes, true) && !in_array('delivery', $codes, true));
$ctx['source']['trade_status'] = '待发货';
$codes = array_column(pa_evaluate($ctx)['reasons'], 'code');
ship_check('待发货：仍被“未发货”拦住', in_array('order_unshipped', $codes, true) || in_array('miniapp_unshipped', $codes, true));
// 付款证据：已发货就是买家已付款（小程序、商标、网站续费、网站售后修改）
$src = [['id' => 1, 'shop' => '美呀美旗舰店', 'order_no' => 'SHIP-TEST', 'raw' => ['__financial_source__' => 'shop_statement', '__order_status__' => '已发货，待收货', '买家实付金额' => '100.00']]];
$ev = pa_payment_evidence($src, '美呀美旗舰店', null, '小程序开发');
ship_check('小程序订单：已发货的店铺流水算付款证据', $ev['paid_cents'] === 10000 && empty($ev['warnings']['payment_status']));
$ev2 = pa_payment_evidence($src, '美呀美旗舰店', null, '微信代写');
foreach (['网站续费', '网站修改', '商标', '设计', '平面设计', '环境配置'] as $biz) { $evb = pa_payment_evidence($src, '美呀美旗舰店', null, $biz); ship_check("{$biz}：已发货的店铺流水算付款证据", $evb['paid_cents'] === 10000 && empty($evb['warnings']['payment_status'])); }
ship_check('其他业务（如微信代写）：已发货仍不算付款证据', $ev2['paid_cents'] === null && !empty($ev2['warnings']['payment_status']));
// 预计分成口径：网站类订单已收款就计入预计（即使交付没标完成）；没收款 / 待发货仍不计；小程序定制照旧看发货状态
$web = ['id' => 2, 'order_no' => 'FC-WEB', 'project_type' => 'AI网站定制', 'order_kind' => '博山定制', 'delivery_status' => 'unfinished', 'settlement_status' => 'draft', 'order_date' => '2026-10-10', 'contract_amount' => 1300, 'receipt_amount' => 1300, 'refund_amount' => 0];
ship_check('预计：网站类交付没完成一律不计（即使已收款 / 已发货）；完成了的才计入', ps_forecast_unshipped($web, ['trade_status' => '卖家已发货，等待买家确认']) && !ps_forecast_unshipped(array_replace($web, ['delivery_status' => 'finished']), ['trade_status' => '卖家已发货，等待买家确认']));
$webNoCash = array_replace($web, ['receipt_amount' => 0, 'delivery_status' => 'finished']);
ship_check('预计：网站模板没收款记录仍不计', ps_forecast_unshipped(array_replace($webNoCash, ['project_type' => '网站模板']), ['trade_status' => '交易成功']));
ship_check('预计：网站类没收款记录，但店铺已发货 / 交易成功，或没有店铺状态（对公、微信）也计入', !ps_forecast_unshipped($webNoCash, ['trade_status' => '交易成功']) && !ps_forecast_unshipped($webNoCash, ['trade_status' => '卖家已发货，等待买家确认']) && !ps_forecast_unshipped($webNoCash, []));
ship_check('预计：待发货 / 交易关闭仍不计', ps_forecast_unshipped($webNoCash, ['trade_status' => '买家已付款,等待卖家发货']) && ps_forecast_unshipped($webNoCash, ['trade_status' => '交易关闭']) && ps_forecast_unshipped($web, ['trade_status' => '待发货']));
ship_check('预计：小程序定制仍看发货状态', ps_forecast_unshipped($order, ['trade_status' => '待发货']) && !ps_forecast_unshipped($order, ['trade_status' => '交易成功']));
ship_check('预计：网站类交付没完成，客服、技术都不计；完成了的都计；小程序不受影响', ps_forecast_group_skip($web, 'technical') && ps_forecast_group_skip($web, 'customer_service') && !ps_forecast_group_skip(array_replace($web, ['delivery_status' => 'finished']), 'customer_service') && !ps_forecast_group_skip($order, 'technical'));
echo "\n=== 小程序定制发货结算测试全部通过 ===\n";
