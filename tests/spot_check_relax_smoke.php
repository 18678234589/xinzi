<?php
// 免财务审核业务（AI网站定制 / 网站模板…）自动结算的放宽：只有一位技术算一人全栈、SSL 成本对不上和小额待审成本不再拦、
// 实收不超过本单售价（实付含别的部门 / 订单的钱时只记本单自己的售价）。大额成本、两位以上技术没指定后端仍然等人处理。
$root = getenv('HEZUOSHANG_APP') ?: dirname(__DIR__);
require_once $root . '/includes/ProjectExpectedSettlement.php';
require_once $root . '/includes/ProjectAutoReviewMath.php';
function sp_check($name, $ok) { if (!$ok) throw new RuntimeException('未通过：' . $name); echo "  [OK] $name\n"; }
$order = ['id' => 1, 'order_no' => 'SPOT-TEST', 'project_type' => 'AI网站定制', 'order_kind' => '博山定制', 'delivery_status' => 'finished', 'settlement_status' => 'draft', 'order_date' => '2026-10-10', 'contract_amount' => 100, 'receipt_amount' => 0, 'refund_amount' => 0, 'shop' => '美呀美旗舰店'];
$rule = ['id' => 1, 'rate' => .15, 'calc_mode' => 'individual', 'service_fee_rate' => 0, 'per_order_subsidy' => 0];
$person = ['employee_id' => 1, 'role_name' => '前端', 'group_weight' => 1, 'rule' => $rule, 'estimated_calc' => ps_calc_person($rule, 100, 0, 100, 1, 0)];
$summary = ['income' => 100, 'profit' => 100, 'service_fee_rate' => 0, 'direct_cost' => 0, 'pending_cost' => 0, 'groups' => ['technical' => ['people' => [$person], 'weight' => 1, 'missing_rule' => false, 'subsidy' => 0, 'subsidy_rule' => false]]];
$base = ['order' => $order, 'source' => ['trade_status' => '交易成功'], 'cash' => [], 'costs' => [], 'resource' => [], 'catalog' => ['requires_technical' => true], 'technical_count' => 1, 'participants' => [['commission_group' => 'technical', 'role_name' => '前端']],
    'pending_requests' => 0, 'pending_refunds' => 0, 'snapshot_count' => 0, 'period_status' => 'open', 'today' => '2026-10-18', 'later_refund' => 0,
    'payment' => ['paid_cents' => 10000, 'refund_cents' => 0, 'warnings' => [], 'references' => [], 'matched_sources' => 1], 'summary' => $summary];
$codes = function ($ctx) { return array_column(pa_evaluate($ctx)['reasons'], 'code'); };

sp_check('只有一位技术（只写前端）：算一人全栈，不再要求指定后端', !in_array('backend_technical', $codes($base), true));
$two = $base; $two['technical_count'] = 2; $two['participants'] = [['commission_group' => 'technical', 'role_name' => '前端'], ['commission_group' => 'technical', 'role_name' => '前端']];
sp_check('两位技术都是前端：仍要指定后端', in_array('backend_technical', $codes($two), true));

$ssl = $base; $ssl['resource'] = ['ssl_expected_amount' => 100];
sp_check('免审核业务：SSL 报备成本与凭证对不上不再拦', !in_array('ssl_cost', $codes($ssl), true));
$sslOther = $ssl; $sslOther['order']['project_type'] = '商标'; $sslOther['catalog'] = [];
sp_check('其他业务：SSL 成本对不上仍然是异常', in_array('ssl_cost', $codes($sslOther), true));

$small = $base; $small['costs'] = [['id' => 1, 'review_status' => 'pending', 'amount' => 300, 'category' => 'other', 'item_name' => '成本', 'is_custom' => 1, 'proof_path' => null, 'reviewed_by_admin' => null, 'quantity' => 1, 'unit_price' => 300, 'cost_kind' => 'one_time']];
sp_check('小额（≤500）待审成本：免审核业务不再拦', !in_array('cost_pending', $codes($small), true));
$big = $base; $big['costs'] = [array_replace($small['costs'][0], ['amount' => 800, 'unit_price' => 800])];
sp_check('大额（>500）待审成本：仍等财务', in_array('cost_pending', $codes($big), true));
$smallOther = $small; $smallOther['order']['project_type'] = '商标'; $smallOther['catalog'] = [];
sp_check('其他业务：小额待审成本仍等财务', in_array('cost_pending', $codes($smallOther), true));

// 实收：实付 1100 > 售价 100（合并付款）→ 只登记 100；实付 90 < 售价 → 登记 90；没有实付证据 → 按售价
$over = $base; $over['payment']['paid_cents'] = 110000;
$r = pa_evaluate($over);
sp_check('实付 1100 大于本单售价 100：只登记 100（不多算分成）：' . $r['receipt_to_add_cents'], $r['receipt_to_add_cents'] === 10000 && !empty($r['spot_check']));
$under = $base; $under['payment']['paid_cents'] = 9000;
sp_check('实付 90 小于售价 100（优惠）：登记实付 90', pa_evaluate($under)['receipt_to_add_cents'] === 9000);
$none = $base; $none['order']['project_type'] = '设计'; $none['catalog'] = []; $none['payment']['paid_cents'] = null; $none['payment']['matched_sources'] = 0;
$rr = pa_evaluate($none);
sp_check('设计：没有实付证据：按售价登记 100：' . json_encode(array_column($rr['reasons'], 'code')) . $rr['receipt_to_add_cents'], $rr['receipt_to_add_cents'] === 10000);
// 参与人写明原因把实收改成大于售价（500 > 100）：免审核业务自动审核通过（事后在每月 5 单抽查里核对）
$ov = $base; $ov['order']['receipt_amount'] = 500; $ov['cash'] = [['movement_type' => 'receipt', 'amount' => 500, 'note' => '实收更正（订单参与人）：客户补款', 'review_status' => 'approved']]; $ov['payment']['paid_cents'] = 50000;
$res = pa_evaluate($ov);
sp_check('实收 500 大于售价 100（写了原因）：自动审核通过：' . json_encode(array_column($res['reasons'], 'code')), $res['can_apply'] && !empty($res['spot_check']));
echo "\n=== 免审核业务自动结算放宽测试全部通过 ===\n";
